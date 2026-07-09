<?php

namespace Kombee\IndexAdvisor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kombee\IndexAdvisor\Services\ExplainPlanRunner;

/**
 * Runs EXPLAIN against the top N slowest / most-frequent queries stored in
 * `index_advisor_queries` and persists the execution plan into
 * `index_advisor_explains`, flagging full table scans.
 *
 * Stored SQL is validated as read-only SELECT before EXPLAIN. Queries that fail
 * validation are skipped rather than interpolated into DB::select().
 *
 * Usage:
 *   php artisan index-advisor:run-explain
 *   php artisan index-advisor:run-explain --limit=100
 */
class RunExplainCommand extends Command
{
    protected $signature = 'index-advisor:run-explain {--limit=50 : Number of queries to EXPLAIN}';

    protected $description = 'Run EXPLAIN on top queries and detect full table scans';

    public function __construct(private ExplainPlanRunner $explainRunner)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $driver = DB::getDriverName();
        $limit = (int) $this->option('limit');
        $minExec = (int) config('index_advisor.min_executions', 3);
        $verbose = $this->option('verbose');

        $queries = DB::table('index_advisor_queries')
            ->where('execution_count', '>=', $minExec)
            ->orderByDesc('total_duration_ms')
            ->limit($limit)
            ->get();

        if ($queries->isEmpty()) {
            $this->warn("No qualifying queries found (need >= {$minExec} executions).");

            return Command::SUCCESS;
        }

        $this->info("Running EXPLAIN on {$queries->count()} queries (driver: {$driver})...");
        $bar = $this->output->createProgressBar($queries->count());
        $bar->start();

        $ok = 0;
        $err = 0;
        $skipReasons = [];

        foreach ($queries as $q) {
            try {
                $result = $this->explainRunner->run($driver, $q->sql_sample);

                if ($result === null) {
                    $reason = $this->explainRunner->getLastSkipReason() ?? 'Unknown';
                    $skipReasons[$reason] = ($skipReasons[$reason] ?? 0) + 1;

                    if ($verbose) {
                        $bar->clear();
                        $this->line("  <fg=yellow>SKIP</> [{$q->fingerprint}] {$reason}");
                        $this->line('    SQL: '.mb_substr($q->sql_sample, 0, 120));
                        $bar->display();
                    }

                    $err++;

                    continue;
                }

                [$hasFullScan, $plan] = $result;
                $planJson = json_encode($plan);

                if ($driver === 'pgsql') {
                    DB::statement(
                        'INSERT INTO index_advisor_explains
                            (fingerprint, driver, raw_plan, has_full_scan, analyzed_at)
                         VALUES (?, ?, ?, ?, ?)
                         ON CONFLICT (fingerprint) DO UPDATE SET
                            driver        = EXCLUDED.driver,
                            raw_plan      = EXCLUDED.raw_plan,
                            has_full_scan = EXCLUDED.has_full_scan,
                            analyzed_at   = EXCLUDED.analyzed_at',
                        [$q->fingerprint, $driver, $planJson, $hasFullScan ? 1 : 0, now()]
                    );
                } else {
                    DB::table('index_advisor_explains')->upsert(
                        [
                            'fingerprint' => $q->fingerprint,
                            'driver' => $driver,
                            'raw_plan' => $planJson,
                            'has_full_scan' => $hasFullScan ? 1 : 0,
                            'analyzed_at' => now(),
                        ],
                        ['fingerprint'],
                        ['driver', 'raw_plan', 'has_full_scan', 'analyzed_at']
                    );
                }

                $ok++;
            } catch (\Throwable $e) {
                $reason = 'Exception: '.$e->getMessage();
                $skipReasons[$reason] = ($skipReasons[$reason] ?? 0) + 1;

                if ($verbose) {
                    $bar->clear();
                    $this->line("  <fg=red>ERR</>  [{$q->fingerprint}] {$reason}");
                    $this->line('    SQL: '.mb_substr($q->sql_sample, 0, 120));
                    $bar->display();
                }

                logger()->debug("IndexAdvisor EXPLAIN failed [{$q->fingerprint}]: ".$e->getMessage());
                $err++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("EXPLAIN done — {$ok} succeeded, {$err} skipped.");

        if (! empty($skipReasons)) {
            $this->newLine();
            $this->warn('Skip reasons:');

            arsort($skipReasons);
            foreach ($skipReasons as $reason => $count) {
                $this->line("  {$count}x  {$reason}");
            }

            $this->newLine();
            $this->line('Run with <fg=cyan>-v</> (verbose) to see the SQL for each skipped query.');
        }

        return Command::SUCCESS;
    }
}
