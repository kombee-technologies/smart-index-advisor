<?php

namespace Kombee\IndexAdvisor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mark one or all generated recommendations as applied after running
 * `php artisan migrate`.
 *
 * Usage:
 *   php artisan index-advisor:mark-applied 42
 *   php artisan index-advisor:mark-applied --all-generated
 */
class MarkAppliedCommand extends Command
{
    protected $signature = 'index-advisor:mark-applied
                            {id?             : ID of the recommendation to mark as applied}
                            {--all-generated : Mark every "generated" recommendation as applied}';

    protected $description = 'Mark recommendation(s) as applied after running php artisan migrate';

    public function handle(): int
    {
        if ($this->option('all-generated')) {
            return $this->markAllGenerated();
        }

        $id = $this->argument('id');

        if (! $id) {
            $this->error('Provide a recommendation ID or use --all-generated.');

            return Command::FAILURE;
        }

        $rec = DB::table('index_advisor_recommendations')->find((int) $id);

        if (! $rec) {
            $this->error("Recommendation #{$id} not found.");

            return Command::FAILURE;
        }

        $evidence = json_decode($rec->evidence ?? '{}', true);
        $evidence['applied_at'] = now()->toDateTimeString();

        DB::table('index_advisor_recommendations')
            ->where('id', (int) $id)
            ->update([
                'status' => 'applied',
                'evidence' => json_encode($evidence),
                'updated_at' => now(),
            ]);

        $this->info("✅  Recommendation #{$id} [{$rec->table_name}.{$rec->column_name}] marked as applied.");

        return Command::SUCCESS;
    }

    private function markAllGenerated(): int
    {
        $count = DB::table('index_advisor_recommendations')
            ->where('status', 'generated')
            ->count();

        if ($count === 0) {
            $this->warn('No "generated" recommendations found. Run `php artisan index-advisor:generate-migrations` first.');

            return Command::SUCCESS;
        }

        DB::table('index_advisor_recommendations')
            ->where('status', 'generated')
            ->update([
                'status' => 'applied',
                'updated_at' => now(),
            ]);

        $this->info("✅  Marked {$count} recommendation(s) as applied.");

        return Command::SUCCESS;
    }
}
