<?php

namespace Kombee\IndexAdvisor\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kombee\IndexAdvisor\Contracts\ColumnSchemaSyncer;
use Kombee\IndexAdvisor\Contracts\SchemaIntrospectorContract;

/**
 * Resolves contradictory recommendations for the same table/column.
 *
 * The unique key is (table_name, column_name, index_type), so DROP, INDEX, and
 * REDUNDANT_CHECK can coexist until reconciliation runs against the live schema.
 *
 * Reconciliation dismisses stale rows AND re-syncs the correct INDEX / REDUNDANT_CHECK
 * row from the live schema (via ColumnSchemaSyncer).
 */
class RecommendationReconciler
{
    private const SINGLE_COLUMN_TYPES = ['INDEX', 'REDUNDANT_CHECK', 'DROP'];

    public function __construct(
        private SchemaIntrospectorContract $schema,
        private RecommendationActiveState $activeState,
        private ColumnSchemaSyncer $columnSyncer,
    ) {}

    /**
     * @return array{dismissed: int, synced: int}
     */
    public function reconcile(): array
    {
        if (! config('index_advisor.reconciliation.enabled', true)) {
            return ['dismissed' => 0, 'synced' => 0];
        }

        $this->schema->resetCache();

        $dismissed = 0;
        $synced = 0;

        foreach ($this->collectTableColumnPairs() as $pair) {
            $result = $this->reconcilePair($pair->table_name, $pair->column_name);
            $dismissed += $result['dismissed'];
            $synced += $result['synced'];
        }

        return ['dismissed' => $dismissed, 'synced' => $synced];
    }

    public function hasActiveRecommendation(string $table, string $column, string $indexType): bool
    {
        return $this->activeState->hasActiveRecommendation($table, $column, $indexType);
    }

    public function dismissConflictingActiveTypes(string $table, string $column, string $keepIndexType): void
    {
        $this->activeState->dismissConflictingActiveTypes($table, $column, $keepIndexType);
    }

    /**
     * @return array{dismissed: int, synced: int}
     */
    private function reconcilePair(string $table, string $column): array
    {
        $canonicalTable = $this->schema->canonicalTableName($table);

        if ($canonicalTable === null || ! $this->schema->hasTableAndColumn($canonicalTable, $column)) {
            return ['dismissed' => 0, 'synced' => 0];
        }

        $recs = DB::table('index_advisor_recommendations')
            ->whereRaw('LOWER(table_name) = ?', [strtolower($canonicalTable)])
            ->whereRaw('LOWER(column_name) = ?', [strtolower($column)])
            ->whereIn('index_type', self::SINGLE_COLUMN_TYPES)
            ->whereIn('status', ['pending', 'generated'])
            ->get();

        $indexed = $this->schema->isColumnIndexedFresh($canonicalTable, $column);
        $hasActiveDrop = $recs->contains(fn ($r) => $r->index_type === 'DROP');
        $dismissed = 0;

        foreach ($recs as $rec) {
            $reason = $this->dismissReason($rec, $indexed, $hasActiveDrop);

            if ($reason !== null) {
                $this->activeState->dismiss($rec, $reason);
                $dismissed++;
            }
        }

        $synced = 0;

        if (! $hasActiveDrop) {
            $synced = $this->columnSyncer->syncColumnWithLiveSchema($canonicalTable, $column) ? 1 : 0;
        }

        return ['dismissed' => $dismissed, 'synced' => $synced];
    }

    /**
     * @return Collection<int, object{table_name: string, column_name: string}>
     */
    private function collectTableColumnPairs(): Collection
    {
        $fromRecs = DB::table('index_advisor_recommendations')
            ->whereIn('index_type', self::SINGLE_COLUMN_TYPES)
            ->where('column_name', 'not like', '%,%')
            ->select('table_name', 'column_name')
            ->distinct()
            ->get();

        $fromColumns = DB::table('index_advisor_columns')
            ->where('table_name', '!=', 'unknown')
            ->select('table_name', 'column_name')
            ->distinct()
            ->get();

        return $fromRecs
            ->merge($fromColumns)
            ->unique(fn ($row) => strtolower($row->table_name).'|'.strtolower($row->column_name))
            ->values();
    }

    private function dismissReason(object $rec, bool $indexed, bool $hasActiveDrop): ?string
    {
        return match ($rec->index_type) {
            'INDEX' => match (true) {
                $indexed => 'Column already has an index in the live schema; INDEX recommendation is stale.',
                $hasActiveDrop => 'An active DROP recommendation exists for this column; do not add a new index until unused indexes are reviewed.',
                default => null,
            },
            'REDUNDANT_CHECK' => match (true) {
                ! $indexed => 'No index exists on this column in the live schema; REDUNDANT_CHECK is stale.',
                $hasActiveDrop => 'Active DROP recommendation covers this unused index; REDUNDANT_CHECK is redundant.',
                default => null,
            },
            'DROP' => match (true) {
                ! $indexed => 'Index no longer exists on this column in the live schema; DROP recommendation is stale.',
                ! $this->dropTargetIndexStillExists($rec) => 'Target index from DROP evidence no longer exists in the live schema.',
                default => null,
            },
            default => null,
        };
    }

    private function dropTargetIndexStillExists(object $rec): bool
    {
        $this->schema->forgetTable($rec->table_name);

        $evidence = json_decode($rec->evidence ?? '{}', true);
        $indexName = $evidence['index_name'] ?? null;

        if (! is_string($indexName) || $indexName === '') {
            return $this->schema->isColumnIndexed($rec->table_name, $rec->column_name);
        }

        return $this->schema->indexExistsByName($rec->table_name, $indexName);
    }
}
