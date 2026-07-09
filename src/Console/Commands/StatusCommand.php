<?php

namespace Kombee\IndexAdvisor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Quick check that HTTP query logging (Postman, API, browser) is configured.
 */
class StatusCommand extends Command
{
    protected $signature = 'index-advisor:status';

    protected $description = 'Show whether Smart Index Advisor HTTP query logging is active and table row counts';

    public function handle(): int
    {
        $enabled = (bool) config('index_advisor.enabled');
        $profile = config('index_advisor.profile');
        $slowMs = config('index_advisor.slow_query_ms');
        $telescopeSkipAll = (bool) config('index_advisor.query_logging.skip_when_telescope_recording', false);

        $this->info('');
        $this->info('Smart Index Advisor — runtime logging status');
        $this->info('────────────────────────────────────');

        $this->line('  Profile:              '.$profile);
        $this->line('  INDEX_ADVISOR_ENABLED: '.($enabled ? '<info>true</info>' : '<error>false</error>'));
        $this->line('  slow_query_ms:        '.$slowMs);
        $this->line('  DB driver:            '.DB::getDriverName());

        if (! $enabled) {
            $this->warn('');
            $this->warn('  HTTP logging is OFF. Postman/API requests will NOT write to index_advisor_queries.');
            $this->warn('  Add to .env: INDEX_ADVISOR_ENABLED=true');
            $this->warn('  For local dev also: INDEX_ADVISOR_PROFILE=local');
        } else {
            $this->info('');
            $this->info('  HTTP logging is ON. Each Laravel HTTP request (Postman runner, API, web) that');
            $this->info('  executes SQL via DB::listen can update index_advisor_queries.');
        }

        if ($telescopeSkipAll) {
            $this->warn('');
            $this->warn('  INDEX_ADVISOR_SKIP_WHEN_TELESCOPE=true — all queries skipped while Telescope records.');
            $this->warn('  Set INDEX_ADVISOR_SKIP_WHEN_TELESCOPE=false to log Postman traffic with Telescope on.');
        }

        $this->info('');
        $this->table(
            ['Table', 'Rows'],
            [
                ['index_advisor_queries', number_format(DB::table('index_advisor_queries')->count())],
                ['index_advisor_query_stats', number_format(DB::table('index_advisor_query_stats')->count())],
                ['index_advisor_columns', number_format(DB::table('index_advisor_columns')->count())],
                ['index_advisor_explains', number_format(DB::table('index_advisor_explains')->count())],
                ['index_advisor_recommendations', number_format(DB::table('index_advisor_recommendations')->count())],
            ]
        );

        $queries = DB::table('index_advisor_queries')->count();
        if ($queries === 0 && $enabled) {
            $this->warn('');
            $this->warn('  index_advisor_queries is empty. After setting .env, restart PHP-FPM / `php artisan serve`,');
            $this->warn('  run Postman against your API (not only /index-advisor dashboard), then re-check.');
            $this->warn('  Or run: php artisan index-advisor:ingest-slow-log (PostgreSQL pg_stat bridge).');
        }

        $this->info('');

        return Command::SUCCESS;
    }
}
