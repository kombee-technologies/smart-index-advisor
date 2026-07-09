<?php

namespace Kombee\IndexAdvisor\Services;

use Illuminate\Support\Facades\DB;

/**
 * SQLite-specific schema helpers (PRAGMA-based).
 */
class SqliteIntrospection
{
    public function getAllTables(): array
    {
        return array_column(
            DB::select(
                "SELECT name FROM sqlite_master
                 WHERE type = 'table'
                   AND name NOT LIKE 'sqlite_%'"
            ),
            'name'
        );
    }

    /**
     * @return array<int, object{index_name: string, column_name: string}>
     */
    public function getIndexes(string $table): array
    {
        if (! $this->isSafeIdentifier($table)) {
            return [];
        }

        $indexes = DB::select('PRAGMA index_list('. $this->quoteLiteral($table) .')');
        $result = [];

        foreach ($indexes as $idx) {
            if (($idx->origin ?? '') === 'pk') {
                continue;
            }

            $indexName = $idx->name ?? '';
            if ($indexName === '' || ! $this->isSafeIdentifier($indexName)) {
                continue;
            }

            foreach (DB::select('PRAGMA index_info('. $this->quoteLiteral($indexName) .')') as $col) {
                $result[] = (object) [
                    'index_name' => $indexName,
                    'column_name' => $col->name ?? '',
                ];
            }
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    public function getColumns(string $table): array
    {
        if (! $this->isSafeIdentifier($table)) {
            return [];
        }

        return array_map(
            fn ($row) => strtolower($row->name),
            DB::select('PRAGMA table_info('. $this->quoteLiteral($table) .')')
        );
    }

    public function getTableRowCount(string $table): ?int
    {
        if (! $this->isSafeIdentifier($table)) {
            return null;
        }

        $wrapped = $this->wrapIdentifier($table);

        return (int) DB::selectOne("SELECT COUNT(*) AS row_count FROM {$wrapped}")?->row_count;
    }

    public function getColumnCardinality(string $table, string $column, int $maxRows): ?int
    {
        if (! $this->isSafeIdentifier($table) || ! $this->isSafeIdentifier($column)) {
            return null;
        }

        $rowCount = $this->getTableRowCount($table);
        if ($rowCount === null || $rowCount > $maxRows) {
            return null;
        }

        $wrappedTable = $this->wrapIdentifier($table);
        $wrappedColumn = $this->wrapIdentifier($column);

        return (int) DB::selectOne(
            "SELECT COUNT(DISTINCT {$wrappedColumn}) AS cardinality FROM {$wrappedTable}"
        )?->cardinality;
    }

    private function isSafeIdentifier(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name);
    }

    private function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private function wrapIdentifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
