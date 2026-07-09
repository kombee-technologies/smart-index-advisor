<?php

namespace Kombee\IndexAdvisor\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Correlates index_advisor_columns with runtime queries via SQL parsing and
 * fingerprints — replaces LIKE '%column%' joins in ScoringService.
 *
 * Uses an inverted index built by SqlColumnMatcher::extractReferencedColumns()
 * so that each query SQL is parsed once (O(Q)) instead of once per column
 * candidate (O(C × Q)).
 */
class QueryColumnCorrelator
{
    public function __construct(private SqlColumnMatcher $matcher) {}

    /**
     * @return array<int, object{table_name: string, column_name: string, query_type: string, exec_count: int, avg_ms: float, max_duration_ms: float, has_full_scan: bool, matched_fingerprints: array<int, string>}>
     */
    public function correlateSingleColumnCandidates(array $excludedTables): array
    {
        $columns = DB::table('index_advisor_columns')
            ->whereNotIn('table_name', $excludedTables)
            ->get();

        $context = $this->loadQueryContext();
        $candidates = [];

        foreach ($columns->groupBy(fn ($c) => "{$c->table_name}|{$c->column_name}|{$c->query_type}") as $group) {
            $col = $group->first();
            $stats = $this->aggregateForColumn($col, $context);

            $candidates[] = (object) [
                'table_name' => $col->table_name,
                'column_name' => $col->column_name,
                'query_type' => $col->query_type,
                'exec_count' => $stats['exec_count'],
                'avg_ms' => $stats['avg_ms'],
                'max_duration_ms' => $stats['max_duration_ms'],
                'has_full_scan' => $stats['has_full_scan'],
                'matched_fingerprints' => $stats['fingerprints'],
            ];
        }

        return $candidates;
    }

    /**
     * Build the best scoring candidate for one table/column from code-scan + query stats.
     */
    public function buildCandidateForColumn(string $table, string $column, array $excludedTables = []): ?object
    {
        if (in_array($table, $excludedTables, true)) {
            return null;
        }

        $rows = DB::table('index_advisor_columns')
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->where('table_name', '!=', 'unknown')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $context = $this->loadQueryContext();
        $best = null;
        $bestExec = -1;

        foreach ($rows->groupBy('query_type') as $group) {
            $col = $group->first();
            $stats = $this->aggregateForColumn($col, $context);

            if ($stats['exec_count'] > $bestExec) {
                $bestExec = $stats['exec_count'];
                $best = (object) [
                    'table_name' => $col->table_name,
                    'column_name' => $col->column_name,
                    'query_type' => $col->query_type,
                    'exec_count' => $stats['exec_count'],
                    'avg_ms' => $stats['avg_ms'],
                    'max_duration_ms' => $stats['max_duration_ms'],
                    'has_full_scan' => $stats['has_full_scan'],
                    'matched_fingerprints' => $stats['fingerprints'],
                ];
            }
        }

        return $best;
    }

    /**
     * @return array<int, object{fingerprint: string, table_name: string, execution_count: int, total_duration_ms: float, max_duration_ms: float, columns: array<int, string>}>
     */
    public function correlateCompositeCandidates(array $excludedTables, int $minColumns): array
    {
        $context = $this->loadQueryContext();
        $columns = DB::table('index_advisor_columns')
            ->whereNotIn('table_name', $excludedTables)
            ->whereIn('query_type', ['where', 'join', 'orWhere'])
            ->get()
            ->groupBy('table_name');

        $results = [];
        $seenByCanonical = [];
        $index = $context['index'];

        foreach ($context['queries'] as $query) {
            $queryMatches = $index['queries'][$query->fingerprint] ?? [];

            foreach ($columns as $table => $tableColumns) {
                // Skip queries that do not reference this table at all
                if (! isset($queryMatches[strtolower($table)])) {
                    continue;
                }

                $tableMatch = $queryMatches[strtolower($table)];

                $matched = $this->sortColumns(
                    $tableColumns
                        ->unique('column_name')
                        ->filter(function ($col) use ($tableMatch) {
                            $qt = $col->query_type;

                            return in_array(
                                strtolower($col->column_name),
                                $tableMatch[$qt] ?? $tableMatch['where'] ?? [],
                                true
                            );
                        })
                        ->pluck('column_name')
                        ->all()
                );

                if (count($matched) < $minColumns) {
                    continue;
                }

                $canonicalKey = "{$table}|".implode(',', $matched);

                if (isset($seenByCanonical[$canonicalKey])) {
                    continue;
                }

                $seenByCanonical[$canonicalKey] = true;

                $results[] = (object) [
                    'fingerprint' => $query->fingerprint,
                    'table_name' => $table,
                    'execution_count' => (int) $query->execution_count,
                    'total_duration_ms' => (float) $query->total_duration_ms,
                    'max_duration_ms' => (float) ($query->max_duration_ms ?? 0),
                    'columns' => $matched,
                ];
            }
        }

        return $results;
    }

