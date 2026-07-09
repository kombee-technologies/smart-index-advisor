<?php

namespace Kombee\IndexAdvisor\Services;

use Illuminate\Support\Facades\DB;

/**
 * Persists runtime / ingested query rows with driver-specific upsert syntax.
 */
class QueryLogUpserter
{
    public function record(
        string $fingerprint,
        string $sqlSample,
        float $durationMs,
        mixed $lastSeenAt,
        int $executionCount = 1,
        ?float $totalDurationMs = null,
        ?float $maxDurationMs = null,
    ): void {
        $totalDurationMs ??= $durationMs;
        $maxDurationMs ??= $durationMs;

        match (DB::getDriverName()) {
            'pgsql' => $this->upsertPgsql($fingerprint, $sqlSample, $durationMs, $lastSeenAt),
            'mysql' => $this->upsertMysql($fingerprint, $sqlSample, $executionCount, $totalDurationMs, $maxDurationMs, $lastSeenAt),
            'sqlite' => $this->upsertSqlite($fingerprint, $sqlSample, $durationMs, $lastSeenAt),
            default => $this->upsertGeneric($fingerprint, $sqlSample, $executionCount, $totalDurationMs, $maxDurationMs, $lastSeenAt),
        };
    }

    public function mergeIngestedRow(
        string $fingerprint,
        string $sqlSample,
        int $executionCount,
        float $totalDurationMs,
        float $maxDurationMs,
        mixed $lastSeenAt,
    ): void {
        $this->recordBuffered($fingerprint, $sqlSample, $executionCount, $totalDurationMs, $maxDurationMs, $lastSeenAt);
    }

    /**
     * Upsert aggregated buffer data, ADDING execution_count and total_duration_ms
     * to any existing row. Used by RuntimeQueryListener::flush().
     */
    public function recordBuffered(
        string $fingerprint,
        string $sqlSample,
        int $executionCount,
        float $totalDurationMs,
        float $maxDurationMs,
        mixed $lastSeenAt,
    ): void {
        match (DB::getDriverName()) {
            'pgsql' => $this->upsertBufferedPgsql($fingerprint, $sqlSample, $executionCount, $totalDurationMs, $maxDurationMs, $lastSeenAt),
            'mysql' => $this->upsertMysql($fingerprint, $sqlSample, $executionCount, $totalDurationMs, $maxDurationMs, $lastSeenAt),
            'sqlite' => $this->upsertBufferedSqlite($fingerprint, $sqlSample, $executionCount, $totalDurationMs, $maxDurationMs, $lastSeenAt),
            default => $this->upsertBufferedGeneric($fingerprint, $sqlSample, $executionCount, $totalDurationMs, $maxDurationMs, $lastSeenAt),
        };
    }

    private function upsertPgsql(
        string $fingerprint,
        string $sqlSample,
        float $durationMs,
        mixed $lastSeenAt,
    ): void {
        DB::statement(
            'INSERT INTO index_advisor_queries
                (fingerprint, sql_sample, execution_count, total_duration_ms, max_duration_ms, last_seen_at)
             VALUES (?, ?, 1, ?, ?, ?)
             ON CONFLICT (fingerprint) DO UPDATE SET
                execution_count   = index_advisor_queries.execution_count + 1,
                total_duration_ms = index_advisor_queries.total_duration_ms + EXCLUDED.total_duration_ms,
                max_duration_ms   = GREATEST(index_advisor_queries.max_duration_ms, EXCLUDED.max_duration_ms),
                last_seen_at      = EXCLUDED.last_seen_at',
            [$fingerprint, $sqlSample, $durationMs, $durationMs, $lastSeenAt]
        );
    }

    private function upsertMysql(
        string $fingerprint,
        string $sqlSample,
        int $executionCount,
        float $totalDurationMs,
        float $maxDurationMs,
        mixed $lastSeenAt,
    ): void {
        DB::statement(
            'INSERT INTO index_advisor_queries
                (fingerprint, sql_sample, execution_count, total_duration_ms, max_duration_ms, last_seen_at)
             VALUES (?, ?, ?, ?, ?, ?) AS new
             ON DUPLICATE KEY UPDATE
                execution_count   = index_advisor_queries.execution_count + new.execution_count,
                total_duration_ms = index_advisor_queries.total_duration_ms + new.total_duration_ms,
                max_duration_ms   = GREATEST(index_advisor_queries.max_duration_ms, new.max_duration_ms),
                last_seen_at      = new.last_seen_at',
            [$fingerprint, $sqlSample, $executionCount, $totalDurationMs, $maxDurationMs, $lastSeenAt]
        );
    }

