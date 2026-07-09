<?php

namespace Kombee\IndexAdvisor\Services;

use Illuminate\Support\Facades\DB;
use Kombee\IndexAdvisor\Contracts\SchemaIntrospectorContract;

/**
 * Scoring Engine — correlates code-level column usage with runtime query data
 * and schema information, then writes a 0–100 score into
 * index_advisor_recommendations for each candidate column.
 *
 * Score breakdown (max 100):
 *   +30  Execution frequency      (logarithmic: log10(exec+1)/log10(1001)*30)
 *   +25  Slow average duration    (avg_ms > config slow_query_ms)
 *   +20  Full table scan detected (EXPLAIN shows ALL / Seq Scan / Table Scan)
 *   +10  Used in WHERE / JOIN / orWhere  (most selective access patterns)
 *   + 5  Used in ORDER BY / GROUP BY / HAVING
 *   + 5  FK column heuristic      (column name ends in _id)
 *   + 5  No existing index found
 *   + 5  Spike: max_duration_ms > slow_query_ms × multiplier (outlier detection)
 *
 * Composite indexes are suggested when 2+ columns from the same table
 * appear together in the same query fingerprint.
 *
 * Runtime stats are correlated via QueryColumnCorrelator (SQL clause parsing
 * + fingerprint joins to index_advisor_explains) — not LIKE '%column%'.
 *
 * Candidates are skipped when:
 *   - The table has fewer than 1 000 rows (indexing tiny tables is pointless)
 *   - The column has very low cardinality (< 10 distinct values, e.g. booleans)
 *   - The computed score is below the minimum threshold (default 10)
 */
class ScoringService
{
    /** Skip recommendations below this score to avoid polluting the table. */
    private const MIN_SCORE = 10;

    /** Skip tables with fewer rows than this (indexing tiny tables is pointless). */
    private const MIN_TABLE_ROWS = 1000;

    /** Skip columns with fewer distinct values than this (low-cardinality = bad index). */
    private const MIN_CARDINALITY = 10;

    public function __construct(
        private SchemaIntrospectorContract $schema,
        private RecommendationReconciler $reconciler,
        private QueryColumnCorrelator $correlator,
        private RecommendationScoreCalculator $scoreCalculator,
    ) {}

    /**
     * @return array{saved: int, dismissed: int, synced: int}
     */
    public function score(): array
    {
        $this->schema->resetCache();

        $excludedTables = (array) config('index_advisor.excluded_tables', []);
        $slowMs = (float) config('index_advisor.slow_query_ms', 200);
        $minScore = (int) config('index_advisor.scoring.min_score', self::MIN_SCORE);
        $minTableRows = (int) config('index_advisor.scoring.min_table_rows', self::MIN_TABLE_ROWS);
        $minCardinality = (int) config('index_advisor.scoring.min_cardinality', self::MIN_CARDINALITY);
        // ── Single-column scoring (SQL clause + fingerprint correlation) ──────

        $candidates = $this->correlator->correlateSingleColumnCandidates($excludedTables);
        $saved = 0;

        foreach ($candidates as $c) {
            // Validate the column actually exists in the live schema
            if (! $this->schema->hasTableAndColumn($c->table_name, $c->column_name)) {
                continue;
            }

            // Skip tiny tables — indexing them is pointless
            $rowCount = $this->schema->getTableRowCount($c->table_name);
            if ($rowCount !== null && $rowCount < $minTableRows) {
                continue;
            }

            // Skip low-cardinality columns (e.g. boolean flags)
            $cardinality = $this->schema->getColumnCardinality($c->table_name, $c->column_name);
            if ($cardinality !== null && $cardinality < $minCardinality) {
                continue;
            }

            $canonicalTable = $this->schema->canonicalTableName($c->table_name);

            if ($canonicalTable === null) {
                continue;
            }

            $indexed = $this->schema->isColumnIndexedFresh($canonicalTable, $c->column_name);
            $indexType = $indexed ? 'REDUNDANT_CHECK' : 'INDEX';

            // DROP ingest already flags unused indexes; skip duplicate INDEX upserts.
            if ($indexType === 'INDEX' && $this->reconciler->hasActiveRecommendation($canonicalTable, $c->column_name, 'DROP')) {
                continue;
            }

            // DROP is the actionable path when an unused index already exists.
            if ($indexType === 'REDUNDANT_CHECK' && $this->reconciler->hasActiveRecommendation($canonicalTable, $c->column_name, 'DROP')) {
                continue;
            }

            [$score, $evidence] = $this->scoreCalculator->computeScore($c, $indexed, $slowMs, $rowCount, $cardinality);

            // Skip noise — only persist candidates with a meaningful score
            if ($score < $minScore) {
                continue;
            }

            $this->scoreCalculator->upsertSingleColumnRecommendation(
                $canonicalTable,
                $c->column_name,
                $indexType,
                $score,
                $evidence
            );

            $saved++;
        }

        // ── Composite index scoring ───────────────────────────────────────────
        $saved += $this->scoreCompositeIndexes($excludedTables, $slowMs);

        // ── Post-scoring deduplication ──────────────────────────────────────────
        // Run AFTER all composite rows are written so duplicates from this run
        // (and any leftover from previous runs) are collapsed in one pass.
        $this->deduplicateCompositeRecommendations();

        $reconcileResult = $this->reconciler->reconcile();

        return [
            'saved' => $saved,
            'dismissed' => $reconcileResult['dismissed'],
            'synced' => $reconcileResult['synced'],
        ];
    }

