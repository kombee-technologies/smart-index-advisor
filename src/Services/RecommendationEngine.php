<?php

namespace Kombee\IndexAdvisor\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recommendation Engine — Generates actionable index recommendations
 * based on correlated analysis data from the CorrelationEngine.
 *
 * Index Types Generated:
 *   - Single-column:  One column, high score
 *   - Composite:      Multiple WHERE columns on the same table
 *   - Covering:       WHERE + SELECT columns for read-heavy queries (MySQL InnoDB)
 *   - Partial:        WHERE with constant filter (PostgreSQL)
 *   - DESC:           ORDER BY DESC detected
 *   - CONCURRENTLY:   Always for PostgreSQL production safety
 *
 * Also detects:
 *   - Duplicate / redundant indexes (existing indexes that overlap)
 *   - Unused indexes (candidates for DROP)
 */
class RecommendationEngine
{
    public function __construct(
        private CorrelationEngine $correlation,
        private ScoringService $scorer,
        private SchemaIntrospector $schema,
        private DDLGenerator $ddl,
    ) {}

    /**
     * Generate all recommendations based on correlated data.
     *
     * @return array{created: int, updated: int, composites: int, covering: int}
     */
    public function generate(): array
    {
        $driver     = DB::getDriverName();
        $correlated = $this->correlation->correlate();
        $created    = 0;
        $updated    = 0;

        // Phase 1: Score and store individual column recommendations
        foreach ($correlated as $candidate) {
            [$score, $evidence] = $this->scorer->computeScoreFromCorrelation($candidate);

            $columns = [$candidate->column_name];
            $ddlUp   = null;
            $ddlDown = null;
            $indexType = $candidate->already_indexed ? 'REDUNDANT_CHECK' : 'INDEX';

            // Determine specific index type
            if (! $candidate->already_indexed) {
                if ($candidate->expression_type === 'orderByDesc') {
                    $indexType = 'DESC';
                }

                try {
                    $ddlUp   = $this->ddl->generateCreateIndex($driver, $candidate->table_name, $columns);
                    $ddlDown = $this->ddl->generateDropIndex(
                        $driver,
                        $candidate->table_name,
                        'idx_' . $candidate->table_name . '_' . $candidate->column_name
                    );
                } catch (\Throwable) {
                    // DDL generation failed — skip this candidate
                }
            }

            $result = DB::table('index_advisor_recommendations')->upsert(
                [
                    'table_name'  => $candidate->table_name,
                    'column_name' => $candidate->column_name,
                    'index_type'  => $indexType,
                    'score'       => min(100, $score),
                    'evidence'    => json_encode($evidence),
                    'columns'     => json_encode($columns),
                    'ddl_up'      => $ddlUp,
                    'ddl_down'    => $ddlDown,
                    'status'      => 'pending',
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ],
                ['table_name', 'column_name', 'index_type'],
                ['score', 'evidence', 'columns', 'ddl_up', 'ddl_down', 'updated_at']
            );

            $created++;
        }

        // Phase 2: Detect composite index opportunities
        $composites = $this->generateCompositeRecommendations($correlated, $driver);

        // Phase 3: Detect covering index opportunities (MySQL only)
        $covering = 0;
        if ($driver === 'mysql') {
            $covering = $this->generateCoveringRecommendations($correlated, $driver);
        }

        // Phase 4: Detect unused indexes (DROP candidates)
        $this->generateDropRecommendations($driver);

        return [
            'created'    => $created,
            'updated'    => $updated,
            'composites' => $composites,
            'covering'   => $covering,
        ];
    }

    /**
     * Get top recommendations by score.
     */
    public function getTopRecommendations(int $limit = 30): Collection
    {
        return DB::table('index_advisor_recommendations')
            ->orderByDesc('score')
            ->limit($limit)
            ->get();
    }

    /**
     * Get recommendations filtered by status.
     */
    public function getByStatus(string $status): Collection
    {
        return DB::table('index_advisor_recommendations')
            ->where('status', $status)
            ->orderByDesc('score')
            ->get();
    }

    /**
     * Dismiss a recommendation.
     */
    public function dismiss(int $id): bool
    {
        return DB::table('index_advisor_recommendations')
            ->where('id', $id)
            ->update(['status' => 'dismissed', 'updated_at' => now()]) > 0;
    }

    // ─── Composite Index Detection ─────────────────────────────────────────────

    /**
     * Detect composite index opportunities where multiple WHERE columns
     * are used on the same table across the codebase.
     */
    private function generateCompositeRecommendations(Collection $correlated, string $driver): int
    {
        $count = 0;

        // Group candidates by table
        $byTable = $correlated
            ->whereIn('expression_type', ['where', 'orWhere', 'whereIn', 'whereBetween', 'join', 'rawWhere'])
            ->where('missing_index', true)
            ->groupBy('table_name');

        foreach ($byTable as $table => $candidates) {
            $columns = $candidates->pluck('column_name')->unique()->values()->all();

            // Need at least 2 columns for a composite
            if (count($columns) < 2) {
                continue;
            }

            // Limit to 4 columns max for the composite
            $compositeColumns = array_slice($columns, 0, 4);
            $columnName       = implode('_', $compositeColumns);

            // Calculate composite score (average of individual scores + 10 bonus)
            $avgScore = $candidates->avg(fn ($c) => $this->computeQuickScore($c));
            $compositeScore = min(100, (int) $avgScore + 10);

            try {
                $ddlUp = $this->ddl->generateCreateIndex($driver, $table, $compositeColumns);
                $ddlDown = $this->ddl->generateDropIndex($driver, $table, 'idx_' . $table . '_' . $columnName);
            } catch (\Throwable) {
                continue;
            }

            DB::table('index_advisor_recommendations')->upsert(
                [
                    'table_name'  => $table,
                    'column_name' => $columnName,
                    'index_type'  => 'COMPOSITE',
                    'score'       => $compositeScore,
                    'evidence'    => json_encode([
                        'type'    => 'composite_index',
                        'columns' => $compositeColumns,
                        'reason'  => count($compositeColumns) . ' WHERE columns on same table',
                    ]),
                    'columns'     => json_encode($compositeColumns),
                    'ddl_up'      => $ddlUp,
                    'ddl_down'    => $ddlDown,
                    'status'      => 'pending',
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ],
                ['table_name', 'column_name', 'index_type'],
                ['score', 'evidence', 'columns', 'ddl_up', 'ddl_down', 'updated_at']
            );

            $count++;
        }

        return $count;
    }