    private function upsertSqlite(
        string $fingerprint,
        string $sqlSample,
        float $durationMs,
        mixed $lastSeenAt,
    ): void {
        DB::statement(
            'INSERT INTO index_advisor_queries
                (fingerprint, sql_sample, execution_count, total_duration_ms, max_duration_ms, last_seen_at)
             VALUES (?, ?, 1, ?, ?, ?)
             ON CONFLICT(fingerprint) DO UPDATE SET
                execution_count   = index_advisor_queries.execution_count + 1,
                total_duration_ms = index_advisor_queries.total_duration_ms + excluded.total_duration_ms,
                max_duration_ms   = MAX(index_advisor_queries.max_duration_ms, excluded.max_duration_ms),
                last_seen_at      = excluded.last_seen_at',
            [$fingerprint, $sqlSample, $durationMs, $durationMs, $lastSeenAt]
        );
    }

    private function upsertGeneric(
        string $fingerprint,
        string $sqlSample,
        int $executionCount,
        float $totalDurationMs,
        float $maxDurationMs,
        mixed $lastSeenAt,
    ): void {
        DB::table('index_advisor_queries')->upsert(
            [
                'fingerprint' => $fingerprint,
                'sql_sample' => $sqlSample,
                'execution_count' => $executionCount,
                'total_duration_ms' => $totalDurationMs,
                'max_duration_ms' => $maxDurationMs,
                'last_seen_at' => $lastSeenAt,
            ],
            ['fingerprint'],
            ['execution_count', 'total_duration_ms', 'max_duration_ms', 'last_seen_at']
        );
    }

    private function upsertBufferedPgsql(
        string $fingerprint,
        string $sqlSample,
        int $executionCount,
        float $totalDurationMs,
        float $maxDurationMs,
        mixed $lastSeenAt,
    ): void {
        DB::statement(
            'INSERT INTO index_advisor_queries
                (fingerprint, sql_sample, execution_count, total_duration_ms, max_duration_ms, last_seen_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (fingerprint) DO UPDATE SET
                execution_count   = index_advisor_queries.execution_count + EXCLUDED.execution_count,
                total_duration_ms = index_advisor_queries.total_duration_ms + EXCLUDED.total_duration_ms,
                max_duration_ms   = GREATEST(index_advisor_queries.max_duration_ms, EXCLUDED.max_duration_ms),
                last_seen_at      = EXCLUDED.last_seen_at',
            [$fingerprint, $sqlSample, $executionCount, $totalDurationMs, $maxDurationMs, $lastSeenAt]
        );
    }

    private function upsertBufferedSqlite(
        string $fingerprint,
        string $sqlSample,
        int $executionCount,
        float $totalDurationMs,
        float $maxDurationMs,
        mixed $lastSeenAt,
    ): void {
        DB::statement(
            'INSERT INTO index_advisor_queries
                (fingerprint, sql_sample, execution_count, total_duration_ms, max_duration_ms, last_seen_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(fingerprint) DO UPDATE SET
                execution_count   = index_advisor_queries.execution_count + excluded.execution_count,
                total_duration_ms = index_advisor_queries.total_duration_ms + excluded.total_duration_ms,
                max_duration_ms   = MAX(index_advisor_queries.max_duration_ms, excluded.max_duration_ms),
                last_seen_at      = excluded.last_seen_at',
            [$fingerprint, $sqlSample, $executionCount, $totalDurationMs, $maxDurationMs, $lastSeenAt]
        );
    }

    private function upsertBufferedGeneric(
        string $fingerprint,
        string $sqlSample,
        int $executionCount,
        float $totalDurationMs,
        float $maxDurationMs,
        mixed $lastSeenAt,
    ): void {
        $existing = DB::table('index_advisor_queries')->where('fingerprint', $fingerprint)->first();

        if ($existing) {
            DB::table('index_advisor_queries')
                ->where('fingerprint', $fingerprint)
                ->update([
                    'execution_count' => $existing->execution_count + $executionCount,
                    'total_duration_ms' => $existing->total_duration_ms + $totalDurationMs,
                    'max_duration_ms' => max($existing->max_duration_ms, $maxDurationMs),
                    'last_seen_at' => $lastSeenAt,
                ]);
        } else {
            DB::table('index_advisor_queries')->insert([
                'fingerprint' => $fingerprint,
                'sql_sample' => $sqlSample,
                'execution_count' => $executionCount,
                'total_duration_ms' => $totalDurationMs,
                'max_duration_ms' => $maxDurationMs,
                'last_seen_at' => $lastSeenAt,
            ]);
        }
    }
}
