<?php

namespace Kombee\IndexAdvisor\Helpers;

use InvalidArgumentException;

/**
 * Validates and safely quotes MySQL identifiers when a DDL statement
 * cannot use bound parameters (e.g. SHOW / ALTER).
 */
class MysqlIdentifier
{
    public static function assertSafe(string $identifier): void
    {
        if ($identifier === '' || preg_match('/[\x00;]/', $identifier)) {
            throw new InvalidArgumentException('Invalid MySQL identifier.');
        }

        if (str_contains($identifier, '`')) {
            throw new InvalidArgumentException('Invalid MySQL identifier.');
        }
    }

    public static function quote(string $identifier): string
    {
        self::assertSafe($identifier);

        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
