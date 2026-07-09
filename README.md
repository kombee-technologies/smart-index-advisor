# Smart Index Advisor

**Automated database index optimizer for Laravel.**  
Analyses your PHP codebase, runtime query logs, and production DB statistics to recommend, score, and generate migration files for missing indexes — and flag unused ones for removal.

Supports **MySQL**, **PostgreSQL**, **SQL Server**, and **SQLite**.

---

## Table of Contents

1. [Requirements](#requirements)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [Environment Recommendations](#environment-recommendations)
5. [Quick Start](#quick-start)
6. [Production Import Mode (CSV)](#production-import-mode-csv)
7. [All Artisan Commands](#all-artisan-commands)
8. [Pipeline Steps Explained](#pipeline-steps-explained)
9. [Scoring Formula](#scoring-formula)
10. [Dashboard](#dashboard)
11. [Logging Configuration](#logging-configuration)
12. [Recommendation Status Lifecycle](#recommendation-status-lifecycle)
13. [Scheduler Setup](#scheduler-setup)
14. [Table Name Mapping](#table-name-mapping)
15. [Performance & Reliability](#performance--reliability)
16. [Troubleshooting](#troubleshooting)

---

## Requirements

| Requirement | Version                                                 |
| ----------- | ------------------------------------------------------- |
| PHP         | ^8.1                                                    |
| Laravel     | 10.x, 11.x, 12.x or 13.x                                |
| Database    | MySQL 5.7+, PostgreSQL 12+, SQL Server 2017+, or SQLite |

---

## Installation

### 1. Add the package via Custom Repository (VCS)

If you are testing this package from a specific Git repository, add the following to your `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/kombee-technologies/smart-index-advisor.git"
    }
],
"require": {
    "kombee-technologies/smart-index-advisor": "dev-main"
}
```

_(Replace the URL with your actual repository URL, and `dev-main` with your desired branch like `1.x-dev`)_

Then, run:

```bash
composer update kombee-technologies/smart-index-advisor
```

> **Note on Tokens:** If the repository is private, Composer will ask for a token to download it. You can generate a Personal Access Token in GitHub and paste it when prompted (e.g., `ghp_...`).
>
> **Troubleshooting:** If changes are not reflecting, delete the vendor package folder to force a fresh download:
> Delete the folder: `vendor/kombee-technologies/smart-index-advisor`
> And re-run `composer update`.

### 2. Install the Package

Just like Telescope, you can run a single artisan command to publish the configuration, service provider, and migrations:

```bash
php artisan index-advisor:install
```

### 2a. Register the Service Provider (Optional — for Dashboard Access)

The `install` command copies `app/Providers/IndexAdvisorServiceProvider.php` so you can customize the `viewIndexAdvisor` gate.

1. Register it:

   **For Laravel 11+** (in `bootstrap/providers.php`):

   ```php
   App\Providers\IndexAdvisorServiceProvider::class,
   ```

   **For Laravel 10** (in `config/app.php` under the `providers` array):

   ```php
   App\Providers\IndexAdvisorServiceProvider::class,
   ```

2. Disable auto-discovery in your app's `composer.json` to avoid loading the package provider twice:

   ```json
   "extra": {
       "laravel": {
           "dont-discover": [
               "kombee-technologies/smart-index-advisor"
           ]
       }
   }
   ```

3. Run `composer dump-autoload`.

Edit the published provider and add authorized emails in the `gate()` method.

### 3. Run migrations

```bash
php artisan migrate
```

This creates 5 tables:

| Table                           | Purpose                                                   |
| ------------------------------- | --------------------------------------------------------- |
| `index_advisor_columns`         | Static code-scan results                                  |
| `index_advisor_queries`         | Runtime query log (from `DB::listen`)                     |
| `index_advisor_query_stats`     | DB engine statistics (pg_stat_statements, slow log, etc.) |
| `index_advisor_explains`        | EXPLAIN plan results                                      |
| `index_advisor_recommendations` | Final scored recommendations                              |

### 4. Enable runtime logging (optional but recommended)

Add to your `.env`:

```env
INDEX_ADVISOR_ENABLED=true
```

This registers a `DB::listen` hook that logs every SQL query executed during HTTP requests.

---

## Configuration

After publishing, edit `config/index_advisor.php`. All values can also be set via `.env`. Defaults are production-safe.

| Config Key                                    | Env Variable                                | Default                   | Description                                                        |
| --------------------------------------------- | ------------------------------------------- | ------------------------- | ------------------------------------------------------------------ |
| `enabled`                                     | `INDEX_ADVISOR_ENABLED`                     | `false`                   | Enable/disable runtime query logging                               |
| `profile`                                     | `INDEX_ADVISOR_PROFILE`                     | `production`              | Display-only environment label (does not affect settings)          |
| `log_channel`                                 | `INDEX_ADVISOR_LOG_CHANNEL`                 | `null` (app default)      | Dedicated log channel for Smart Index Advisor warnings/errors            |
| `log_level`                                   | `INDEX_ADVISOR_LOG_LEVEL`                   | `warning`                 | Minimum log level (`debug`–`critical`)                             |
| `min_executions`                              | `INDEX_ADVISOR_MIN_EXEC`                    | `10`                      | Minimum times a query must run before it's analyzed                |
| `slow_query_ms`                               | `INDEX_ADVISOR_SLOW_MS`                     | `500`                     | Queries slower than this (ms) receive the slow-query scoring bonus |
| `retention_days`                              | `INDEX_ADVISOR_RETENTION`                   | `14`                      | Days of data kept by the purge command                             |
| `auto_migrate_score`                          | `INDEX_ADVISOR_AUTO_SCORE`                  | `90`                      | Minimum score to auto-generate migration files                     |
| `report_email`                                | `INDEX_ADVISOR_EMAIL`                       | `null`                    | Email address for weekly HTML reports                              |
| `slow_log_path`                               | `INDEX_ADVISOR_SLOW_LOG`                    | `/var/log/mysql/slow.log` | Path to MySQL slow query log                                       |
| `slow_log_allowed_path_prefixes`              | `INDEX_ADVISOR_SLOW_LOG_ALLOWED_PREFIXES`   | `/var/log`                | Allowed directory prefixes for slow log ingestion                  |
| `pg_schema`                                   | `INDEX_ADVISOR_PG_SCHEMA`                   | `public`                  | PostgreSQL schema to introspect                                    |
| `composite_min_columns`                       | `INDEX_ADVISOR_COMPOSITE_MIN`               | `2`                       | Minimum columns for a composite index suggestion                   |
| `code_analysis.paths`                         | `INDEX_ADVISOR_CODE_PATHS`                  | `app` (implicit)          | Comma-separated directories for static PHP scan (`analyze-code`)   |
| `dashboard.enabled`                           | `INDEX_ADVISOR_DASHBOARD_ENABLED`           | `false`                   | Enable/disable web dashboard (disabled by default)                 |
| `dashboard.path`                              | `INDEX_ADVISOR_PATH`                        | `index-advisor`           | URL path for the dashboard                                         |
| `dashboard.expose_sql_samples`                | `INDEX_ADVISOR_EXPOSE_SQL`                  | `false` (in production)   | Show full SQL samples in dashboard (redacted by default)           |
| `query_logging.skip_when_telescope_recording` | `INDEX_ADVISOR_SKIP_WHEN_TELESCOPE`         | `false`                   | Skip query logging when Laravel Telescope is recording             |
| `scoring.min_score`                           | `INDEX_ADVISOR_MIN_SCORE`                   | `20`                      | Minimum score before a recommendation is stored                    |
| `scoring.min_table_rows`                      | `INDEX_ADVISOR_MIN_TABLE_ROWS`              | `5000`                    | Tables smaller than this are skipped                               |
| `scoring.min_cardinality`                     | `INDEX_ADVISOR_MIN_CARDINALITY`             | `25`                      | Columns with fewer distinct values are skipped                     |
| `scoring.runtime_composite_query_limit`       | `INDEX_ADVISOR_RUNTIME_COMPOSITE_LIMIT`     | `1000`                    | Max runtime queries analysed for composite candidates              |
| `scoring.correlation_query_limit`             | `INDEX_ADVISOR_CORRELATION_QUERY_LIMIT`     | `2000`                    | Max queries for single-column correlation lookups                  |
| `scoring.max_duration_multiplier`             | `INDEX_ADVISOR_MAX_DURATION_MULTIPLIER`     | `3`                       | Multiplier for max-duration spike detection                        |
| `scoring.max_duration_pts`                    | `INDEX_ADVISOR_MAX_DURATION_PTS`            | `5`                       | Points awarded for max-duration spike                              |
| `reconciliation.enabled`                      | `INDEX_ADVISOR_RECONCILE`                   | `true`                    | Auto-dismiss contradictory recommendations after scoring           |
| `sqlite.cardinality_max_rows`                 | `INDEX_ADVISOR_SQLITE_CARDINALITY_MAX_ROWS` | `10000`                   | Row limit for COUNT(DISTINCT) on SQLite                            |
| `mysql.cardinality_max_rows`                  | `INDEX_ADVISOR_MYSQL_CARDINALITY_MAX_ROWS`  | `10000`                   | Row limit for COUNT(DISTINCT) fallback on MySQL                    |

> **`config:cache` note:** All `env()` calls are resolved at config load time. When `php artisan config:cache` is active, `env()` returns `null`, so every setting falls back to its default value (which are production-safe). Override values by setting the corresponding environment variables before running `config:cache`.

---

## Environment Recommendations

Each setting is controlled independently via its own env variable — no profile preset is needed. Just set the values appropriate for each environment in the corresponding `.env` file.

Runtime query logging is **off by default** in every environment. Set `INDEX_ADVISOR_ENABLED=true` to turn on `DB::listen` capture.

### Recommended `.env` values per environment

| Env Variable                    | local | development | uat  | production |
| ------------------------------- | ----- | ----------- | ---- | ---------- |
| `INDEX_ADVISOR_ENABLED`         | true  | true        | true | false      |
| `INDEX_ADVISOR_MIN_EXEC`        | 1     | 3           | 5    | 10         |
| `INDEX_ADVISOR_SLOW_MS`         | 100   | 200         | 300  | 500        |
| `INDEX_ADVISOR_RETENTION`       | 7     | 14          | 30   | 14         |
| `INDEX_ADVISOR_AUTO_SCORE`      | 60    | 70          | 80   | 90         |
| `INDEX_ADVISOR_MIN_SCORE`       | 10    | 10          | 15   | 20         |
| `INDEX_ADVISOR_MIN_TABLE_ROWS`  | 100   | 500         | 1000 | 5000       |
| `INDEX_ADVISOR_MIN_CARDINALITY` | 5     | 10          | 10   | 25         |

### Example: Local development `.env`

```env
INDEX_ADVISOR_ENABLED=true
INDEX_ADVISOR_PROFILE=local
INDEX_ADVISOR_MIN_EXEC=1
INDEX_ADVISOR_SLOW_MS=100
INDEX_ADVISOR_RETENTION=7
INDEX_ADVISOR_AUTO_SCORE=60
INDEX_ADVISOR_MIN_SCORE=10
INDEX_ADVISOR_MIN_TABLE_ROWS=100
INDEX_ADVISOR_MIN_CARDINALITY=5
# Comma-separated scan roots for analyze-code (relative to project root or absolute)
INDEX_ADVISOR_CODE_PATHS=app
```

When query logic lives outside `app/` (e.g. `src/`, `modules/`, or a path-repo package), add those directories:

```env
INDEX_ADVISOR_CODE_PATHS=app,src,packages/MyModule/src
```

Leave unset to scan only `app/` (Laravel default).

### Example: Production `.env`

```env
INDEX_ADVISOR_ENABLED=false
INDEX_ADVISOR_PROFILE=production
# All other settings use their production-safe defaults when not set
```

---

## Quick Start

Run the full pipeline in one command:

```bash
php artisan index-advisor:run --report-only
```

**What this does:**

1. Scans all PHP files in `app/` for query patterns
2. Ingests DB engine statistics (pg_stat_statements, slow log, etc.)
3. Runs EXPLAIN on the slowest queries
4. Scores all candidates (0–100)
5. Prints the recommendations table

Then, to generate migration files for high-scored recommendations:

```bash
php artisan index-advisor:generate-migrations
```

---

## Production Import Mode (CSV)

When you cannot run the full pipeline against your production database, export statistics from production and import them locally.

### Step 1 — Export from production database

Export the following CSV files from your **production** database. Queries are provided for both PostgreSQL and MySQL.

---

#### Unused Indexes (`unused_indexes.csv`)

**PostgreSQL:**

```sql
SELECT
    ui.relname  AS index_name,
    t.relname   AS table_name,
    a.attname   AS column_name,
    s.idx_scan  AS index_scans,
    pg_size_pretty(pg_relation_size(s.indexrelid)) AS index_size
FROM pg_stat_user_indexes s
JOIN pg_class ui  ON ui.oid = s.indexrelid
JOIN pg_class t   ON t.oid  = s.relid
JOIN pg_index ix  ON ix.indexrelid = s.indexrelid
JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
WHERE s.schemaname = 'public'
  AND s.idx_scan   = 0
  AND NOT ix.indisprimary
  AND NOT ix.indisunique
ORDER BY t.relname;
```

**MySQL:**

```sql
SELECT
    u.index_name                              AS index_name,
    u.object_name                             AS table_name,
    COALESCE(s.COLUMN_NAME, u.index_name)     AS column_name,
    0                                         AS index_scans
FROM sys.schema_unused_indexes u
JOIN information_schema.STATISTICS s
       ON s.TABLE_SCHEMA = u.object_schema
      AND s.TABLE_NAME   = u.object_name
      AND s.INDEX_NAME   = u.index_name
      AND s.SEQ_IN_INDEX = 1
WHERE u.object_schema NOT IN ('mysql', 'performance_schema', 'information_schema', 'sys')
  AND u.object_schema = DATABASE()
  AND s.NON_UNIQUE = 1
ORDER BY u.object_name;
```

> **Note:** `sys.schema_unused_indexes` requires MySQL 8.0+ and the `sys` schema. On MySQL 5.7, use `performance_schema.table_io_waits_summary_by_index_usage` instead (see alternative below).

**MySQL 5.7 alternative** (using `performance_schema`):

```sql
SELECT
    s.INDEX_NAME                          AS index_name,
    s.OBJECT_NAME                         AS table_name,
    COALESCE(st.COLUMN_NAME, s.INDEX_NAME) AS column_name,
    s.COUNT_STAR                          AS index_scans
FROM performance_schema.table_io_waits_summary_by_index_usage s
JOIN information_schema.STATISTICS st
       ON st.TABLE_SCHEMA = s.OBJECT_SCHEMA
      AND st.TABLE_NAME   = s.OBJECT_NAME
      AND st.INDEX_NAME   = s.INDEX_NAME
      AND st.SEQ_IN_INDEX = 1
WHERE s.OBJECT_SCHEMA NOT IN ('mysql', 'performance_schema', 'information_schema', 'sys')
  AND s.OBJECT_SCHEMA = DATABASE()
  AND s.INDEX_NAME IS NOT NULL
  AND s.INDEX_NAME != 'PRIMARY'
  AND st.NON_UNIQUE = 1
  AND s.COUNT_STAR = 0
ORDER BY s.OBJECT_NAME;
```

---

#### Sequential / Full Table Scans (`seq_scans.csv`)

**PostgreSQL:**

```sql
SELECT relname AS table_name, seq_scan, seq_tup_read, n_live_tup
FROM pg_stat_user_tables
WHERE schemaname = 'public' AND seq_scan > 0
ORDER BY seq_scan DESC;
```

**MySQL:**

```sql
SELECT
    t.TABLE_NAME                              AS table_name,
    s.COUNT_READ                              AS seq_scan,
    s.COUNT_READ                              AS seq_tup_read,
    t.TABLE_ROWS                              AS n_live_tup
FROM performance_schema.table_io_waits_summary_by_table s
JOIN information_schema.TABLES t
     ON t.TABLE_SCHEMA = s.OBJECT_SCHEMA
    AND t.TABLE_NAME   = s.OBJECT_NAME
WHERE s.OBJECT_SCHEMA NOT IN ('mysql', 'performance_schema', 'information_schema', 'sys')
  AND s.OBJECT_SCHEMA = DATABASE()
  AND s.COUNT_READ > 0
ORDER BY s.COUNT_READ DESC;
```

> **Tip:** MySQL does not track sequential scans the same way PostgreSQL does. The `COUNT_READ` from `table_io_waits_summary_by_table` counts total row reads (including index reads). For a more accurate picture, subtract `SUM_NO_INDEX_USED` from the `events_statements_summary_by_digest` table (see slow queries below).

---

#### Slow Queries (`slow_queries.csv`)

**PostgreSQL:**

```sql
SELECT query, calls,
       ROUND((total_exec_time / NULLIF(calls, 0))::numeric, 2) AS avg_duration_ms,
       total_exec_time
FROM pg_stat_statements
WHERE calls >= 3
  AND query NOT ILIKE '%pg_stat%'
ORDER BY avg_duration_ms DESC
LIMIT 200;
```

**MySQL:**

```sql
SELECT
    DIGEST_TEXT                               AS query,
    COUNT_STAR                                AS calls,
    ROUND(AVG_TIMER_WAIT / 1000000000, 2)    AS avg_duration_ms,
    ROUND(SUM_TIMER_WAIT / 1000000000, 2)    AS total_exec_time
FROM performance_schema.events_statements_summary_by_digest
WHERE SCHEMA_NAME NOT IN ('mysql', 'performance_schema', 'information_schema', 'sys')
  AND (SCHEMA_NAME = DATABASE() OR SCHEMA_NAME IS NULL)
  AND COUNT_STAR >= 3
ORDER BY avg_duration_ms DESC
LIMIT 200;
```

> **Note:** MySQL `performance_schema` timers are in **picoseconds** — divide by `1,000,000,000` to get milliseconds. If `performance_schema` is disabled, enable it in `my.cnf`:
>
> ```ini
> [mysqld]
> performance_schema = ON
> ```
>
> Restart MySQL and let it collect data for a few minutes before exporting.

### Step 2 — Import on your local machine

```bash
php artisan index-advisor:import-stats unused_indexes.csv
php artisan index-advisor:import-stats seq_scans.csv
php artisan index-advisor:import-stats slow_queries.csv
```

The command auto-detects the file type by inspecting column headers.

### Step 3 — Run in pure CSV mode

```bash
php artisan index-advisor:run \
  --skip-code-analysis \
  --skip-local-db \
  --skip-explain \
  --report-only
```

| Flag                   | What it skips                                                        |
| ---------------------- | -------------------------------------------------------------------- |
| `--skip-code-analysis` | PHP static scan of `app/` (use when relying on imported CSV)         |
| `--skip-local-db`      | `pg_stat_user_indexes`, `pg_stat_statements`, slow log from local DB |
| `--skip-explain`       | EXPLAIN step (local DB may not have same tables/data)                |
| `--report-only`        | Migration file generation                                            |

> **Important:** Without `--skip-local-db`, Step 2 of the pipeline will re-query your local `pg_stat_user_indexes` and overwrite the DROP candidates you imported from CSV.

---

## All Artisan Commands

### `index-advisor:run` — Full Pipeline

```bash
php artisan index-advisor:run [options]
```

| Option                 | Description                                                              |
| ---------------------- | ------------------------------------------------------------------------ |
| `--report-only`        | Skip migration file generation (Step 6)                                  |
| `--skip-explain`       | Skip EXPLAIN step (Step 3) — faster for large databases                  |
| `--skip-code-analysis` | Skip PHP static scan (Step 1) — use with imported CSV data               |
| `--skip-local-db`      | Skip local DB telemetry ingestion (Step 2) — preserves imported CSV data |

**Pipeline steps:**

```
[1/5] index-advisor:analyze-code       ← skipped with --skip-code-analysis
[2/5] index-advisor:ingest-slow-log    ← skipped with --skip-local-db
[3/5] index-advisor:run-explain        ← skipped with --skip-explain
[4/5] ScoringService::score()
[5/5] index-advisor:report
[6]   index-advisor:generate-migrations ← skipped with --report-only
```

---

### `index-advisor:analyze-code` — Static PHP Scan

```bash
php artisan index-advisor:analyze-code
```

Scans `.php` files under configurable directories (default: `app/`). Set scan roots via `.env`:

```env
INDEX_ADVISOR_CODE_PATHS=app,src,modules/Billing
```

Paths may be absolute or relative to the Laravel project root. The command prints the resolved paths before scanning.

Uses regex patterns to extract column names from Eloquent/Query Builder calls and raw SQL strings. Detected patterns:

```
->where('col', ...)        → query_type: where
->orWhere('col', ...)      → query_type: orWhere
->join('t', 't.col', ...)  → query_type: join
->orderBy('col')           → query_type: orderBy
->groupBy('col')           → query_type: groupBy
->having('col', ...)       → query_type: having
->whereIn('col', [...])    → query_type: whereIn
WHERE col =                → query_type: rawWhere
ORDER BY col               → query_type: rawOrder
```

Results stored in `index_advisor_columns`.

---

### `index-advisor:ingest-slow-log` — DB Telemetry

```bash
php artisan index-advisor:ingest-slow-log [options]
```

| Option                  | Description                                                           |
| ----------------------- | --------------------------------------------------------------------- |
| `--skip-unused-indexes` | Do not read `pg_stat_user_indexes` (preserves imported CSV DROP data) |

Ingests from:

- **PostgreSQL:** `pg_stat_statements`, `pg_stat_user_tables`, `pg_stat_user_indexes`
- **MySQL:** slow query log file + `performance_schema`
- **SQL Server:** `sys.dm_exec_query_stats` DMV
- **SQLite:** syncs `index_advisor_queries` → `index_advisor_query_stats`

---

### `index-advisor:import-stats` — Import Production CSV/JSON

```bash
php artisan index-advisor:import-stats {file}
```

Imports production database statistics exported as CSV or JSON. Auto-detects file type:

| Detected type    | Required columns                                | Stored in                                             |
| ---------------- | ----------------------------------------------- | ----------------------------------------------------- |
| `unused-indexes` | `index_name`, `table_name`, `column_name`       | `index_advisor_recommendations` (DROP)                |
| `seq-scans`      | `table_name`, `seq_scan`                        | `index_advisor_query_stats`                           |
| `queries`        | `query`/`sql_query`, `calls`, `avg_duration_ms` | `index_advisor_queries` + `index_advisor_query_stats` |

---

### `index-advisor:run-explain` — EXPLAIN Runner

```bash
php artisan index-advisor:run-explain [--limit=50]
```

| Option      | Description                                |
| ----------- | ------------------------------------------ |
| `--limit=N` | Number of queries to EXPLAIN (default: 50) |

Reads top N slowest queries from `index_advisor_queries`, runs EXPLAIN, detects full table scans, stores results in `index_advisor_explains`.

---

### `index-advisor:report` — Console + Email Report

```bash
php artisan index-advisor:report [--email=address]
```

| Option         | Description                                     |
| -------------- | ----------------------------------------------- |
| `--email=addr` | Override the `report_email` config for this run |

Prints all recommendations sorted by score. Optionally emails an HTML report with color-coded severity.

---

### `index-advisor:generate-migrations` — Create Migration Files

```bash
php artisan index-advisor:generate-migrations [options]
```

| Option        | Description                                                             |
| ------------- | ----------------------------------------------------------------------- |
| `--score=N`   | Override minimum score threshold (default: `auto_migrate_score` config) |
| `--type=TYPE` | Filter by type: `INDEX`, `COMPOSITE`, or `DROP`                         |
| `--dry-run`   | Print DDL without writing files                                         |
| `--ids=1,2,3` | Generate only for specific recommendation IDs                           |

Generates Laravel migration files with safe, production-ready DDL:

- MySQL: `ALTER TABLE ... ADD INDEX ... ALGORITHM=INPLACE, LOCK=NONE`
- PostgreSQL: `CREATE INDEX CONCURRENTLY IF NOT EXISTS ...`
- SQL Server: `CREATE INDEX ... WITH (ONLINE=ON)`

---

### `index-advisor:drop-unused` — DROP INDEX Migrations

```bash
php artisan index-advisor:drop-unused [--dry-run]
```

| Option      | Description                     |
| ----------- | ------------------------------- |
| `--dry-run` | Print DDL without writing files |

Generates DROP INDEX migration files for recommendations with `index_type = DROP`. Supports both **PostgreSQL** and **MySQL**.

Generated migrations use `$withinTransaction = false` only for PostgreSQL (required for `DROP INDEX CONCURRENTLY`). MySQL migrations retain full transaction safety.

---

### `index-advisor:dismiss` — Dismiss Recommendations

```bash
php artisan index-advisor:dismiss [id] [options]
```

| Argument/Option   | Description                                           |
| ----------------- | ----------------------------------------------------- |
| `{id}`            | ID of the recommendation to dismiss                   |
| `--reason="..."`  | Reason stored in evidence JSON                        |
| `--all-redundant` | Dismiss all `REDUNDANT_CHECK` recommendations at once |

---

### `index-advisor:mark-applied` — Mark as Applied

```bash
php artisan index-advisor:mark-applied [id] [options]
```

| Argument/Option   | Description                                     |
| ----------------- | ----------------------------------------------- |
| `{id}`            | ID of the recommendation to mark as applied     |
| `--all-generated` | Mark all `generated` recommendations as applied |

Run this after `php artisan migrate` to update recommendation statuses.

---

### `index-advisor:reconcile` — Sync with Live Schema

```bash
php artisan index-advisor:reconcile [options]
```

| Option          | Description                                              |
| --------------- | -------------------------------------------------------- |
| `--table=name`  | Reconcile only this table (case-insensitive)             |
| `--column=name` | Reconcile only this column (must combine with `--table`) |

Re-reads live database indexes and dismisses stale recommendations. **Run this after manually dropping or adding an index** without going through the full pipeline.

```bash
# After dropping an index on lytLoginUsr:
php artisan index-advisor:reconcile --table=lytLoginUsr

# After dropping a specific column's index:
php artisan index-advisor:reconcile --table=lytLoginUsr --column=userLyId

# Reconcile everything:
php artisan index-advisor:reconcile
```

---

### `index-advisor:purge` — Data Retention Cleanup

```bash
php artisan index-advisor:purge [options]
```

| Option     | Description                                                  |
| ---------- | ------------------------------------------------------------ |
| `--days=N` | Override retention period (default: `retention_days` config) |
| `--all`    | Truncate ALL rows from ALL five tables (full reset)          |
| `--force`  | Skip confirmation prompt when using `--all`                  |

> **Note:** By default, `pending` and `generated` recommendations are **never purged**. Only `applied` and `dismissed` rows older than the retention period are deleted.

```bash
# Age-based purge (default)
php artisan index-advisor:purge

# Delete rows older than 7 days
php artisan index-advisor:purge --days=7

# Full reset (truncate everything)
php artisan index-advisor:purge --all

# Full reset without confirmation (CI/scripts)
php artisan index-advisor:purge --all --force
```

---

### `index-advisor:diagnose` — Debug Missing Recommendations

```bash
php artisan index-advisor:diagnose [--table=name]
```

| Option         | Description                         |
| -------------- | ----------------------------------- |
| `--table=name` | Focus diagnosis on a specific table |

Explains why `COMPOSITE` or `REDUNDANT_CHECK` recommendations are not appearing. Checks:

- Table row counts
- `table_name = unknown` inference failures
- Which columns are already indexed (for REDUNDANT_CHECK)
- Which runtime SQL samples have multi-column WHERE clauses (for COMPOSITE)

---

### `index-advisor:status` — Check Logging Status

```bash
php artisan index-advisor:status
```

Shows whether HTTP query logging is active, the current profile, DB driver, and row counts for all five tables.

---

## Pipeline Steps Explained

### Step 1 — Static Code Analysis (`analyze-code`)

Reads `.php` files from `INDEX_ADVISOR_CODE_PATHS` (default: `app/`) and extracts column names from Eloquent/Query Builder patterns. Results go into `index_advisor_columns`. Table names are inferred from model class names or the `table_map` config.

### Step 2 — DB Telemetry Ingestion (`ingest-slow-log`)

Reads actual query performance data from the database engine:

- **PostgreSQL:** `pg_stat_statements` (query stats) → writes to both `index_advisor_queries` and `index_advisor_query_stats`. `pg_stat_user_indexes` (unused indexes) → writes DROP candidates directly to `index_advisor_recommendations`.
- **MySQL:** slow query log + `performance_schema`
- **SQL Server:** `sys.dm_exec_query_stats` DMV

### Step 3 — EXPLAIN Analysis (`run-explain`)

Reads top N queries from `index_advisor_queries` and runs `EXPLAIN FORMAT=JSON`. Detects full table scans (`Seq Scan` / `access_type: ALL` / `Table Scan`). Results stored in `index_advisor_explains`.

### Step 4 — Scoring (`ScoringService`)

Correlates data from all four input tables using `SqlColumnMatcher` (clause-aware SQL parsing — not naive `LIKE '%col%'`).

**Score breakdown (max 100):**

| Signal                       | Points | Condition                                |
| ---------------------------- | ------ | ---------------------------------------- |
| Execution frequency          | 0–30   | `log10(executions+1) / log10(1001) * 30` |
| Slow average duration        | +25    | `avg_ms > slow_query_ms`                 |
| Max duration spike           | +5     | `max_ms > slow_query_ms × 3`             |
| Full table scan              | +20    | EXPLAIN detected `Seq Scan` / `ALL`      |
| WHERE / JOIN / orWhere       | +10    | Query clause type                        |
| ORDER BY / GROUP BY / HAVING | +5     | Query clause type                        |
| FK heuristic (`_id` suffix)  | +5     | Column name pattern                      |
| No existing index            | +5     | Live schema check                        |

**Filters applied before scoring:**

- Table must have ≥ `min_table_rows` rows (default 5000 in production)
- Column must have ≥ `min_cardinality` distinct values (default 25 in production)
- Score must be ≥ `min_score` (default 20 in production)

**COMPOSITE detection:** Finds queries where 2+ columns from the same table appear in the WHERE/JOIN clause. Also parses runtime SQL directly to find multi-column predicates.

### Step 5 — Report (`report`)

Prints a table of all recommendations sorted by score. Shows scoring evidence when expanded in the dashboard.

### Step 6 — Generate Migrations (`generate-migrations`)

Creates Laravel migration files for recommendations with `score >= auto_migrate_score`. Updates status to `generated`.

---

## Dashboard

Open in browser: `http://your-app.test/index-advisor`

The dashboard is **disabled by default** for security. Enable it explicitly:

```env
INDEX_ADVISOR_DASHBOARD_ENABLED=true
```

The dashboard is protected by default middleware (`web`, `auth`, `can:viewIndexAdvisor`). The built-in gate only allows access in `local` and `development` environments. Publish the service provider to customize authorization (see [Installation step 2b](#2b-publish-service-provider-optional--for-dashboard-access-control)).

The dashboard provides:

- **Recommendations** — scored list with evidence breakdown, correlated SQL, and EXPLAIN plans
- **Migrations** — select specific recommendations and generate migration files
- **Query Log** — runtime queries with execution count, avg/max duration, and full-scan indicator
- **Overview** — row counts for all 5 tables plus environment info
- **Upload** — import production CSV/JSON statistics files directly from the browser

**Dashboard actions:**

- **Run Analysis** button — triggers `index-advisor:run --report-only --skip-explain`
- **Generate Migrations** button — triggers `index-advisor:generate-migrations`
- **Dismiss** — dismisses a recommendation with a reason
- **Mark Applied** — marks a recommendation as applied

### SQL Sample Redaction

By default, SQL samples are **redacted** in the dashboard and API responses to prevent exposing sensitive data (e.g. PII in WHERE clauses). To show full SQL:

```env
INDEX_ADVISOR_EXPOSE_SQL=true
```

In non-production environments (`local`, `development`), this defaults to `true`. In production, it defaults to `false`.

### API Pagination

The `/api/recommendations` endpoint supports optional pagination:

```
GET /index-advisor/api/recommendations?per_page=25
```

When `per_page` is provided, the response includes `pagination` metadata (current_page, last_page, total). Without `per_page`, all recommendations are returned (backward-compatible).

### Self-Referential Query Prevention

Dashboard routes include the `PreventIndexAdvisorLogging` middleware, which marks requests so the query listener skips logging. This prevents the dashboard's own queries from being captured and creating a feedback loop.

To change the dashboard URL path:

```env
INDEX_ADVISOR_PATH=my-index-advisor
```

---

## Logging Configuration

Smart Index Advisor logs warnings and errors to a configurable channel and level:

```env
# Use a dedicated log channel to avoid flooding the main application log
INDEX_ADVISOR_LOG_CHANNEL=index-advisor

# Minimum log level: debug, info, notice, warning, error, critical
INDEX_ADVISOR_LOG_LEVEL=warning
```

When `INDEX_ADVISOR_LOG_CHANNEL` is not set (or `null`), the package uses the application's default log channel.

**What gets logged:**

| Event                        | Level                            | Condition                                                           |
| ---------------------------- | -------------------------------- | ------------------------------------------------------------------- |
| Unknown profile fallback     | `warning`                        | `INDEX_ADVISOR_PROFILE` is set to a value not in the profiles array |
| Schema introspection failure | Configurable (default `warning`) | Any `\Throwable` caught during schema introspection                 |
| Query listener error         | `error`                          | Any `\Throwable` caught during buffered query flushing              |
| Explain plan storage error   | `error`                          | Any `\Throwable` caught during explain plan writing                 |

---

## Recommendation Status Lifecycle

```
pending  →  generated  →  applied
   ↓
dismissed
```

| Status      | Meaning                            | Purged?                   |
| ----------- | ---------------------------------- | ------------------------- |
| `pending`   | Found by scoring, not yet acted on | ❌ Never                  |
| `generated` | Migration file created             | ❌ Never                  |
| `applied`   | Migration was run                  | ✅ After `retention_days` |
| `dismissed` | Manually or auto-dismissed         | ✅ After `retention_days` |

**Four recommendation types:**

| Type              | Meaning                                             | Source                                    |
| ----------------- | --------------------------------------------------- | ----------------------------------------- |
| `INDEX`           | Column needs a new single-column index              | ScoringService                            |
| `COMPOSITE`       | 2+ columns appear together in queries               | ScoringService                            |
| `REDUNDANT_CHECK` | Column already has an index (informational)         | ScoringService                            |
| `DROP`            | Existing index has never been used (`idx_scan = 0`) | IngestSlowLogCommand / ImportStatsCommand |

---

## Scheduler Setup

Add to `app/Console/Kernel.php` (Laravel 10) or `routes/console.php` (Laravel 11+):

```php
// Laravel 10 — app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    // Automatically purge old applied/dismissed recommendations
    $schedule->command('index-advisor:purge')->weekly()->sundays()->at('02:00');

    // Only if report_email is configured:
    $schedule->command('index-advisor:report')->weekly()->mondays()->at('08:00');
}
```

```php
// Laravel 11+ — routes/console.php
use Illuminate\Support\Facades\Schedule;

// Automatically purge old applied/dismissed recommendations
Schedule::command('index-advisor:purge')->weekly()->sundays()->at('02:00');

// Only if report_email is configured:
Schedule::command('index-advisor:report')->weekly()->mondays()->at('08:00');
```

The package also registers these automatically via `callAfterResolving(Schedule::class, ...)`.

---

## Table Name Mapping

Your project uses abbreviated model names (e.g. `LytLoginUsr`, `LymLeadMstr`). The code analyzer tries to infer the DB table name from the PHP file name. When inference fails, the row is stored with `table_name = 'unknown'`.

Add manual overrides in `config/index_advisor.php`:

```php
'table_map' => [
    'LymLeadMstr'   => 'lym_lead_mstrs',
    'LytLoginUsr'   => 'lytLoginUsr',     // mixed-case tables use exact name
    'CtmInfluncr'   => 'ctmInfluncr',
    'LymInfluncr'   => 'lymInfluncr',
    'LytCodeHsty'   => 'lytCodeHsty',
    // add more as needed ...
],
```

After adding entries, re-run the code analyzer:

```bash
php artisan index-advisor:analyze-code
```

---

## Troubleshooting

### `index_advisor_queries` is empty after running the pipeline

`DB::listen` only fires during HTTP requests, not during `artisan` commands. The pipeline bridges this by reading from `pg_stat_statements`.

**Fix:**

```bash
# Ensure pg_stat_statements is enabled in PostgreSQL:
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;

# Then ingest:
php artisan index-advisor:ingest-slow-log
```

---

### Postman requests not populating `index_advisor_queries`

Most likely cause: **Telescope is enabled and recording**. By default the listener still logs queries when Telescope is recording. To skip logging during Telescope recording:

```env
INDEX_ADVISOR_SKIP_WHEN_TELESCOPE=true
```

If queries are still not appearing, ensure runtime logging is enabled:

```env
INDEX_ADVISOR_ENABLED=true
```

```bash
php artisan config:clear
```

> **Note:** Queries are buffered during the HTTP request and flushed after the response is sent (via terminable middleware). If the process crashes before `terminate()` runs, buffered queries may be lost.

---

### Report shows only DROP, no INDEX or COMPOSITE

`index_advisor_queries` is empty — the scoring engine has no runtime data to correlate.

```bash
php artisan index-advisor:ingest-slow-log   # populate from pg_stat_statements
php artisan index-advisor:run --report-only  # re-score
```

---

### Dropped an index but recommendation still shows REDUNDANT_CHECK

The reconciler uses cached schema data per process. Run:

```bash
php artisan index-advisor:reconcile --table=yourTableName
```

Or for a specific column:

```bash
php artisan index-advisor:reconcile --table=lytLoginUsr --column=userLyId
```

---

### `purge` command not clearing recommendations

By design — `pending` recommendations are never purged. To clear everything:

```bash
# Option 1: Dismiss first, then purge
php artisan index-advisor:dismiss --all-redundant
php artisan index-advisor:purge --days=0

# Option 2: Full truncate (all five tables)
php artisan index-advisor:purge --all --force
```

---

### Table name shows as `unknown`

The code analyzer could not infer the table from the file name. Add it to `table_map` in config:

```php
'table_map' => [
    'YourModelName' => 'your_actual_table',
],
```

---

### COMPOSITE recommendations not appearing

Run the diagnose command to see exactly why:

```bash
php artisan index-advisor:diagnose
php artisan index-advisor:diagnose --table=lym_lead_mstrs
```

Common reasons:

1. `index_advisor_queries` is empty (no runtime data)
2. Your queries only filter on one column at a time
3. The table has fewer than `min_table_rows` rows
4. A composite index already exists

---

### pg_stat_statements not available

```sql
-- Run in psql:
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
```

Add to `postgresql.conf`:

```
shared_preload_libraries = 'pg_stat_statements'
```

Restart PostgreSQL, use the app for a few minutes to generate query history, then:

```bash
php artisan index-advisor:ingest-slow-log
```

---

### Resetting Database Statistics

If you want to clear your database's internal statistics (to start tracking query performance from scratch after an index change or server upgrade), run the following queries:

**PostgreSQL:**

```sql
-- Check when stats started collecting (formatted as DDMMYY HH:MM:SS)
SELECT to_char(stats_reset, 'DDMMYY HH24:MI:SS') AS stats_started_at
FROM pg_stat_database
WHERE datname = current_database();

-- Reset pg_stat_statements (slow query history)
SELECT pg_stat_statements_reset();

-- Reset pg_stat_user_tables and pg_stat_user_indexes (scans and unused indexes)
SELECT pg_stat_reset();
```

**MySQL:**

```sql
-- Check when stats started collecting (formatted as DDMMYY HH:MM:SS)
-- (MySQL resets performance_schema automatically on server restart)
SELECT DATE_FORMAT(DATE_SUB(NOW(), INTERVAL variable_value SECOND), '%d%m%y %H:%i:%s') AS stats_started_at
FROM performance_schema.global_status
WHERE variable_name = 'UPTIME';

-- Cross-verify the exact timestamps of the oldest and newest captured queries
SELECT
    MIN(FIRST_SEEN) AS oldest_statement_seen,
    MAX(LAST_SEEN) AS latest_statement_seen
FROM performance_schema.events_statements_summary_by_digest;

-- Reset performance_schema metrics manually without restarting
TRUNCATE TABLE performance_schema.events_statements_summary_by_digest;
TRUNCATE TABLE performance_schema.table_io_waits_summary_by_index_usage;
TRUNCATE TABLE performance_schema.table_io_waits_summary_by_table;
```

---

## Command Quick Reference

```bash
# Full pipeline
php artisan index-advisor:run

# Full pipeline — skip migration generation
php artisan index-advisor:run --report-only

# Full pipeline — skip EXPLAIN (faster)
php artisan index-advisor:run --skip-explain

# Pure CSV import mode (use only imported production data)
php artisan index-advisor:run --skip-code-analysis --skip-local-db --skip-explain --report-only

# Individual steps
php artisan index-advisor:analyze-code
php artisan index-advisor:ingest-slow-log
php artisan index-advisor:ingest-slow-log --skip-unused-indexes
php artisan index-advisor:run-explain
php artisan index-advisor:run-explain --limit=100
php artisan index-advisor:report
php artisan index-advisor:report --email=dba@example.com

# Import production CSV data
php artisan index-advisor:import-stats unused_indexes.csv
php artisan index-advisor:import-stats seq_scans.csv
php artisan index-advisor:import-stats slow_queries.csv

# Generate migrations
php artisan index-advisor:generate-migrations
php artisan index-advisor:generate-migrations --score=60
php artisan index-advisor:generate-migrations --type=COMPOSITE
php artisan index-advisor:generate-migrations --dry-run

# DROP unused indexes
php artisan index-advisor:drop-unused
php artisan index-advisor:drop-unused --dry-run

# Status & lifecycle
php artisan index-advisor:status
php artisan index-advisor:diagnose
php artisan index-advisor:diagnose --table=lym_lead_mstrs
php artisan index-advisor:reconcile
php artisan index-advisor:reconcile --table=lytLoginUsr
php artisan index-advisor:reconcile --table=lytLoginUsr --column=userLyId
php artisan index-advisor:dismiss 42
php artisan index-advisor:dismiss 42 --reason="Low cardinality column"
php artisan index-advisor:dismiss --all-redundant
php artisan index-advisor:mark-applied 42
php artisan index-advisor:mark-applied --all-generated
php artisan index-advisor:purge
php artisan index-advisor:purge --days=7
php artisan index-advisor:purge --all --force
```

---

## Performance & Reliability

### Buffered Query Logging

Runtime query logging uses an **in-memory buffer** instead of synchronous DB writes. During each HTTP request:

1. `RuntimeQueryListener::handle()` aggregates queries by fingerprint in memory (merging execution counts and durations)
2. After the response is sent to the client, `FlushQueryBufferMiddleware::terminate()` flushes all buffered queries in a single batch write

This reduces DB writes from **2 per query** (1 query insert + 1 explain insert) to **1 batch per request**, significantly reducing latency overhead.

### Idempotent Migrations

The `create_index_advisor_tables` migration is idempotent — each `Schema::create` call is guarded by a `Schema::hasTable()` check. If the migration fails partway through, re-running it will skip already-created tables instead of throwing an error.

> **Migration timestamp note:** Package migrations use 2026 timestamps. If your application has existing migrations from the same year, you may need to rename the published migration file so it sorts correctly. After running `vendor:publish`, rename if needed (e.g., `2026_01_01_000000_create_index_advisor_tables.php`).

### Conditional Transaction Safety

Generated DROP INDEX migrations only set `$withinTransaction = false` for **PostgreSQL** (required for `DROP INDEX CONCURRENTLY`). MySQL and other drivers retain full transaction safety, ensuring that if a DROP fails, it rolls back cleanly.

### Post-Scoring Deduplication

Composite recommendation deduplication runs **after** all scoring is complete, not inside the scoring hot path. This means duplicates from the current run are collapsed immediately, and the scoring loop itself is not slowed down by a full-table dedup scan.

### Selective Stale Cleanup

Runtime composite scoring uses selective stale cleanup instead of a delete-all-then-reinsert pattern. Only recommendations that were not regenerated in the current scoring run are deleted, avoiding unnecessary write load on large tables.

### Composite Index Query Limit

You can control how many queries the system attempts to parse for complex multi-column relationships via your `.env` or config file:

```env
INDEX_ADVISOR_RUNTIME_COMPOSITE_LIMIT=1000
```

If you set the limit to `1000`, it will analyze the **Top 1000** most frequently executed queries and skip the rest.

Here is exactly how the code works behind the scenes when looking for composite indexes:

1. It takes all the raw SQL queries your application ran.
2. It sorts them from highest to lowest based on execution count (`orderByDesc('execution_count')`).
3. It takes the top 1,000 queries.
4. Any query that ranks #1001 or lower is completely ignored for the composite calculation.

**Why does it skip the rest?**
Calculating composite indexes (where the AI has to parse the raw SQL string, find the `WHERE` clauses, split multiple `AND` conditions, and match them against the tables) is highly intensive for PHP.

If your database has 50,000 unique queries and the package tried to parse all 50,000 in memory at once, your server would likely crash or run out of RAM (`Allowed memory size exhausted`).

By taking the Top 1000 most frequently executed queries, the package guarantees that it is only spending its computing power finding composite indexes for the queries that run the most often, which are the ones that actually matter for your application's speed!

---

## License

MIT — © Kombee Technologies
