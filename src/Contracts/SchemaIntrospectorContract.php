<?php

namespace Kombee\IndexAdvisor\Contracts;

interface SchemaIntrospectorContract
{
    public function resetCache(): void;

    public function forgetTable(string $table): void;

    public function canonicalTableName(string $table): ?string;

    public function resolveTableName(string $table): ?string;

    public function hasTableAndColumn(string $table, string $column): bool;

    public function canonicalColumnName(string $table, string $column): ?string;

    public function isColumnIndexed(string $table, string $column): bool;

    public function isColumnIndexedFresh(string $table, string $column): bool;

    /**
     * @return array<int, array{index_name: string, is_primary: bool, is_unique: bool}>
     */
    public function getColumnIndexDetailsFresh(string $table, string $column): array;

    /**
     * @return array<int, array{index_name: string, is_primary: bool, is_unique: bool}>
     */
    public function getColumnIndexDetails(string $table, string $column): array;

    public function indexExistsByName(string $table, string $indexName): bool;

    public function getExistingIndexes(string $table): array;

    public function getAllTables(): array;

    public function getUnusedPgsqlIndexes(): array;

    public function getUnusedMysqlIndexes(): array;

    public function getPgsqlTableStats(): array;

    public function getTableRowCount(string $table): ?int;

    public function getColumnCardinality(string $table, string $column): ?int;
}
