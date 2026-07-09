<?php

namespace Kombee\IndexAdvisor\Services;

use Illuminate\Support\Facades\DB;

/**
 * Runs EXPLAIN plans without interpolating untrusted SQL literals into queries.
 *
 * Live queries (DB::listen) use PDO parameter binding. Stored sql_sample rows
 * without bindings use type-aware default binds (not bare 0 literals) before EXPLAIN.
 */
class ExplainPlanRunner
{
    private ?string $lastSkipReason = null;

    public function __construct(
        private readonly ExplainQueryGuard $guard = new ExplainQueryGuard,
        private readonly ExplainPlanAnalyzer $planAnalyzer = new ExplainPlanAnalyzer,
    ) {}

    /**
     * Return the reason the most recent run() call was skipped, or null if it succeeded.
     */
    public function getLastSkipReason(): ?string
    {
        return $this->lastSkipReason;
    }

    /**
     * @return array{0: bool, 1: mixed}|null [hasFullScan, plan] or null when skipped
     */
    public function run(string $driver, string $sql, array $bindings = []): ?array
    {
        $this->lastSkipReason = null;

        if (! $this->guard->isSafeToExplain($sql)) {
            $this->lastSkipReason = 'Skipped non-analyzable/write query (Guard)';

            return null;
        }

        if ($driver === 'pgsql') {
            // pg_stat_statements preserves PgSQL native bindings ($1, $2, etc.)
            // Convert them to ? so buildSafeDefaultBindings can parse and bind them.
            $sql = preg_replace('/\$[0-9]+/', '?', $sql);
        }

        $bindings = $this->normalizeBindings($bindings);
        $prepared = $this->prepareSqlForExplain($sql, $bindings);

        if ($prepared === null) {
            $this->lastSkipReason = 'Placeholder/binding count mismatch with provided bindings';

            return null;
        }

        if (! $this->guard->isSafeToExplain($prepared)) {
            $this->lastSkipReason = 'Skipped non-analyzable/write query (Guard)';

            return null;
        }

        try {
            [$planJson, $plan] = $this->executeExplain($driver, $prepared, $bindings);

            return [$this->planAnalyzer->hasFullTableScan($planJson, $driver), $plan];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'syntax error at or near ".."')) {
                $this->lastSkipReason = 'Skipped query (truncated by database logs)';
            } else {
                $this->lastSkipReason = 'EXPLAIN failed: ' . $msg;
            }

            return null;
        }
    }

    /**
     * @param  array<int, mixed>  $bindings
     */
    private function prepareSqlForExplain(string $sql, array &$bindings): ?string
    {
        $placeholderCount = $this->countPlaceholdersOutsideLiterals($sql);

        if ($placeholderCount === 0) {
            $bindings = [];

            return $sql;
        }

        if ($placeholderCount === count($bindings)) {
            return $sql;
        }

        if ($bindings !== []) {
            return null;
        }

        $bindings = $this->buildSafeDefaultBindings($sql);

        return $sql;
    }

    /**
     * @return array<int, mixed>
     */
    private function buildSafeDefaultBindings(string $sql): array
    {
        $bindings = [];
        $offset = 0;

        while (($pos = strpos($sql, '?', $offset)) !== false) {
            $context = strtolower(substr($sql, max(0, $pos - 60), 60));
            $bindings[] = $this->inferSafeBindingValue($context);
            $offset = $pos + 1;
        }

        return $bindings;
    }

    private function inferSafeBindingValue(string $contextBefore): mixed
    {
        $trimmed = strtolower(trim($contextBefore));

        if (preg_match('/\blike\s*$/', $trimmed) === 1) {
            return '%';
        }

        if (preg_match('/\b(in|between)\s*$/', $trimmed) === 1) {
            return null;
        }

        if (preg_match('/\b(is|is not)\s*$/', $trimmed) === 1) {
            return null;
        }

        if (preg_match('/\b(limit|offset)\s*$/', $trimmed) === 1) {
            return 1;
        }

        if (preg_match('/\b(date|time|dt|at)\b.*[=<>!]+\s*$/i', $contextBefore) === 1) {
            return '2020-01-01';
        }

        if (preg_match('/\b(id|_id|_no|_num|count|amount|qty|number)\b.*[=<>!]+\s*$/i', $contextBefore) === 1) {
            return 1;
        }

        // Return null instead of '' to avoid strict type errors in PgSQL (e.g. invalid syntax for boolean/bigint)
        return null;
    }

    private function countPlaceholdersOutsideLiterals(string $sql): int
    {
        return substr_count($this->stripForPlaceholderCount($sql), '?');
    }

    private function stripForPlaceholderCount(string $sql): string
    {
        $sql = preg_replace("/'(?:''|[^'])*'/", '', $sql) ?? $sql;
        $sql = preg_replace('/"(?:[^"]|"")*"/', '', $sql) ?? $sql;
        $sql = preg_replace('/`(?:[^`]|``)*`/', '', $sql) ?? $sql;

        return $sql;
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return array{0: string, 1: mixed}
     */
    private function executeExplain(string $driver, string $sql, array $bindings): array
    {
        if ($driver === 'sqlsrv') {
            return $this->executeSqlSrvExplain($sql, $bindings);
        }

        $plan = match ($driver) {
            'mysql' => DB::select('EXPLAIN FORMAT=JSON '.$sql, $bindings),
            'pgsql' => DB::select('EXPLAIN (ANALYZE false, FORMAT JSON) '.$sql, $bindings),
            'sqlite' => DB::select('EXPLAIN QUERY PLAN '.$sql, $bindings),
            default => throw new \RuntimeException("Unsupported driver [{$driver}] for EXPLAIN."),
        };

        if (in_array($driver, ['mysql', 'pgsql']) && !empty($plan)) {
            $first = (array) $plan[0];
            $jsonStr = $first['EXPLAIN'] ?? $first['QUERY PLAN'] ?? current($first);
            if (is_string($jsonStr)) {
                $decoded = json_decode($jsonStr, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $plan = $decoded;
                }
            }
        }

        $planJson = json_encode($plan);

        return [$planJson, $plan];
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return array{0: string, 1: mixed}
     */
    private function executeSqlSrvExplain(string $sql, array $bindings): array
    {
        DB::unprepared('SET SHOWPLAN_XML ON');

        try {
            $plan = DB::select($sql, $bindings);
        } finally {
            DB::unprepared('SET SHOWPLAN_XML OFF');
        }

        $planJson = json_encode($plan);

        return [$planJson, $plan];
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return array<int, mixed>
     */
    private function normalizeBindings(array $bindings): array
    {
        return array_map(static function ($binding) {
            if ($binding instanceof \DateTimeInterface) {
                return $binding->format('Y-m-d H:i:s');
            }

            if (is_bool($binding)) {
                return $binding ? 1 : 0;
            }

            return $binding;
        }, $bindings);
    }
}
