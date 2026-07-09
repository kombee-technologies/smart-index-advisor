<?php

namespace Kombee\IndexAdvisor\Services;

/**
 * Validates that a SQL string is safe to pass to EXPLAIN.
 *
 * Only read-only SELECT statements are permitted. Anything that could execute
 * writes, DDL, privilege changes, or stacked statements is rejected.
 */
class ExplainQueryGuard
{
    /** @var list<string> */
    private const BLOCKED_KEYWORDS = [
        'insert', 'update', 'delete', 'drop', 'alter', 'create', 'truncate',
        'grant', 'revoke', 'exec', 'execute', 'call', 'merge', 'replace',
        'outfile', 'dumpfile', 'load_file', 'benchmark', 'sleep', 'pg_sleep',
        'xp_', 'sp_', 'prepare', 'deallocate',
    ];

    public function isSafeToExplain(string $sql): bool
    {
        $sql = trim($sql);

        if ($sql === '' || str_contains($sql, "\0")) {
            return false;
        }

        // Strip quoted literals FIRST so that #, --, /*, ; inside string values
        // (e.g. WHERE color = '#ff0000') don't trigger false-positive rejections.
        $stripped = $this->stripQuotedLiterals($sql);
        $normalized = preg_replace('/\s+/', ' ', $stripped ?? $sql);
        $normalized = ltrim($normalized ?? '');

        if ($this->hasMultipleStatements($normalized)) {
            return false;
        }

        if ($this->hasCommentInjection($normalized)) {
            return false;
        }

        if (! preg_match('/^select\b/i', $normalized)) {
            return false;
        }

        foreach (self::BLOCKED_KEYWORDS as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $normalized)) {
                return false;
            }
        }

        return true;
    }

    private function hasMultipleStatements(string $sql): bool
    {
        return (bool) preg_match('/;[\s\S]/', $sql);
    }

    private function hasCommentInjection(string $sql): bool
    {
        return (bool) preg_match('/--|\/\*|\*\/|#/', $sql);
    }

    private function stripQuotedLiterals(string $sql): string
    {
        $sql = preg_replace("/'(?:''|[^'])*'/", "''", $sql) ?? $sql;
        $sql = preg_replace('/"(?:[^"]|"")*"/', '""', $sql) ?? $sql;
        $sql = preg_replace('/`(?:[^`]|``)*`/', '``', $sql) ?? $sql;

        return $sql;
    }
}
