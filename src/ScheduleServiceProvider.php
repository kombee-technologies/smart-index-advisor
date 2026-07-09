<?php

namespace Kombee\IndexAdvisor;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class ScheduleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('index-advisor:purge')->weekly()->sundays()->at('02:00');

            if (config('index_advisor.report_email')) {
                $schedule->command('index-advisor:report')->weekly()->mondays()->at('08:00');
            }
        });
    }
}
