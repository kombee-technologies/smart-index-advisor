<?php

namespace Kombee\IndexAdvisor;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class DashboardServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! config('index_advisor.dashboard.enabled', true)) {
            return;
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'index-advisor');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        Gate::define('viewIndexAdvisor', function ($user = null) {
            return app()->environment('local', 'development');
        });
    }
}
