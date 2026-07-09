<?php

namespace Kombee\IndexAdvisor\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * Static Code Analyzer — Deep line-level scan of PHP source files.
 *
 * Goes beyond the basic AnalyzeCodebaseCommand by:
 *  - Tracking exact line numbers for each column usage
 *  - Detecting whereIn, whereBetween, whereNull, whereHas, select()
 *  - Resolving model → table mappings via $table property or naming convention
 *  - Generating fingerprints for correlation with runtime data
 *  - Extracting context snippets around each match
 *
 * Results are stored in `index_advisor_code_patterns`.
 */
class StaticCodeAnalyzer
{
    /**
     * Extended regex patterns keyed by expression type.
     * Each pattern has one capture group for the column name.
     */
    private array $patterns = [
        'where'        => '/->where\(\s*[\'\"]([a-zA-Z_][a-zA-Z0-9_.]*)[\'\"]/m',
        'orWhere'      => '/->orWhere\(\s*[\'\"]([a-zA-Z_][a-zA-Z0-9_.]*)[\'\"]/m',
        'whereIn'      => '/->whereIn\(\s*[\'\"]([a-zA-Z_][a-zA-Z0-9_.]*)[\'\"]/m',
        'whereNotIn'   => '/->whereNotIn\(\s*[\'\"]([a-zA-Z_][a-zA-Z0-9_.]*)[\'\"]/m',
        'whereBetween' => '/->whereBetween\(\s*[\'\"]([a-zA-Z_][a-zA-Z0-9_.]*)[\'\"]/m',
        'whereNull'    => '/->whereNull\(\s*[\'\"]([a-zA-Z_][a-zA-Z0-9_.]*)[\'\"]/m',
        'whereNotNull' => '/->whereNotNull\(\s*[\'\"]([a-zA-Z_][a-zA-Z0-9_.]*)[\'\"]/m',
        'whereHas'     => '/->whereHas\(\s*[\'\"]([a-zA-Z_][a-zA-Z0-9_.]*)[\'\"]/m',
        'join'         => '/->(?:join|leftJoin|rightJoin|crossJoin)\(\s*[\'\"][a-zA-Z_]+[\'\"]\s*,\s*[\'\"](?:[a-zA-Z_]+\.)([a-zA-Z_]+)[\'\"]/m',
        'orderBy'      => '/->orderBy\(\s*[\'\"]([a-zA-Z_][a-zA-Z0-9_.]*)[\'\"]/m',
        'orderByDesc'  => '/->orderByDesc\(\s*[\'\"]([a-zA-Z_][a-zA-Z0-9_.]*)[\'\"]/m',
        'groupBy'      => '/->groupBy\(\s*[\'\"]([a-zA-Z_][a-zA-Z0-9_.]*)[\'\"]/m',
        'having'       => '/->having\(\s*[\'\"]([a-zA-Z_][a-zA-Z0-9_.]*)[\'\"]/m',
        'select'       => '/->select\(\s*(?:\[\s*)?[\'\"]([a-zA-Z_][a-zA-Z0-9_.]*)[\'\"]/m',
        'pluck'        => '/->pluck\(\s*[\'\"]([a-zA-Z_][a-zA-Z0-9_.]*)[\'\"]/m',
        'scope'        => '/->scope[A-Z]\w*\(.*?[\'\"]([a-zA-Z_][a-zA-Z0-9_.]*)[\'\"]/m',
        'rawWhere'     => '/\bWHERE\b\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s*(?:=|!=|<>|IN\s*\(|LIKE|>=?|<=?|IS\s+(?:NOT\s+)?NULL)/im',
        'rawJoin'      => '/\bJOIN\b\s+`?\w+`?\s+(?:AS\s+\w+\s+)?ON\s+`?(?:\w+\.)?([a-zA-Z_][a-zA-Z0-9_]*)`?\s*=/im',
        'rawOrder'     => '/\bORDER\s+BY\b\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/im',
        'rawGroup'     => '/\bGROUP\s+BY\b\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/im',
    ];

    /** Columns that are noise and never useful to index-advise. */
    private array $skipColumns = [
        'id', 'created_at', 'updated_at', 'deleted_at',
        'created_by', 'updated_by', 'deleted_by',
        '*', 'null', 'true', 'false', 'password',
        'remember_token', 'email_verified_at',
    ];

    /** Cache of model class → table name mappings. */
    private array $modelTableMap = [];

