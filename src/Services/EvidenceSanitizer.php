<?php

namespace Kombee\IndexAdvisor\Services;

/**
 * Removes sensitive SQL fragments from recommendation evidence before API output.
 */
class EvidenceSanitizer
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'sql_sample',
        'query',
        'query_text',
        'digest_text',
        'sql_query',
    ];

    public function forApi(array|object|null $evidence): object
    {
        if ($evidence === null) {
            return (object) [];
        }

        $decoded = is_array($evidence)
            ? $evidence
            : (array) json_decode(json_encode($evidence), true);

        foreach (self::SENSITIVE_KEYS as $key) {
            unset($decoded[$key]);
        }

        return (object) $decoded;
    }
}
