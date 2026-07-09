<?php

namespace Kombee\IndexAdvisor\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Database Telemetry Collector — Gathers runtime performance metrics
 * directly from the database engine's internal instrumentation.
 *
 * Supported Sources:
 *   MySQL:   performance_schema.events_statements_summary_by_digest
 *            information_schema.statistics (for existing index usage)
 *   PostgreSQL: pg_stat_statements, pg_stat_user_tables, pg_stat_user_indexes
 *   SQL Server: sys.dm_exec_query_stats, sys.dm_db_missing_index_details,
 *               sys.dm_db_index_usage_stats
 *
 * Results are stored in `index_advisor_runtime_stats`.
 */
class DatabaseTelemetryCollector
{
    /**
     * Collect runtime telemetry from the current database engine.
     *
     * @return array{collected: int, driver: string, sources: array}
     */
    public function collect(): array
    {
        $driver  = DB::getDriverName();
        $sources = [];
        $total   = 0;

        match ($driver) {
            'mysql'  => $total += $this->collectMysql($sources),
            'pgsql'  => $total += $this->collectPgsql($sources),
            'sqlsrv' => $total += $this->collectMssql($sources),
            default  => null,
        };

        return [
            'collected' => $total,
            'driver'    => $driver,
            'sources'   => $sources,
        ];
    }

    /**
     * Get unused indexes (indexes that exist but are never or rarely used).
     *
     * @return \Illuminate\Support\Collection
     */
    public function getUnusedIndexes(): \Illuminate\Support\Collection
    {
        return match (DB::getDriverName()) {
            'mysql'  => $this->mysqlUnusedIndexes(),
            'pgsql'  => $this->pgsqlUnusedIndexes(),
            'sqlsrv' => $this->mssqlUnusedIndexes(),
            default  => collect(),
        };
    }

    /**
     * Get tables with high sequential scan rates (potential index candidates).
     *
     * @return \Illuminate\Support\Collection
     */
    public function getHighScanTables(): \Illuminate\Support\Collection
    {
        return match (DB::getDriverName()) {
            'pgsql'  => $this->pgsqlHighScanTables(),
            'mysql'  => $this->mysqlHighScanTables(),
            default  => collect(),
        };
    }

    // ─── MySQL ─────────────────────────────────────────────────────────────────

