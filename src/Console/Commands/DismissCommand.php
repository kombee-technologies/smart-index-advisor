<?php

namespace Kombee\IndexAdvisor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Dismiss a recommendation so it is excluded from future reports and
 * migration generation.
 *
 * Usage:
 *   php artisan index-advisor:dismiss 42
 *   php artisan index-advisor:dismiss 42 --reason="Low-cardinality column"
 *   php artisan index-advisor:dismiss --all-redundant
 */
class DismissCommand extends Command
{
    protected $signature = 'index-advisor:dismiss
                            {id?            : ID of the recommendation to dismiss}
                            {--reason=      : Optional reason stored in evidence JSON}
                            {--all-redundant: Dismiss all REDUNDANT_CHECK recommendations}';

    protected $description = 'Dismiss a recommendation (marks it as dismissed so it is skipped in future runs)';

    public function handle(): int
    {
        if ($this->option('all-redundant')) {
            return $this->dismissAllRedundant();
        }

        $id = $this->argument('id');

        if (! $id) {
            $this->error('Provide a recommendation ID or use --all-redundant.');

            return Command::FAILURE;
        }

        $rec = DB::table('index_advisor_recommendations')->find((int) $id);

        if (! $rec) {
            $this->error("Recommendation #{$id} not found.");

            return Command::FAILURE;
        }

        $evidence = json_decode($rec->evidence ?? '{}', true);
        $evidence['dismissed_at'] = now()->toDateTimeString();
        $evidence['dismiss_reason'] = $this->option('reason') ?? 'Manually dismissed';

        DB::table('index_advisor_recommendations')
            ->where('id', (int) $id)
            ->update([
                'status' => 'dismissed',
                'evidence' => json_encode($evidence),
                'updated_at' => now(),
            ]);

        $this->info("✅  Recommendation #{$id} [{$rec->table_name}.{$rec->column_name}] dismissed.");

        return Command::SUCCESS;
    }

    private function dismissAllRedundant(): int
    {
        $count = DB::table('index_advisor_recommendations')
            ->where('index_type', 'REDUNDANT_CHECK')
            ->where('status', 'pending')
            ->count();

        if ($count === 0) {
            $this->warn('No pending REDUNDANT_CHECK recommendations found.');

            return Command::SUCCESS;
        }

        DB::table('index_advisor_recommendations')
            ->where('index_type', 'REDUNDANT_CHECK')
            ->where('status', 'pending')
            ->update([
                'status' => 'dismissed',
                'updated_at' => now(),
            ]);

        $this->info("✅  Dismissed {$count} REDUNDANT_CHECK recommendation(s).");

        return Command::SUCCESS;
    }
}
