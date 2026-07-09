<?php

namespace Kombee\IndexAdvisor;

use Illuminate\Support\ServiceProvider;
use Kombee\IndexAdvisor\Console\Commands\AnalyzeCodebaseCommand;
use Kombee\IndexAdvisor\Console\Commands\DiagnoseCommand;
use Kombee\IndexAdvisor\Console\Commands\DismissCommand;
use Kombee\IndexAdvisor\Console\Commands\DropUnusedCommand;
use Kombee\IndexAdvisor\Console\Commands\GenerateMigrationsCommand;
use Kombee\IndexAdvisor\Console\Commands\ImportStatsCommand;
use Kombee\IndexAdvisor\Console\Commands\IngestSlowLogCommand;
use Kombee\IndexAdvisor\Console\Commands\InstallCommand;
use Kombee\IndexAdvisor\Console\Commands\MarkAppliedCommand;
use Kombee\IndexAdvisor\Console\Commands\PurgeCommand;
use Kombee\IndexAdvisor\Console\Commands\ReconcileCommand;
use Kombee\IndexAdvisor\Console\Commands\ReportCommand;
use Kombee\IndexAdvisor\Console\Commands\RunAdvisorCommand;
use Kombee\IndexAdvisor\Console\Commands\RunExplainCommand;
use Kombee\IndexAdvisor\Console\Commands\StatusCommand;
use Kombee\IndexAdvisor\Contracts\ColumnSchemaSyncer;
use Kombee\IndexAdvisor\Contracts\SchemaIntrospectorContract;
use Kombee\IndexAdvisor\Services\ExplainPlanRunner;
use Kombee\IndexAdvisor\Services\LiveSchemaColumnSyncer;
use Kombee\IndexAdvisor\Services\QueryColumnCorrelator;
use Kombee\IndexAdvisor\Services\QueryFingerprinter;
use Kombee\IndexAdvisor\Services\QueryLogUpserter;
use Kombee\IndexAdvisor\Http\Middleware\FlushQueryBufferMiddleware;
use Kombee\IndexAdvisor\Services\QueryLoggerRegistrar;
use Kombee\IndexAdvisor\Services\RecommendationActiveState;
use Kombee\IndexAdvisor\Services\RecommendationReconciler;
use Kombee\IndexAdvisor\Services\RecommendationScoreCalculator;
use Kombee\IndexAdvisor\Services\RuntimeQueryListener;
use Kombee\IndexAdvisor\Services\SchemaIntrospector;
use Kombee\IndexAdvisor\Services\ScoringService;
use Kombee\IndexAdvisor\Services\SqlColumnMatcher;

class IndexAdvisorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/index_advisor.php', 'index_advisor');

        $this->app->singleton(SchemaIntrospector::class);
        $this->app->alias(SchemaIntrospector::class, SchemaIntrospectorContract::class);

        $this->app->singleton(QueryFingerprinter::class);
        $this->app->singleton(ExplainPlanRunner::class);
        $this->app->singleton(SqlColumnMatcher::class);
        $this->app->singleton(QueryColumnCorrelator::class);
        $this->app->singleton(QueryLogUpserter::class);
        $this->app->singleton(RecommendationActiveState::class);
        $this->app->singleton(RecommendationScoreCalculator::class);
        $this->app->singleton(ColumnSchemaSyncer::class, LiveSchemaColumnSyncer::class);
        $this->app->singleton(LiveSchemaColumnSyncer::class);
        $this->app->singleton(RecommendationReconciler::class);
        $this->app->singleton(ScoringService::class);
        $this->app->singleton(RuntimeQueryListener::class);
        $this->app->singleton(QueryLoggerRegistrar::class);

        $this->app->register(DashboardServiceProvider::class);
        $this->app->register(ScheduleServiceProvider::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/index_advisor.php' => config_path('index_advisor.php'),
        ], 'index-advisor-config');

        if (! $this->migrationExists('create_index_advisor_tables.php')) {
            $this->publishes([
                __DIR__.'/../database/migrations/create_index_advisor_tables.php.stub' => database_path('migrations/'.date('Y_m_d_His', time()).'_create_index_advisor_tables.php'),
            ], 'index-advisor');
        }

        if (! $this->migrationExists('update_index_advisor_tables.php')) {
            $this->publishes([
                __DIR__.'/../database/migrations/update_index_advisor_tables.php.stub' => database_path('migrations/'.date('Y_m_d_His', time() + 1).'_update_index_advisor_tables.php'),
            ], 'index-advisor');
        }

        $this->publishes([
            __DIR__.'/../stubs/IndexAdvisorServiceProvider.stub' => app_path('Providers/IndexAdvisorServiceProvider.php'),
        ], 'index-advisor-provider');

        $this->commands([
            AnalyzeCodebaseCommand::class,
            IngestSlowLogCommand::class,
            RunExplainCommand::class,
            GenerateMigrationsCommand::class,
            RunAdvisorCommand::class,
            PurgeCommand::class,
            ReportCommand::class,
            DropUnusedCommand::class,
            DismissCommand::class,
            MarkAppliedCommand::class,
            DiagnoseCommand::class,
            ReconcileCommand::class,
            StatusCommand::class,
            ImportStatsCommand::class,
            InstallCommand::class,
        ]);

        $this->app->make(QueryLoggerRegistrar::class)->register();

        $router = $this->app->make('router');
        $router->pushMiddlewareToGroup('web', FlushQueryBufferMiddleware::class);
        $router->pushMiddlewareToGroup('api', FlushQueryBufferMiddleware::class);

        $this->warnIfProfileUnknown();
    }

    /**
     * Log a warning when INDEX_ADVISOR_PROFILE is set to an unrecognized
     * value. The profile is a display-only label and does not affect settings,
     * but an unexpected value may indicate a typo in the .env file.
     */
    private function warnIfProfileUnknown(): void
    {
        $profile = config('index_advisor.profile');

        $known = ['local', 'development', 'uat', 'production', 'staging', 'testing'];

        if ($profile !== null && ! in_array($profile, $known, true)) {
            $channel = config('index_advisor.log_channel');

            /** @var \Illuminate\Log\LogManager $logManager */
            $logManager = app('log');
            $logger = $channel ? $logManager->channel($channel) : $logManager->driver();

            $logger->warning("IndexAdvisor: Unrecognized profile '{$profile}'. Common values: ".implode(', ', $known).'. This is a display-only label and does not affect settings.');
        }
    }

    /**
     * Determine if a migration with the given name has already been published.
     */
    protected function migrationExists(string $migrationFileName): bool
    {
        $path = database_path('migrations');
        $files = glob($path.'/*_'.$migrationFileName);

        return $files !== false && count($files) > 0;
    }
}
