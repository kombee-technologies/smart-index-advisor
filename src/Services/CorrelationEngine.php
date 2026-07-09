<?php

namespace Kombee\IndexAdvisor\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Correlation Engine — The core of the hybrid index advisory system.
 *
 * Matches fingerprints across three independent data sources:
 *   1. Static code patterns  (index_advisor_code_patterns)
 *   2. Runtime query log     (index_advisor_queries + index_advisor_query_stats)
 *   3. Database telemetry    (index_advisor_runtime_stats + index_advisor_explain_reports)
 *
 * Produces enriched correlation entities per table+column combination with
 * flags indicating:
 *   - used_in_code:    Static analysis confirms column usage
 *   - slow_at_runtime: Average execution time exceeds threshold
 *   - missing_index:   No existing index covers this column
 *   - full_table_scan: EXPLAIN detected ALL / Seq Scan
 *   - high_frequency:  Execution count exceeds frequency threshold
 *   - has_filesort:    EXPLAIN detected filesort usage
 *   - has_temp_table:  EXPLAIN detected temp table usage
 *   - unused_index:    Index exists but is never used (penalty signal)
 */
class CorrelationEngine
{
    public function __construct(
        private SchemaIntrospector $schema,
        private DatabaseTelemetryCollector $telemetry,
    ) {}

    /**
     * Run the correlation and produce enriched entities.
     *
     * @return Collection<int, object>  Collection of correlated candidates
     */
    public function correlate(): Collection
    {
        $slowMs       = (float) config('index_advisor.slow_query_ms', 200);
        $minExec      = (int) config('index_advisor.min_executions', 10);
        $excluded     = config('index_advisor.excluded_tables', []);
        $unusedIndexes = $this->telemetry->getUnusedIndexes();

        // Step 1: Gather all unique table+column candidates from code patterns
        $codePatterns = DB::table('index_advisor_code_patterns')
            ->select('table_name', 'column_name', 'expression_type', 'fingerprint')
            ->selectRaw('COUNT(*) as code_occurrence_count')
            ->selectRaw('GROUP_CONCAT(DISTINCT file_path) as source_files')
            ->when(DB::getDriverName() === 'pgsql', function ($q) {
                // PostgreSQL doesn't have GROUP_CONCAT, use string_agg
                $q->selectRaw("string_agg(DISTINCT file_path, ',') as source_files");
            })
            ->whereNotIn('table_name', $excluded)
            ->where('table_name', '!=', 'unknown')
            ->groupBy('table_name', 'column_name', 'expression_type', 'fingerprint')
            ->get();

        // Step 2: Also gather columns from the basic code column scan
        $codeColumns = DB::table('index_advisor_columns')
            ->select('table_name', 'column_name', 'query_type as expression_type')
            ->whereNotIn('table_name', $excluded)
            ->where('table_name', '!=', 'unknown')
            ->distinct()
            ->get();

        // Step 3: Merge both code analysis sources
        $allCandidates = $codePatterns->merge($codeColumns)
            ->unique(fn ($c) => $c->table_name . '|' . $c->column_name . '|' . $c->expression_type);

        // Step 4: Enrich each candidate with runtime + telemetry + EXPLAIN data
        $results = collect();

        foreach ($allCandidates as $candidate) {
            $table  = $candidate->table_name;
            $column = $candidate->column_name;

            // Verify the table and column actually exist in the database
            if (! $this->schema->hasTableAndColumn($table, $column)) {
                continue;
            }

            // Check existing indexes
            $existingIndexes = $this->schema->getExistingIndexes($table);
            $isIndexed = $this->isAlreadyIndexed($column, $existingIndexes);

            // Check if the existing index is unused
            $isUnused = $this->isIndexUnused($table, $column, $unusedIndexes);

            // Get runtime query data correlated by column name in sql_sample
            $runtimeData = $this->getRuntimeData($column);

            // Get EXPLAIN report data
            $explainData = $this->getExplainData($column);

            // Build enriched entity
            $entity = (object) [
                'table_name'           => $table,
                'column_name'          => $column,
                'expression_type'      => $candidate->expression_type,
                'code_occurrence_count' => (int) ($candidate->code_occurrence_count ?? 1),
                'source_files'         => $candidate->source_files ?? '',

                // Runtime metrics
                'exec_count'           => (int) ($runtimeData->exec_count ?? 0),
                'total_duration_ms'    => (float) ($runtimeData->total_duration_ms ?? 0),
                'avg_duration_ms'      => (float) ($runtimeData->avg_ms ?? 0),
                'max_duration_ms'      => (float) ($runtimeData->max_duration_ms ?? 0),

                // Telemetry metrics
                'telemetry_calls'      => (int) ($runtimeData->telemetry_calls ?? 0),
                'rows_examined'        => (int) ($runtimeData->rows_examined ?? 0),
                'rows_returned'        => (int) ($runtimeData->rows_returned ?? 0),

                // EXPLAIN metrics
                'has_full_scan'        => (bool) ($explainData->has_full_scan ?? false),
                'has_filesort'         => (bool) ($explainData->has_filesort ?? false),
                'has_temp_table'       => (bool) ($explainData->has_temp_table ?? false),
                'access_type'          => $explainData->access_type ?? null,
                'possible_keys'        => $explainData->possible_keys ?? null,
                'key_used'             => $explainData->key_used ?? null,
                'filtered_pct'         => (float) ($explainData->filtered_pct ?? 100),

                // Correlation flags
                'used_in_code'         => true,  // by definition (came from code patterns)
                'slow_at_runtime'      => (float) ($runtimeData->avg_ms ?? 0) > $slowMs,
                'missing_index'        => ! $isIndexed,
                'high_frequency'       => (int) ($runtimeData->exec_count ?? 0) >= $minExec,
                'unused_index'         => $isUnused,
                'already_indexed'      => $isIndexed,
            ];

            $results->push($entity);
        }

        return $results;
    }

