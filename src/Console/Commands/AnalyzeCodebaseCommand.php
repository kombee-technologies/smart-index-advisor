<?php

namespace Kombee\IndexAdvisor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kombee\IndexAdvisor\Contracts\SchemaIntrospectorContract;
use Symfony\Component\Finder\Finder;

/**
 * Statically scans configured PHP paths and extracts column names used in:
 *   - Eloquent / Query Builder fluent chains
 *   - Raw DB::select / DB::statement calls
 *   - Eloquent local scope methods (scopeWhere*)
 *   - Raw SQL WHERE / ORDER BY / JOIN clauses
 *
 * Results are upserted into `index_advisor_columns`.
 *
 * Usage:
 *   php artisan index-advisor:analyze-code
 */
class AnalyzeCodebaseCommand extends Command
{
    protected $signature = 'index-advisor:analyze-code';

    protected $description = 'Statically scan the PHP codebase to extract query column usage patterns';

    /**
     * Regex patterns keyed by query clause type.
     * Each pattern has exactly one capture group: the column name.
     */
    private array $patterns = [
        // Fluent builder
        'where' => '/->where\(\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]/',
        'orWhere' => '/->orWhere\(\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]/',
        'join' => '/->(?:join|leftJoin|rightJoin|crossJoin)\(\s*[\'"][a-zA-Z_]+[\'"]\s*,\s*[\'"](?:[a-zA-Z_]+\.)([a-zA-Z_]+)[\'"]/',
        'orderBy' => '/->orderBy\(\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]/',
        'groupBy' => '/->groupBy\(\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]/',
        'having' => '/->having\(\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]/',

        // Raw SQL strings (inside DB::select / DB::statement / whereRaw)
        'rawWhere' => '/\bWHERE\b\s+[`"]?([a-zA-Z_][a-zA-Z0-9_]*)[`"]?\s*(?:=|!=|<>|IN\s*\(|LIKE|>=?|<=?)/i',
        'rawOrder' => '/\bORDER\s+BY\b\s+[`"]?([a-zA-Z_][a-zA-Z0-9_]*)[`"]?/i',
        'rawJoin' => '/\bJOIN\b\s+\S+\s+ON\s+\S+\s*=\s*\S+\.([a-zA-Z_][a-zA-Z0-9_]*)/i',

        // Eloquent local scopes: public function scopeWhereStatus(...) { ->where('status', ...)
        'scope' => '/function\s+scope[A-Z][a-zA-Z0-9]*\s*\([^)]*\)[^{]*\{[^}]*->where\(\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]/',

        // whereIn / whereNotIn / whereBetween
        'whereIn' => '/->where(?:In|NotIn|Between)\(\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]/',
    ];

    /** Columns that are always noise and never useful to index-advise */
    private array $skipColumns = [
        'id', 'created_at', 'updated_at', 'deleted_at',
        'created_by', 'updated_by', 'deleted_by',
        '*', 'null', 'true', 'false', '1', '0',
    ];

    public function handle(): int
    {
        $this->info('🔍 Scanning codebase for query patterns...');

        $introspector = app(SchemaIntrospectorContract::class);
        $scanPaths = $this->scanPaths();

        if ($scanPaths === []) {
            $this->error('No valid code analysis paths found. Set INDEX_ADVISOR_CODE_PATHS or ensure app_path() exists.');

            return Command::FAILURE;
        }

        $this->line('  Paths: '.implode(', ', $scanPaths));

        $finder = (new Finder)
            ->files()
            ->in($scanPaths)
            ->name('*.php')
            ->notPath(['vendor', 'storage']);

        $results = [];
        $files = 0;

        foreach ($finder as $file) {
            $contents = $file->getContents();
            $path = $file->getRelativePathname();
            $files++;

            foreach ($this->patterns as $type => $regex) {
                if (! preg_match_all($regex, $contents, $matches)) {
                    continue;
                }

                foreach ($matches[1] as $column) {
                    $column = \Kombee\IndexAdvisor\Helpers\NameNormalizer::normalize(trim($column));

                    if (in_array($column, $this->skipColumns, true) || strlen($column) < 2) {
                        continue;
                    }

                    $table = $this->inferTableFromPath($path);
                    $resolvedTable = $introspector->resolveTableName($table);
                    if ($resolvedTable !== null) {
                        $table = $resolvedTable;
                        $canonicalColumn = $introspector->canonicalColumnName($table, $column);
                        if ($canonicalColumn !== null) {
                            $column = $canonicalColumn;
                        }
                    }

                    $results[] = [
                        'source_file' => $path,
                        'query_type' => $type,
                        'column_name' => $column,
                        'table_name' => $table,
                        'detected_at' => now(),
                    ];
                }
            }
        }

        // Deduplicate in PHP before upserting
        $unique = collect($results)
            ->unique(fn ($r) => $r['source_file'].'|'.$r['query_type'].'|'.$r['column_name'])
            ->values()
            ->all();

        foreach (array_chunk($unique, 500) as $chunk) {
            DB::table('index_advisor_columns')->upsert(
                $chunk,
                ['source_file', 'query_type', 'column_name'],
                ['table_name', 'detected_at']
            );
        }

        $this->info("✅  Scanned {$files} files — stored ".count($unique).' unique column-usage records.');

        return Command::SUCCESS;
    }

    /**
     * Attempt to infer the table name from the file path.
     *
     * Resolution order:
     *   1. Manual override from config('index_advisor.table_map')
     *   2. Eloquent model App\Models\<Base>::getTable()
     *   3. Str::snake(Str::plural(<Base>))
     */
    private function inferTableFromPath(string $path): string
    {
        $base = pathinfo(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), PATHINFO_FILENAME);

        // Remove common suffixes
        $base = preg_replace(
            '/(?:Table|Component|Controller|Request|Observer|Policy|Job|Command|Seeder|Factory|Export|Import|Resource|Listener|Event|Scope)$/',
            '',
            $base
        );

        if (empty($base)) {
            return 'unknown';
        }

        // 1. Manual override map — highest priority
        $tableMap = (array) config('index_advisor.table_map', []);
        if (isset($tableMap[$base])) {
            return $tableMap[$base];
        }

        // 2. Try to resolve via Eloquent model if the class exists
        $modelClass = 'App\\Models\\'.$base;
        if (class_exists($modelClass)) {
            try {
                $model = new $modelClass;
                if (method_exists($model, 'getTable')) {
                    return $model->getTable();
                }
            } catch (\Throwable) {
                // Fall through to snake_case plural
            }
        }

        // 3. Fallback: snake_case plural
        return Str::snake(Str::plural($base));
    }

    /**
     * @return array<int, string>
     */
    private function scanPaths(): array
    {
        $configured = config('index_advisor.code_analysis.paths', []);

        if (! is_array($configured) || $configured === []) {
            $resolved = $this->resolveScanPath('app');

            return $resolved !== null ? [$resolved] : [];
        }

        $paths = [];

        foreach ($configured as $path) {
            if (! is_string($path)) {
                continue;
            }

            $resolved = $this->resolveScanPath($path);
            if ($resolved !== null) {
                $paths[] = $resolved;
            }
        }

        return array_values(array_unique($paths));
    }

    private function resolveScanPath(string $path): ?string
    {
        $path = trim($path);

        if ($path === '') {
            return null;
        }

        $candidates = [$path];

        if (! str_starts_with($path, DIRECTORY_SEPARATOR) && ! preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
            $candidates[] = base_path($path);
        }

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        return null;
    }
}
