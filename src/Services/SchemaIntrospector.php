<?php

namespace Kombee\IndexAdvisor\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kombee\IndexAdvisor\Contracts\SchemaIntrospectorContract;

/**
 * Queries the live database engine to discover existing indexes, tables,
 * and unused indexes. Supports MySQL, PostgreSQL, MS SQL Server, and SQLite.
 */
class SchemaIntrospector implements SchemaIntrospectorContract
{
    private ?array $tables = null;

    private array $tableColumns = [];

    private string $pgSchema;

    private ?SqliteIntrospection $sqlite = null;

    private ?SchemaStatisticsReader $stats = null;

    public function __construct()
    {
        $this->pgSchema = config('index_advisor.pg_schema', 'public');
    }

    /**
     * Clear cached table/column lists (call before re-reading live schema).
     */
    public function resetCache(): void
    {
        $this->tables = null;
        $this->tableColumns = [];
    }

    /**
     * Clear cached column metadata for one table (indexes are always read from the database).
     */
    public function forgetTable(string $table): void
    {
        $resolved = $this->resolveTableName($table);

        if ($resolved !== null) {
            unset($this->tableColumns[$resolved]);
        }
    }

    /**
     * Canonical DB table name (e.g. lytLoginUsr, not lyt_login_usr).
     */
    public function canonicalTableName(string $table): ?string
    {
        return $this->resolveTableName($table);
    }

    /**
     * Resolve a table name to the exact name returned by the database (case-sensitive).
     */
    public function resolveTableName(string $table): ?string
    {
        if ($this->tables === null) {
            $this->tables = $this->getAllTables();
        }

        if (in_array($table, $this->tables, true)) {
            return $table;
        }

        foreach ($this->tables as $known) {
            if (strcasecmp($known, $table) === 0) {
                return $known;
            }
        }

        return null;
    }

    private function sqlite(): SqliteIntrospection
    {
        return $this->sqlite ??= new SqliteIntrospection;
    }

    private function stats(): SchemaStatisticsReader
    {
        return $this->stats ??= new SchemaStatisticsReader($this->pgSchema);
    }

    /**
     * Check if a table AND a specific column actually exist in the database.
     */
    public function hasTableAndColumn(string $table, string $column): bool
    {
        $resolved = $this->resolveTableName($table);

        if ($resolved === null) {
            return false;
        }

        if (! isset($this->tableColumns[$resolved])) {
            $this->tableColumns[$resolved] = $this->getColumnsForTable($resolved);
        }
        
        $sorted = array_map([\Kombee\IndexAdvisor\Helpers\NameNormalizer::class, 'normalize'], $this->tableColumns[$resolved]);

        return in_array(\Kombee\IndexAdvisor\Helpers\NameNormalizer::normalize($column), $sorted, true);
    }

    /**
     * Resolve a column name to its exact database casing case-insensitively.
     */
    public function canonicalColumnName(string $table, string $column): ?string
    {
        $resolved = $this->resolveTableName($table);
        if ($resolved === null) {
            return null;
        }

        if (! isset($this->tableColumns[$resolved])) {
            $this->tableColumns[$resolved] = $this->getColumnsForTable($resolved);
        }

        foreach ($this->tableColumns[$resolved] as $known) {
            if (strcasecmp($known, $column) === 0) {
                return $known;
            }
        }

        return null;
    }

    /**
     * Return true when any valid index on the table includes the given column.
     */
    public function isColumnIndexed(string $table, string $column): bool
    {
        return $this->getColumnIndexDetails($table, $column) !== [];
    }

    /**
     * Always hits the database (no in-process index cache). Use in reconciliation.
     */
    public function isColumnIndexedFresh(string $table, string $column): bool
    {
        $this->forgetTable($table);

        return $this->isColumnIndexed($table, $column);
    }

    /**
     * @return array<int, array{index_name: string, is_primary: bool, is_unique: bool}>
     */
    public function getColumnIndexDetailsFresh(string $table, string $column): array
    {
        $this->forgetTable($table);

        return $this->getColumnIndexDetails($table, $column);
    }