    /**
     * Get a summary of the correlation results.
     *
     * @return array
     */
    public function getSummary(): array
    {
        $correlated = $this->correlate();

        return [
            'total_candidates'     => $correlated->count(),
            'used_in_code'         => $correlated->where('used_in_code', true)->count(),
            'slow_at_runtime'      => $correlated->where('slow_at_runtime', true)->count(),
            'missing_index'        => $correlated->where('missing_index', true)->count(),
            'full_table_scans'     => $correlated->where('has_full_scan', true)->count(),
            'high_frequency'       => $correlated->where('high_frequency', true)->count(),
            'unused_indexes'       => $correlated->where('unused_index', true)->count(),
            'critical_candidates'  => $correlated
                ->filter(fn ($c) => $c->missing_index && ($c->slow_at_runtime || $c->has_full_scan))
                ->count(),
        ];
    }

    // ─── Private helpers ───────────────────────────────────────────────────────

    /**
     * Get aggregated runtime data for queries referencing a column.
     */
    private function getRuntimeData(string $column): object
    {
        $concatExpr = match (DB::getDriverName()) {
            'pgsql'  => "'%' || ? || '%'",
            'sqlsrv' => "'%' + ? + '%'",
            default  => "CONCAT('%', ?, '%')",
        };

        // Query runtime data from both index_advisor_queries and runtime_stats
        $queryData = DB::selectOne("
            SELECT
                COALESCE(SUM(aq.execution_count), 0) AS exec_count,
                COALESCE(SUM(aq.total_duration_ms), 0) AS total_duration_ms,
                COALESCE(
                    SUM(aq.total_duration_ms) / NULLIF(SUM(aq.execution_count), 0),
                    0
                ) AS avg_ms,
                COALESCE(MAX(aq.max_duration_ms), 0) AS max_duration_ms
            FROM index_advisor_queries aq
            WHERE aq.sql_sample LIKE {$concatExpr}
        ", [$column]);

        // Get telemetry data
        $telemetryData = DB::selectOne("
            SELECT
                COALESCE(SUM(rs.calls), 0) AS telemetry_calls,
                COALESCE(SUM(rs.rows_examined), 0) AS rows_examined,
                COALESCE(SUM(rs.rows_returned), 0) AS rows_returned
            FROM index_advisor_runtime_stats rs
            WHERE rs.sql_sample LIKE {$concatExpr}
        ", [$column]);

        return (object) [
            'exec_count'        => (int) ($queryData->exec_count ?? 0),
            'total_duration_ms' => (float) ($queryData->total_duration_ms ?? 0),
            'avg_ms'            => (float) ($queryData->avg_ms ?? 0),
            'max_duration_ms'   => (float) ($queryData->max_duration_ms ?? 0),
            'telemetry_calls'   => (int) ($telemetryData->telemetry_calls ?? 0),
            'rows_examined'     => (int) ($telemetryData->rows_examined ?? 0),
            'rows_returned'     => (int) ($telemetryData->rows_returned ?? 0),
        ];
    }

    /**
     * Get EXPLAIN analysis data for queries referencing a column.
     */
    private function getExplainData(string $column): object
    {
        $concatExpr = match (DB::getDriverName()) {
            'pgsql'  => "'%' || ? || '%'",
            'sqlsrv' => "'%' + ? + '%'",
            default  => "CONCAT('%', ?, '%')",
        };

        $hasFullScanExpr = match (DB::getDriverName()) {
            'pgsql' => 'COALESCE(BOOL_OR(er.has_full_scan), false)',
            default => 'COALESCE(MAX(CASE WHEN er.has_full_scan THEN 1 ELSE 0 END), 0)',
        };

        $hasFilesortExpr = match (DB::getDriverName()) {
            'pgsql' => 'COALESCE(BOOL_OR(er.has_filesort), false)',
            default => 'COALESCE(MAX(CASE WHEN er.has_filesort THEN 1 ELSE 0 END), 0)',
        };

        $hasTempExpr = match (DB::getDriverName()) {
            'pgsql' => 'COALESCE(BOOL_OR(er.has_temp_table), false)',
            default => 'COALESCE(MAX(CASE WHEN er.has_temp_table THEN 1 ELSE 0 END), 0)',
        };

        // Try from the detailed explain_reports first
        $report = DB::selectOne("
            SELECT
                {$hasFullScanExpr} AS has_full_scan,
                {$hasFilesortExpr} AS has_filesort,
                {$hasTempExpr}     AS has_temp_table,
                MAX(er.access_type) AS access_type,
                MAX(er.key_used) AS key_used,
                MIN(er.filtered_pct) AS filtered_pct
            FROM index_advisor_explain_reports er
            WHERE er.sql_sample LIKE {$concatExpr}
        ", [$column]);

        // Fallback to the basic explains table
        if (! $report || ! (bool) $report->has_full_scan) {
            $basicExplain = DB::selectOne("
                SELECT
                    {$hasFullScanExpr} AS has_full_scan
                FROM index_advisor_explains er
                JOIN index_advisor_queries aq ON aq.fingerprint = er.fingerprint
                WHERE aq.sql_sample LIKE {$concatExpr}
            ", [$column]);

            if ($basicExplain && (bool) $basicExplain->has_full_scan) {
                $report = $report ?? (object) [];
                $report->has_full_scan = true;
            }
        }

        return (object) [
            'has_full_scan'  => (bool) ($report->has_full_scan ?? false),
            'has_filesort'   => (bool) ($report->has_filesort ?? false),
            'has_temp_table' => (bool) ($report->has_temp_table ?? false),
            'access_type'    => $report->access_type ?? null,
            'possible_keys'  => null,
            'key_used'       => $report->key_used ?? null,
            'filtered_pct'   => (float) ($report->filtered_pct ?? 100),
        ];
    }

    private function isAlreadyIndexed(string $column, array $indexes): bool
    {
        foreach ($indexes as $idx) {
            $col = $idx->column_name ?? '';
            if (strtolower($col) === strtolower($column)) {
                return true;
            }
        }
        return false;
    }

    private function isIndexUnused(string $table, string $column, Collection $unusedIndexes): bool
    {
        return $unusedIndexes->contains(function ($idx) use ($table, $column) {
            $idxTable = $idx->table_name ?? '';
            $idxCol   = $idx->column_name ?? '';
            return strtolower($idxTable) === strtolower($table)
                && strtolower($idxCol) === strtolower($column);
        });
    }
}
