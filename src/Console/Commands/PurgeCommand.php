<?php

namespace Kombee\IndexAdvisor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes rows from all index_advisor_* tables.
 *
 * Default behaviour: deletes rows older than `retention_days`.
 * With --all: truncates ALL rows from ALL five tables immediately.
 *
 * Usage:
 *   php artisan index-advisor:purge
 *   php artisan index-advisor:purge --days=7
 *   php artisan index-advisor:purge --all
 *   php artisan index-advisor:purge --all --force
 */
class PurgeCommand extends Command
{
    protected $signature = 'index-advisor:purge
                            {--days=  : Override retention_days config}
                            {--all    : Truncate ALL rows from ALL five tables (full reset)}
                            {--force  : Skip confirmation prompt when using --all}';

    protected $description = 'Purge old rows from all index_advisor_* tables (use --all to truncate everything)';

    private array $tables = [
        'index_advisor_columns',
        'index_advisor_queries',
        'index_advisor_query_stats',
        'index_advisor_explains',
        'index_advisor_recommendations',
    ];

    public function handle(): int
    {
        if ($this->option('all')) {
            return $this->truncateAll();
        }

        return $this->purgeByAge();
    }

    // ─── Full truncate ────────────────────────────────────────────────────────

    private function truncateAll(): int
    {
        if (! $this->option('force')) {
            $this->warn('⚠️  This will DELETE ALL rows from all five index_advisor_* tables.');
            $this->warn('    This cannot be undone. All recommendations, query logs, and EXPLAIN plans will be lost.');

            if (! $this->confirm('Are you sure you want to truncate all Index Advisor tables?', false)) {
                $this->info('Aborted.');

                return Command::SUCCESS;
            }
        }

        $this->info('Truncating all Index Advisor tables...');

        $driver = DB::getDriverName();
        $deleted = 0;

        foreach ($this->tables as $table) {
            $count = DB::table($table)->count();

            if ($driver === 'pgsql') {
                // TRUNCATE ... RESTART IDENTITY resets auto-increment sequences
                DB::statement("TRUNCATE TABLE {$table} RESTART IDENTITY CASCADE");
            } else {
                DB::table($table)->truncate();
            }

            $this->line("  ✓ {$table}: {$count} rows deleted");
            $deleted += $count;
        }

        $this->info("✅  All tables truncated — {$deleted} total rows removed.");

        return Command::SUCCESS;
    }

    // ─── Age-based purge ──────────────────────────────────────────────────────

    private function purgeByAge(): int
    {
        $days = (int) ($this->option('days') ?? config('index_advisor.retention_days', 30));
        $cutoff = now()->subDays($days);

        $this->info("Purging records older than {$days} days (before {$cutoff->toDateString()})...");

        $deleted = 0;

        $deleted += DB::table('index_advisor_queries')
            ->where('last_seen_at', '<', $cutoff)
            ->delete();

        $deleted += DB::table('index_advisor_query_stats')
            ->where('recorded_at', '<', $cutoff)
            ->delete();

        $deleted += DB::table('index_advisor_explains')
            ->where('analyzed_at', '<', $cutoff)
            ->delete();

        $deleted += DB::table('index_advisor_columns')
            ->where('detected_at', '<', $cutoff)
            ->delete();

        // Only purge applied/dismissed recommendations — keep pending ones
        $deleted += DB::table('index_advisor_recommendations')
            ->whereIn('status', ['applied', 'dismissed'])
            ->where('updated_at', '<', $cutoff)
            ->delete();

        $this->info("✅  Purged {$deleted} total rows.");

        return Command::SUCCESS;
    }
}
