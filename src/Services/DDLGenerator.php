<?php

namespace Kombee\IndexAdvisor\Services;

/**
 * Generates CREATE INDEX / DROP INDEX DDL statements for MySQL, PostgreSQL,
 * SQL Server, and SQLite.
 * Supports single-column, composite, and partial (PostgreSQL) indexes.
 *
 * All CREATE INDEX statements use IF NOT EXISTS (where supported) and
 * online / lock-free DDL to be safe for production use.
 */
class DDLGenerator
{
    /**
     * Maximum index name length (PostgreSQL limit is 63 bytes; we use 60 for safety).
     */
    private const MAX_NAME_LENGTH = 60;

    /**
     * Build a CREATE INDEX statement.
     *
     * @param  string  $driver  mysql | pgsql | sqlsrv | sqlite
     * @param  string  $table  target table name
     * @param  string[]  $columns  columns to include (order matters for composites)
     * @param  bool  $online  use online / lock-free DDL where supported
     * @param  string|null  $condition  PostgreSQL partial index WHERE clause (pgsql only)
     */
    public function generateCreateIndex(
        string $driver,
        string $table,
        array $columns,
        bool $online = true,
        ?string $condition = null
    ): string {
        $name = $this->buildIndexName($table, $columns);

        return match ($driver) {
            'mysql' => $this->mysqlCreate($table, $name, $columns, $online),
            'pgsql' => $this->pgsqlCreate($table, $name, $columns, $online, $condition),
            'sqlsrv' => $this->mssqlCreate($table, $name, $columns, $online),
            'sqlite' => $this->sqliteCreate($table, $name, $columns),
            default => throw new \InvalidArgumentException("Unsupported driver: {$driver}"),
        };
    }

    /**
     * Build a DROP INDEX statement.
     */
    /**
    * Build a DROP CONSTRAINT statement (PostgreSQL) or fallback to DROP INDEX for other drivers.
    */

    /**
     * Generate a DROP INDEX statement.
     * For PostgreSQL, uses CONCURRENTLY to avoid locking.
     */
    public function generateDropIndex(string $driver, string $table, string $indexName): string
    {
        return match ($driver) {
            'pgsql' => "DROP INDEX CONCURRENTLY IF EXISTS \"{$indexName}\";",
            'mysql' => "DROP INDEX `{$indexName}` ON `{$table}`;",
            'sqlsrv' => "DROP INDEX IF EXISTS [{$indexName}] ON [{$table}];",
            'sqlite' => "DROP INDEX IF EXISTS \"{$indexName}\";",
            default => throw new \InvalidArgumentException("Unsupported driver: {$driver}"),
        };
    }

    /**
     * Generate a DROP CONSTRAINT statement for PostgreSQL, or fallback to DROP INDEX for other drivers.
     */
    public function generateDropConstraint(string $driver, string $table, string $constraintName): string
    {
        return match ($driver) {
            'pgsql' => "ALTER TABLE \"{$table}\" DROP CONSTRAINT IF EXISTS \"{$constraintName}\";",
            default => $this->generateDropIndex($driver, $table, $constraintName),
        };
    }
    /**
     * Return the DDL in all three dialects — useful for reports.
     */
    public function allDialects(string $table, array $columns, bool $online = true): array
    {
        return [
            'mysql' => $this->generateCreateIndex('mysql', $table, $columns, $online),
            'pgsql' => $this->generateCreateIndex('pgsql', $table, $columns, $online),
            'sqlsrv' => $this->generateCreateIndex('sqlsrv', $table, $columns, $online),
            'sqlite' => $this->generateCreateIndex('sqlite', $table, $columns, $online),
        ];
    }

    // ─── Driver implementations ────────────────────────────────────────────────

    private function mysqlCreate(string $table, string $name, array $columns, bool $online): string
    {
        $cols = '`'.implode('`, `', $columns).'`';
        $base = "ALTER TABLE `{$table}` ADD INDEX IF NOT EXISTS `{$name}` ({$cols})";

        if (! $online) {
            return $base.';';
        }

        $inplace = $base.' ALGORITHM=INPLACE, LOCK=NONE;';
        $fallback = $base.' ALGORITHM=COPY, LOCK=SHARED;';

        return $inplace.PHP_EOL
            .'-- INPLACE/LOCK=NONE may fail on InnoDB tables with FULLTEXT indexes or some partitions.'.PHP_EOL
            .'-- If migration fails, retry with:'.PHP_EOL
            .'-- '.$fallback;
    }

    private function pgsqlCreate(
        string $table,
        string $name,
        array $columns,
        bool $online,
        ?string $condition
    ): string {
        // CONCURRENTLY avoids a full table lock.
        // IF NOT EXISTS prevents failure when the index already exists.
        $concurrently = $online ? 'CONCURRENTLY ' : '';
        $cols = implode(', ', array_map(fn ($col) => '"'.str_replace('"', '""', $col).'"', $columns));
        $where = $condition ? " WHERE {$condition}" : '';

        return "CREATE INDEX {$concurrently}IF NOT EXISTS \"{$name}\" ON \"{$table}\" ({$cols}){$where};";
    }

    private function mssqlCreate(string $table, string $name, array $columns, bool $online): string
    {
        $cols = implode(', ', array_map(fn ($col) => '['.str_replace(['[', ']'], '', $col).']', $columns));
        $opts = $online ? ' WITH (ONLINE=ON)' : '';

        // SQL Server has no IF NOT EXISTS for CREATE INDEX; wrap in an existence check.
        return "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = '{$name}' AND object_id = OBJECT_ID('{$table}')) "
             ."CREATE INDEX [{$name}] ON [{$table}] ({$cols}){$opts};";
    }

    private function sqliteCreate(string $table, string $name, array $columns): string
    {
        $cols = implode(', ', array_map(
            fn ($col) => '"'.str_replace('"', '""', $col).'"',
            $columns
        ));

        return 'CREATE INDEX IF NOT EXISTS "'.$name.'" ON "'.$table.'" ('.$cols.');';
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Build a safe index name, truncating if it would exceed MAX_NAME_LENGTH.
     * Format: idx_<table>_<col1>_<col2>
     * If too long: idx_<table>_<crc32hex>
     */
    private function buildIndexName(string $table, array $columns): string
    {
        $full = 'idx_'.$table.'_'.implode('_', $columns);

        if (strlen($full) <= self::MAX_NAME_LENGTH) {
            return $full;
        }

        // Truncate: keep table prefix + hash of columns
        $hash = substr(dechex(crc32(implode('_', $columns))), 0, 8);
        $prefix = 'idx_'.substr($table, 0, self::MAX_NAME_LENGTH - 14).'_';

        return $prefix.$hash;
    }
}
