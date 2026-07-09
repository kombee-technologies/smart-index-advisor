<?php

namespace Kombee\IndexAdvisor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kombee\IndexAdvisor\Services\ScoringService;

/**
 * Master orchestrator — runs the full Smart Index Advisor pipeline in sequence.
 *
 * Pipeline steps:
 *   1. index-advisor:analyze-code      (static PHP scan — skipped with --skip-code-analysis)
 *   2. index-advisor:ingest-slow-log   (DB-level stats + pg_stat_user_tables/indexes — skipped with --skip-local-db)
 *   3. index-advisor:run-explain       (EXPLAIN top queries — skipped with --skip-explain)
 *   4. ScoringService::score()         (score single + composite candidates)
 *   5. index-advisor:report            (print summary table)
 *   6. index-advisor:generate-migrations (optional, skipped with --report-only)
 *
 * Usage:
 *   php artisan index-advisor:run
 *   php artisan index-advisor:run --report-only
 *   php artisan index-advisor:run --skip-explain
 *   php artisan index-advisor:run --skip-code-analysis              (production import mode)
 *   php artisan index-advisor:run --skip-code-analysis --skip-local-db  (pure CSV mode — uses ONLY imported data)
 *   php artisan index-advisor:run --skip-code-analysis --skip-local-db --skip-explain
 */
class RunAdvisorCommand extends Command
{
    protected $signature = 'index-advisor:run
                            {--report-only         : Run analysis and scoring only; skip migration generation}
                            {--skip-explain        : Skip the EXPLAIN step (faster for large databases)}
                            {--skip-code-analysis  : Skip local codebase scan (use when running in production-import mode with imported CSV stats)}
                            {--skip-local-db       : Skip ingesting from local pg_stat_* / slow log / performance_schema (use this when you have imported CSV data and do NOT want local DB stats to overwrite them)}';

    protected $description = 'Run the full Smart Index Advisor pipeline (analyze → ingest → explain → score → report → migrate)';

    public function __construct(private ScoringService $scorer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Prevent OOM: disable query logging and Telescope during analysis
        config(['index_advisor.enabled' => false, 'telescope.enabled' => false]);
        if (class_exists(\Laravel\Telescope\Telescope::class)) {
            \Laravel\Telescope\Telescope::stopRecording();
        }

        $this->info('');
        $this->info('╔══════════════════════════════════════════════╗');
        $this->info('║       Smart Index Advisor — Full Pipeline Run      ║');
        $this->info('╚══════════════════════════════════════════════╝');
        $this->info('');

        // Step 1: Static code analysis
        if (! $this->option('skip-code-analysis')) {
            $this->step('1/5', 'Static code analysis');
            $this->call('index-advisor:analyze-code');
        } else {
            $this->warn('');
            $this->warn('  [1/5] Code analysis skipped (--skip-code-analysis).');
            $this->warn('        Scoring will rely entirely on imported production stats.');
            $this->warn('        Tip: Ensure you have imported CSV files via index-advisor:import-stats first.');
        }

        // Step 2: DB-level stats ingestion
        if (! $this->option('skip-local-db')) {
            $this->step('2/5', 'Ingesting DB stats (slow log + pg_stat sources)');
            // When --skip-code-analysis is used (CSV import mode), also skip overwriting
            // pg_stat_user_indexes DROP data so imported CSV results are preserved.
            $ingestOptions = [];
            if ($this->option('skip-code-analysis')) {
                $ingestOptions['--skip-unused-indexes'] = true;
            }
            $this->call('index-advisor:ingest-slow-log', $ingestOptions);
        } else {
            $this->warn('');
            $this->warn('  [2/5] Local DB ingestion skipped (--skip-local-db).');
            $this->warn('        pg_stat_user_indexes, pg_stat_statements, and slow log will NOT be queried.');
            $this->warn('        Only imported CSV data will be used for DROP candidates and query stats.');
        }

        // Step 3: EXPLAIN top queries
        if (! $this->option('skip-explain')) {
            $this->step('3/5', 'Running EXPLAIN on slow queries');
            $this->call('index-advisor:run-explain');
        } else {
            $this->warn('  [3/5] EXPLAIN step skipped (--skip-explain)');
        }

        // Step 4: Score candidates (single + composite)
        $this->step('4/5', 'Scoring candidates (single-column + composite)');
        $result = $this->scorer->score();
        $this->line("  → {$result['saved']} recommendation(s) scored / updated.");
        if ($result['dismissed'] > 0) {
            $this->line("  → {$result['dismissed']} stale recommendation(s) dismissed.");
        }
        if (($result['synced'] ?? 0) > 0) {
            $this->line("  → {$result['synced']} column(s) re-synced with live schema (INDEX / REDUNDANT_CHECK).");
        }

        // Step 5: Report
        $this->step('5/5', 'Generating report');
        $this->call('index-advisor:report');

        // Step 6: Generate migration files --skipped
        $this->warn('Migration generation skipped.');

        $this->info('');
        $this->info('Pipeline complete.');
        $this->info('Tip: Run `php artisan index-advisor:drop-unused` to review unused index DROP candidates.');

        return Command::SUCCESS;
    }

    private function step(string $num, string $label): void
    {
        $this->info('');
        $this->info("  [{$num}] {$label}");
        $this->info('  '.str_repeat('─', 60));
    }
}
