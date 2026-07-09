<?php

namespace Kombee\IndexAdvisor\Services;

/**
 * Parses EXPLAIN output to detect full table scans without fragile substring checks.
 */
class ExplainPlanAnalyzer
{
    public function hasFullTableScan(string $planJson, string $driver): bool
    {
        return match ($driver) {
            'mysql' => $this->mysqlHasFullTableScan($planJson),
            'pgsql' => $this->pgsqlHasSeqScan($planJson),
            'sqlsrv' => $this->sqlsrvHasTableScan($planJson),
            'sqlite' => $this->sqliteHasScan($planJson),
            default => false,
        };
    }

    private function mysqlHasFullTableScan(string $planJson): bool
    {
        $decoded = json_decode($planJson, true);
        if (! is_array($decoded)) {
            return false;
        }

        // Handle cleanly unpacked structure
        if (isset($decoded['query_block'])) {
            return $this->mysqlSearchAccessType($decoded, 'ALL');
        }

        // Fallback for older double-encoded structure
        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }

            $explain = $row['EXPLAIN'] ?? null;
            if (is_string($explain)) {
                $explain = json_decode($explain, true);
            }

            if (is_array($explain) && $this->mysqlSearchAccessType($explain, 'ALL')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function mysqlSearchAccessType(array $node, string $accessType): bool
    {
        if (($node['access_type'] ?? null) === $accessType) {
            return true;
        }

        foreach (['table', 'nested_loop', 'group', 'ordering_operation', 'query_block'] as $key) {
            $child = $node[$key] ?? null;
            if (is_array($child) && $this->mysqlSearchAccessType($child, $accessType)) {
                return true;
            }
        }

        if (isset($node['tables']) && is_array($node['tables'])) {
            foreach ($node['tables'] as $table) {
                if (is_array($table) && $this->mysqlSearchAccessType($table, $accessType)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function pgsqlHasSeqScan(string $planJson): bool
    {
        $decoded = json_decode($planJson, true);
        if (! is_array($decoded)) {
            return false;
        }

        // Handle cleanly unpacked structure: array of plans or a single plan object
        $roots = isset($decoded['Plan']) ? [$decoded] : $decoded;

        foreach ($roots as $root) {
            if (! is_array($root)) {
                continue;
            }

            $plan = $root['Plan'] ?? $root;
            if (is_array($plan) && $this->pgsqlSearchNodeType($plan, 'Seq Scan')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function pgsqlSearchNodeType(array $node, string $nodeType): bool
    {
        if (($node['Node Type'] ?? null) === $nodeType) {
            return true;
        }

        foreach ($node['Plans'] ?? [] as $child) {
            if (is_array($child) && $this->pgsqlSearchNodeType($child, $nodeType)) {
                return true;
            }
        }

        return false;
    }

    private function sqlsrvHasTableScan(string $planJson): bool
    {
        $decoded = json_decode($planJson, true);
        if (! is_array($decoded)) {
            return str_contains($planJson, 'Table Scan');
        }

        return $this->sqlsrvSearchPlan($decoded);
    }

    /**
     * @param  array<int|string, mixed>  $node
     */
    private function sqlsrvSearchPlan(array $node): bool
    {
        foreach ($node as $key => $value) {
            if (is_string($key) && strcasecmp($key, 'PhysicalOp') === 0 && $value === 'Table Scan') {
                return true;
            }

            if (is_array($value) && $this->sqlsrvSearchPlan($value)) {
                return true;
            }
        }

        return false;
    }

    private function sqliteHasScan(string $planJson): bool
    {
        $decoded = json_decode($planJson, true);
        if (! is_array($decoded)) {
            return str_contains($planJson, 'SCAN TABLE');
        }

        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }

            $detail = strtolower((string) ($row['detail'] ?? ''));
            if (str_contains($detail, 'scan table') || preg_match('/\bscan\b/', $detail) === 1) {
                return true;
            }
        }

        return false;
    }
}
