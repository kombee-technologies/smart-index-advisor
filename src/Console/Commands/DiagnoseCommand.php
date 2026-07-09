<?php

namespace Kombee\IndexAdvisor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kombee\IndexAdvisor\Services\QueryColumnCorrelator;
use Kombee\IndexAdvisor\Contracts\SchemaIntrospectorContract;
use Kombee\IndexAdvisor\Services\SqlColumnMatcher;

/**
 * Diagnoses why COMPOSITE and REDUNDANT_CHECK recommendations are not appearing.
 *
 * Usage:
 *   php artisan index-advisor:diagnose
 *   php artisan index-advisor:diagnose --table=lym_lead_mstrs
 */
class DiagnoseCommand extends Command
{
    protected $signature = 'index-advisor:diagnose
                            {--table= : Focus diagnosis on a specific table}';

    protected $description = 'Diagnose why COMPOSITE / REDUNDANT_CHECK recommendations are missing';

    public function __construct(
        private SchemaIntrospectorContract $schema,
        private SqlColumnMatcher $matcher,
        private QueryColumnCorrelator $correlator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('');
        $this->info('╔══════════════════════════════════════════════╗');
        $this->info('║     Smart Index Advisor — Diagnosis Report         ║');
        $this->info('╚══════════════════════════════════════════════╝');
        $this->info('');

        $this->checkTableCounts();
        $this->checkIndexAdvisorColumns();
        $this->checkRedundantCheck();
        $this->checkComposite();

        return Command::SUCCESS;
    }

    // ─── Step 1: Table row counts ─────────────────────────────────────────────

    private function checkTableCounts(): void
    {
        $this->info('── Step 1: Table row counts ──────────────────────────────');

        $tables = [
            'index_advisor_columns',
            'index_advisor_queries',
            'index_advisor_query_stats',
            'index_advisor_explains',
            'index_advisor_recommendations',
        ];

        $rows = [];
        foreach ($tables as $t) {
            $count = DB::table($t)->count();
            $status = $count > 0 ? '<info>✓</info>' : '<comment>⚠ EMPTY</comment>';
            $rows[] = [$t, number_format($count), $count > 0 ? 'OK' : 'EMPTY'];
        }

        $this->table(['Table', 'Rows', 'Status'], $rows);

        $queriesCount = DB::table('index_advisor_queries')->count();
        $columnsCount = DB::table('index_advisor_columns')->count();

        if ($queriesCount === 0) {
            $this->warn('  ⚠  index_advisor_queries is EMPTY.');
            $this->warn('     COMPOSITE detection needs runtime SQL samples.');
            $this->warn('     Fix: Run HTTP requests through the app, then run:');
            $this->warn('          php artisan index-advisor:ingest-slow-log');
        }

        if ($columnsCount === 0) {
            $this->warn('  ⚠  index_advisor_columns is EMPTY.');
            $this->warn('     Fix: php artisan index-advisor:analyze-code');
        }

        $this->info('');
    }

    // ─── Step 2: Columns analysis ─────────────────────────────────────────────

    private function checkIndexAdvisorColumns(): void
    {
        $this->info('── Step 2: index_advisor_columns analysis ────────────────');

        $tableFilter = $this->option('table');

        $query = DB::table('index_advisor_columns')
            ->selectRaw('table_name, count(*) as col_count')
            ->groupBy('table_name')
            ->orderByDesc('col_count');

        if ($tableFilter) {
            $query->where('table_name', $tableFilter);
        }

        $tables = $query->limit(20)->get();

        if ($tables->isEmpty()) {
            $this->warn('  No columns found. Run: php artisan index-advisor:analyze-code');
            $this->info('');

            return;
        }

        $unknownCount = DB::table('index_advisor_columns')
            ->where('table_name', 'unknown')
            ->count();

        if ($unknownCount > 0) {
            $this->warn("  ⚠  {$unknownCount} rows have table_name='unknown' (table inference failed).");
            $this->warn("     Fix: Add entries to config('index_advisor.table_map')");
            $this->warn("     Example: 'LymLeadMstr' => 'lym_lead_mstrs'");
        }

        $rows = $tables->map(fn ($r) => [
            $r->table_name,
            $r->col_count,
            $r->table_name === 'unknown' ? '⚠ UNKNOWN' : ($this->schema->getAllTables() && in_array($r->table_name, $this->schema->getAllTables()) ? '✓ exists' : '✗ not in DB'),
        ])->toArray();

        $this->table(['Table Name', 'Column Count', 'In Live DB?'], $rows);
        $this->info('');
    }

