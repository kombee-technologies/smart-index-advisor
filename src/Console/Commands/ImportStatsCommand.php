<?php

namespace Kombee\IndexAdvisor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kombee\IndexAdvisor\Services\QueryFingerprinter;
use Kombee\IndexAdvisor\Services\RecommendationReconciler;

/**
 * Imports query performance statistics exported from a production or UAT database
 * into the local Smart Index Advisor tables for offline analysis.
 *
 * Supports three types of production data export (auto-detected by CSV/JSON headers):
 *
 *   1. Sequential Scan data  (from pg_stat_user_tables)
 *      Required columns: table_name, seq_scan, seq_tup_read, n_live_tup
 *      → Stored in index_advisor_query_stats
 *
 *   2. Unused Index data (from pg_stat_user_indexes)
 *      Required columns: table_name, index_name, column_name
 *      → Stored as DROP candidates in index_advisor_recommendations
 *
 *   3. Slow Query Stats (from pg_stat_statements, MySQL perf_schema, or SQL Server DMV)
 *      Required columns: query / sql_query / sql_sample, calls / execution_count, avg_duration_ms / mean_exec_time
 *      → Stored in index_advisor_queries + index_advisor_query_stats
 *
 * Usage:
 *   php artisan index-advisor:import-stats seq_scans.csv
 *   php artisan index-advisor:import-stats unused_indexes.csv
 *   php artisan index-advisor:import-stats slow_queries.csv
 *   php artisan index-advisor:import-stats slow_queries.json
 */
class ImportStatsCommand extends Command
{
    protected $signature = 'index-advisor:import-stats
                            {file : Path to the JSON or CSV file exported from production/UAT database}';

    protected $description = 'Import production query performance statistics (seq scans, unused indexes, or slow queries) from a CSV or JSON file';

    public function __construct(
        private QueryFingerprinter $fingerprinter,
        private RecommendationReconciler $reconciler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = $this->argument('file');

        if (! file_exists($path)) {
            $this->error("File not found: {$path}");

            return Command::FAILURE;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'json') {
            $records = json_decode(file_get_contents($path), true);
            if (! is_array($records)) {
                $this->error('Invalid JSON structure. The file must be an array of objects.');

                return Command::FAILURE;
            }
        } elseif ($extension === 'csv' || $extension === 'txt') {
            // Support .txt files that contain CSV data
            $records = $this->parseCsv($path);
        } else {
            $this->error("Unsupported file format: .{$extension}. Supported formats: .json, .csv, .txt");

            return Command::FAILURE;
        }

        if (empty($records)) {
            $this->warn('The imported file has no records.');

            return Command::SUCCESS;
        }

        $type = $this->detectMetricType($records[0]);

        $this->info('');
        $this->info('╔══════════════════════════════════════════════╗');
        $this->info('║    Smart Index Advisor — Import Stats               ║');
        $this->info('╚══════════════════════════════════════════════╝');
        $this->info('');
        $this->info("📂 File    : {$path}");
        $this->info('📊 Type    : '.ucwords(str_replace('-', ' ', $type)));
        $this->info('📋 Records : '.count($records));
        $this->info('');

        $imported = match ($type) {
            'unused-indexes' => $this->importUnusedIndexes($records),
            'seq-scans'      => $this->importSeqScans($records),
            default          => $this->importQueryStats($records),
        };

        $this->info('');
        $this->info("✅ Successfully imported {$imported} record(s) into local database.");

        // Run reconciliation to dismiss any stale or conflicting recommendations
        if (config('index_advisor.reconciliation.enabled', true)) {
            $reconcile = $this->reconciler->reconcile();
            if ($reconcile['dismissed'] > 0) {
                $this->line("  Reconciliation: {$reconcile['dismissed']} stale recommendation(s) dismissed.");
            }
            if ($reconcile['synced'] > 0) {
                $this->line("  Reconciliation: {$reconcile['synced']} column(s) re-synced with live schema.");
            }
        }

        $this->info('');
        $this->line('  ▶ Next steps:');
        $this->line('     php artisan index-advisor:analyze-code');
        $this->line('     php artisan index-advisor:run --report-only');
        $this->info('');

        return Command::SUCCESS;
    }

    // ─── Type Detection ────────────────────────────────────────────────────────

