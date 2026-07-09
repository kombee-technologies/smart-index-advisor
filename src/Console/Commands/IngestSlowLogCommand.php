<?php

namespace Kombee\IndexAdvisor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kombee\IndexAdvisor\Services\QueryFingerprinter;
use Kombee\IndexAdvisor\Services\QueryLogUpserter;
use Kombee\IndexAdvisor\Services\RecommendationReconciler;
use Kombee\IndexAdvisor\Services\SlowLogPathValidator;

/**
 * Ingests slow query data from multiple sources into index_advisor_query_stats.
 *
 * PostgreSQL sources:
 *   1. pg_stat_statements  — per-query execution stats
 *   2. pg_stat_user_tables — per-table sequential scan counts
 *   3. pg_stat_user_indexes — unused index detection (idx_scan = 0)
 *
 * MySQL sources:
 *   1. Slow query log file
 *   2. performance_schema.events_statements_summary_by_digest
 *
 * Usage:
 *   php artisan index-advisor:ingest-slow-log
 */
class IngestSlowLogCommand extends Command
{
    protected $signature = 'index-advisor:ingest-slow-log
                            {--skip-unused-indexes : Skip querying pg_stat_user_indexes (do not overwrite imported CSV DROP candidates with local DB data)}';

    protected $description = 'Ingest slow query log entries and DB engine stats into index_advisor_query_stats';

    public function __construct(
        private QueryFingerprinter $fingerprinter,
        private SlowLogPathValidator $slowLogPathValidator,
        private QueryLogUpserter $queryLogUpserter,
        private RecommendationReconciler $reconciler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $driver = DB::getDriverName();
        $total = 0;

        match ($driver) {
            'mysql' => $total += $this->ingestMysql(),
            'pgsql' => $total += $this->ingestPgsql(),
            'sqlsrv' => $total += $this->ingestMssql(),
            'sqlite' => $total += $this->ingestSqlite(),
            default => $this->warn("Unsupported driver: {$driver}"),
        };

        $this->info("✅  Ingested / refreshed {$total} query-stat records.");

        if (config('index_advisor.reconciliation.enabled', true)) {
            $reconcile = $this->reconciler->reconcile();
            if ($reconcile['dismissed'] > 0) {
                $this->line("  Reconciliation: {$reconcile['dismissed']} stale recommendation(s) dismissed.");
            }
            if ($reconcile['synced'] > 0) {
                $this->line("  Reconciliation: {$reconcile['synced']} column(s) re-synced with live schema.");
            }
        }

        return Command::SUCCESS;
    }

    // ─── MySQL ─────────────────────────────────────────────────────────────────

    private function ingestMysql(): int
    {
        $count = 0;
        $count += $this->ingestMysqlSlowLog();
        $count += $this->ingestMysqlPerfSchema();

        if (! $this->option('skip-unused-indexes')) {
            $count += $this->ingestMysqlUnusedIndexes();
        } else {
            $this->line('  sys.schema_unused_indexes: skipped (--skip-unused-indexes). Imported CSV DROP data is preserved.');
        }

        return $count;
    }

