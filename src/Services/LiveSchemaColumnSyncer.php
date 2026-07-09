<?php

namespace Kombee\IndexAdvisor\Services;

use Kombee\IndexAdvisor\Contracts\ColumnSchemaSyncer;
use Kombee\IndexAdvisor\Contracts\SchemaIntrospectorContract;

/**
 * Re-reads live indexes for one column and upserts INDEX or REDUNDANT_CHECK rows.
 */
class LiveSchemaColumnSyncer implements ColumnSchemaSyncer
{
    private const MIN_SCORE = 10;

    private const MIN_TABLE_ROWS = 1000;

    private const MIN_CARDINALITY = 10;

    public function __construct(
        private SchemaIntrospectorContract $schema,
        private RecommendationActiveState $activeState,
        private QueryColumnCorrelator $correlator,
        private RecommendationScoreCalculator $scoreCalculator,
    ) {}

    public function syncColumnWithLiveSchema(string $table, string $column): bool
    {
        $canonicalTable = $this->schema->canonicalTableName($table);

        if ($canonicalTable === null || ! $this->schema->hasTableAndColumn($canonicalTable, $column)) {
            return false;
        }

        $excludedTables = (array) config('index_advisor.excluded_tables', []);
        $slowMs = (float) config('index_advisor.slow_query_ms', 200);
        $minScore = (int) config('index_advisor.scoring.min_score', self::MIN_SCORE);
        $minTableRows = (int) config('index_advisor.scoring.min_table_rows', self::MIN_TABLE_ROWS);
        $minCardinality = (int) config('index_advisor.scoring.min_cardinality', self::MIN_CARDINALITY);

        $indexed = $this->schema->isColumnIndexedFresh($canonicalTable, $column);
        $indexType = $indexed ? 'REDUNDANT_CHECK' : 'INDEX';

        if ($indexType === 'INDEX' && $this->activeState->hasActiveRecommendation($canonicalTable, $column, 'DROP')) {
            return false;
        }

        if ($indexType === 'REDUNDANT_CHECK' && $this->activeState->hasActiveRecommendation($canonicalTable, $column, 'DROP')) {
            return false;
        }

        $this->activeState->dismissConflictingActiveTypes($canonicalTable, $column, $indexType);

        $candidate = $this->correlator->buildCandidateForColumn($canonicalTable, $column, $excludedTables);

        if ($candidate === null) {
            $candidate = (object) [
                'table_name' => $canonicalTable,
                'column_name' => $column,
                'query_type' => 'where',
                'exec_count' => 0,
                'avg_ms' => 0.0,
                'max_duration_ms' => 0.0,
                'has_full_scan' => false,
                'matched_fingerprints' => [],
            ];
        }

        $rowCount = $this->schema->getTableRowCount($canonicalTable);

        if ($rowCount !== null && $rowCount < $minTableRows) {
            return false;
        }

        $cardinality = $this->schema->getColumnCardinality($canonicalTable, $column);
        if ($cardinality !== null && $cardinality < $minCardinality) {
            return false;
        }

        [$score, $evidence] = $this->scoreCalculator->computeScore($candidate, $indexed, $slowMs, $rowCount, $cardinality);
        $evidence['schema_sync'] = true;
        $evidence['sync_index_type'] = $indexType;
        $evidence['sync_reason'] = $indexed
            ? 'Live schema has index(es) on this column.'
            : 'Live schema has no index on this column.';

        if ($score < $minScore) {
            $score = $minScore;
            $evidence['schema_sync_min_score_applied'] = true;
        }

        $this->scoreCalculator->upsertSingleColumnRecommendation($canonicalTable, $column, $indexType, $score, $evidence);

        return true;
    }
}
