<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master Enable / Disable Switch
    |--------------------------------------------------------------------------
    |
    | When enabled, registers a DB::listen hook that captures every SQL query
    | executed during HTTP requests. Queries are buffered in-memory and flushed
    | to the database after the response is sent (via FlushQueryBufferMiddleware).
    |
    | Disabled by default. Set INDEX_ADVISOR_ENABLED=true to turn on runtime
    | query logging.
    |
    */
    'enabled' => env('INDEX_ADVISOR_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Environment Label
    |--------------------------------------------------------------------------
    |
    | A display-only label identifying the current environment (shown in the
    | dashboard and status command). Does NOT affect any settings — each
    | setting is controlled independently via its own env variable.
    |
    | Common values: local, development, uat, production.
    |
    */
    'profile' => env('INDEX_ADVISOR_PROFILE', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Web Dashboard
    |--------------------------------------------------------------------------
    |
    | The dashboard provides a browser UI at /index-advisor to review
    | recommendations, query logs, and generate migration files.
    |
    | Disabled by default for security. Set INDEX_ADVISOR_DASHBOARD_ENABLED=true
    | to enable. Access is gated by the 'viewIndexAdvisor' authorization gate,
    | which by default only allows access in local/development environments.
    | Publish the service provider to customize the gate definition.
    |
    | SQL samples are redacted by default in production to prevent exposing
    | sensitive data. Set INDEX_ADVISOR_EXPOSE_SQL=true to show full SQL.
    |
    */
    'dashboard' => [
        'enabled' => env('INDEX_ADVISOR_DASHBOARD_ENABLED', false),
        'path' => env('INDEX_ADVISOR_PATH', 'index-advisor'),
        'middleware' => ['web', 'auth', 'can:viewIndexAdvisor'],
        'expose_sql_samples' => env(
            'INDEX_ADVISOR_EXPOSE_SQL',
            in_array(env('APP_ENV', 'production'), ['local', 'development'], true)
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | The log channel used for all Smart Index Advisor warnings and errors. When null,
    | the application's default channel is used. Set to a dedicated channel
    | (e.g. 'index-advisor') to avoid flooding the main application log.
    |
    | Supported log levels: debug, info, notice, warning, error, critical.
    | Schema introspection failures use the configured level; runtime errors
    | always log at 'error' level.
    |
    */
    'log_channel' => env('INDEX_ADVISOR_LOG_CHANNEL', null),
    'log_level' => env('INDEX_ADVISOR_LOG_LEVEL', 'warning'),

    /*
    |--------------------------------------------------------------------------
    | Runtime Query Logging
    |--------------------------------------------------------------------------
    |
    | Controls how HTTP request queries are captured by the DB::listen hook.
    | Queries are buffered per-request and batch-flushed after the response.
    |
    | By default, queries are logged even when Laravel Telescope is recording.
    | Set skip_when_telescope_recording=true to skip capture during Telescope
    | recording sessions.
    |
    */
    'query_logging' => [
        'skip_when_telescope_recording' => env('INDEX_ADVISOR_SKIP_WHEN_TELESCOPE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime / Ingestion Thresholds
    |--------------------------------------------------------------------------
    |
    | These thresholds control which queries are considered significant enough
    | to analyse. Defaults are production-safe; override per environment via
    | the corresponding env variable in your .env file.
    |
    |   min_executions     — Minimum times a query must run before it is analysed.
    |   slow_query_ms      — Queries slower than this (ms) receive a scoring bonus.
    |   retention_days     — Days of data kept by the purge command.
    |   auto_migrate_score — Minimum score to auto-generate migration files.
    |
    */
    'min_executions' => (int) env('INDEX_ADVISOR_MIN_EXEC', 10),
    'slow_query_ms' => (int) env('INDEX_ADVISOR_SLOW_MS', 500),
    'retention_days' => (int) env('INDEX_ADVISOR_RETENTION', 14),
    'auto_migrate_score' => (int) env('INDEX_ADVISOR_AUTO_SCORE', 90),

    /*
    |--------------------------------------------------------------------------
    | Scoring Controls
    |--------------------------------------------------------------------------
    |
    | Parameters that control how candidates are scored and filtered.
    | Defaults are production-safe; override per environment via .env.
    |
    |   min_score                   — Recommendations below this score are discarded.
    |   min_table_rows              — Tables with fewer rows are skipped (tiny tables).
    |   min_cardinality             — Columns with fewer distinct values are skipped.
    |   runtime_composite_query_limit — Max runtime queries analysed for composites.
    |   correlation_query_limit     — Max queries for single-column correlation lookups.
    |   max_duration_multiplier     — Multiplier for max-duration spike detection.
    |   max_duration_pts            — Points awarded when a spike is detected.
    |   verdict_thresholds          — Score boundaries for severity labels.
    |
    */
    'scoring' => [
        'min_score' => (int) env('INDEX_ADVISOR_MIN_SCORE', 20),
        'min_table_rows' => (int) env('INDEX_ADVISOR_MIN_TABLE_ROWS', 5000),
        'min_cardinality' => (int) env('INDEX_ADVISOR_MIN_CARDINALITY', 25),
        'runtime_composite_query_limit' => (int) env('INDEX_ADVISOR_RUNTIME_COMPOSITE_LIMIT', 1000),
        'correlation_query_limit' => (int) env('INDEX_ADVISOR_CORRELATION_QUERY_LIMIT', 2000),
        'max_duration_multiplier' => (float) env('INDEX_ADVISOR_MAX_DURATION_MULTIPLIER', 3),
        'max_duration_pts' => (int) env('INDEX_ADVISOR_MAX_DURATION_PTS', 5),
        'verdict_thresholds' => [
            'critical' => (int) env('INDEX_ADVISOR_SCORE_CRITICAL', 80),
            'high' => (int) env('INDEX_ADVISOR_SCORE_HIGH', 60),
            'medium' => (int) env('INDEX_ADVISOR_SCORE_MEDIUM', 40),
            'low' => (int) env('INDEX_ADVISOR_SCORE_LOW', 20),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Report Email
    |--------------------------------------------------------------------------
    |
    | Email address for the weekly HTML report sent by index-advisor:report.
    | Leave null to disable email reports (console-only output).
    |
    */
    'report_email' => env('INDEX_ADVISOR_EMAIL', null),

    /*
    |--------------------------------------------------------------------------
    | MySQL Slow Query Log
    |--------------------------------------------------------------------------
    |
    | Path to the MySQL slow query log file on disk. Only used by the
    | ingest-slow-log command when the driver is mysql.
    |
    | slow_log_allowed_path_prefixes restricts which directories the
    | ingest-slow-log command is allowed to read from, preventing path
    | traversal attacks.
    |
    */
    'slow_log_path' => env('INDEX_ADVISOR_SLOW_LOG', '/var/log/mysql/slow.log'),
    'slow_log_allowed_path_prefixes' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('INDEX_ADVISOR_SLOW_LOG_ALLOWED_PREFIXES', '/var/log'))
    ))),

    /*
    |--------------------------------------------------------------------------
    | PostgreSQL Schema
    |--------------------------------------------------------------------------
    |
    | The schema name used when introspecting PostgreSQL system catalogs
    | (pg_stat_user_indexes, pg_stat_user_tables, etc.). Defaults to 'public'.
    |
    */
    'pg_schema' => env('INDEX_ADVISOR_PG_SCHEMA', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Composite Index Minimum Columns
    |--------------------------------------------------------------------------
    |
    | Minimum number of columns required for a composite index recommendation.
    | Queries that filter on fewer columns than this will not generate
    | composite suggestions. Default: 2.
    |
    */
    'composite_min_columns' => (int) env('INDEX_ADVISOR_COMPOSITE_MIN', 2),

    /*
    |--------------------------------------------------------------------------
    | Static Code Analysis Paths
    |--------------------------------------------------------------------------
    |
    | Comma-separated directories scanned by index-advisor:analyze-code.
    | Use absolute paths or paths relative to the Laravel base directory.
    | When empty, the command defaults to app_path().
    |
    */
    'code_analysis' => [
        'paths' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('INDEX_ADVISOR_CODE_PATHS', ''))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cross-Type Recommendation Reconciliation
    |--------------------------------------------------------------------------
    |
    | After scoring / DROP ingest, the reconciler dismisses stale INDEX,
    | REDUNDANT_CHECK, or DROP rows that contradict the live schema
    | (e.g. a DROP and an INDEX on the same column).
    |
    */
    'reconciliation' => [
        'enabled' => env('INDEX_ADVISOR_RECONCILE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | SQLite (local / testing)
    |--------------------------------------------------------------------------
    |
    | Cardinality uses COUNT(DISTINCT) only when the table row count is below
    | this limit, to avoid full scans on large SQLite database files.
    |
    */
    'sqlite' => [
        'cardinality_max_rows' => (int) env('INDEX_ADVISOR_SQLITE_CARDINALITY_MAX_ROWS', 10000),
    ],

    /*
    |--------------------------------------------------------------------------
    | MySQL (cardinality fallback)
    |--------------------------------------------------------------------------
    |
    | COUNT(DISTINCT) fallback is only used for small tables when
    | information_schema.STATISTICS has no cardinality data for a column.
    |
    */
    'mysql' => [
        'cardinality_max_rows' => (int) env('INDEX_ADVISOR_MYSQL_CARDINALITY_MAX_ROWS', 10000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tables to Exclude from Analysis
    |--------------------------------------------------------------------------
    |
    | Tables listed here are skipped during scoring and telemetry ingestion.
    | Add any application-specific tables that should never receive index
    | recommendations (e.g. log tables, queue tables).
    |
    */
    'excluded_tables' => [
        'index_advisor_columns',
        'index_advisor_queries',
        'index_advisor_explains',
        'index_advisor_recommendations',
        'index_advisor_query_stats',
        'migrations',
        'telescope_entries',
        'telescope_monitoring',
        'telescope_entries_tags',
        'failed_jobs',
        'jobs',
        'cache',
        'sessions',
        'password_reset_tokens',
        'pulse_entries',
        'pulse_aggregates',
        'pulse_values',
        'personal_access_tokens',
        'firewall_ips',
        'firewall_logs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Name Override Map
    |--------------------------------------------------------------------------
    |
    | Map Eloquent model class stems to actual database table names when the
    | code analyzer cannot infer them automatically. This is common when
    | projects use abbreviated model names (e.g. LymLeadMstr → lym_lead_mstrs).
    |
    */
    'table_map' => [],

];
