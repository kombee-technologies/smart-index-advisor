<?php

namespace Kombee\IndexAdvisor\Services;

use Illuminate\Support\Facades\DB;
use Kombee\IndexAdvisor\Contracts\SchemaIntrospectorContract;

/**
 * Computes single-column recommendation scores and persists rows.
 */
class RecommendationScoreCalculator
{
    public function __construct(private SchemaIntrospectorContract $schema) {}

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function upsertSingleColumnRecommendation(
        string $table,
        string $column,
        string $indexType,
        int $score,
        array $evidence,
    ): void {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'INSERT INTO index_advisor_recommendations
                    (table_name, column_name, index_type, score, evidence, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (table_name, column_name, index_type) DO UPDATE SET
                    score      = EXCLUDED.score,
                    evidence   = EXCLUDED.evidence,
                    status     = CASE
                        WHEN index_advisor_recommendations.status = \'dismissed\' THEN \'pending\'
                        ELSE index_advisor_recommendations.status
                    END,
                    updated_at = EXCLUDED.updated_at',
                [
                    $table,
                    $column,
                    $indexType,
                    min(100, $score),
                    json_encode($evidence),
                    'pending',
                    now(),
                    now(),
                ]
            );

            return;
        }

        $existing = DB::table('index_advisor_recommendations')
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->where('index_type', $indexType)
            ->first();

        if ($existing) {
            DB::table('index_advisor_recommendations')
                ->where('id', $existing->id)
                ->update([
                    'score' => min(100, $score),
                    'evidence' => json_encode($evidence),
                    'status' => $existing->status === 'dismissed' ? 'pending' : $existing->status,
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('index_advisor_recommendations')->insert([
            'table_name' => $table,
            'column_name' => $column,
            'index_type' => $indexType,
            'score' => min(100, $score),
            'evidence' => json_encode($evidence),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function applyMaxDurationSpike(int $score, array &$evidence, float $maxDurationMs, float $slowMs): int
    {
        $multiplier = (float) config('index_advisor.scoring.max_duration_multiplier', 3);
        $pts = (int) config('index_advisor.scoring.max_duration_pts', 5);
        $threshold = $slowMs * $multiplier;

        $evidence['max_duration_ms'] = round($maxDurationMs, 2);
        $evidence['max_duration_threshold_ms'] = round($threshold, 2);

        if ($maxDurationMs > $threshold) {
            $score += $pts;
            $evidence['max_duration_spike'] = true;
            $evidence['max_duration_pts'] = $pts;
        } else {
            $evidence['max_duration_spike'] = false;
            $evidence['max_duration_pts'] = 0;
        }

        return $score;
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function computeScore(object $c, bool $indexed, float $slowMs, ?int $rowCount, ?int $cardinality): array
    {
        $score = 0;
        $evidence = [
            'table_name' => $c->table_name,
            'column_name' => $c->column_name,
            'query_type' => $c->query_type,
        ];

        $execPts = $this->frequencyScore((int) $c->exec_count);
        $score += $execPts;
        $evidence['exec_count'] = (int) $c->exec_count;
        $evidence['exec_score_pts'] = $execPts;

        if ((float) $c->avg_ms > $slowMs) {
            $score += 25;
            $evidence['avg_ms'] = round((float) $c->avg_ms, 2);
            $evidence['slow_pts'] = 25;
        } else {
            $evidence['avg_ms'] = round((float) $c->avg_ms, 2);
            $evidence['slow_pts'] = 0;
        }

        $maxDurationMs = property_exists($c, 'max_duration_ms') ? (float) $c->max_duration_ms : 0.0;
        $score = $this->applyMaxDurationSpike($score, $evidence, $maxDurationMs, $slowMs);

        if ((bool) $c->has_full_scan) {
            $score += 20;
            $evidence['full_scan'] = true;
            $evidence['full_scan_pts'] = 20;
        } else {
            $evidence['full_scan'] = false;
            $evidence['full_scan_pts'] = 0;
        }

        $clausePts = match ($c->query_type) {
            'where', 'join', 'orWhere' => 10,
            'orderBy', 'groupBy', 'having' => 5,
            default => 0,
        };
        $score += $clausePts;
        $evidence['clause_pts'] = $clausePts;

        if (str_ends_with($c->column_name, '_id')) {
            $score += 5;
            $evidence['fk_heuristic'] = true;
            $evidence['fk_heuristic_pts'] = 5;
        } else {
            $evidence['fk_heuristic'] = false;
            $evidence['fk_heuristic_pts'] = 0;
        }

        if (! $indexed) {
            $score += 5;
            $evidence['no_existing_index'] = true;
            $evidence['no_existing_index_pts'] = 5;
        } else {
            $evidence['already_indexed'] = true;
            $evidence['no_existing_index_pts'] = 0;
        }

        if ($rowCount !== null) {
            $evidence['table_row_count'] = $rowCount;
        }
        if ($cardinality !== null) {
            $evidence['column_cardinality'] = $cardinality;
        }

        if (property_exists($c, 'matched_fingerprints') && is_array($c->matched_fingerprints)) {
            $evidence['matched_fingerprint_count'] = count($c->matched_fingerprints);
            $evidence['correlation_method'] = 'fingerprint_sql_match';
        }

        $liveIndexes = $this->schema->getColumnIndexDetails($c->table_name, $c->column_name);
        $evidence['live_indexed'] = $liveIndexes !== [];
        $evidence['live_indexes'] = $liveIndexes;
        $evidence['live_schema_checked_at'] = now()->toDateTimeString();

        $evidence['verdict'] = $this->verdict($score);

        return [$score, $evidence];
    }

    public function frequencyScore(int $execCount): int
    {
        if ($execCount <= 0) {
            return 0;
        }

        return (int) min(30, (log10($execCount + 1) / log10(1001)) * 30);
    }

    public function verdict(int $score): string
    {
        $thresholds = (array) config('index_advisor.scoring.verdict_thresholds', []);

        if ($score >= (int) ($thresholds['critical'] ?? 80)) {
            return 'CRITICAL - Create index immediately';
        }
        if ($score >= (int) ($thresholds['high'] ?? 60)) {
            return 'HIGH - Create index this sprint';
        }
        if ($score >= (int) ($thresholds['medium'] ?? 40)) {
            return 'MEDIUM - Investigate composite opportunity';
        }
        if ($score >= (int) ($thresholds['low'] ?? 20)) {
            return 'LOW - Monitor';
        }

        return 'IGNORE';
    }
}
