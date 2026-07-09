<?php

namespace Kombee\IndexAdvisor\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kombee\IndexAdvisor\Http\Controllers\Concerns\DispatchesIndexAdvisorTasks;
use Kombee\IndexAdvisor\Jobs\GenerateIndexAdvisorMigrationsJob;
use Kombee\IndexAdvisor\Jobs\RunIndexAdvisorPipelineJob;
use Kombee\IndexAdvisor\Services\EvidenceSanitizer;
use Kombee\IndexAdvisor\Contracts\SchemaIntrospectorContract;
use Kombee\IndexAdvisor\Services\SqlColumnMatcher;
use Kombee\IndexAdvisor\Services\StatsImportUpload;

class IndexAdvisorController extends Controller
{
    use DispatchesIndexAdvisorTasks;

    public function __construct(
        private SqlColumnMatcher $sqlMatcher,
        private SchemaIntrospectorContract $schema,
        private StatsImportUpload $statsImportUpload,
        private EvidenceSanitizer $evidenceSanitizer,
    ) {}

    public function index()
    {
        return view('smart-index-advisor::dashboard', array_merge($this->viewData(), [
            'active' => 'recommendations',
            'showActions' => true,
        ]));
    }

    public function migrationsPage()
    {
        return view('smart-index-advisor::migrations', array_merge($this->viewData(), [
            'active' => 'migrations',
        ]));
    }

    public function queriesPage()
    {
        return view('smart-index-advisor::queries', array_merge($this->viewData(), [
            'active' => 'queries',
        ]));
    }

    public function overviewPage()
    {
        return view('smart-index-advisor::overview', array_merge($this->viewData(), [
            'active' => 'overview',
        ]));
    }

