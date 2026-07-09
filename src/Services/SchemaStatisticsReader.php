<?php

namespace Kombee\IndexAdvisor\Services;

use Illuminate\Support\Facades\DB;
use Kombee\IndexAdvisor\Helpers\MysqlIdentifier;

/**
 * Driver-specific cardinality and row-count helpers for SchemaIntrospector.
 */
class SchemaStatisticsReader
{
    public function __construct(private string $pgSchema) {}

    public function mysqlTableRowCount(string $table): ?int
    {
        $row = DB::selectOne(
            'SELECT TABLE_ROWS AS row_count FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );

        return $row === null ? null : max(0, (int) $row->row_count);
    }

    public function pgsqlTableRowCount(string $table): ?int
    {
        $estimate = DB::selectOne(
            'SELECT reltuples::bigint AS row_count FROM pg_class c
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relname = ? AND n.nspname = ?',
            [$table, $this->pgSchema]
        );

        if ($estimate === null) {
            return null;
        }

        $rowCount = (int) $estimate->row_count;

        // reltuples is -1 when the table has never been ANALYZEd.
        if ($rowCount < 0) {
            return null;
        }

        return $rowCount;
    }

    public function mysqlColumnCardinality(string $table, string $column): ?int
    {
        $row = DB::selectOne(
            'SELECT CARDINALITY FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             ORDER BY CARDINALITY DESC
             LIMIT 1',
            [$table, $column]
        );

        if ($row !== null && $row->CARDINALITY !== null) {
            return (int) $row->CARDINALITY;
        }

        $maxRows = (int) config('index_advisor.mysql.cardinality_max_rows', 10000);
        $tableRows = $this->mysqlTableRowCount($table);

        if ($tableRows === null || $tableRows > $maxRows) {
            return null;
        }

        MysqlIdentifier::assertSafe($table);
        MysqlIdentifier::assertSafe($column);

        $quotedTable = MysqlIdentifier::quote($table);
        $quotedColumn = MysqlIdentifier::quote($column);

        $distinct = DB::selectOne(
            "SELECT COUNT(DISTINCT {$quotedColumn}) AS cardinality FROM {$quotedTable}"
        );

        return $distinct === null ? null : (int) $distinct->cardinality;
    }
}