    /**
     * Detect which type of production export this file contains
     * by inspecting the column headers of the first record.
     */
    private function detectMetricType(array $row): string
    {
        $keys = array_map('strtolower', array_keys($row));

        // pg_stat_user_indexes export: has index_name + table_name but no query column
        if ((in_array('index_name', $keys, true) || in_array('unused_index_name', $keys, true)) && in_array('table_name', $keys, true)
            && ! in_array('query', $keys, true)
            && ! in_array('sql_query', $keys, true)) {
            return 'unused-indexes';
        }

        // pg_stat_user_tables export: has seq_scan column
        if (in_array('seq_scan', $keys, true) || in_array('sequential_scans', $keys, true)) {
            return 'seq-scans';
        }

        // pg_stat_statements / MySQL perf_schema / SQL Server DMV export
        return 'queries';
    }

    // ─── Unused Indexes (Query 2) ──────────────────────────────────────────────

    /**
     * Import pg_stat_user_indexes export as DROP recommendations.
     *
     * Required columns: table_name, index_name, column_name
     * Optional columns: index_scans, index_size
     */
    private function importUnusedIndexes(array $records): int
    {
        $count = 0;

        foreach ($records as $row) {
            $table     = $this->findValue($row, ['table_name', 'relname']);
            $indexName = $this->findValue($row, ['index_name', 'indexrelname', 'unused_index_name']);
            $column    = $this->findValue($row, ['column_name', 'primary_column_name']);
            $idxScan   = (int) $this->findValue($row, ['index_scans', 'idx_scan'], 0);
            $indexSize = $this->findValue($row, ['index_size'], 'unknown');

            if (empty($table) || empty($indexName)) {
                continue;
            }

            // If column_name is not in the export, fall back to the index name
            if (empty($column)) {
                $column = $indexName;
            }

            DB::table('index_advisor_recommendations')->upsert(
                [
                    'table_name'  => $table,
                    'column_name' => $column,
                    'index_type'  => 'DROP',
                    'score'       => 70,
                    'evidence'    => json_encode([
                        'type'       => 'DROP',
                        'index_name' => $indexName,
                        'table_name' => $table,
                        'column'     => $column,
                        'idx_scan'   => $idxScan,
                        'index_size' => $indexSize,
                        'source'     => 'production_import',
                        'verdict'    => 'HIGH — Unused index detected in production. Consider dropping to reduce write overhead.',
                    ]),
                    'status'     => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                ['table_name', 'column_name', 'index_type'],
                ['score', 'evidence', 'updated_at']
            );

            $count++;
        }

        $this->line("  Unused indexes: {$count} DROP recommendation(s) stored.");

        return $count;
    }

    // ─── Sequential Scans (Query 1) ───────────────────────────────────────────

    /**
     * Import pg_stat_user_tables sequential scan export.
     *
     * Required columns: table_name, seq_scan
     * Optional columns: seq_tup_read, n_live_tup
     */
    private function importSeqScans(array $records): int
    {
        $count  = 0;
        $driver = DB::getDriverName();

        foreach ($records as $row) {
            $table   = $this->findValue($row, ['table_name', 'relname']);
            $seqScan = (int) $this->findValue($row, ['seq_scan', 'sequential_scans'], 0);
            $seqRead = (int) $this->findValue($row, ['seq_tup_read', 'rows_read_via_seq_scan'], 0);
            $liveTup = (int) $this->findValue($row, ['n_live_tup', 'estimated_table_rows'], 0);

            if (empty($table) || $seqScan === 0) {
                continue;
            }

            // Heuristic: estimate avg scan latency based on rows read per scan
            $avgMs       = $seqScan > 0 ? round(($seqRead / max($seqScan, 1)) / 1000, 2) : 0;
            $fingerprint = md5("table_seq_scan::{$table}");

            DB::table('index_advisor_query_stats')->upsert(
                [
                    'fingerprint'    => $fingerprint,
                    'sql_sample'     => "-- Sequential scan on {$table}: {$seqScan} scans, {$seqRead} rows read, {$liveTup} live tuples (production_import)",
                    'avg_duration_ms' => $avgMs,
                    'source'         => 'pg_stat_user_tables',
                    'db_driver'      => $driver,
                    'recorded_at'    => now(),
                ],
                ['fingerprint'],
                ['avg_duration_ms', 'sql_sample', 'recorded_at']
            );

            $count++;
        }

        $this->line("  Sequential scans: {$count} table record(s) ingested into query_stats.");

        return $count;
    }

    // ─── Query Performance Stats (Query 6) ────────────────────────────────────

    /**
     * Import pg_stat_statements, MySQL performance_schema, or SQL Server DMV export.
     *
     * Required columns: query / sql_query / sql_sample / query_text / digest_text
     * Optional columns: calls / execution_count, avg_duration_ms / mean_exec_time, total_exec_time
     */
    private function importQueryStats(array $records): int
    {
        $count  = 0;
        $driver = DB::getDriverName();

        foreach ($records as $row) {
            $sql = $this->findValue($row, [
                'query', 'sql_query', 'sql_sample', 'query_text',
                'query_digest', 'digest_text', 'normalized_query',
            ]);

            if (empty($sql)) {
                continue;
            }

            $calls   = (int) $this->findValue($row, ['calls', 'execution_count', 'exec_count', 'count_star'], 1);
            $avgMs   = (float) $this->findValue($row, ['avg_duration_ms', 'avg_ms', 'mean_exec_time', 'avg_latency'], 0.0);
            $totalMs = (float) $this->findValue($row, ['total_duration_ms', 'total_exec_time', 'total_latency'], $calls * $avgMs);

            $fingerprint = $this->fingerprinter->fingerprint($sql);
            $sqlSample   = Str::limit($sql, 2000);

            // Insert into index_advisor_query_stats (for scoring correlation)
            // Insert into index_advisor_queries (for RunExplainCommand and ScoringService fingerprint joins)
            if ($driver === 'pgsql') {
                DB::statement(
                    'INSERT INTO index_advisor_query_stats
                        (fingerprint, sql_sample, avg_duration_ms, source, db_driver, recorded_at)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON CONFLICT (fingerprint) DO UPDATE SET
                        avg_duration_ms = EXCLUDED.avg_duration_ms,
                        recorded_at     = EXCLUDED.recorded_at',
                    [$fingerprint, $sqlSample, $avgMs, 'production_import', 'pgsql', now()]
                );

                DB::statement(
                    'INSERT INTO index_advisor_queries
                        (fingerprint, sql_sample, execution_count, total_duration_ms, max_duration_ms, last_seen_at)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON CONFLICT (fingerprint) DO UPDATE SET
                        execution_count   = EXCLUDED.execution_count,
                        total_duration_ms = EXCLUDED.total_duration_ms,
                        max_duration_ms   = GREATEST(index_advisor_queries.max_duration_ms, EXCLUDED.max_duration_ms),
                        last_seen_at      = EXCLUDED.last_seen_at',
                    [$fingerprint, $sqlSample, $calls, $totalMs, $avgMs, now()]
                );
            } else {
                DB::table('index_advisor_query_stats')->upsert(
                    [
                        'fingerprint'    => $fingerprint,
                        'sql_sample'     => $sqlSample,
                        'avg_duration_ms' => $avgMs,
                        'source'         => 'production_import',
                        'db_driver'      => $driver,
                        'recorded_at'    => now(),
                    ],
                    ['fingerprint'],
                    ['avg_duration_ms', 'recorded_at']
                );

                DB::table('index_advisor_queries')->upsert(
                    [
                        'fingerprint'      => $fingerprint,
                        'sql_sample'       => $sqlSample,
                        'execution_count'  => $calls,
                        'total_duration_ms' => $totalMs,
                        'max_duration_ms'  => $avgMs,
                        'last_seen_at'     => now(),
                    ],
                    ['fingerprint'],
                    ['execution_count', 'total_duration_ms', 'max_duration_ms', 'last_seen_at']
                );
            }

            $count++;
        }

        $this->line("  Query stats: {$count} record(s) ingested into queries + query_stats tables.");

        return $count;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Parse a CSV file into an array of associative arrays using the first row as headers.
     *
     * @return array<int, array<string, string>>
     */
    private function parseCsv(string $path): array
    {
        $records = [];

        if (($handle = fopen($path, 'r')) !== false) {
            $headers = fgetcsv($handle);

            if ($headers === false) {
                fclose($handle);

                return [];
            }

            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) === count($headers)) {
                    $records[] = array_combine($headers, $row);
                }
            }

            fclose($handle);
        }

        return $records;
    }

    /**
     * Search for a value in an array using multiple possible key names.
     * Performs a case-insensitive match to support different column naming conventions
     * across database engines (e.g. 'avg_ms' vs 'AVG_MS' vs 'avg_duration_ms').
     */
    private function findValue(array $row, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            // Exact match
            if (isset($row[$key])) {
                return $row[$key];
            }

            // Case-insensitive match
            foreach ($row as $k => $v) {
                if (strcasecmp($k, $key) === 0) {
                    return $v;
                }
            }
        }

        return $default;
    }
}
