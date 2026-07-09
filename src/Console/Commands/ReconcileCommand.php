<?php

namespace Kombee\IndexAdvisor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kombee\IndexAdvisor\Contracts\ColumnSchemaSyncer;
use Kombee\IndexAdvisor\Contracts\SchemaIntrospectorContract;
use Kombee\IndexAdvisor\Services\RecommendationReconciler;

/**
 * Immediately re-checks the live database schema against all pending/generated
 * recommendations and dismisses any that are stale (e.g. you dropped an index,
 * or an index was added externally).
 *
 * Use this whenever you manually change indexes without running the full pipeline.
 *
 * Usage:
 *   php artisan index-advisor:reconcile
 *   php artisan index-advisor:reconcile --table=lytLoginUsr
 *   php artisan index-advisor:reconcile --table=lytLoginUsr --column=userLyId
 */
class ReconcileCommand extends Command
{
    protected $signature = 'index-advisor:reconcile
                            {--table=  : Reconcile only this table (case-insensitive)}
                            {--column= : Reconcile only this column (must be combined with --table)}';

    protected $description = 'Re-check live DB indexes and dismiss stale recommendations (run after manually dropping/adding indexes)';

    public function __construct(
        private SchemaIntrospectorContract $schema,
        private RecommendationReconciler $reconciler,
        private ColumnSchemaSyncer $columnSyncer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tableFilter = $this->option('table');
        $columnFilter = $this->option('column');

        // Always start with a fully fresh schema cache
        $this->schema->resetCache();

        $this->info('');
        $this->info('╔══════════════════════════════════════════════╗');
        $this->info('║   Index Advisor — Live Schema Reconcile      ║');
        $this->info('╚══════════════════════════════════════════════╝');
        $this->info('');

        if ($tableFilter) {
            $canonical = $this->schema->canonicalTableName($tableFilter);
            if ($canonical === null) {
                $this->error("Table '{$tableFilter}' not found in the live database.");

                return Command::FAILURE;
            }

            if ($columnFilter) {
                return $this->reconcileSingleColumn($canonical, $columnFilter);
            }

            return $this->reconcileTable($canonical);
        }

        return $this->reconcileAll();
    }

    // ─── Reconcile one column ─────────────────────────────────────────────────

    private function reconcileSingleColumn(string $table, string $column): int
    {
        $this->info("  Checking live indexes for: {$table}.{$column}");
        $this->info('');

        $indexed = $this->schema->isColumnIndexedFresh($table, $column);

        $this->line('  Index exists in live DB : '.($indexed ? '<info>YES</info>' : '<comment>NO</comment>'));
        $this->info('');

        // Show current recommendations for this column
        $recs = DB::table('index_advisor_recommendations')
            ->whereRaw('LOWER(table_name) = ?', [strtolower($table)])
            ->whereRaw('LOWER(column_name) = ?', [strtolower($column)])
            ->whereIn('status', ['pending', 'generated'])
            ->get(['id', 'index_type', 'score', 'status']);

        if ($recs->isEmpty()) {
            $this->info("  No active recommendations found for {$table}.{$column}.");
        } else {
            $this->table(
                ['ID', 'Type', 'Score', 'Status'],
                $recs->map(fn ($r) => [$r->id, $r->index_type, $r->score, $r->status])->toArray()
            );
        }

        // Run sync
        $synced = $this->columnSyncer->syncColumnWithLiveSchema($table, $column);

        $this->info('');
        if ($synced) {
            $this->info("  ✅  {$table}.{$column} re-synced successfully.");
        } else {
            $this->warn("  ⚠  {$table}.{$column} not re-synced (table too small, low cardinality, or active DROP exists).");
        }

        // Show updated recommendations
        $updated = DB::table('index_advisor_recommendations')
            ->whereRaw('LOWER(table_name) = ?', [strtolower($table)])
            ->whereRaw('LOWER(column_name) = ?', [strtolower($column)])
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get(['id', 'index_type', 'score', 'status', 'updated_at']);

        $this->info('');
        $this->info('  Current recommendations after reconciliation:');
        $this->table(
            ['ID', 'Type', 'Score', 'Status', 'Updated'],
            $updated->map(fn ($r) => [$r->id, $r->index_type, $r->score, $r->status, $r->updated_at])->toArray()
        );

        return Command::SUCCESS;
    }

    // ─── Reconcile all columns for a table ────────────────────────────────────

    private function reconcileTable(string $table): int
    {
        $this->info("  Reconciling all recommendations for table: {$table}");
        $this->info('');

        // Get all unique columns with active recommendations for this table
        $columns = DB::table('index_advisor_recommendations')
            ->whereRaw('LOWER(table_name) = ?', [strtolower($table)])
            ->whereIn('index_type', ['INDEX', 'REDUNDANT_CHECK', 'DROP'])
            ->whereIn('status', ['pending', 'generated'])
            ->where('column_name', 'not like', '%,%')
            ->distinct()
            ->pluck('column_name');

        if ($columns->isEmpty()) {
            $this->warn("  No active recommendations found for table '{$table}'.");

            return Command::SUCCESS;
        }

        $dismissed = 0;
        $synced = 0;

        foreach ($columns as $column) {
            $indexed = $this->schema->isColumnIndexedFresh($table, $column);
            $icon = $indexed ? '🔑' : '—';
            $this->line("  {$icon}  {$column} ".($indexed ? '(indexed)' : '(no index)'));
        }

        $this->info('');

        $result = $this->reconciler->reconcile();
        $dismissed = $result['dismissed'];
        $synced = $result['synced'];

        $this->info("  ✅  Dismissed: {$dismissed} stale recommendation(s)");
        $this->info("      Re-synced: {$synced} column(s) with correct INDEX / REDUNDANT_CHECK type");

        return Command::SUCCESS;
    }

    // ─── Reconcile all tables ─────────────────────────────────────────────────

    private function reconcileAll(): int
    {
        $this->info('  Checking all pending/generated recommendations against live schema...');
        $this->info('  (This clears the schema cache and re-reads indexes from the database.)');
        $this->info('');

        $before = DB::table('index_advisor_recommendations')
            ->whereIn('status', ['pending', 'generated'])
            ->count();

        $result = $this->reconciler->reconcile();
        $dismissed = $result['dismissed'];
        $synced = $result['synced'];

        $after = DB::table('index_advisor_recommendations')
            ->whereIn('status', ['pending', 'generated'])
            ->count();

        $this->info("  Before : {$before} active recommendation(s)");
        $this->info("  After  : {$after} active recommendation(s)");
        $this->info('');

        if ($dismissed > 0) {
            $this->info("  ✅  {$dismissed} stale recommendation(s) dismissed.");
            $this->line('     Reasons: dropped indexes, already-existing indexes, schema drift.');
        } else {
            $this->info('  ✅  No stale recommendations found. Schema is in sync.');
        }

        if ($synced > 0) {
            $this->info("  🔄  {$synced} column(s) re-synced (INDEX ↔ REDUNDANT_CHECK type corrected).");
        }

        $this->info('');
        $this->line('  Run `php artisan index-advisor:report` to see the updated recommendations.');

        return Command::SUCCESS;
    }
}
