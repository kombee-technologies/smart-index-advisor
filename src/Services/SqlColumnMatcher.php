<?php

namespace Kombee\IndexAdvisor\Services;

/**
 * Determines whether a SQL sample references a specific table/column in the
 * appropriate clause. Avoids naive LIKE '%column%' matching (e.g. "id" in
 * "modified_at").
 */
class SqlColumnMatcher
{
    private const WHERE_TYPES = ['where', 'orWhere', 'join', 'rawWhere', 'rawJoin', 'whereIn', 'scope'];

    private const ORDER_TYPES = ['orderBy', 'rawOrder'];

    private const GROUP_TYPES = ['groupBy', 'having'];

    public function matches(string $sql, string $table, string $column, string $queryType): bool
    {
        if (! $this->referencesTable($sql, $table)) {
            return false;
        }

        return match (true) {
            in_array($queryType, self::WHERE_TYPES, true) => $this->columnInWhereOrJoin($sql, $column),
            in_array($queryType, self::ORDER_TYPES, true) => $this->columnInOrderBy($sql, $column),
            in_array($queryType, self::GROUP_TYPES, true) => $this->columnInGroupByOrHaving($sql, $column, $queryType),
            default => $this->columnInWhereOrJoin($sql, $column),
        };
    }

    public function referencesTable(string $sql, string $table): bool
    {
        $table = preg_quote(strtolower($table), '/');

        return (bool) preg_match(
            '/\bfrom\s+"?'.$table.'"?|\bjoin\s+"?'.$table.'"?/i',
            $sql
        );
    }

    private function columnInWhereOrJoin(string $sql, string $column): bool
    {
        if (! preg_match('/\bwhere\b(.+?)(?:\border\s+by\b|\bgroup\s+by\b|\bhaving\b|\blimit\b|$)/is', $sql, $match)) {
            return $this->columnAppearsAsPredicate($sql, $column);
        }

        return $this->columnAppearsAsPredicate($match[1], $column)
            || $this->columnInJoinOn($sql, $column);
    }

    private function columnInJoinOn(string $sql, string $column): bool
    {
        $quoted = preg_quote($column, '/');

        return (bool) preg_match(
            '/\bjoin\b.+?\bon\b.+?(?:"'.$quoted.'"|\.\"'.$quoted.'\"|\b'.$quoted.'\b\s*=)/is',
            $sql
        );
    }

    private function columnInOrderBy(string $sql, string $column): bool
    {
        if (! preg_match('/\border\s+by\b(.+?)(?:\blimit\b|$)/is', $sql, $match)) {
            return false;
        }

        return $this->columnInClauseList($match[1], $column);
    }

    private function columnInGroupByOrHaving(string $sql, string $column, string $queryType): bool
    {
        $clause = $queryType === 'having' ? 'having' : 'group\s+by';

        if (! preg_match('/\b'.$clause.'\b(.+?)(?:\bhaving\b|\border\s+by\b|\blimit\b|$)/is', $sql, $match)) {
            return false;
        }

        return $this->columnInClauseList($match[1], $column);
    }

    private function columnInClauseList(string $clause, string $column): bool
    {
        $quoted = preg_quote($column, '/');

        return (bool) preg_match('/"'.$quoted.'"(?:\s|,|$)|\b'.$quoted.'\b/i', $clause);
    }

