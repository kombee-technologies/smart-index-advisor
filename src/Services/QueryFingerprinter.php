<?php

namespace Kombee\IndexAdvisor\Services;

/**
 * Centralised query fingerprinting service.
 *
 * Normalises a raw SQL string into a stable, repeatable identifier so that
 * the same logical query executed with different literal values always maps
 * to the same fingerprint.
 *
 * Normalisation steps (applied in order):
 *   1. Strip single-quoted string literals  → ?
 *   2. Collapse IN / VALUES lists           → IN (?)
 *   3. Replace bare numeric literals        → ?
 *   4. Collapse consecutive whitespace      → single space
 *   5. Lowercase the result
 *   6. MD5-hash the normalised string
 */
class QueryFingerprinter
{
    /**
     * Return a 32-char hex fingerprint for the given SQL.
     */
    public function fingerprint(string $sql): string
    {
        return md5($this->normalize($sql));
    }

    /**
     * Return the normalised (human-readable) form of the SQL.
     * Useful for debugging / logging.
     */
    public function normalize(string $sql): string
    {
        // 1. Strip single-quoted string literals  'foo' → ?
        $sql = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $sql);

        // 2. Collapse IN / VALUES lists  IN (1, 2, 3) → IN (?)
        $sql = preg_replace('/\bIN\s*\([^)]+\)/i', 'IN (?)', $sql);

        // 3. Replace bare numeric literals  42, 3.14 → ?
        $sql = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql);

        // 4. Collapse whitespace
        $sql = preg_replace('/\s+/', ' ', trim($sql));

        // 5. Lowercase
        return strtolower($sql);
    }
}