    // ─── Covering Index Detection ──────────────────────────────────────────────

    /**
     * Detect covering index opportunities (WHERE columns + SELECT columns).
     * Only for MySQL InnoDB where covering indexes can prevent row lookups.
     */
    private function generateCoveringRecommendations(Collection $correlated, string $driver): int
    {
        $count = 0;

        // Find tables that have both WHERE and SELECT columns in code patterns
        $byTable = DB::table('index_advisor_code_patterns')
            ->select('table_name')
            ->selectRaw("GROUP_CONCAT(DISTINCT CASE WHEN expression_type IN ('where','whereIn','join') THEN column_name END) AS where_cols")
            ->selectRaw("GROUP_CONCAT(DISTINCT CASE WHEN expression_type = 'select' THEN column_name END) AS select_cols")
            ->where('table_name', '!=', 'unknown')
            ->groupBy('table_name')
            ->havingRaw("GROUP_CONCAT(DISTINCT CASE WHEN expression_type IN ('where','whereIn','join') THEN column_name END) IS NOT NULL")
            ->havingRaw("GROUP_CONCAT(DISTINCT CASE WHEN expression_type = 'select' THEN column_name END) IS NOT NULL")
            ->get();

        foreach ($byTable as $row) {
            $whereCols  = array_filter(explode(',', $row->where_cols ?? ''));
            $selectCols = array_filter(explode(',', $row->select_cols ?? ''));

            if (empty($whereCols) || empty($selectCols)) {
                continue;
            }

            // Covering index = WHERE columns first, then SELECT columns (INCLUDE)
            $coveringCols = array_unique(array_merge($whereCols, $selectCols));
            $coveringCols = array_slice($coveringCols, 0, 6); // Max 6 columns

            $columnName = implode('_', $coveringCols);

            try {
                $ddlUp   = $this->ddl->generateCreateIndex($driver, $row->table_name, $coveringCols);
                $ddlDown = $this->ddl->generateDropIndex($driver, $row->table_name, 'idx_' . $row->table_name . '_' . $columnName);
            } catch (\Throwable) {
                continue;
            }

            DB::table('index_advisor_recommendations')->upsert(
                [
                    'table_name'  => $row->table_name,
                    'column_name' => $columnName,
                    'index_type'  => 'COVERING',
                    'score'       => 70, // Covering indexes are generally valuable
                    'evidence'    => json_encode([
                        'type'        => 'covering_index',
                        'where_cols'  => $whereCols,
                        'select_cols' => $selectCols,
                        'reason'      => 'Covers WHERE + SELECT to avoid row lookups',
                    ]),
                    'columns'     => json_encode($coveringCols),
                    'ddl_up'      => $ddlUp,
                    'ddl_down'    => $ddlDown,
                    'status'      => 'pending',
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ],
                ['table_name', 'column_name', 'index_type'],
                ['score', 'evidence', 'columns', 'ddl_up', 'ddl_down', 'updated_at']
            );

            $count++;
        }

        return $count;
    }

    // ─── Unused Index Detection ────────────────────────────────────────────────

    /**
     * Detect unused indexes and recommend DROP.
     */
    private function generateDropRecommendations(string $driver): int
    {
        $telemetry = app(DatabaseTelemetryCollector::class);
        $unused    = $telemetry->getUnusedIndexes();
        $count     = 0;

        foreach ($unused as $idx) {
            $table     = $idx->table_name ?? '';
            $indexName = $idx->index_name ?? '';

            if (empty($table) || empty($indexName)) {
                continue;
            }

            // Skip primary keys
            if (strtoupper($indexName) === 'PRIMARY') {
                continue;
            }

            try {
                $ddlDown = $this->ddl->generateDropIndex($driver, $table, $indexName);
            } catch (\Throwable) {
                continue;
            }

            DB::table('index_advisor_recommendations')->upsert(
                [
                    'table_name'  => $table,
                    'column_name' => $indexName,
                    'index_type'  => 'DROP',
                    'score'       => 30,
                    'evidence'    => json_encode([
                        'type'       => 'unused_index',
                        'index_name' => $indexName,
                        'reason'     => 'Index exists but has never been used by the query optimizer',
                    ]),
                    'columns'     => null,
                    'ddl_up'      => $ddlDown,  // "UP" is to drop the index
                    'ddl_down'    => null,
                    'status'      => 'pending',
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ],
                ['table_name', 'column_name', 'index_type'],
                ['score', 'evidence', 'updated_at']
            );

            $count++;
        }

        return $count;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function computeQuickScore(object $candidate): int
    {
        $score = 0;

        // Frequency
        $score += min(30, (int) ((float) $candidate->exec_count / 100 * 30));

        // Slow
        if ($candidate->slow_at_runtime) $score += 25;

        // Full scan
        if ($candidate->has_full_scan) $score += 20;

        // Code usage
        if ($candidate->used_in_code) $score += 10;

        // Missing index
        if ($candidate->missing_index) $score += 5;

        return min(100, $score);
    }
}