    // ─── Composite index detection ────────────────────────────────────────────

    private function scoreCompositeIndexes(array $excludedTables, float $slowMs): int
    {
        $minColumns = (int) config('index_advisor.composite_min_columns', 2);
        $fingerprints = $this->correlator->correlateCompositeCandidates($excludedTables, $minColumns);

        $saved = 0;
        $seenCanonical = [];

        foreach ($fingerprints as $fp) {
            $validColumns = $this->sortCompositeColumns($fp->table_name, array_values(array_filter(
                $fp->columns,
                fn ($col) => $this->schema->hasTableAndColumn($fp->table_name, $col)
            )));

            if (count($validColumns) < $minColumns) {
                continue;
            }

            $columnKey = $this->canonicalCompositeColumnKey($fp->table_name, $validColumns);
            $canonicalId = "{$fp->table_name}|{$columnKey}";

            if (isset($seenCanonical[$canonicalId])) {
                continue;
            }

            $seenCanonical[$canonicalId] = true;

            if ($this->hasCompositeIndex($fp->table_name, $validColumns)) {
                continue;
            }

            $avgMs = $fp->execution_count > 0
                ? $fp->total_duration_ms / $fp->execution_count
                : 0;

            $score = 0;
            $evidence = [
                'type' => 'COMPOSITE',
                'table_name' => $fp->table_name,
                'columns' => $validColumns,
                'column_weights' => $this->columnWeights($validColumns),
                'fingerprint' => $fp->fingerprint,
            ];

            // Frequency
            $execPts = $this->scoreCalculator->frequencyScore((int) $fp->execution_count);
            $score += $execPts;
            $evidence['exec_count'] = (int) $fp->execution_count;
            $evidence['exec_score_pts'] = $execPts;

            // Slow duration
            if ($avgMs > $slowMs) {
                $score += 25;
                $evidence['avg_ms'] = round($avgMs, 2);
                $evidence['slow_pts'] = 25;
            } else {
                $evidence['avg_ms'] = round($avgMs, 2);
                $evidence['slow_pts'] = 0;
            }

            $score = $this->scoreCalculator->applyMaxDurationSpike($score, $evidence, (float) ($fp->max_duration_ms ?? 0), $slowMs);

            // Full-scan bonus for composite (check explains table)
            $hasFullScan = DB::table('index_advisor_explains')
                ->where('fingerprint', $fp->fingerprint)
                ->where('has_full_scan', true)
                ->exists();

            if ($hasFullScan) {
                $score += 20;
                $evidence['full_scan'] = true;
                $evidence['full_scan_pts'] = 20;
            }

            // Composite bonus
            $score += 15;
            $evidence['composite_pts'] = 15;

            $evidence['verdict'] = $this->scoreCalculator->verdict($score);

            if (DB::getDriverName() === 'pgsql') {
                DB::statement(
                    'INSERT INTO index_advisor_recommendations
                        (table_name, column_name, index_type, score, evidence, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                     ON CONFLICT (table_name, column_name, index_type) DO UPDATE SET
                        score      = EXCLUDED.score,
                        evidence   = EXCLUDED.evidence,
                        updated_at = EXCLUDED.updated_at',
                    [$fp->table_name, $columnKey, 'COMPOSITE', min(100, $score), json_encode($evidence), 'pending', now(), now()]
                );
            } else {
                DB::table('index_advisor_recommendations')->upsert(
                    [
                        'table_name' => $fp->table_name,
                        'column_name' => $columnKey,
                        'index_type' => 'COMPOSITE',
                        'score' => min(100, $score),
                        'evidence' => json_encode($evidence),
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    ['table_name', 'column_name', 'index_type'],
                    ['score', 'evidence', 'updated_at']
                );
            }

            $saved++;
        }

        return $saved + $this->scoreRuntimeCompositeIndexes($excludedTables, $slowMs, $minColumns);
    }

    // ─── Single-column score computation ─────────────────────────────────────

    /**
     * Build composite candidates from captured runtime SQL. This avoids relying
     * solely on static file-name table inference, which is weak for controllers.
     */
    private function scoreRuntimeCompositeIndexes(array $excludedTables, float $slowMs, int $minColumns): int
    {
        $queries = DB::table('index_advisor_queries')
            ->whereRaw('LOWER(sql_sample) LIKE ?', ['select %'])
            ->orderByDesc('execution_count')
            ->limit((int) config('index_advisor.scoring.runtime_composite_query_limit', 500))
            ->get(['fingerprint', 'sql_sample', 'execution_count', 'total_duration_ms', 'max_duration_ms']);

        $saved = 0;
        $seenCanonical = [];
        $processedKeys = [];

        foreach ($queries as $query) {
            $candidate = $this->extractRuntimeCompositeCandidate($query->sql_sample);
            if ($candidate === null) {
                continue;
            }

            [$table, $columns] = $candidate;

            if (in_array($table, $excludedTables, true)) {
                continue;
            }

            $validColumns = $this->sortCompositeColumns($table, array_values(array_filter(
                $columns,
                fn ($col) => $this->schema->hasTableAndColumn($table, $col)
            )));

            if (count($validColumns) < $minColumns || $this->hasCompositeIndex($table, $validColumns)) {
                continue;
            }

            $columnKey = $this->canonicalCompositeColumnKey($table, $validColumns);
            $canonicalId = "{$table}|{$columnKey}";

            if (isset($seenCanonical[$canonicalId])) {
                continue;
            }

            $seenCanonical[$canonicalId] = true;
            $processedKeys[$canonicalId] = true;

            $avgMs = $query->execution_count > 0
                ? $query->total_duration_ms / $query->execution_count
                : 0;

            $score = 15;
            $execPts = $this->scoreCalculator->frequencyScore((int) $query->execution_count);
            $score += $execPts;

            $evidence = [
                'type' => 'COMPOSITE',
                'source' => 'runtime_sql',
                'table_name' => $table,
                'columns' => $validColumns,
                'column_weights' => $this->columnWeights($validColumns),
                'fingerprint' => $query->fingerprint,
                'sql_sample' => mb_substr($query->sql_sample, 0, 500),
                'exec_count' => (int) $query->execution_count,
                'exec_score_pts' => $execPts,
                'composite_pts' => 15,
            ];

            if ($avgMs > $slowMs) {
                $score += 25;
                $evidence['avg_ms'] = round($avgMs, 2);
                $evidence['slow_pts'] = 25;
            } else {
                $evidence['avg_ms'] = round($avgMs, 2);
                $evidence['slow_pts'] = 0;
            }

            $hasFullScan = DB::table('index_advisor_explains')
                ->where('fingerprint', $query->fingerprint)
                ->where('has_full_scan', true)
                ->exists();

            if ($hasFullScan) {
                $score += 20;
                $evidence['full_scan'] = true;
                $evidence['full_scan_pts'] = 20;
            } else {
                $evidence['full_scan'] = false;
                $evidence['full_scan_pts'] = 0;
            }

            $score = $this->scoreCalculator->applyMaxDurationSpike($score, $evidence, (float) ($query->max_duration_ms ?? 0), $slowMs);

            $evidence['verdict'] = $this->scoreCalculator->verdict($score);

            if (DB::getDriverName() === 'pgsql') {
                DB::statement(
                    'INSERT INTO index_advisor_recommendations
                        (table_name, column_name, index_type, score, evidence, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                     ON CONFLICT (table_name, column_name, index_type) DO UPDATE SET
                        score      = EXCLUDED.score,
                        evidence   = EXCLUDED.evidence,
                        updated_at = EXCLUDED.updated_at',
                    [$table, $columnKey, 'COMPOSITE', min(100, $score), json_encode($evidence), 'pending', now(), now()]
                );
            } else {
                DB::table('index_advisor_recommendations')->upsert(
                    [
                        'table_name' => $table,
                        'column_name' => $columnKey,
                        'index_type' => 'COMPOSITE',
                        'score' => min(100, $score),
                        'evidence' => json_encode($evidence),
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    ['table_name', 'column_name', 'index_type'],
                    ['score', 'evidence', 'updated_at']
                );
            }

            $saved++;
        }

        // Delete only stale runtime_sql composite recommendations that were not
        // regenerated in this run — avoids the write-heavy DELETE-all pattern.
        $this->deleteStaleRuntimeCompositeRecommendations(array_keys($processedKeys));

        return $saved;
    }

    /**
     * Remove stale runtime_sql composite recommendations not present in the current run.
     */
    private function deleteStaleRuntimeCompositeRecommendations(array $processedKeys): void
    {
        if ($processedKeys === []) {
            return;
        }

        $existing = DB::table('index_advisor_recommendations')
            ->where('index_type', 'COMPOSITE')
            ->where('status', 'pending')
            ->where('evidence', 'like', '%"source":"runtime_sql"%')
            ->get(['id', 'table_name', 'column_name']);

        $staleIds = [];
        foreach ($existing as $row) {
            $key = "{$row->table_name}|{$row->column_name}";
            if (! in_array($key, $processedKeys, true)) {
                $staleIds[] = $row->id;
            }
        }

        if ($staleIds !== []) {
            foreach (array_chunk($staleIds, 1000) as $chunk) {
                DB::table('index_advisor_recommendations')->whereIn('id', $chunk)->delete();
            }
        }
    }

    /**
     * @return array{string, array<int, string>}|null
     */
    private function extractRuntimeCompositeCandidate(string $sql): ?array
    {
        if (! preg_match('/\bfrom\s+"?([a-zA-Z_][a-zA-Z0-9_]*)"?/i', $sql, $tableMatch)) {
            return null;
        }

        $table = $tableMatch[1];
        if (! preg_match('/\bwhere\b(.+?)(?:\border\s+by\b|\bgroup\s+by\b|\bhaving\b|\blimit\b|$)/is', $sql, $whereMatch)) {
            return null;
        }

        $where = $whereMatch[1];
        $columns = [];

        preg_match_all(
            '/(?:"[a-zA-Z_][a-zA-Z0-9_]*"\.)?"([a-zA-Z_][a-zA-Z0-9_]*)"\s*(?:=|\blike\b|\bilike\b|\bin\s*\()/i',
            $where,
            $quotedMatches
        );

        foreach ($quotedMatches[1] as $column) {
            $columns[] = $column;
        }

        preg_match_all(
            '/\b([a-zA-Z_][a-zA-Z0-9_]*)\b\s*(?:=|\blike\b|\bilike\b|\bin\s*\()/i',
            preg_replace('/"[^"]+"/', ' ', $where),
            $plainMatches
        );

        foreach ($plainMatches[1] as $column) {
            $columns[] = $column;
        }

        $columns = collect($columns)
            ->map(fn ($column) => strtolower($column))
            ->reject(fn ($column) => in_array($column, [
                'id', 'created_at', 'updated_at', 'deleted_at',
                'created_by', 'updated_by', 'deleted_by',
            ], true))
            ->unique()
            ->values()
            ->all();

        if (count($columns) < 2) {
            return null;
        }

        return [$table, $this->sortCompositeColumns($table, $columns)];
    }

    private function hasCompositeIndex(string $table, array $columns): bool
    {
        $wanted = $this->sortCompositeColumns($table, $columns);
        $indexes = collect($this->schema->getExistingIndexes($table))
            ->groupBy(fn ($idx) => $idx->index_name ?? spl_object_id($idx))
            ->map(fn ($group) => $group
                ->pluck('column_name')
                ->filter()
                ->map(fn ($column) => strtolower($column))
                ->values()
                ->all()
            );

        foreach ($indexes as $indexedColumns) {
            $prefix = array_slice($indexedColumns, 0, count($wanted));

            if ($prefix === $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * Collapse (a,b) vs (b,a) COMPOSITE rows — keep highest score, normalize column order.
     */
    private function deduplicateCompositeRecommendations(): void
    {
        $pending = DB::table('index_advisor_recommendations')
            ->where('index_type', 'COMPOSITE')
            ->whereIn('status', ['pending', 'generated'])
            ->get();

        $groups = [];

        foreach ($pending as $rec) {
            $cols = array_map('trim', explode(',', $rec->column_name));
            $key = $rec->table_name.'|'.$this->canonicalCompositeColumnKey($rec->table_name, $cols);
            $groups[$key][] = $rec;
        }

        foreach ($groups as $group) {
            if (count($group) <= 1) {
                $rec = $group[0];
                $canonical = $this->canonicalCompositeColumnKey($rec->table_name, explode(',', $rec->column_name));
                if ($rec->column_name !== $canonical) {
                    DB::table('index_advisor_recommendations')
                        ->where('id', $rec->id)
                        ->update(['column_name' => $canonical, 'updated_at' => now()]);
                }

                continue;
            }

            usort($group, fn ($a, $b) => $b->score <=> $a->score);
            $keep = $group[0];
            $canonical = $this->canonicalCompositeColumnKey($keep->table_name, explode(',', $keep->column_name));

            DB::table('index_advisor_recommendations')
                ->where('id', $keep->id)
                ->update(['column_name' => $canonical, 'updated_at' => now()]);

            foreach (array_slice($group, 1) as $dup) {
                $evidence = json_decode($dup->evidence ?? '{}', true);
                $evidence['dismissed_at'] = now()->toDateTimeString();
                $evidence['dismiss_reason'] = 'Duplicate composite column order; merged into canonical key '.$canonical;
                $evidence['reconciled'] = true;

                DB::table('index_advisor_recommendations')
                    ->where('id', $dup->id)
                    ->update([
                        'status' => 'dismissed',
                        'evidence' => json_encode($evidence),
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function canonicalCompositeColumnKey(string $table, array $columns): string
    {
        return implode(',', $this->sortCompositeColumns($table, $columns));
    }

    /**
     * Order composite columns by descending cardinality (most selective first).
     *
     * @param  array<int, string>  $columns
     * @return array<int, string>
     */
    private function sortCompositeColumns(string $table, array $columns): array
    {
        $normalized = array_map('strtolower', $columns);

        usort($normalized, function (string $a, string $b) use ($table): int {
            $cardA = $this->schema->getColumnCardinality($table, $a);
            $cardB = $this->schema->getColumnCardinality($table, $b);

            if ($cardA === null && $cardB === null) {
                return $a <=> $b;
            }

            if ($cardA === null) {
                return 1;
            }

            if ($cardB === null) {
                return -1;
            }

            if ($cardA !== $cardB) {
                return $cardB <=> $cardA;
            }

            return $a <=> $b;
        });

        return array_values($normalized);
    }

    private function columnWeights(array $columns): array
    {
        $count = count($columns);
        if ($count === 0) {
            return [];
        }

        $total = ($count * ($count + 1)) / 2;

        return collect($columns)
            ->values()
            ->map(function ($column, $index) use ($count, $total) {
                $weight = (($count - $index) / $total) * 100;

                return [
                    'column' => $column,
                    'position' => $index + 1,
                    'weight_percent' => round($weight, 2),
                    'composite_points' => round(15 * ($weight / 100), 2),
                    'reason' => $index === 0
                        ? 'Leading column gets the highest weight because composite indexes are left-prefix sensitive.'
                        : 'Lower weight because this column is useful after preceding composite columns are matched.',
                ];
            })
            ->all();
    }
}
