<?php

namespace Kombee\IndexAdvisor\Contracts;

interface ColumnSchemaSyncer
{
    public function syncColumnWithLiveSchema(string $table, string $column): bool;
}