    /**
     * Ingest unused index data from MySQL sys.schema_unused_indexes.
     * These are stored as DROP candidates in index_advisor_recommendations.
     */
    private function ingestMysqlUnusedIndexes(): int
    {
        try {
            $rows = DB::select("
                SELECT
                    u.index_name,
                    u.object_name AS table_name,
                    COALESCE(s.column_name, u.index_name) AS column_name,
                    0 AS idx_scan
                FROM sys.schema_unused_indexes u
                JOIN information_schema.STATISTICS s
                       ON s.table_schema = u.object_schema
                      AND s.table_name = u.object_name
                      AND s.index_name = u.index_name
                      AND s.seq_in_index = 1
                WHERE u.object_schema NOT IN ('mysql', 'performance_schema', 'information_schema', 'sys')
                  AND u.object_schema = DATABASE()
                  AND s.NON_UNIQUE = 1
            ");
        } catch (\Throwable $e) {
            $this->warn("  sys.schema_unused_indexes query failed: {$e->getMessage()}");

            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            DB::table('index_advisor_recommendations')->upsert(
                [
                    'table_name' => $row->table_name,
                    'column_name' => $row->column_name,
                    'index_type' => 'DROP',
                    'score' => 70,
                    'evidence' => json_encode([
                        'type' => 'DROP',
                        'index_name' => $row->index_name,
                        'table_name' => $row->table_name,
                        'column' => $row->column_name,
                        'idx_scan' => 0,
                        'verdict' => 'HIGH — Unused index, consider dropping to reduce write overhead',
                    ]),
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                ['table_name', 'column_name', 'index_type'],
                ['score', 'evidence', 'updated_at']
            );
            $count++;
        }

        $this->line("  sys.schema_unused_indexes: {$count} unused index records stored as DROP candidates.");

        return $count;
    }

    private function ingestMysqlSlowLog(): int
    {
        $logPath = $this->slowLogPathValidator->resolve(
            (string) config('index_advisor.slow_log_path', '/var/log/mysql/slow.log')
        );

        if ($logPath === null) {
            $this->warn('  MySQL slow log path is missing, unreadable, or outside allowed directories.');

            return 0;
        }

        $content = file_get_contents($logPath);
        $pattern = '/# Query_time:\s*([\d.]+)\s+Lock_time:\s*([\d.]+).*?\n((?:SELECT|INSERT|UPDATE|DELETE).*?);/si';

        if (! preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            $this->warn('  No slow log entries matched the expected format.');

            return 0;
        }

        $count = 0;
        foreach ($matches as $match) {
            $sql = trim($match[3]);
            $avgMs = (float) $match[1] * 1000;
            $fingerprint = $this->fingerprinter->fingerprint($sql);
            $sqlSample = Str::limit($sql, 2000);

            DB::table('index_advisor_query_stats')->upsert(
                [
                    'fingerprint' => $fingerprint,
                    'sql_sample' => $sqlSample,
                    'avg_duration_ms' => $avgMs,
                    'source' => 'slow_log',
                    'db_driver' => 'mysql',
                    'recorded_at' => now(),
                ],
                ['fingerprint'],
                ['avg_duration_ms', 'recorded_at']
            );

            $this->queryLogUpserter->mergeIngestedRow(
                $fingerprint,
                $sqlSample,
                1,
                $avgMs,
                $avgMs,
                now(),
            );
            $count++;
        }

        $this->line("  Slow log: {$count} entries ingested.");

        return $count;
    }

    private function ingestMysqlPerfSchema(): int
    {
        try {
            $rows = DB::select("
                SELECT
                    DIGEST_TEXT                              AS sql_digest,
                    COUNT_STAR                               AS exec_count,
                    ROUND(AVG_TIMER_WAIT / 1000000000, 2)    AS avg_ms,
                    SUM_NO_INDEX_USED                        AS full_scans
                FROM performance_schema.events_statements_summary_by_digest
                WHERE SCHEMA_NAME NOT IN ('mysql', 'performance_schema', 'information_schema', 'sys')
                  AND (SCHEMA_NAME = DATABASE() OR DATABASE() IS NULL)
                  AND COUNT_STAR >= ?
                ORDER BY avg_ms DESC
                LIMIT 200
            ", [config('index_advisor.min_executions', 3)]);
        } catch (\Throwable $e) {
            $this->warn("  Performance Schema not available: {$e->getMessage()}");

            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            $fingerprint = $this->fingerprinter->fingerprint($row->sql_digest);
            $sqlSample = Str::limit($row->sql_digest, 2000);
            $avgMs = (float) $row->avg_ms;
            $calls = (int) $row->exec_count;
            $totalMs = $avgMs * $calls;

            DB::table('index_advisor_query_stats')->upsert(
                [
                    'fingerprint' => $fingerprint,
                    'sql_sample' => $sqlSample,
                    'avg_duration_ms' => $avgMs,
                    'source' => 'perf_schema',
                    'db_driver' => 'mysql',
                    'recorded_at' => now(),
                ],
                ['fingerprint'],
                ['avg_duration_ms', 'recorded_at']
            );

            DB::table('index_advisor_queries')->upsert(
                [
                    'fingerprint' => $fingerprint,
                    'sql_sample' => $sqlSample,
                    'execution_count' => $calls,
                    'total_duration_ms' => $totalMs,
                    'max_duration_ms' => $avgMs,
                    'last_seen_at' => now(),
                ],
                ['fingerprint'],
                ['execution_count', 'total_duration_ms', 'max_duration_ms', 'last_seen_at']
            );
            $count++;
        }

        $this->line("  Performance Schema: {$count} digest entries merged.");

        return $count;
    }

    // ─── PostgreSQL ───────────────────────────────────────────────────────────

    private function ingestPgsql(): int
    {
        $count = 0;
        $count += $this->ingestPgStatStatements();
        $count += $this->ingestPgStatUserTables();

        if (! $this->option('skip-unused-indexes')) {
            $count += $this->ingestPgStatUserIndexes();
        } else {
            $this->line('  pg_stat_user_indexes: skipped (--skip-unused-indexes). Imported CSV DROP data is preserved.');
        }

        return $count;
    }

    private function ingestPgStatStatements(): int
    {
        if (! $this->hasPgStatStatements()) {
            $this->warn('  pg_stat_statements not found. Enable it with:');
            $this->warn('  CREATE EXTENSION IF NOT EXISTS pg_stat_statements;');
            $this->warn("  Then add 'pg_stat_statements' to shared_preload_libraries in postgresql.conf.");

            return 0;
        }

        try {
            $rows = DB::select("
                SELECT
                    query,
                    calls,
                    ROUND((total_exec_time / NULLIF(calls, 0))::numeric, 2) AS avg_ms,
                    total_exec_time
                FROM pg_stat_statements
                WHERE query NOT ILIKE '%pg_stat%'
                  AND query NOT ILIKE '%index_advisor%'
                  AND query NOT ILIKE '%telescope%'
                  AND query NOT ILIKE '%sessions%'
                  AND query NOT ILIKE '%jobs%'
                  AND calls >= ?
                ORDER BY avg_ms DESC
                LIMIT 200
            ", [config('index_advisor.min_executions', 3)]);
        } catch (\Throwable $e) {
            $this->warn("  Error querying pg_stat_statements: {$e->getMessage()}");

            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            $fingerprint = $this->fingerprinter->fingerprint($row->query);
            $sqlSample = Str::limit($row->query, 2000);
            $avgMs = (float) $row->avg_ms;
            $totalMs = (float) $row->total_exec_time;
            $calls = (int) $row->calls;

            // Write to index_advisor_query_stats (for scoring correlation)
            DB::statement(
                'INSERT INTO index_advisor_query_stats
                    (fingerprint, sql_sample, avg_duration_ms, source, db_driver, recorded_at)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT (fingerprint) DO UPDATE SET
                    avg_duration_ms = EXCLUDED.avg_duration_ms,
                    recorded_at     = EXCLUDED.recorded_at',
                [$fingerprint, $sqlSample, $avgMs, 'pg_stat_statements', 'pgsql', now()]
            );

            // ALSO write to index_advisor_queries so RunExplainCommand and
            // ScoringService can join against it (DB::listen only fires on HTTP,
            // not CLI — this bridges the gap for the pipeline run).
            DB::statement(
                'INSERT INTO index_advisor_queries
                    (fingerprint, sql_sample, execution_count, total_duration_ms, max_duration_ms, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT (fingerprint) DO UPDATE SET
                    execution_count   = EXCLUDED.execution_count,
                    total_duration_ms = EXCLUDED.total_duration_ms,
                    last_seen_at      = EXCLUDED.last_seen_at',
                [$fingerprint, $sqlSample, $calls, $totalMs, $avgMs, now()]
            );

            $count++;
        }

        $this->line("  pg_stat_statements: {$count} entries ingested (query_stats + queries tables).");

        return $count;
    }

    /**
     * Ingest per-table sequential scan stats from pg_stat_user_tables.
     * Tables with high seq_scan counts are strong candidates for new indexes.
     * Stored as synthetic "table_seq_scan::<table>" fingerprints.
     */
    private function ingestPgStatUserTables(): int
    {
        $schema = config('index_advisor.pg_schema', 'public');

        try {
            $rows = DB::select('
                SELECT
                    relname       AS table_name,
                    seq_scan,
                    seq_tup_read,
                    n_live_tup
                FROM pg_stat_user_tables
                WHERE schemaname = ?
                  AND seq_scan   > 0
                ORDER BY seq_scan DESC
                LIMIT 100
            ', [$schema]);
        } catch (\Throwable $e) {
            $this->warn("  pg_stat_user_tables query failed: {$e->getMessage()}");

            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            // Estimate avg ms per scan: assume 1ms per 1000 tuples read (rough heuristic)
            $avgMs = $row->seq_scan > 0
                ? round(($row->seq_tup_read / max($row->seq_scan, 1)) / 1000, 2)
                : 0;
            $fingerprint = md5("table_seq_scan::{$row->table_name}");

            DB::table('index_advisor_query_stats')->upsert(
                [
                    'fingerprint' => $fingerprint,
                    'sql_sample' => "-- Sequential scan on {$row->table_name}: {$row->seq_scan} scans, {$row->seq_tup_read} rows read, {$row->n_live_tup} live tuples",
                    'avg_duration_ms' => $avgMs,
                    'source' => 'pg_stat_user_tables',
                    'db_driver' => 'pgsql',
                    'recorded_at' => now(),
                ],
                ['fingerprint'],
                ['avg_duration_ms', 'sql_sample', 'recorded_at']
            );
            $count++;
        }

        $this->line("  pg_stat_user_tables: {$count} table scan records ingested.");

        return $count;
    }

    /**
     * Ingest unused index data from pg_stat_user_indexes (idx_scan = 0).
     * These are stored as DROP candidates in index_advisor_recommendations.
     */
    private function ingestPgStatUserIndexes(): int
    {
        $schema = config('index_advisor.pg_schema', 'public');

        try {
            $rows = DB::select('
                SELECT
                    ui.relname  AS index_name,
                    t.relname   AS table_name,
                    a.attname   AS column_name,
                    s.idx_scan
                FROM pg_stat_user_indexes s
                JOIN pg_class ui         ON ui.oid = s.indexrelid
                JOIN pg_class t          ON t.oid  = s.relid
                JOIN pg_index ix         ON ix.indexrelid = s.indexrelid
                JOIN pg_attribute a      ON a.attrelid = t.oid
                                        AND a.attnum = ANY(ix.indkey)
                WHERE s.schemaname = ?
                  AND s.idx_scan   = 0
                  AND NOT ix.indisprimary
                  AND NOT ix.indisunique
                ORDER BY t.relname
            ', [$schema]);
        } catch (\Throwable $e) {
            $this->warn("  pg_stat_user_indexes query failed: {$e->getMessage()}");

            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            // Upsert as DROP recommendation with score 70 (high — unused indexes waste write overhead)
            DB::table('index_advisor_recommendations')->upsert(
                [
                    'table_name' => $row->table_name,
                    'column_name' => $row->column_name,
                    'index_type' => 'DROP',
                    'score' => 70,
                    'evidence' => json_encode([
                        'type' => 'DROP',
                        'index_name' => $row->index_name,
                        'table_name' => $row->table_name,
                        'column' => $row->column_name,
                        'idx_scan' => 0,
                        'verdict' => 'HIGH — Unused index, consider dropping to reduce write overhead',
                    ]),
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                ['table_name', 'column_name', 'index_type'],
                ['score', 'evidence', 'updated_at']
            );
            $count++;
        }

        $this->line("  pg_stat_user_indexes: {$count} unused index records stored as DROP candidates.");

        return $count;
    }

    // ─── MS SQL Server ────────────────────────────────────────────────────────

    private function ingestMssql(): int
    {
        try {
            $rows = DB::select('
                SELECT TOP 200
                    SUBSTRING(st.text, (qs.statement_start_offset / 2) + 1,
                        ((CASE qs.statement_end_offset WHEN -1 THEN DATALENGTH(st.text)
                          ELSE qs.statement_end_offset END - qs.statement_start_offset) / 2) + 1
                    )                                                     AS query_text,
                    qs.execution_count,
                    ROUND(qs.total_elapsed_time / NULLIF(qs.execution_count, 0) / 1000.0, 2) AS avg_ms
                FROM sys.dm_exec_query_stats qs
                CROSS APPLY sys.dm_exec_sql_text(qs.sql_handle) st
                WHERE qs.execution_count >= ?
                ORDER BY avg_ms DESC
            ', [config('index_advisor.min_executions', 3)]);
        } catch (\Throwable $e) {
            $this->warn("  DMV query failed: {$e->getMessage()}");

            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            $fingerprint = $this->fingerprinter->fingerprint($row->query_text);
            DB::table('index_advisor_query_stats')->upsert(
                [
                    'fingerprint' => $fingerprint,
                    'sql_sample' => Str::limit($row->query_text, 2000),
                    'avg_duration_ms' => (float) $row->avg_ms,
                    'source' => 'dmv',
                    'db_driver' => 'sqlsrv',
                    'recorded_at' => now(),
                ],
                ['fingerprint'],
                ['avg_duration_ms', 'recorded_at']
            );
            $count++;
        }

        $this->line("  DMV: {$count} query stats ingested.");

        return $count;
    }

    // ─── SQLite ───────────────────────────────────────────────────────────────

    /**
     * SQLite has no pg_stat_statements or idx_scan counters.
     * Bridge runtime index_advisor_queries into query_stats for scoring.
     */
    private function ingestSqlite(): int
    {
        $this->line('  sqlite: syncing runtime query log to query_stats (no server-level stats).');

        $limit = (int) config('index_advisor.scoring.correlation_query_limit', 2000);
        $minExec = (int) config('index_advisor.min_executions', 1);

        $queries = DB::table('index_advisor_queries')
            ->where('execution_count', '>=', $minExec)
            ->orderByDesc('execution_count')
            ->limit($limit)
            ->get();

        if ($queries->isEmpty()) {
            $this->warn('  sqlite: index_advisor_queries is empty — enable INDEX_ADVISOR_ENABLED and run HTTP requests first.');

            return 0;
        }

        $count = 0;

        foreach ($queries as $query) {
            $avgMs = $query->execution_count > 0
                ? $query->total_duration_ms / $query->execution_count
                : 0;

            DB::table('index_advisor_query_stats')->upsert(
                [
                    'fingerprint' => $query->fingerprint,
                    'sql_sample' => $query->sql_sample,
                    'avg_duration_ms' => $avgMs,
                    'source' => 'runtime_queries',
                    'db_driver' => 'sqlite',
                    'recorded_at' => now(),
                ],
                ['fingerprint'],
                ['avg_duration_ms', 'recorded_at', 'sql_sample']
            );
            $count++;
        }

        $this->line("  sqlite: {$count} runtime queries synced to query_stats.");

        return $count;
    }

    private function hasPgStatStatements(): bool
    {
        try {
            DB::selectOne('SELECT 1 FROM pg_stat_statements LIMIT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