    /**
     * Extract all table names referenced in FROM / JOIN clauses.
     *
     * @return array<int, string>  lowercased table names
     */
    public function extractReferencedTables(string $sql): array
    {
        preg_match_all('/\b(?:from|join)\s+"?([a-zA-Z_][a-zA-Z0-9_]*)"?/i', $sql, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1] ?? [])));
    }

    /**
     * Extract column names per query-type clause from a SQL sample.
     *
     * Used to build an inverted index so that each query is parsed once
     * instead of once per (table, column, queryType) candidate.
     *
     * @return array<string, array<int, string>>  keyed by query type, values are lowercased column names
     */
    public function extractReferencedColumns(string $sql): array
    {
        $result = [];

        // WHERE clause (everything between WHERE and ORDER BY / GROUP BY / HAVING / LIMIT)
        if (preg_match('/\bwhere\b(.+?)(?:\border\s+by\b|\bgroup\s+by\b|\bhaving\b|\blimit\b|$)/is', $sql, $m)) {
            $where = $m[1];

            // Quoted predicate columns:  "col" = / <> / != / >= / <= / IN ( / LIKE / ILIKE
            preg_match_all(
                '/(?:"[a-zA-Z_][a-zA-Z0-9_]*"\.)?"([a-zA-Z_][a-zA-Z0-9_]*)"\s*(?:=|<>|!=|>=?|<=?|\bin\s*\(|\blike\b|\bilike\b)/i',
                $where,
                $quoted
            );
            $cols = $quoted[1] ?? [];

            // Bare predicate columns (after stripping quoted identifiers to avoid false positives)
            $stripped = preg_replace('/"[^"]+"/', ' ', $where);
            preg_match_all(
                '/\b([a-zA-Z_][a-zA-Z0-9_]*)\b\s*(?:=|<>|!=|>=?|<=?|\bin\s*\(|\blike\b|\bilike\b)/i',
                $stripped,
                $bare
            );
            $cols = array_merge($cols, $bare[1] ?? []);

            // JOIN ON columns:  JOIN ... ON ... "col" =
            preg_match_all(
                '/\bjoin\b.+?\bon\b.+?(?:"([a-zA-Z_][a-zA-Z0-9_]*)"|\.\"([a-zA-Z_][a-zA-Z0-9_]*)\"|\b([a-zA-Z_][a-zA-Z0-9_]*)\b\s*=)/is',
                $sql,
                $joinMatches
            );
            foreach (array_merge($joinMatches[1] ?? [], $joinMatches[2] ?? [], $joinMatches[3] ?? []) as $col) {
                if ($col !== '') {
                    $cols[] = $col;
                }
            }

            $result['where'] = array_values(array_unique(array_map('strtolower', $cols)));
            $result['join'] = $result['where'];
            $result['orWhere'] = $result['where'];
            $result['rawWhere'] = $result['where'];
            $result['rawJoin'] = $result['where'];
            $result['whereIn'] = $result['where'];
            $result['scope'] = $result['where'];
        }

        // ORDER BY
        if (preg_match('/\border\s+by\b(.+?)(?:\blimit\b|$)/is', $sql, $m)) {
            preg_match_all('/"([a-zA-Z_][a-zA-Z0-9_]*)"(?:\s|,|$)|\b([a-zA-Z_][a-zA-Z0-9_]*)\b/i', $m[1], $cols);
            $result['orderBy'] = array_values(array_unique(array_map('strtolower',
                array_filter(array_merge($cols[1] ?? [], $cols[2] ?? []))
            )));
            $result['rawOrder'] = $result['orderBy'];
        }

        // GROUP BY
        if (preg_match('/\bgroup\s+by\b(.+?)(?:\bhaving\b|\border\s+by\b|\blimit\b|$)/is', $sql, $m)) {
            preg_match_all('/"([a-zA-Z_][a-zA-Z0-9_]*)"(?:\s|,|$)|\b([a-zA-Z_][a-zA-Z0-9_]*)\b/i', $m[1], $cols);
            $result['groupBy'] = array_values(array_unique(array_map('strtolower',
                array_filter(array_merge($cols[1] ?? [], $cols[2] ?? []))
            )));
        }

        // HAVING
        if (preg_match('/\bhaving\b(.+?)(?:\border\s+by\b|\blimit\b|$)/is', $sql, $m)) {
            preg_match_all('/"([a-zA-Z_][a-zA-Z0-9_]*)"(?:\s|,|$)|\b([a-zA-Z_][a-zA-Z0-9_]*)\b/i', $m[1], $cols);
            $result['having'] = array_values(array_unique(array_map('strtolower',
                array_filter(array_merge($cols[1] ?? [], $cols[2] ?? []))
            )));
        }

        return $result;
    }

    private function columnAppearsAsPredicate(string $fragment, string $column): bool
    {
        $quoted = preg_quote($column, '/');

        return (bool) preg_match(
            '/(?:"[a-zA-Z_][a-zA-Z0-9_]*"\.)?"'.$quoted.'"\s*(?:=|<>|!=|>=?|<=?|\bin\s*\(|\blike\b|\bilike\b)/i',
            $fragment
        ) || (bool) preg_match(
            '/\b'.$quoted.'\b\s*(?:=|<>|!=|>=?|<=?|\bin\s*\(|\blike\b|\bilike\b)/i',
            preg_replace('/"[^"]+"/', ' ', $fragment)
        );
    }
}
