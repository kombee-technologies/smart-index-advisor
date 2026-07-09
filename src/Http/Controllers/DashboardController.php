<?php

namespace Kombee\IndexAdvisor\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Kombee\IndexAdvisor\Services\RecommendationEngine;
use Kombee\IndexAdvisor\Services\CorrelationEngine;

/**
 * Dashboard Controller — Serves the Index Advisor web UI.
 *
 * Provides views for:
 *   - Overview dashboard with key metrics
 *   - Recommendations list with filtering
 *   - EXPLAIN plan viewer
 *   - Code analysis results
 *   - API endpoint for AJAX stats
 */
class DashboardController extends Controller
{
    public function __construct(
        private RecommendationEngine $recommendation,
        private CorrelationEngine $correlation,
    ) {}

    /**
     * Main dashboard page.
     */
    public function index()
    {
        $stats = [
            'total_queries'        => DB::table('index_advisor_queries')->count(),
            'total_recommendations' => DB::table('index_advisor_recommendations')->count(),
            'critical_count'       => DB::table('index_advisor_recommendations')->where('score', '>=', 80)->count(),
            'high_count'           => DB::table('index_advisor_recommendations')->whereBetween('score', [60, 79])->count(),
            'medium_count'         => DB::table('index_advisor_recommendations')->whereBetween('score', [40, 59])->count(),
            'low_count'            => DB::table('index_advisor_recommendations')->where('score', '<', 40)->count(),
            'pending_count'        => DB::table('index_advisor_recommendations')->where('status', 'pending')->count(),
            'generated_count'      => DB::table('index_advisor_recommendations')->where('status', 'generated')->count(),
            'applied_count'        => DB::table('index_advisor_recommendations')->where('status', 'applied')->count(),
            'code_patterns'        => DB::table('index_advisor_code_patterns')->count(),
            'runtime_stats'        => DB::table('index_advisor_runtime_stats')->count(),
            'explain_reports'      => DB::table('index_advisor_explain_reports')->count(),
        ];

        $topSlowQueries = DB::table('index_advisor_queries')
            ->orderByDesc(DB::raw('total_duration_ms / NULLIF(execution_count, 0)'))
            ->limit(10)
            ->get(['fingerprint', 'sql_sample', 'execution_count', 'total_duration_ms', 'max_duration_ms', 'last_seen_at']);

        $topRecommendations = $this->recommendation->getTopRecommendations(15);

        $fullScanQueries = DB::table('index_advisor_explain_reports')
            ->where('has_full_scan', true)
            ->orderByDesc('rows_examined')
            ->limit(10)
            ->get();

        return view('index-advisor::dashboard', compact(
            'stats',
            'topSlowQueries',
            'topRecommendations',
            'fullScanQueries',
        ));
    }

    /**
     * Recommendations list with filtering.
     */
    public function recommendations(Request $request)
    {
        $query = DB::table('index_advisor_recommendations')
            ->orderByDesc('score');

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->has('type')) {
            $query->where('index_type', $request->get('type'));
        }

        if ($request->has('min_score')) {
            $query->where('score', '>=', (int) $request->get('min_score'));
        }

        $recommendations = $query->paginate(25);

        return view('index-advisor::recommendations', compact('recommendations'));
    }

    /**
     * EXPLAIN plan viewer.
     */
    public function explain(string $fingerprint)
    {
        $report = DB::table('index_advisor_explain_reports')
            ->where('fingerprint', $fingerprint)
            ->first();

        $query = DB::table('index_advisor_queries')
            ->where('fingerprint', $fingerprint)
            ->first();

        if (! $report && ! $query) {
            abort(404, 'No explain report found for this fingerprint.');
        }

        return view('index-advisor::explain', compact('report', 'query'));
    }

    /**
     * Code analysis results.
     */
    public function codeAnalysis()
    {
        $patterns = DB::table('index_advisor_code_patterns')
            ->select('table_name', 'column_name', 'expression_type')
            ->selectRaw('COUNT(*) as occurrence_count')
            ->selectRaw('MIN(file_path) as sample_file')
            ->selectRaw('MIN(line_number) as sample_line')
            ->groupBy('table_name', 'column_name', 'expression_type')
            ->orderBy('table_name')
            ->paginate(50);

        return view('index-advisor::code-analysis', compact('patterns'));
    }

    /**
     * Dismiss a recommendation.
     */
    public function dismiss(int $id)
    {
        $this->recommendation->dismiss($id);
        return back()->with('success', 'Recommendation dismissed.');
    }

    /**
     * API endpoint for AJAX stats.
     */
    public function apiStats()
    {
        return response()->json([
            'correlation' => $this->correlation->getSummary(),
            'queries'     => [
                'total'     => DB::table('index_advisor_queries')->count(),
                'slow'      => DB::table('index_advisor_queries')
                    ->whereRaw('total_duration_ms / NULLIF(execution_count, 0) > ?', [config('index_advisor.slow_query_ms', 200)])
                    ->count(),
            ],
            'recommendations' => [
                'critical' => DB::table('index_advisor_recommendations')->where('score', '>=', 80)->count(),
                'high'     => DB::table('index_advisor_recommendations')->whereBetween('score', [60, 79])->count(),
                'pending'  => DB::table('index_advisor_recommendations')->where('status', 'pending')->count(),
            ],
        ]);
    }
}