    // ─── Step 3: REDUNDANT_CHECK diagnosis ───────────────────────────────────

    private function checkRedundantCheck(): void
    {
        $this->info('── Step 3: REDUNDANT_CHECK diagnosis ─────────────────────');
        $this->line('  REDUNDANT_CHECK appears when a column from index_advisor_columns');
        $this->line('  already has an index in the live database.');
        $this->info('');

        $tableFilter = $this->option('table');

        $query = DB::table('index_advisor_columns')
            ->where('table_name', '!=', 'unknown')
            ->select('table_name', 'column_name')
            ->distinct();

        if ($tableFilter) {
            $query->where('table_name', $tableFilter);
        }

        $columns = $query->limit(50)->get();

        if ($columns->isEmpty()) {
            $this->warn('  No columns to check. Run: php artisan index-advisor:analyze-code');
            $this->info('');

            return;
        }

        $indexed = [];
        $notIndexed = [];

        foreach ($columns as $col) {
            if (! $this->schema->hasTableAndColumn($col->table_name, $col->column_name)) {
                continue; // table/column doesn't exist in live DB
            }

            if ($this->schema->isColumnIndexed($col->table_name, $col->column_name)) {
                $names = collect($this->schema->getColumnIndexDetails($col->table_name, $col->column_name))
                    ->map(fn ($i) => $i['index_name'].($i['is_primary'] ? ' (PK)' : ''))
                    ->implode(', ');
                $indexed[] = [$col->table_name, $col->column_name, "✓ INDEXED → REDUNDANT_CHECK [{$names}]"];
            } else {
                $notIndexed[] = [$col->table_name, $col->column_name, '✗ not indexed → INDEX candidate'];
            }
        }

        $this->line('  Columns that ARE already indexed (will produce REDUNDANT_CHECK):');
        if (empty($indexed)) {
            $this->warn('  → None found. No REDUNDANT_CHECK will be generated.');
            $this->line('    This is normal if none of your scanned columns have existing indexes.');
        } else {
            $this->table(['Table', 'Column', 'Status'], array_slice($indexed, 0, 15));
        }

        $this->info('');
        $this->line('  Columns NOT indexed (will produce INDEX recommendations):');
        $this->line('  → '.count($notIndexed).' unindexed column(s) found — these will become INDEX recommendations.');
        $this->info('');
    }

    // ─── Step 4: COMPOSITE diagnosis ─────────────────────────────────────────

