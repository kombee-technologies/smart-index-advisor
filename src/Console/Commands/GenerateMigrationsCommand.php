<?php

namespace Kombee\IndexAdvisor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kombee\IndexAdvisor\Services\DDLGenerator;

/**
 * Generates Laravel migration files for every recommendation whose score
 * is >= the configured auto_migrate_score threshold.
 *
 * Handles three index types:
 *   INDEX      — single-column CREATE INDEX
 *   COMPOSITE  — multi-column CREATE INDEX (column_name is comma-separated)
 *   DROP       — DROP INDEX for unused indexes
 *
 * Usage:
 *   php artisan index-advisor:generate-migrations
 *   php artisan index-advisor:generate-migrations --score=60
 *   php artisan index-advisor:generate-migrations --dry-run
 *   php artisan index-advisor:generate-migrations --type=COMPOSITE
 *   php artisan index-advisor:generate-migrations --ids=1,2,3
 */
class GenerateMigrationsCommand extends Command
{
    protected $signature = 'index-advisor:generate-migrations
                            {--score=  : Override the minimum score threshold}
                            {--type=   : Filter by index type: INDEX, COMPOSITE, DROP}
                            {--dry-run : Print DDL without writing files}
                            {--ids=    : Comma-separated list of recommendation IDs to generate migrations for}';

    protected $description = 'Generate Laravel migration files for high-scored index recommendations';

    public function __construct(private DDLGenerator $ddl)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $driver = DB::getDriverName();
        $minScore = (int) ($this->option('score') ?? (
            $this->option('ids') ? 0 : config('index_advisor.auto_migrate_score', 80)
        ));
        $dryRun = $this->option('dry-run');
        $typeFilter = $this->option('type');

        $this->info(
            "Generating migrations for score >= {$minScore} (driver: {$driver})"
            . ($typeFilter ? " [type={$typeFilter}]" : '')
            . ($dryRun ? ' [DRY RUN]' : '')
            . "...\n"
        );

        $query = DB::table('index_advisor_recommendations')
            ->when($this->option('ids'), function ($q) {
                $ids = array_map('intval', explode(',', $this->option('ids')));
                $q->whereIn('id', $ids);
            })
            ->where('score', '>=', $minScore)
            ->where('status', 'pending')
            ->whereNotIn('table_name', (array) config('index_advisor.excluded_tables', []))
            ->whereNotIn('index_type', ['REDUNDANT_CHECK'])
            ->orderByDesc('score');

        if ($typeFilter) {
            $query->where('index_type', strtoupper($typeFilter));
        }

        $recs = $query->get();

        if ($recs->isEmpty()) {
            $this->warn('No pending recommendations meet the score threshold.');
            return Command::SUCCESS;
        }

        $created = 0;

        foreach ($recs as $rec) {
            [$upDDL, $downDDL, $migName] = $this->buildDDL($driver, $rec);
            $disableTx = $this->shouldDisableTransaction($driver, $upDDL);

            if ($dryRun) {
                $this->line("<info>[Score {$rec->score}] [{$rec->index_type}] {$rec->table_name} → {$rec->column_name}</info>");
                $this->line("  UP:   {$upDDL}");
                $this->line("  DOWN: {$downDDL}");
                $this->newLine();
                continue;
            }

            $timestamp = now()->addSeconds($created)->format('Y_m_d_His');
            $className = Str::studly($migName);
            $path = database_path("migrations/{$timestamp}_{$migName}.php");

            file_put_contents($path, $this->stub($className, $upDDL, $downDDL, $disableTx));
            // Mark recommendation as generated
            DB::table('index_advisor_recommendations')->where('id', $rec->id)->update([
                'status' => 'generated',
                'updated_at' => now(),
            ]);

            $this->line("  <info>✅ Created:</info> database/migrations/{$timestamp}_{$migName}.php  [score={$rec->score}]");
            $created++;
        }

        if (! $dryRun) {
            $this->newLine();
            $this->info("{$created} migration(s) generated. Run `php artisan migrate` to apply.");
        }

        return Command::SUCCESS;
    }

    private function shouldDisableTransaction(string $driver, string $upDDL): bool
    {
        return $driver === 'pgsql' && stripos($upDDL, 'concurrently') !== false;
    }

    /**
     * Build UP/DOWN DDL and a migration file name based on index type.
     */
    private function buildDDL(string $driver, object $rec): array
    {
        $evidence = json_decode($rec->evidence ?? '{}', true);

        return match ($rec->index_type) {
            'COMPOSITE' => $this->buildCompositeDDL($driver, $rec, $evidence),
            'DROP' => $this->buildDropDDL($driver, $rec, $evidence),
            default => $this->buildSingleDDL($driver, $rec),
        };
    }

    private function buildSingleDDL(string $driver, object $rec): array
    {
        $upDDL = $this->ddl->generateCreateIndex($driver, $rec->table_name, [$rec->column_name]);
        $downDDL = $this->ddl->generateDropIndex($driver, $rec->table_name, "idx_{$rec->table_name}_{$rec->column_name}");
        $name = "add_index_{$rec->table_name}_{$rec->column_name}";
        return [$upDDL, $downDDL, $name];
    }

    private function buildCompositeDDL(string $driver, object $rec, array $evidence): array
    {
        $columns = $evidence['columns'] ?? explode(',', $rec->column_name);
        $upDDL = $this->ddl->generateCreateIndex($driver, $rec->table_name, $columns);
        $colKey = implode('_', $columns);
        $downDDL = $this->ddl->generateDropIndex($driver, $rec->table_name, "idx_{$rec->table_name}_{$colKey}");
        $name = "add_composite_index_{$rec->table_name}_{$colKey}";
        return [$upDDL, $downDDL, $name];
    }

    private function buildDropDDL(string $driver, object $rec, array $evidence): array
    {
                $indexName = $evidence['constraint_name'] ?? $evidence['index_name'] ?? "idx_{$rec->table_name}_{$rec->column_name}";
        // UP = drop the unused index or constraint; DOWN = recreate it
                if ($driver === 'pgsql') {
            // PostgreSQL: drop constraint (which also drops the underlying index)
            $upDDL = $this->ddl->generateDropConstraint($driver, $rec->table_name, $indexName);
        } else {
            $upDDL = $this->ddl->generateDropIndex($driver, $rec->table_name, $indexName);
        }
        $downDDL = $this->ddl->generateCreateIndex($driver, $rec->table_name, [$rec->column_name]);
        $name = "drop_unused_index_{$rec->table_name}_{$rec->column_name}";

        return [$upDDL, $downDDL, $name];
    }

    /**
     * Generate the migration stub.
     * If $disableTx is true, the generated migration class will set
     * public $withinTransaction = false to avoid wrapping the statements
     * in a transaction (required for PostgreSQL CONCURRENTLY statements).
     */
    private function stub(string $className, string $up, string $down, bool $disableTx = false): string
    {
        $upExported = var_export($up, true);
        $downExported = var_export($down, true);
        $transactionProperty = $disableTx ? "public \$withinTransaction = false;\n    " : '';
        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Auto-generated by Smart Index Advisor.
 * Review before running in production.
 */
return new class extends Migration {
    {$transactionProperty}public function up(): void
    {
        \DB::unprepared({$upExported});
    }

    public function down(): void
    {
        \DB::unprepared({$downExported});
    }
};
PHP;
    }
}