    private function collectMysql(array &$sources): int
    {
        $count = 0;

        // Source 1: Performance Schema digest stats
        try {
            $rows = DB::select("
                SELECT
                    DIGEST_TEXT                                              AS sql_digest,
                    COUNT_STAR                                               AS calls,
                    ROUND(SUM_TIMER_WAIT / 1000000000, 2)                   AS total_ms,
                    ROUND(AVG_TIMER_WAIT / 1000000000, 2)                   AS avg_ms,
                    SUM_ROWS_EXAMINED                                        AS rows_examined,
                    SUM_ROWS_SENT                                            AS rows_returned,
                    SUM_NO_INDEX_USED                                        AS no_index_used
                FROM performance_schema.events_statements_summary_by_digest
                WHERE SCHEMA_NAME = DATABASE()
                  AND DIGEST_TEXT IS NOT NULL
                  AND COUNT_STAR >= ?
                ORDER BY avg_ms DESC
                LIMIT 500
            ", [config('index_advisor.min_executions', 10)]);

            foreach ($rows as $row) {
                $fingerprint = md5($row->sql_digest);
                DB::table('index_advisor_runtime_stats')->upsert(
                    [
                        'fingerprint'       => $fingerprint,
                        'sql_sample'        => Str::limit($row->sql_digest, 2000),
                        'total_exec_time_ms' => (float) $row->total_ms,
                        'avg_exec_time_ms'  => (float) $row->avg_ms,
                        'calls'             => (int) $row->calls,
                        'rows_examined'     => (int) $row->rows_examined,
                        'rows_returned'     => (int) $row->rows_returned,
                        'shared_blks_hit'   => 0,
                        'shared_blks_read'  => 0,
                        'source'            => 'perf_schema',
                        'db_driver'         => 'mysql',
                        'collected_at'      => now(),
                    ],
                    ['fingerprint'],
                    ['total_exec_time_ms', 'avg_exec_time_ms', 'calls', 'rows_examined', 'rows_returned', 'collected_at']
                );
                $count++;
            }

            $sources[] = "perf_schema: {$count} digests";
        } catch (\Throwable $e) {
            $sources[] = "perf_schema: unavailable ({$e->getMessage()})";
        }

        return $count;
    }

    private function mysqlUnusedIndexes(): \Illuminate\Support\Collection
    {
        try {
            return collect(DB::select("
                SELECT
                    s.TABLE_NAME  AS table_name,
                    s.INDEX_NAME  AS index_name,
                    s.COLUMN_NAME AS column_name,
                    s.SEQ_IN_INDEX AS seq_in_index,
                    s.CARDINALITY AS cardinality
                FROM information_schema.STATISTICS s
                LEFT JOIN performance_schema.table_io_waits_summary_by_index_usage p
                    ON s.TABLE_SCHEMA = p.OBJECT_SCHEMA
                   AND s.TABLE_NAME   = p.OBJECT_NAME
                   AND s.INDEX_NAME   = p.INDEX_NAME
                WHERE s.TABLE_SCHEMA = DATABASE()
                  AND s.INDEX_NAME != 'PRIMARY'
                  AND (p.COUNT_STAR IS NULL OR p.COUNT_STAR = 0)
                ORDER BY s.TABLE_NAME, s.INDEX_NAME
            "));
        } catch (\Throwable) {
            return collect();
        }
    }

    private function mysqlHighScanTables(): \Illuminate\Support\Collection
    {
        try {
            return collect(DB::select("
                SELECT
                    OBJECT_NAME     AS table_name,
                    COUNT_READ      AS reads,
                    COUNT_FETCH     AS fetches,
                    SUM_TIMER_WAIT / 1000000000 AS total_wait_ms
                FROM performance_schema.table_io_waits_summary_by_table
                WHERE OBJECT_SCHEMA = DATABASE()
                  AND COUNT_READ > 1000
                ORDER BY total_wait_ms DESC
                LIMIT 50
            "));
        } catch (\Throwable) {
            return collect();
        }
    }

    // ─── PostgreSQL ───────────────────────────────────────────────────────────

    private function collectPgsql(array &$sources): int
    {
        $count = 0;

        // Source 1: pg_stat_statements
        if ($this->hasPgStatStatements()) {
            try {
                $rows = DB::select("
                    SELECT
                        query,
                        calls,
                        ROUND(total_exec_time::numeric, 2)                     AS total_ms,
                        ROUND((total_exec_time / NULLIF(calls, 0))::numeric, 2) AS avg_ms,
                        rows                                                     AS rows_returned,
                        shared_blks_hit,
                        shared_blks_read
                    FROM pg_stat_statements
                    WHERE query NOT ILIKE '%pg_stat%'
                      AND query NOT ILIKE '%index_advisor_%'
                      AND calls >= ?
                    ORDER BY avg_ms DESC
                    LIMIT 500
                ", [config('index_advisor.min_executions', 10)]);

                foreach ($rows as $row) {
                    $fingerprint = md5(preg_replace('/\d+/', '?', $row->query));
                    DB::table('index_advisor_runtime_stats')->upsert(
                        [
                            'fingerprint'        => $fingerprint,
                            'sql_sample'         => Str::limit($row->query, 2000),
                            'total_exec_time_ms' => (float) $row->total_ms,
                            'avg_exec_time_ms'   => (float) $row->avg_ms,
                            'calls'              => (int) $row->calls,
                            'rows_examined'      => 0,
                            'rows_returned'      => (int) $row->rows_returned,
                            'shared_blks_hit'    => (int) $row->shared_blks_hit,
                            'shared_blks_read'   => (int) $row->shared_blks_read,
                            'source'             => 'pg_stat',
                            'db_driver'          => 'pgsql',
                            'collected_at'       => now(),
                        ],
                        ['fingerprint'],
                        ['total_exec_time_ms', 'avg_exec_time_ms', 'calls', 'rows_returned',
                         'shared_blks_hit', 'shared_blks_read', 'collected_at']
                    );
                    $count++;
                }

                $sources[] = "pg_stat_statements: {$count} queries";
            } catch (\Throwable $e) {
                $sources[] = "pg_stat_statements: error ({$e->getMessage()})";
            }
        } else {
            $sources[] = "pg_stat_statements: not installed";
        }

        return $count;
    }

    private function pgsqlUnusedIndexes(): \Illuminate\Support\Collection
    {
        try {
            return collect(DB::select("
                SELECT
                    s.relname                                    AS table_name,
                    i.relname                                    AS index_name,
                    pg_size_pretty(pg_relation_size(i.oid))      AS index_size,
                    idx_scan                                      AS index_scans,
                    idx_tup_read                                  AS tuples_read,
                    idx_tup_fetch                                 AS tuples_fetched
                FROM pg_stat_user_indexes ui
                JOIN pg_class s ON s.oid = ui.relid
                JOIN pg_class i ON i.oid = ui.indexrelid
                JOIN pg_index ix ON ix.indexrelid = ui.indexrelid
                WHERE ui.idx_scan = 0
                  AND s.relname NOT LIKE 'index_advisor_%'
                  AND NOT ix.indisprimary
                  AND NOT ix.indisunique
                ORDER BY pg_relation_size(i.oid) DESC
                LIMIT 50
            "));
        } catch (\Throwable) {
            return collect();
        }
    }

    private function pgsqlHighScanTables(): \Illuminate\Support\Collection
    {
        try {
            return collect(DB::select("
                SELECT
                    relname                  AS table_name,
                    seq_scan                  AS sequential_scans,
                    idx_scan                  AS index_scans,
                    seq_tup_read              AS seq_rows_read,
                    idx_tup_fetch             AS idx_rows_fetched,
                    n_live_tup                AS live_rows,
                    CASE WHEN (seq_scan + idx_scan) > 0
                         THEN ROUND(100.0 * seq_scan / (seq_scan + idx_scan), 1)
                         ELSE 0 END           AS seq_scan_pct
                FROM pg_stat_user_tables
                WHERE seq_scan > 100
                  AND relname NOT LIKE 'index_advisor_%'
                ORDER BY seq_scan_pct DESC
                LIMIT 50
            "));
        } catch (\Throwable) {
            return collect();
        }
    }

    // ─── MS SQL Server ────────────────────────────────────────────────────────

    private function collectMssql(array &$sources): int
    {
        $count = 0;

        // Source 1: dm_exec_query_stats
        try {
            $rows = DB::select("
                SELECT TOP 500
                    SUBSTRING(st.text, (qs.statement_start_offset / 2) + 1,
                        ((CASE qs.statement_end_offset WHEN -1 THEN DATALENGTH(st.text)
                          ELSE qs.statement_end_offset END - qs.statement_start_offset) / 2) + 1
                    )                                                            AS query_text,
                    qs.execution_count                                            AS calls,
                    ROUND(qs.total_elapsed_time / 1000.0, 2)                     AS total_ms,
                    ROUND(qs.total_elapsed_time / NULLIF(qs.execution_count, 0) / 1000.0, 2) AS avg_ms,
                    qs.total_logical_reads                                        AS logical_reads,
                    qs.total_rows                                                 AS rows_returned
                FROM sys.dm_exec_query_stats qs
                CROSS APPLY sys.dm_exec_sql_text(qs.sql_handle) st
                WHERE qs.execution_count >= ?
                ORDER BY avg_ms DESC
            ", [config('index_advisor.min_executions', 10)]);

            foreach ($rows as $row) {
                $fingerprint = md5(preg_replace('/\d+/', '?', $row->query_text));
                DB::table('index_advisor_runtime_stats')->upsert(
                    [
                        'fingerprint'        => $fingerprint,
                        'sql_sample'         => Str::limit($row->query_text, 2000),
                        'total_exec_time_ms' => (float) $row->total_ms,
                        'avg_exec_time_ms'   => (float) $row->avg_ms,
                        'calls'              => (int) $row->calls,
                        'rows_examined'      => (int) ($row->logical_reads ?? 0),
                        'rows_returned'      => (int) ($row->rows_returned ?? 0),
                        'shared_blks_hit'    => 0,
                        'shared_blks_read'   => 0,
                        'source'             => 'dmv',
                        'db_driver'          => 'sqlsrv',
                        'collected_at'       => now(),
                    ],
                    ['fingerprint'],
                    ['total_exec_time_ms', 'avg_exec_time_ms', 'calls', 'rows_examined', 'rows_returned', 'collected_at']
                );
                $count++;
            }

            $sources[] = "dmv_query_stats: {$count} queries";
        } catch (\Throwable $e) {
            $sources[] = "dmv_query_stats: error ({$e->getMessage()})";
        }

        // Source 2: Missing index details
        try {
            $missing = DB::select("
                SELECT TOP 100
                    d.statement                   AS table_ref,
                    d.equality_columns,
                    d.inequality_columns,
                    d.included_columns,
                    gs.avg_total_user_cost         AS avg_cost,
                    gs.avg_user_impact             AS avg_impact,
                    gs.user_seeks + gs.user_scans  AS total_accesses
                FROM sys.dm_db_missing_index_details d
                JOIN sys.dm_db_missing_index_groups ig ON d.index_handle = ig.index_handle
                JOIN sys.dm_db_missing_index_group_stats gs ON ig.index_group_handle = gs.group_handle
                ORDER BY (gs.avg_total_user_cost * gs.avg_user_impact * (gs.user_seeks + gs.user_scans)) DESC
            ");

            $sources[] = "dmv_missing_indexes: " . count($missing) . " suggestions";
        } catch (\Throwable $e) {
            $sources[] = "dmv_missing_indexes: error ({$e->getMessage()})";
        }

        return $count;
    }

    private function mssqlUnusedIndexes(): \Illuminate\Support\Collection
    {
        try {
            return collect(DB::select("
                SELECT
                    OBJECT_NAME(i.object_id) AS table_name,
                    i.name                   AS index_name,
                    us.user_seeks,
                    us.user_scans,
                    us.user_lookups,
                    us.user_updates
                FROM sys.indexes i
                LEFT JOIN sys.dm_db_index_usage_stats us
                    ON i.object_id = us.object_id AND i.index_id = us.index_id
                WHERE OBJECTPROPERTY(i.object_id, 'IsUserTable') = 1
                  AND i.type_desc = 'NONCLUSTERED'
                  AND i.is_primary_key = 0
                  AND COALESCE(us.user_seeks, 0) + COALESCE(us.user_scans, 0) + COALESCE(us.user_lookups, 0) = 0
                ORDER BY us.user_updates DESC
            "));
        } catch (\Throwable) {
            return collect();
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function hasPgStatStatements(): bool
    {
        try {
            DB::selectOne("SELECT 1 FROM pg_stat_statements LIMIT 1");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
