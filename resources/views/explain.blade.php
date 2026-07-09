<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Index Advisor — EXPLAIN Plan</title>
    <style>
        :root {
            --bg: #0f172a; --surface: #1e293b; --surface-2: #334155; --border: #475569;
            --text: #f1f5f9; --text-muted: #94a3b8; --primary: #6366f1; --primary-light: #818cf8;
            --critical: #ef4444; --high: #f97316; --medium: #eab308; --low: #22c55e;
            --gradient: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); line-height: 1.6; }
        .header { background: var(--surface); border-bottom: 1px solid var(--border); padding: 1rem 2rem; display: flex; align-items: center; justify-content: space-between; }
        .header h1 { font-size: 1.5rem; background: var(--gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; font-weight: 700; }
        .container { max-width: 1400px; margin: 0 auto; padding: 1.5rem 2rem; }
        .nav { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; }
        .nav a { color: var(--text-muted); text-decoration: none; padding: 0.5rem 1rem; border-radius: 8px; font-size: 0.85rem; font-weight: 500; transition: all 0.2s; }
        .nav a:hover, .nav a.active { background: var(--surface-2); color: var(--text); }
        .section { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; }
        .section h2 { font-size: 1.1rem; font-weight: 600; margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between;}
        .sql-block { background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 1rem; font-family: 'JetBrains Mono', 'Fira Code', monospace; font-size: 0.85rem; overflow-x: auto; white-space: pre-wrap; word-break: break-all; color: var(--text-muted); margin-bottom: 1.5rem; }
        .json-block { background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 1rem; font-family: 'JetBrains Mono', 'Fira Code', monospace; font-size: 0.8rem; overflow-x: auto; color: var(--primary-light); }
        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .metric { background: var(--surface-2); padding: 1rem; border-radius: 8px; border: 1px solid var(--border); }
        .metric .label { font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600; margin-bottom: 0.25rem; }
        .metric .value { font-size: 1.25rem; font-weight: 700; }
        .metric.critical .value { color: var(--critical); }
        .metric.low .value { color: var(--low); }
        .badge { display: inline-flex; padding: 0.2rem 0.6rem; border-radius: 6px; font-weight: 600; font-size: 0.8rem; }
        .badge-critical { background: rgba(239,68,68,0.15); color: var(--critical); }
        .badge-low { background: rgba(34,197,94,0.15); color: var(--low); }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="header">
        <h1>🏗️ Index Advisor</h1>
    </div>

    <div class="container">
        <nav class="nav">
            <a href="{{ route('index-advisor.dashboard') }}">📊 Dashboard</a>
            <a href="{{ route('index-advisor.recommendations') }}">📋 Recommendations</a>
            <a href="{{ route('index-advisor.code-analysis') }}">🔍 Code Analysis</a>
        </nav>

        <div class="section">
            <h2>
                <span>🔍 EXPLAIN Plan Analysis</span>
                <a href="{{ route('index-advisor.dashboard') }}" style="color: var(--primary-light); text-decoration: none; font-size: 0.85rem; font-weight: 500;">&larr; Back</a>
            </h2>

            @if($query)
                <div class="metric" style="margin-bottom: 1rem;">
                    <div class="label">Query Details</div>
                    <div style="display: flex; gap: 2rem; margin-top: 0.5rem;">
                        <div><span style="color: var(--text-muted)">Executions:</span> <strong>{{ number_format($query->execution_count) }}</strong></div>
                        <div><span style="color: var(--text-muted)">Avg Time:</span> <strong>{{ number_format($query->execution_count > 0 ? $query->total_duration_ms / $query->execution_count : 0, 1) }} ms</strong></div>
                        <div><span style="color: var(--text-muted)">Max Time:</span> <strong>{{ number_format($query->max_duration_ms, 1) }} ms</strong></div>
                    </div>
                </div>
            @endif

            <div class="sql-block">{{ $report->sql_sample ?? $query->sql_sample ?? 'SQL not available' }}</div>

            @if($report)
                <div class="metrics-grid">
                    <div class="metric {{ ($report->has_full_scan ?? false) ? 'critical' : 'low' }}">
                        <div class="label">Access Type</div>
                        <div class="value">
                            {{ $report->access_type ?? 'UNKNOWN' }}
                            @if($report->has_full_scan ?? false)
                                <span class="badge badge-critical" style="margin-left: 0.5rem; font-size: 0.65rem;">FULL SCAN</span>
                            @endif
                        </div>
                    </div>
                    
                    <div class="metric">
                        <div class="label">Rows Examined</div>
                        <div class="value">{{ number_format($report->rows_examined ?? 0) }}</div>
                    </div>
                    
                    <div class="metric">
                        <div class="label">Rows Returned</div>
                        <div class="value">{{ number_format($report->rows_returned ?? 0) }}</div>
                    </div>
                    
                    <div class="metric {{ ($report->filtered_pct ?? 100) < 10 ? 'critical' : '' }}">
                        <div class="label">Filtered</div>
                        <div class="value">{{ $report->filtered_pct ?? 100 }}%</div>
                    </div>

                    <div class="metric {{ ($report->has_filesort ?? false) ? 'critical' : 'low' }}">
                        <div class="label">Filesort</div>
                        <div class="value">{{ ($report->has_filesort ?? false) ? 'Yes' : 'No' }}</div>
                    </div>

                    <div class="metric {{ ($report->has_temp_table ?? false) ? 'critical' : 'low' }}">
                        <div class="label">Temp Table</div>
                        <div class="value">{{ ($report->has_temp_table ?? false) ? 'Yes' : 'No' }}</div>
                    </div>
                </div>

                <div style="margin-bottom: 1.5rem;">
                    <div style="font-weight: 600; margin-bottom: 0.5rem;">Index Usage:</div>
                    <div style="background: var(--surface-2); padding: 1rem; border-radius: 8px; border: 1px solid var(--border);">
                        <div style="margin-bottom: 0.5rem;">
                            <span style="color: var(--text-muted); display: inline-block; width: 120px;">Key Used:</span>
                            <strong>{{ $report->key_used ?? 'None' }}</strong>
                        </div>
                        <div>
                            <span style="color: var(--text-muted); display: inline-block; width: 120px;">Possible Keys:</span>
                            @php
                                $possible = json_decode($report->possible_keys ?? '[]');
                            @endphp
                            @if(empty($possible))
                                <span>None</span>
                            @else
                                {{ implode(', ', $possible) }}
                            @endif
                        </div>
                    </div>
                </div>

                <div style="font-weight: 600; margin-bottom: 0.5rem;">Raw EXPLAIN JSON:</div>
                <div class="json-block">{{ json_encode(json_decode($report->plan_json ?? '{}'), JSON_PRETTY_PRINT) }}</div>
            @else
                <div class="metric critical">
                    <div class="label">Analysis Status</div>
                    <div class="value">No detailed report available</div>
                    <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.5rem;">
                        This query has not been successfully analyzed by the EXPLAIN engine. It may contain syntax that prevents EXPLAIN from running.
                    </div>
                </div>
            @endif
        </div>
    </div>
</body>
</html>