    private function checkComposite(): void
    {
        $this->info('── Step 4: COMPOSITE diagnosis ───────────────────────────');
        $this->line('  COMPOSITE appears when a runtime SQL sample has 2+ columns');
        $this->line('  from the same table in its WHERE/JOIN clause.');
        $this->info('');

        $queriesCount = DB::table('index_advisor_queries')->count();

        if ($queriesCount === 0) {
            $this->warn('  ⚠  index_advisor_queries is empty — cannot detect COMPOSITE.');
            $this->warn('     Run: php artisan index-advisor:ingest-slow-log');
            $this->info('');

            return;
        }

        $tableFilter = $this->option('table');

        // Check runtime SQL for multi-column WHERE patterns
        $queries = DB::table('index_advisor_queries')
            ->whereRaw('LOWER(sql_sample) LIKE ?', ['select %'])
            ->orderByDesc('execution_count')
            ->limit(200)
            ->get(['fingerprint', 'sql_sample', 'execution_count']);

        $multiColumnQueries = [];

        foreach ($queries as $query) {
            if (! preg_match('/\bwhere\b(.+?)(?:\border\s+by\b|\bgroup\s+by\b|\blimit\b|$)/is', $query->sql_sample, $whereMatch)) {
                continue;
            }

            // Count column predicates in WHERE clause
            preg_match_all(
                '/"([a-zA-Z_][a-zA-Z0-9_]*)"\s*(?:=|\blike\b|\bilike\b|\bin\s*\()/i',
                $whereMatch[1],
                $matches
            );

            $cols = collect($matches[1])
                ->map(fn ($c) => strtolower($c))
                ->reject(fn ($c) => in_array($c, ['id', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by', 'deleted_by'], true))
                ->unique()
                ->values();

            if ($cols->count() >= 2) {
                // Extract table name
                preg_match('/\bfrom\s+"?([a-zA-Z_][a-zA-Z0-9_]*)"?/i', $query->sql_sample, $tableMatch);
                $table = $tableMatch[1] ?? 'unknown';

                if ($tableFilter && $table !== $tableFilter) {
                    continue;
                }

                $multiColumnQueries[] = [
                    $table,
                    $cols->implode(', '),
                    number_format($query->execution_count),
                    mb_substr($query->sql_sample, 0, 80).'...',
                ];
            }
        }

        if (empty($multiColumnQueries)) {
            $this->warn('  ⚠  No runtime SQL samples found with 2+ columns in WHERE clause.');
            $this->warn('     COMPOSITE detection requires queries like:');
            $this->warn('       SELECT * FROM users WHERE mobile = ? AND status = ?');
            $this->info('');
            $this->line('  Possible reasons:');
            $this->line('  1. Your queries only filter on one column at a time');
            $this->line('  2. index_advisor_queries has no SELECT queries with WHERE clauses');
            $this->line('  3. The SQL uses positional params ($1, $2) — check samples below');
            $this->info('');

            // Show sample SQL from the table
            $samples = DB::table('index_advisor_queries')
                ->whereRaw('LOWER(sql_sample) LIKE ?', ['select %'])
                ->orderByDesc('execution_count')
                ->limit(5)
                ->pluck('sql_sample');

            if ($samples->isNotEmpty()) {
                $this->line('  Sample SQL in index_advisor_queries:');
                foreach ($samples as $i => $sql) {
                    $this->line('  '.($i + 1).'. '.mb_substr($sql, 0, 120));
                }
            }
        } else {
            $this->info('  ✓ Found '.count($multiColumnQueries).' query(ies) with multi-column WHERE clauses:');
            $this->table(['Table', 'Columns in WHERE', 'Exec Count', 'SQL (truncated)'], array_slice($multiColumnQueries, 0, 10));
            $this->info('');
            $this->line('  These SHOULD produce COMPOSITE recommendations after scoring.');
            $this->line('  If they are not appearing, check:');
            $this->line('  1. Are these columns in index_advisor_columns? (run analyze-code)');
            $this->line('  2. Does the table have >= '.config('index_advisor.scoring.min_table_rows', 1000).' rows? (min_table_rows config)');
            $this->line('  3. Does a composite index already exist on these columns?');
        }

        $this->info('');

        // Show current recommendations breakdown
        $this->info('── Current recommendations breakdown ─────────────────────');
        $breakdown = DB::table('index_advisor_recommendations')
            ->selectRaw('index_type, status, count(*) as cnt')
            ->groupBy('index_type', 'status')
            ->orderBy('index_type')
            ->orderBy('status')
            ->get();

        if ($breakdown->isEmpty()) {
            $this->warn('  No recommendations exist yet. Run: php artisan index-advisor:run --report-only');
        } else {
            $this->table(
                ['Type', 'Status', 'Count'],
                $breakdown->map(fn ($r) => [$r->index_type, $r->status, $r->cnt])->toArray()
            );
        }

        $this->info('');
        $this->info('── Summary & Next Steps ──────────────────────────────────');
        $this->line('  To get REDUNDANT_CHECK: Some of your scanned columns must already be indexed.');
        $this->line('  To get COMPOSITE:       Your app must run queries with 2+ column WHERE clauses.');
        $this->info('');
        $this->line('  Run the full pipeline after fixing data issues:');
        $this->line('    php artisan index-advisor:run --report-only');
    }
}