    public function getRecommendations(Request $request)
    {
        $perPage = (int) $request->query('per_page', 0);

        $query = DB::table('index_advisor_recommendations')
            ->orderByDesc('score')
            ->orderByDesc('id');

        $mapRec = function ($rec) {
            $rec->evidence = $this->evidenceSanitizer->forApi(json_decode($rec->evidence ?? '{}', true));
            // Use cached schema check to avoid a DB round-trip per row.
            // SchemaIntrospector is a singleton, so repeated table lookups
            // hit the in-process cache after the first miss.
            $liveIndexes = $this->schema->getColumnIndexDetails($rec->table_name, $rec->column_name);
            $rec->live_indexed = $liveIndexes !== [];
            $rec->live_indexes = $liveIndexes;
            $rec->schema_stale = $this->isSchemaStale($rec);

            return $rec;
        };

        if ($perPage > 0) {
            $paginator = $query->paginate(min(100, max(1, $perPage)));
            $recommendations = $paginator->getCollection()->map($mapRec);

            return response()->json([
                'recommendations' => $recommendations,
                'stats' => $this->recommendationStatsFromDb(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ]);
        }

        // Backward-compatible path when no per_page is requested.
        $recommendations = $query->get()->map($mapRec);

        return response()->json([
            'recommendations' => $recommendations,
            'stats' => $this->recommendationStats($recommendations),
        ]);
    }

    public function getOverview()
    {
        $recommendations = DB::table('index_advisor_recommendations')->get();
        $sampleLimit = 30;

        $explains = DB::table('index_advisor_explains as e')
            ->leftJoin('index_advisor_queries as q', 'q.fingerprint', '=', 'e.fingerprint')
            ->select([
                'e.fingerprint',
                'e.driver',
                'e.has_full_scan',
                'e.analyzed_at',
                'q.sql_sample',
            ])
            ->orderByDesc('e.analyzed_at')
            ->limit($sampleLimit)
            ->get()
            ->map(function ($explain) {
                $explain->sql_sample = $this->redactSqlSample($explain->sql_sample ?? null);

                return $explain;
            });

        return response()->json([
            'last_scan_at' => DB::table('index_advisor_recommendations')->max('updated_at'),
            'driver' => DB::getDriverName(),
            'profile' => config('index_advisor.profile'),
            'enabled' => (bool) config('index_advisor.enabled'),
            'tables' => [
                'columns' => DB::table('index_advisor_columns')->count(),
                'queries' => DB::table('index_advisor_queries')->count(),
                'query_stats' => DB::table('index_advisor_query_stats')->count(),
                'explains' => DB::table('index_advisor_explains')->count(),
                'recommendations' => $recommendations->count(),
            ],
            'recommendations' => $this->recommendationStats($recommendations),
            'top_queries' => $this->redactSqlSampleCollection(
                $this->applicationQueriesQuery()
                    ->orderByDesc('total_duration_ms')
                    ->limit(10)
                    ->get(['fingerprint', 'sql_sample', 'execution_count', 'total_duration_ms', 'max_duration_ms', 'last_seen_at'])
            ),
            'full_scans' => DB::table('index_advisor_explains')
                ->where('has_full_scan', true)
                ->count(),
            'samples' => [
                'columns' => DB::table('index_advisor_columns')
                    ->orderBy('table_name')
                    ->orderBy('column_name')
                    ->limit($sampleLimit)
                    ->get(['table_name', 'column_name', 'query_type', 'source_file']),
                'queries' => $this->redactSqlSampleCollection(
                    $this->applicationQueriesQuery()
                        ->orderByDesc('execution_count')
                        ->limit($sampleLimit)
                        ->get(['fingerprint', 'sql_sample', 'execution_count', 'total_duration_ms', 'max_duration_ms', 'last_seen_at'])
                ),
                'query_stats' => $this->redactSqlSampleCollection(
                    DB::table('index_advisor_query_stats')
                        ->orderByDesc('avg_duration_ms')
                        ->limit($sampleLimit)
                        ->get(['fingerprint', 'sql_sample', 'avg_duration_ms', 'source', 'db_driver', 'recorded_at'])
                ),
                'explains' => $explains,
                'recommendations' => DB::table('index_advisor_recommendations')
                    ->orderByDesc('score')
                    ->limit($sampleLimit)
                    ->get(['id', 'table_name', 'column_name', 'index_type', 'score', 'status', 'updated_at']),
            ],
        ]);
    }

    public function getQueryLog(Request $request)
    {
        $limit = min(200, max(10, (int) $request->query('limit', 50)));

        $query = $this->applicationQueriesQuery();

        if ($request->boolean('include_system', false)) {
            $query = DB::table('index_advisor_queries');
        }

        $queries = $query
            ->orderByDesc('execution_count')
            ->limit($limit)
            ->get();

        $fingerprints = $queries->pluck('fingerprint');
        $explains = DB::table('index_advisor_explains')
            ->whereIn('fingerprint', $fingerprints)
            ->get()
            ->keyBy('fingerprint');

        $rows = $queries->map(function ($query) use ($explains) {
            $explain = $explains->get($query->fingerprint);
            $avgMs = $query->execution_count > 0
                ? $query->total_duration_ms / $query->execution_count
                : 0;

            return [
                'fingerprint' => $query->fingerprint,
                'sql_sample' => $this->redactSqlSample($query->sql_sample),
                'execution_count' => (int) $query->execution_count,
                'total_duration_ms' => (float) $query->total_duration_ms,
                'max_duration_ms' => (float) $query->max_duration_ms,
                'avg_duration_ms' => round($avgMs, 2),
                'last_seen_at' => $query->last_seen_at,
                'has_full_scan' => $explain ? (bool) $explain->has_full_scan : false,
            ];
        });

        return response()->json(['queries' => $rows]);
    }

    public function getQueries($id)
    {
        $rec = DB::table('index_advisor_recommendations')->find((int) $id);

        if (! $rec) {
            return response()->json(['error' => 'Recommendation not found'], 404);
        }

        $evidence = json_decode($rec->evidence ?? '{}', true);
        $queries = $this->resolveQueriesForRecommendation($rec, $evidence);

        return response()->json(['queries' => $queries]);
    }

    public function dismiss(Request $request, $id)
    {
        $rec = DB::table('index_advisor_recommendations')->find((int) $id);

        if (! $rec) {
            return response()->json(['error' => 'Recommendation not found'], 404);
        }

        $evidence = json_decode($rec->evidence ?? '{}', true);
        $evidence['dismissed_at'] = now()->toDateTimeString();
        $evidence['dismiss_reason'] = $request->input('reason') ?? 'Manually dismissed via Dashboard';

        DB::table('index_advisor_recommendations')
            ->where('id', (int) $id)
            ->update([
                'status' => 'dismissed',
                'evidence' => json_encode($evidence),
                'updated_at' => now(),
            ]);

        return response()->json(['success' => true]);
    }

    public function apply(Request $request, $id)
    {
        $rec = DB::table('index_advisor_recommendations')->find((int) $id);

        if (! $rec) {
            return response()->json(['error' => 'Recommendation not found'], 404);
        }

        $evidence = json_decode($rec->evidence ?? '{}', true);
        $evidence['applied_at'] = now()->toDateTimeString();

        DB::table('index_advisor_recommendations')
            ->where('id', (int) $id)
            ->update([
                'status' => 'applied',
                'evidence' => json_encode($evidence),
                'updated_at' => now(),
            ]);

        return response()->json(['success' => true]);
    }

    public function runPipeline(Request $request)
    {
        $options = ['--report-only' => true];

        if ($request->boolean('skip_explain', true)) {
            $options['--skip-explain'] = true;
        }

        if ($request->boolean('skip_code_analysis', false)) {
            $options['--skip-code-analysis'] = true;
        }

        if ($request->boolean('skip_local_db', false)) {
            $options['--skip-local-db'] = true;
        }

        $outputPrefix = $request->boolean('skip_explain', true)
            ? "[Web run: EXPLAIN step skipped for speed. Run `php artisan index-advisor:run --report-only` for full analysis.]\n\n"
            : '';

        return $this->dispatchIndexAdvisorTask(
            new RunIndexAdvisorPipelineJob($this->newTaskId(), $options, $outputPrefix)
        );
    }

    public function generateMigrations(Request $request)
    {
        return $this->dispatchIndexAdvisorTask(
            new GenerateIndexAdvisorMigrationsJob($this->newTaskId())
        );
    }

    public function generateSelectedMigrations(Request $request)
    {
        $ids = $request->input('ids');

        if (! is_array($ids) || empty($ids)) {
            return response()->json(['error' => 'No recommendation IDs provided.'], 422);
        }

        // Sanitize: only integers
        $ids = array_values(array_filter(array_map('intval', $ids), fn ($id) => $id > 0));

        if (empty($ids)) {
            return response()->json(['error' => 'Invalid recommendation IDs.'], 422);
        }

        $excludedTables = (array) config('index_advisor.excluded_tables', []);

        // Validate all IDs exist, are pending, and not in excluded tables
        $recs = DB::table('index_advisor_recommendations')
            ->whereIn('id', $ids)
            ->get();

        $errors = [];
        $validIds = [];

        foreach ($ids as $id) {
            $rec = $recs->firstWhere('id', $id);

            if (! $rec) {
                $errors[] = "ID {$id}: recommendation not found.";

                continue;
            }

            if ($rec->status !== 'pending') {
                $errors[] = "ID {$id} ({$rec->table_name}.{$rec->column_name}): status is '{$rec->status}', only 'pending' recommendations can generate migrations.";

                continue;
            }

            if (in_array($rec->table_name, $excludedTables, true)) {
                $errors[] = "ID {$id} ({$rec->table_name}): table is in the excluded list.";

                continue;
            }

            $validIds[] = $id;
        }

        if (empty($validIds)) {
            return response()->json([
                'success' => false,
                'error' => 'No valid recommendations to generate migrations for.',
                'details' => $errors,
            ], 422);
        }

        return $this->dispatchIndexAdvisorTask(
            new GenerateIndexAdvisorMigrationsJob(
                $this->newTaskId(),
                ['--ids' => implode(',', $validIds)],
                count($validIds),
                $errors,
            )
        );
    }

    /**
     * API: Return pending recommendations suitable for migration generation.
     */
    public function getMigrationCandidates()
    {
        $excludedTables = (array) config('index_advisor.excluded_tables', []);

        $recs = DB::table('index_advisor_recommendations')
            ->whereIn('status', ['pending', 'generated'])
            ->whereNotIn('table_name', $excludedTables)
            ->whereNotIn('index_type', ['REDUNDANT_CHECK'])
            ->orderByDesc('score')
            ->get()
            ->map(function ($rec) {
                $rec->evidence = $this->evidenceSanitizer->forApi(json_decode($rec->evidence ?? '{}', true));

                return $rec;
            });

        return response()->json([
            'recommendations' => $recs,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(): array
    {
        $path = trim((string) config('index_advisor.dashboard.path', 'index-advisor'), '/');

        return [
            'basePath' => '/'.$path,
            'path' => $path,
        ];
    }

    private function isSchemaStale(object $rec): bool
    {
        if (! in_array($rec->status, ['pending', 'generated'], true)) {
            return false;
        }

        return match ($rec->index_type) {
            'REDUNDANT_CHECK' => ! $rec->live_indexed,
            'INDEX' => (bool) $rec->live_indexed,
            'DROP' => ! $rec->live_indexed,
            default => false,
        };
    }

    /**
     * Runtime queries excluding Telescope and Smart Index Advisor internal traffic.
     */
    private function applicationQueriesQuery()
    {
        return DB::table('index_advisor_queries')
            ->where('sql_sample', 'not like', '%telescope_%')
            ->where('sql_sample', 'not like', '%index_advisor_%');
    }

    private function exposeSqlSamples(): bool
    {
        return (bool) config('index_advisor.dashboard.expose_sql_samples', false);
    }

    private function redactSqlSample(?string $sql): ?string
    {
        if ($this->exposeSqlSamples() || $sql === null) {
            return $sql;
        }

        return '[SQL redacted — set INDEX_ADVISOR_EXPOSE_SQL=true to view]';
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, object>
     */
    private function redactSqlSampleCollection(Collection $rows): Collection
    {
        if ($this->exposeSqlSamples()) {
            return $rows;
        }

        return $rows->map(function ($row) {
            if (isset($row->sql_sample)) {
                $row->sql_sample = $this->redactSqlSample($row->sql_sample);
            }

            return $row;
        });
    }

    /**
     * @param  Collection<int, object>  $recommendations
     * @return array<string, int>
     */
    private function recommendationStats($recommendations): array
    {
        $pending = $recommendations->where('status', 'pending');

        $byType = [];
        foreach (['INDEX', 'COMPOSITE', 'DROP', 'REDUNDANT_CHECK'] as $type) {
            $ofType = $recommendations->where('index_type', $type);
            $byType[$type] = [
                'total' => $ofType->count(),
                'pending' => $ofType->where('status', 'pending')->count(),
                'dismissed' => $ofType->where('status', 'dismissed')->count(),
            ];
        }

        return [
            'total' => $recommendations->count(),
            'pending' => $recommendations->where('status', 'pending')->count(),
            'generated' => $recommendations->where('status', 'generated')->count(),
            'applied' => $recommendations->where('status', 'applied')->count(),
            'dismissed' => $recommendations->where('status', 'dismissed')->count(),
            'critical' => $pending->filter(fn ($r) => $r->score >= 80)->count(),
            'high' => $pending->filter(fn ($r) => $r->score >= 60 && $r->score < 80)->count(),
            'tables' => $recommendations->pluck('table_name')->unique()->count(),
            'by_type' => $byType,
        ];
    }

    /**
     * Compute recommendation stats directly from the database.
     * Used by getRecommendations() when paginating to avoid loading all rows.
     *
     * @return array<string, mixed>
     */
    private function recommendationStatsFromDb(): array
    {
        $totals = DB::table('index_advisor_recommendations')
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'generated' THEN 1 ELSE 0 END) as generated,
                SUM(CASE WHEN status = 'applied' THEN 1 ELSE 0 END) as applied,
                SUM(CASE WHEN status = 'dismissed' THEN 1 ELSE 0 END) as dismissed,
                SUM(CASE WHEN status = 'pending' AND score >= 80 THEN 1 ELSE 0 END) as critical,
                SUM(CASE WHEN status = 'pending' AND score >= 60 AND score < 80 THEN 1 ELSE 0 END) as high,
                COUNT(DISTINCT table_name) as tables
            ")
            ->first();

        $byTypeRows = DB::table('index_advisor_recommendations')
            ->select('index_type')
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'dismissed' THEN 1 ELSE 0 END) as dismissed
            ")
            ->groupBy('index_type')
            ->get();

        $byType = [];
        foreach (['INDEX', 'COMPOSITE', 'DROP', 'REDUNDANT_CHECK'] as $type) {
            $row = $byTypeRows->firstWhere('index_type', $type);
            $byType[$type] = [
                'total' => (int) ($row?->total ?? 0),
                'pending' => (int) ($row?->pending ?? 0),
                'dismissed' => (int) ($row?->dismissed ?? 0),
            ];
        }

        return [
            'total' => (int) ($totals->total ?? 0),
            'pending' => (int) ($totals->pending ?? 0),
            'generated' => (int) ($totals->generated ?? 0),
            'applied' => (int) ($totals->applied ?? 0),
            'dismissed' => (int) ($totals->dismissed ?? 0),
            'critical' => (int) ($totals->critical ?? 0),
            'high' => (int) ($totals->high ?? 0),
            'tables' => (int) ($totals->tables ?? 0),
            'by_type' => $byType,
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<int, array<string, mixed>>
     */
    private function resolveQueriesForRecommendation(object $rec, array $evidence): array
    {
        $fingerprints = [];

        if (! empty($evidence['fingerprint'])) {
            $fingerprints[] = $evidence['fingerprint'];
        }

        if (! empty($evidence['matched_fingerprints']) && is_array($evidence['matched_fingerprints'])) {
            $fingerprints = array_merge($fingerprints, $evidence['matched_fingerprints']);
        }

        $fingerprints = array_values(array_unique($fingerprints));
        $queries = [];

        if ($fingerprints !== []) {
            foreach (DB::table('index_advisor_queries')->whereIn('fingerprint', $fingerprints)->get() as $query) {
                $queries[] = $this->formatQueryRow($query);
            }
        }

        if ($queries !== [] || in_array($rec->index_type, ['DROP'], true)) {
            return $queries;
        }

        $queryType = $rec->index_type === 'REDUNDANT_CHECK' ? 'where' : 'where';

        $dbQueries = DB::table('index_advisor_queries')
            ->orderByDesc('execution_count')
            ->limit((int) config('index_advisor.scoring.correlation_query_limit', 2000))
            ->get();

        foreach ($dbQueries as $query) {
            if ($this->sqlMatcher->matches(
                $query->sql_sample,
                $rec->table_name,
                $rec->column_name,
                $queryType
            )) {
                $queries[] = $this->formatQueryRow($query);
            }

            if (count($queries) >= 5) {
                break;
            }
        }

        return $queries;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatQueryRow(object $query): array
    {
        $explain = DB::table('index_advisor_explains')
            ->where('fingerprint', $query->fingerprint)
            ->first();

        $planDecoded = null;
        if ($explain && ! empty($explain->raw_plan)) {
            $raw = $explain->raw_plan;
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $planDecoded = json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
            } else {
                $planDecoded = $raw;
            }

            // Unpack legacy Postgres double-encoded JSON if it wasn't caught during ingest
            if (is_array($planDecoded) && isset($planDecoded[0]) && is_array($planDecoded[0])) {
                $firstRow = $planDecoded[0];
                $jsonStr = $firstRow['EXPLAIN'] ?? $firstRow['QUERY PLAN'] ?? $firstRow['query plan'] ?? current($firstRow);
                if (is_string($jsonStr) && str_starts_with(trim($jsonStr), '[')) {
                    $unpacked = json_decode($jsonStr, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $planDecoded = $unpacked;
                    }
                }
            }
        }

        return [
            'sql_sample' => $this->redactSqlSample($query->sql_sample),
            'execution_count' => (int) $query->execution_count,
            'total_duration_ms' => (float) $query->total_duration_ms,
            'max_duration_ms' => (float) ($query->max_duration_ms ?? 0),
            'last_seen_at' => $query->last_seen_at,
            'explain' => $planDecoded,
            'has_full_scan' => $explain ? (bool) $explain->has_full_scan : false,
        ];
    }

    /**
     * Show the CSV upload form.
     */
    public function showUploadForm()
    {
        return view('smart-index-advisor::upload', array_merge($this->viewData(), ['active' => 'upload']));
    }

    /**
     * Handle uploaded CSV / JSON / TXT stats files.
     */
    public function handleUpload(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required',
                'file.*' => 'file|max:5120|mimes:csv,txt,json|mimetypes:text/plain,text/csv,application/json,application/csv,text/json,application/octet-stream',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'imported_count' => 0,
                'status' => 'failed',
                'details' => implode(' ', \Illuminate\Support\Arr::flatten($e->errors())),
            ], 422);
        }

        $files = $request->file('file');
        if (! is_array($files)) {
            $files = [$files];
        }

        $results = [];

        foreach ($files as $uploadedFile) {
            try {
                $destPath = $this->statsImportUpload->store($uploadedFile);
            } catch (\InvalidArgumentException $e) {
                $results[] = [
                    'filename' => basename($uploadedFile->getClientOriginalName()),
                    'imported_count' => 0,
                    'status' => 'failed',
                    'details' => $e->getMessage(),
                ];

                continue;
            } catch (\Throwable) {
                $results[] = [
                    'filename' => basename($uploadedFile->getClientOriginalName()),
                    'imported_count' => 0,
                    'status' => 'failed',
                    'details' => 'Failed to save uploaded file.',
                ];

                continue;
            }

            Artisan::call('index-advisor:import-stats', ['file' => $destPath]);
            $output = Artisan::output();

            $imported = 0;
            $patterns = [
                '/Successfully imported (\d+) record(?:\(s\))?/i',
                '/(\d+) records? imported/i',
                '/Inserted (\d+) rows?/i',
                '/(\d+) rows? affected/i',
                '/(\d+) entries? imported/i',
            ];

            foreach ($patterns as $pat) {
                if (preg_match($pat, $output, $m)) {
                    $imported = (int) $m[1];
                    break;
                }
            }

            $results[] = [
                'filename' => basename($uploadedFile->getClientOriginalName()),
                'imported_count' => $imported,
                'status' => $imported > 0 ? 'success' : 'failed',
                'details' => $output,
            ];
        }

        return response()->json($results[0] ?? [
            'imported_count' => 0,
            'status' => 'failed',
            'details' => 'No file processed.',
        ]);
    }

    /**
     * Export all recommendations as a downloadable CSV report.
     */
    public function exportReport(Request $request)
    {
        $status = $request->query('status'); // optional filter: pending, applied, dismissed, etc.

        $query = DB::table('index_advisor_recommendations')
            ->orderByDesc('score')
            ->orderByDesc('id');

        if ($status && in_array($status, ['pending', 'applied', 'dismissed', 'generated'], true)) {
            $query->where('status', $status);
        }

        $recommendations = $query->get();

        $filename = 'smart-index-advisor-report-' . now()->format('Y-m-d_His') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-store',
        ];

        $callback = function () use ($recommendations) {
            $fp = fopen('php://output', 'w');

            // BOM for Excel UTF-8 compatibility
            fwrite($fp, "\xEF\xBB\xBF");

            // Header row
            fputcsv($fp, [
                'ID',
                'Table Name',
                'Column Name',
                'Index Type',
                'Priority',
                'Score (pts)',
                'Status',
                'DDL Up',
                'DDL Down',
                'Verdict',
                'Exec Count',
                'Avg Duration (ms)',
                'Max Duration (ms)',
                'Has Full Scan',
                'SQL Clause Pts',
                'FK Heuristic',
                'No Existing Index',
                'Already Indexed',
                'Created At',
                'Updated At',
            ]);

            foreach ($recommendations as $rec) {
                $evidence = json_decode($rec->evidence ?? '{}', true) ?: [];
                $score    = (int) $rec->score;

                // Priority label matching the UI badge logic
                if ($score >= 80)      { $priority = 'P1 (Critical)'; }
                elseif ($score >= 60)  { $priority = 'P2 (High)'; }
                elseif ($score >= 30)  { $priority = 'P3 (Medium)'; }
                else                   { $priority = 'P4 (Low)'; }

                fputcsv($fp, [
                    $rec->id,
                    $rec->table_name,
                    $rec->column_name ?? '',
                    $rec->index_type,
                    $priority,
                    $score,
                    ucfirst($rec->status),
                    $rec->ddl_up ?? '',
                    $rec->ddl_down ?? '',
                    $evidence['verdict'] ?? '',
                    $evidence['exec_count'] ?? '',
                    $evidence['avg_ms'] ?? '',
                    $evidence['max_duration_ms'] ?? '',
                    isset($evidence['full_scan']) ? ($evidence['full_scan'] ? 'Yes' : 'No') : '',
                    $evidence['clause_pts'] ?? '',
                    !empty($evidence['fk_heuristic']) ? 'Yes' : '',
                    !empty($evidence['no_existing_index']) ? 'Yes' : '',
                    !empty($evidence['already_indexed']) ? 'Yes' : '',
                    $rec->created_at ?? '',
                    $rec->updated_at ?? '',
                ]);
            }

            fclose($fp);
        };

        return response()->stream($callback, 200, $headers);
    }
}
