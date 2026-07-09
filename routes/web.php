<?php

use Illuminate\Support\Facades\Route;
use Kombee\IndexAdvisor\Http\Controllers\IndexAdvisorController;
use Kombee\IndexAdvisor\Http\Middleware\PreventIndexAdvisorLogging;

$path = trim((string) config('index_advisor.dashboard.path', 'index-advisor'), '/');
$middleware = array_values(array_unique(array_merge(
    config('index_advisor.dashboard.middleware', ['web', 'auth', 'can:viewIndexAdvisor']),
    [PreventIndexAdvisorLogging::class]
)));

// Dashboard routes use the web middleware stack, including CSRF verification.
Route::prefix($path)
    ->middleware($middleware)
    ->group(function () {
        Route::get('/', [IndexAdvisorController::class, 'index'])->name('index-advisor.dashboard');
        Route::get('/queries', [IndexAdvisorController::class, 'queriesPage'])->name('index-advisor.queries');
        Route::get('/overview', [IndexAdvisorController::class, 'overviewPage'])->name('index-advisor.overview');
        Route::get('/migrations', [IndexAdvisorController::class, 'migrationsPage'])->name('index-advisor.migrations');

        Route::get('/api/recommendations', [IndexAdvisorController::class, 'getRecommendations']);
        Route::get('/api/recommendations/{id}/queries', [IndexAdvisorController::class, 'getQueries']);
        Route::post('/api/recommendations/{id}/dismiss', [IndexAdvisorController::class, 'dismiss']);
        Route::post('/api/recommendations/{id}/apply', [IndexAdvisorController::class, 'apply']);
        Route::post('/api/run', [IndexAdvisorController::class, 'runPipeline']);
        Route::get('/api/tasks/{runId}', [IndexAdvisorController::class, 'getTaskStatus']);
        Route::post('/api/generate-migrations', [IndexAdvisorController::class, 'generateMigrations']);
        Route::get('/api/migration-candidates', [IndexAdvisorController::class, 'getMigrationCandidates']);
        Route::post('/api/generate-selected-migrations', [IndexAdvisorController::class, 'generateSelectedMigrations']);

        Route::get('/api/overview', [IndexAdvisorController::class, 'getOverview']);
        Route::get('/api/query-log', [IndexAdvisorController::class, 'getQueryLog']);
        Route::get('/upload', [IndexAdvisorController::class, 'showUploadForm'])->name('index-advisor.upload');
        Route::post('/upload', [IndexAdvisorController::class, 'handleUpload'])->name('index-advisor.handle-upload');

        Route::get('/export-report', [IndexAdvisorController::class, 'exportReport'])->name('index-advisor.export-report');
    });