    /**
     * Load queries, stats, full-scan flags, and build the inverted index.
     *
     * Builds BOTH a forward index (fingerprint → tables/columns) for composite
     * correlation AND a reverse index (table → column → queryType → fingerprints)
     * so that aggregateForColumn() can look up matching queries in O(1)
     * instead of iterating all queries.
     *
     * @return array{queries: Collection, stats: Collection, full_scan: array<string, true>, index: array{queries: array<string, array<string, array<string, array<int, string>>>>, stats: array<string, array<string, array<string, array<int, string>>>>}, reverse_index: array{queries: array<string, array<string, array<string, array<string, true>>>>, stats: array<string, array<string, array<string, array<string, true>>>>}}
     */
    private function loadQueryContext(): array
    {
        $limit = (int) config('index_advisor.scoring.correlation_query_limit', 2000);
        $driver = DB::getDriverName();

        $queries = DB::table('index_advisor_queries')
            ->orderByDesc('execution_count')
            ->limit($limit)
            ->get(['fingerprint', 'sql_sample', 'execution_count', 'total_duration_ms', 'max_duration_ms']);

        $stats = DB::table('index_advisor_query_stats')
            ->where('db_driver', $driver)
            ->orderByDesc('avg_duration_ms')
            ->limit($limit)
            ->get(['fingerprint', 'sql_sample', 'avg_duration_ms']);

        // Build forward index: fingerprint → table → queryType → [column, ...]
        // AND reverse index: table → column → queryType → [fingerprint => true, ...]
        $queryIndex = [];
        $reverseQueryIndex = [];
        foreach ($queries as $q) {
            $tables = $this->matcher->extractReferencedTables($q->sql_sample);
            $columnsByType = $this->matcher->extractReferencedColumns($q->sql_sample);
            $entry = [];

            foreach ($tables as $table) {
                $entry[$table] = $columnsByType;

                foreach ($columnsByType as $queryType => $columns) {
                    foreach ($columns as $column) {
                        $reverseQueryIndex[$table][$column][$queryType][$q->fingerprint] = true;
                    }
                }
            }

            $queryIndex[$q->fingerprint] = $entry;
        }

        $statsIndex = [];
        $reverseStatsIndex = [];
        foreach ($stats as $s) {
            if (isset($queryIndex[$s->fingerprint])) {
                continue; // already indexed via queries
            }

            $tables = $this->matcher->extractReferencedTables($s->sql_sample);
            $columnsByType = $this->matcher->extractReferencedColumns($s->sql_sample);
            $entry = [];

            foreach ($tables as $table) {
                $entry[$table] = $columnsByType;

                foreach ($columnsByType as $queryType => $columns) {
                    foreach ($columns as $column) {
                        $reverseStatsIndex[$table][$column][$queryType][$s->fingerprint] = true;
                    }
                }
            }

            $statsIndex[$s->fingerprint] = $entry;
        }

        return [
            'queries' => $queries,
            'stats' => $stats,
            'query_map' => $queries->keyBy('fingerprint')->all(),
            'stats_map' => $stats->keyBy('fingerprint')->all(),
            'full_scan' => DB::table('index_advisor_explains')
                ->where('has_full_scan', true)
                ->pluck('fingerprint')
                ->flip()
                ->all(),
            'index' => [
                'queries' => $queryIndex,
                'stats' => $statsIndex,
            ],
            'reverse_index' => [
                'queries' => $reverseQueryIndex,
                'stats' => $reverseStatsIndex,
            ],
        ];
    }

    /**
     * Aggregate runtime stats for a single column using the reverse index.
     *
     * Instead of iterating all queries (O(Q)) and running regex per iteration,
     * this performs an O(1) lookup in the pre-built reverse index.
     *
     * @param  array  $context  returned by loadQueryContext()
     * @return array{exec_count: int, avg_ms: float, max_duration_ms: float, has_full_scan: bool, fingerprints: array<int, string>}
     */
    private function aggregateForColumn(object $col, array $context): array
    {
        $execCount = 0;
        $totalMs = 0.0;
        $maxDurationMs = 0.0;
        $fingerprints = [];
        $hasFullScan = false;
        $statAvgSamples = [];

        $tableLower = strtolower($col->table_name);
        $columnLower = strtolower($col->column_name);
        $queryType = $col->query_type;

        // O(1) lookup: which query fingerprints reference this table+column+type?
        $matchingFingerprints = $context['reverse_index']['queries'][$tableLower][$columnLower][$queryType] ?? [];

        foreach ($matchingFingerprints as $fp => $_) {
            $query = $context['query_map'][$fp] ?? null;

            if (! $query) {
                continue;
            }

            $execCount += (int) $query->execution_count;
            $totalMs += (float) $query->total_duration_ms;
            $maxDurationMs = max($maxDurationMs, (float) ($query->max_duration_ms ?? 0));
            $fingerprints[] = $fp;

            if (isset($context['full_scan'][$fp])) {
                $hasFullScan = true;
            }
        }

        // Also check the stats-only reverse index for fingerprints not already matched
        $statsFingerprints = $context['reverse_index']['stats'][$tableLower][$columnLower][$queryType] ?? [];

        foreach ($statsFingerprints as $fp => $_) {
            if (isset($matchingFingerprints[$fp])) {
                continue;
            }

            $stat = $context['stats_map'][$fp] ?? null;

            if ($stat) {
                $statAvgSamples[] = (float) $stat->avg_duration_ms;

                if (isset($context['full_scan'][$fp])) {
                    $hasFullScan = true;
                }
            }
        }

        $avgMs = $execCount > 0
            ? $totalMs / $execCount
            : ($statAvgSamples !== [] ? (float) max($statAvgSamples) : 0.0);

        return [
            'exec_count' => $execCount,
            'avg_ms' => $avgMs,
            'max_duration_ms' => $maxDurationMs,
            'has_full_scan' => $hasFullScan,
            'fingerprints' => array_values(array_unique($fingerprints)),
        ];
    }

    /**
     * @param  array<int, string>  $columns
     * @return array<int, string>
     */
    private function sortColumns(array $columns): array
    {
        $sorted = array_map('strtolower', $columns);
        sort($sorted, SORT_STRING);

        return array_values($sorted);
    }
}