    /**
     * Indexes in the live database that include this column (valid / ready only).
     *
     * @return array<int, array{index_name: string, is_primary: bool, is_unique: bool}>
     */
    public function getColumnIndexDetails(string $table, string $column): array
    {
        $details = [];

        foreach ($this->getExistingIndexes($table) as $idx) {
            if (\Kombee\IndexAdvisor\Helpers\NameNormalizer::normalize($idx->column_name ?? '') !== \Kombee\IndexAdvisor\Helpers\NameNormalizer::normalize($column)) {
                continue;
            }

            $details[] = [
                'index_name' => (string) ($idx->index_name ?? ''),
                'is_primary' => (bool) ($idx->indisprimary ?? $idx->is_primary ?? false),
                'is_unique' => (bool) ($idx->indisunique ?? $idx->is_unique ?? false),
            ];
        }

        return $details;
    }

    /**
     * Return true when a named index exists on the table (any column).
     */
    public function indexExistsByName(string $table, string $indexName): bool
    {
        foreach ($this->getExistingIndexes($table) as $idx) {
            if (strcasecmp($idx->index_name ?? '', $indexName) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return all indexes currently defined on a given table.
     */
    public function getExistingIndexes(string $table): array
    {
        $resolved = $this->resolveTableName($table);

        if ($resolved === null) {
            return [];
        }

        try {
            return match (DB::getDriverName()) {
                'mysql' => $this->mysqlIndexes($resolved),
                'pgsql' => $this->pgsqlIndexes($resolved),
                'sqlsrv' => $this->mssqlIndexes($resolved),
                'sqlite' => $this->sqlite()->getIndexes($resolved),
                default => [],
            };
        } catch (\Throwable $e) {
            $this->logSchemaIntrospectionFailure('getExistingIndexes', $table, $e);

            return [];
        }
    }

    /**
     * Return all user-created table names in the current database / schema.
     */
    public function getAllTables(): array
    {
        try {
            return match (DB::getDriverName()) {
                'mysql' => array_map(
                    fn ($row) => current((array) $row),
                    DB::select('SHOW TABLES')
                ),
                'pgsql' => array_column(
                    DB::select(
                        'SELECT tablename FROM pg_tables WHERE schemaname = ?',
                        [$this->pgSchema]
                    ),
                    'tablename'
                ),
                'sqlsrv' => array_column(
                    DB::select("SELECT name FROM sys.tables WHERE type = 'U'"),
                    'name'
                ),
                'sqlite' => $this->sqlite()->getAllTables(),
                default => [],
            };
        } catch (\Throwable $e) {
            $this->logSchemaIntrospectionFailure('getAllTables', null, $e);

            return [];
        }
    }

    /**
     * Return PostgreSQL indexes with idx_scan = 0 (unused indexes).
     * These are candidates for DROP INDEX.
     *
     * @return array<object{index_name: string, table_name: string, column_name: string, idx_scan: int}>
     */
    public function getUnusedPgsqlIndexes(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }

        try {
            return DB::select('
                SELECT
                    ui.relname          AS index_name,
                    t.relname           AS table_name,
                    a.attname           AS column_name,
                    s.idx_scan
                FROM pg_stat_user_indexes s
                JOIN pg_class ui         ON ui.oid = s.indexrelid
                JOIN pg_class t          ON t.oid  = s.relid
                JOIN pg_index ix         ON ix.indexrelid = s.indexrelid
                JOIN pg_attribute a      ON a.attrelid = t.oid
                                        AND a.attnum = ANY(ix.indkey)
                WHERE s.schemaname = ?
                  AND s.idx_scan   = 0
                  AND NOT ix.indisprimary
                  AND NOT ix.indisunique
                ORDER BY t.relname, ui.relname
            ', [$this->pgSchema]);
        } catch (\Throwable $e) {
            $this->logSchemaIntrospectionFailure('getUnusedPgsqlIndexes', null, $e);

            return [];
        }
    }

    /**
     * Return MySQL unused indexes using sys.schema_unused_indexes.
     * These are candidates for DROP INDEX.
     *
     * @return array<object{index_name: string, table_name: string, column_name: string, idx_scan: int}>
     */
    public function getUnusedMysqlIndexes(): array
    {
        if (DB::getDriverName() !== 'mysql') {
            return [];
        }

        try {
            return DB::select("
                SELECT
                    u.index_name,
                    u.object_name AS table_name,
                    COALESCE(s.column_name, u.index_name) AS column_name,
                    0 AS idx_scan
                FROM sys.schema_unused_indexes u
                JOIN information_schema.STATISTICS s
                       ON s.table_schema = u.object_schema
                      AND s.table_name = u.object_name
                      AND s.index_name = u.index_name
                      AND s.seq_in_index = 1
                WHERE u.object_schema NOT IN ('mysql', 'performance_schema', 'information_schema', 'sys')
                  AND u.object_schema = DATABASE()
                  AND s.NON_UNIQUE = 1
            ");
        } catch (\Throwable $e) {
            $this->logSchemaIntrospectionFailure('getUnusedMysqlIndexes', null, $e);

            return [];
        }
    }

    /**
     * Return per-table sequential scan stats from pg_stat_user_tables.
     *
     * @return array<object{table_name: string, seq_scan: int, seq_tup_read: int, n_live_tup: int}>
     */
    public function getPgsqlTableStats(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }

        try {
            return DB::select('
                SELECT
                    relname       AS table_name,
                    seq_scan,
                    seq_tup_read,
                    n_live_tup
                FROM pg_stat_user_tables
                WHERE schemaname = ?
                ORDER BY seq_scan DESC
            ', [$this->pgSchema]);
        } catch (\Throwable $e) {
            $this->logSchemaIntrospectionFailure('getPgsqlTableStats', null, $e);

            return [];
        }
    }

    /**
     * Return the approximate row count for a table.
     * Returns null if the table does not exist or the driver is unsupported.
     */
    public function getTableRowCount(string $table): ?int
    {
        $resolved = $this->resolveTableName($table);

        if ($resolved === null) {
            return null;
        }

        try {
            return match (DB::getDriverName()) {
                'pgsql' => $this->stats()->pgsqlTableRowCount($resolved),
                'mysql' => $this->stats()->mysqlTableRowCount($resolved),
                'sqlsrv' => (int) DB::selectOne(
                    'SELECT SUM(p.rows) AS row_count FROM sys.tables t
                     JOIN sys.partitions p ON t.object_id = p.object_id
                     WHERE t.name = ? AND p.index_id IN (0,1)',
                    [$resolved]
                )?->row_count,
                'sqlite' => $this->sqlite()->getTableRowCount($resolved),
                default => null,
            };
        } catch (\Throwable $e) {
            $this->logSchemaIntrospectionFailure('getTableRowCount', $resolved, $e);

            return null;
        }
    }

    /**
     * Return the approximate number of distinct values for a column.
     * Uses pg_stats for PostgreSQL (fast, no full scan).
     * Returns null if unavailable.
     */
    public function getColumnCardinality(string $table, string $column): ?int
    {
        $resolved = $this->resolveTableName($table);

        if ($resolved === null) {
            return null;
        }

        try {
            return match (DB::getDriverName()) {
                'pgsql' => (function () use ($resolved, $column): ?int {
                    $row = DB::selectOne(
                        'SELECT n_distinct FROM pg_stats
                         WHERE schemaname = ? AND tablename = ? AND attname = ?',
                        [$this->pgSchema, $resolved, $column]
                    );
                    if ($row === null) {
                        $row = DB::selectOne(
                            'SELECT n_distinct FROM pg_stats
                             WHERE schemaname = ? AND tablename = ? AND lower(attname) = lower(?)',
                            [$this->pgSchema, $resolved, $column]
                        );
                    }
                    if ($row === null) {
                        return null;
                    }
                    // n_distinct < 0 means fraction of total rows; convert to absolute
                    $nd = (float) $row->n_distinct;
                    if ($nd < 0) {
                        $rowCount = $this->getTableRowCount($resolved) ?? 1;

                        return (int) abs($nd * $rowCount);
                    }

                    return (int) $nd;
                })(),
                'mysql' => $this->stats()->mysqlColumnCardinality($resolved, $column),
                'sqlite' => $this->sqlite()->getColumnCardinality(
                    $resolved,
                    $column,
                    (int) config('index_advisor.sqlite.cardinality_max_rows', 10000)
                ),
                default => null,
            };
        } catch (\Throwable $e) {
            $this->logSchemaIntrospectionFailure('getColumnCardinality', $resolved, $e);

            return null;
        }
    }

    private function mysqlIndexes(string $table): array
    {
        $rows = DB::select(
            'SELECT
                INDEX_NAME AS Key_name,
                COLUMN_NAME AS Column_name,
                NON_UNIQUE AS Non_unique
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$table]
        );

        return array_map(static function ($row) {
            $row->column_name = $row->Column_name ?? $row->column_name ?? '';
            $row->indisprimary = ($row->Key_name ?? '') === 'PRIMARY';
            $row->indisunique = (int) ($row->Non_unique ?? 1) === 0;
            $row->index_name = $row->Key_name ?? '';

            return $row;
        }, $rows);
    }

    private function pgsqlIndexes(string $table): array
    {
        return DB::select(
            "SELECT
                i.relname       AS index_name,
                a.attname       AS column_name,
                ix.indisprimary AS indisprimary,
                ix.indisunique  AS indisunique
             FROM pg_class t
             JOIN pg_index ix     ON t.oid = ix.indrelid
             JOIN pg_class i      ON i.oid = ix.indexrelid
             JOIN pg_attribute a  ON a.attrelid = t.oid
                                 AND a.attnum = ANY(ix.indkey)
                                 AND a.attnum > 0
                                 AND NOT a.attisdropped
             WHERE t.relname  = ?
               AND t.relkind  = 'r'
               AND ix.indisvalid = true
               AND ix.indisready = true
               AND t.relnamespace = (
                   SELECT oid FROM pg_namespace WHERE nspname = ?
               )",
            [$table, $this->pgSchema]
        );
    }

    private function mssqlIndexes(string $table): array
    {
        return DB::select(
            'SELECT
                i.name  AS index_name,
                c.name  AS column_name
             FROM sys.indexes i
             JOIN sys.index_columns ic
                  ON i.object_id = ic.object_id AND i.index_id = ic.index_id
             JOIN sys.columns c
                  ON ic.object_id = c.object_id AND ic.column_id = c.column_id
             WHERE OBJECT_NAME(i.object_id) = ?',
            [$table]
        );
    }

    private function getColumnsForTable(string $table): array
    {
        try {
            return match (DB::getDriverName()) {
                'mysql' => array_map(
                    fn ($row) => \Kombee\IndexAdvisor\Helpers\NameNormalizer::normalize($row->Field),
                    DB::select(
                        'SELECT COLUMN_NAME AS Field
                         FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                         ORDER BY ORDINAL_POSITION',
                        [$table]
                    )
                ),
                'pgsql' => array_column(
                    DB::select(
                        'SELECT column_name FROM information_schema.columns
                         WHERE table_schema = ? AND table_name = ?',
                        [$this->pgSchema, $table]
                    ),
                    'column_name'
                ),
                'sqlsrv' => array_column(
                    DB::select(
                        'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ?',
                        [$table]
                    ),
                    'COLUMN_NAME'
                ),
                'sqlite' => $this->sqlite()->getColumns($table),
                default => [],
            };
        } catch (\Throwable $e) {
            $this->logSchemaIntrospectionFailure('getColumnsForTable', $table, $e);

            return [];
        }
    }

    private function logSchemaIntrospectionFailure(string $operation, ?string $table, \Throwable $e): void
    {
        $channel = config('index_advisor.log_channel');
        $level = config('index_advisor.log_level', 'warning');

        /** @var \Illuminate\Log\LogManager $logManager */
        $logManager = app('log');
        $logger = $channel ? $logManager->channel($channel) : $logManager->driver();

        $logger->$level('IndexAdvisor schema introspection failed', [
            'operation' => $operation,
            'table' => $table,
            'driver' => DB::getDriverName(),
            'message' => $e->getMessage(),
        ]);
    }
}
