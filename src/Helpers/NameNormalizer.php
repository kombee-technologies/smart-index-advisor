<?php

namespace Kombee\IndexAdvisor\Helpers;

/**
 * Normalises table and column names for case-insensitive comparisons.
 */
class NameNormalizer
{
    public static function normalize(string $name): string
    {
        return strtolower($name);
    }
}