    /**
     * Run the full static analysis.
     *
     * @return array{files: int, patterns: int}
     */
    public function analyze(): array
    {
        $directories = $this->getDirectories();
        $results     = [];
        $filesCount  = 0;

        foreach ($directories as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $finder = (new Finder())
                ->files()
                ->in($dir)
                ->name('*.php')
                ->notPath(['vendor', 'storage', 'node_modules']);

            foreach ($finder as $file) {
                $contents = $file->getContents();
                $path     = $file->getRelativePathname();
                $lines    = explode("\n", $contents);
                $filesCount++;

                // Try to resolve the model/table for this file
                $tableName = $this->resolveTableName($contents, $path);

                foreach ($this->patterns as $type => $regex) {
                    // Search line-by-line for precise line numbers
                    foreach ($lines as $lineIndex => $lineContent) {
                        if (! preg_match_all($regex, $lineContent, $matches)) {
                            continue;
                        }

                        foreach ($matches[1] as $column) {
                            $column = $this->normalizeColumn($column);

                            if ($this->shouldSkip($column)) {
                                continue;
                            }

                            $lineNumber = $lineIndex + 1;
                            $fingerprint = md5("{$tableName}|{$column}|{$type}");
                            $context = $this->extractContext($lines, $lineIndex, 2);

                            $results[] = [
                                'fingerprint'     => $fingerprint,
                                'file_path'       => $path,
                                'line_number'     => $lineNumber,
                                'table_name'      => $tableName,
                                'column_name'     => $column,
                                'expression_type' => $type,
                                'context_snippet' => Str::limit($context, 500),
                                'detected_at'     => now(),
                            ];
                        }
                    }
                }
            }
        }

        // Deduplicate by file + line + column + type
        $unique = collect($results)
            ->unique(fn ($r) => $r['file_path'] . '|' . $r['line_number'] . '|' . $r['column_name'] . '|' . $r['expression_type'])
            ->values()
            ->all();

        // Truncate and reload
        DB::table('index_advisor_code_patterns')->truncate();

        foreach (array_chunk($unique, 500) as $chunk) {
            DB::table('index_advisor_code_patterns')->insert($chunk);
        }

        return ['files' => $filesCount, 'patterns' => count($unique)];
    }

    /**
     * Get summary of patterns grouped by table and expression type.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getSummary(): \Illuminate\Support\Collection
    {
        return DB::table('index_advisor_code_patterns')
            ->select('table_name', 'column_name', 'expression_type')
            ->selectRaw('COUNT(*) as occurrence_count')
            ->selectRaw('GROUP_CONCAT(DISTINCT file_path) as files')
            ->groupBy('table_name', 'column_name', 'expression_type')
            ->orderBy('table_name')
            ->get();
    }

    // ─── Private helpers ───────────────────────────────────────────────────────

    /**
     * Resolve the database table name from file contents or naming convention.
     */
    private function resolveTableName(string $contents, string $path): string
    {
        // 1. Try to find explicit $table property in the class
        if (preg_match('/protected\s+\$table\s*=\s*[\'\"]([a-zA-Z_][a-zA-Z0-9_]*)[\'\"]/', $contents, $m)) {
            return $m[1];
        }

        // 2. Try to find the model class name and pluralize
        if (preg_match('/class\s+(\w+)\s+extends\s+Model/', $contents, $m)) {
            return Str::snake(Str::plural($m[1]));
        }

        // 3. Infer from file path
        return $this->inferTableFromPath($path);
    }

    /**
     * Infer table name from file path using naming conventions.
     */
    private function inferTableFromPath(string $path): string
    {
        $base = pathinfo(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), PATHINFO_FILENAME);

        // Remove common suffixes that aren't part of the model name
        $base = preg_replace(
            '/(?:Table|Component|Controller|Request|Observer|Policy|Job|Command|Seeder|Factory|Service|Repository|Action|Trait|Scope)$/',
            '',
            $base
        );

        if (empty($base)) {
            return 'unknown';
        }

        return Str::snake(Str::plural($base));
    }

    /**
     * Normalize a column name (strip table prefix, lowercase).
     */
    private function normalizeColumn(string $column): string
    {
        // Strip table prefix (e.g., "users.email" → "email")
        if (str_contains($column, '.')) {
            $column = Str::afterLast($column, '.');
        }

        return strtolower(trim($column));
    }

    /**
     * Check if a column should be skipped.
     */
    private function shouldSkip(string $column): bool
    {
        if (in_array($column, $this->skipColumns, true)) {
            return true;
        }

        // Skip if it looks like a variable, not a real column
        if (str_starts_with($column, '$') || strlen($column) < 2) {
            return true;
        }

        return false;
    }

    /**
     * Extract surrounding context lines for a match.
     */
    private function extractContext(array $lines, int $lineIndex, int $radius = 2): string
    {
        $start = max(0, $lineIndex - $radius);
        $end   = min(count($lines) - 1, $lineIndex + $radius);

        $context = [];
        for ($i = $start; $i <= $end; $i++) {
            $marker = ($i === $lineIndex) ? '>>>' : '   ';
            $context[] = $marker . ' ' . ($i + 1) . ': ' . trim($lines[$i]);
        }

        return implode("\n", $context);
    }

    /**
     * Get the directories to scan from config.
     */
    private function getDirectories(): array
    {
        $configured = config('index_advisor.scan_directories', [
            'app/Models',
            'app/Http/Controllers',
            'app/Livewire',
            'app/Services',
        ]);

        return array_map(fn ($dir) => base_path($dir), $configured);
    }
}
