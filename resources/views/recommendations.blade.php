<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Index Advisor — Recommendations</title>
    <style>
        :root {
            --bg: #0f172a; --surface: #1e293b; --surface-2: #334155; --border: #475569;
            --text: #f1f5f9; --text-muted: #94a3b8; --primary: #6366f1; --primary-light: #818cf8;
            --critical: #ef4444; --high: #f97316; --medium: #eab308; --low: #22c55e; --ignore: #64748b;
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
        .section h2 { font-size: 1.1rem; font-weight: 600; margin-bottom: 1rem; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th { text-align: left; padding: 0.75rem; border-bottom: 2px solid var(--border); color: var(--text-muted); font-weight: 600; font-size: 0.75rem; text-transform: uppercase; }
        td { padding: 0.75rem; border-bottom: 1px solid var(--surface-2); }
        tr:hover { background: var(--surface-2); }
        .score-badge { display: inline-flex; padding: 0.2rem 0.6rem; border-radius: 6px; font-weight: 600; font-size: 0.8rem; }
        .score-critical { background: rgba(239,68,68,0.15); color: var(--critical); }
        .score-high { background: rgba(249,115,22,0.15); color: var(--high); }
        .score-medium { background: rgba(234,179,8,0.15); color: var(--medium); }
        .score-low { background: rgba(34,197,94,0.15); color: var(--low); }
        .score-ignore { background: rgba(100,116,139,0.15); color: var(--ignore); }
        .type-badge { display: inline-block; padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; }
        .type-index { background: rgba(99,102,241,0.15); color: var(--primary-light); }
        .type-composite { background: rgba(168,85,247,0.15); color: #a855f7; }
        .type-covering { background: rgba(14,165,233,0.15); color: #0ea5e9; }
        .type-drop { background: rgba(239,68,68,0.15); color: var(--critical); }
        .status-badge { padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.7rem; font-weight: 600; }
        .status-pending { background: rgba(234,179,8,0.15); color: var(--medium); }
        .status-generated { background: rgba(99,102,241,0.15); color: var(--primary-light); }
        .status-applied { background: rgba(34,197,94,0.15); color: var(--low); }
        .status-dismissed { background: rgba(100,116,139,0.15); color: var(--ignore); }
        .btn { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.35rem 0.6rem; border: none; border-radius: 6px; font-size: 0.7rem; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-dismiss { background: rgba(239,68,68,0.15); color: var(--critical); }
        .btn-dismiss:hover { background: rgba(239,68,68,0.3); }
        .evidence-toggle { color: var(--primary-light); cursor: pointer; font-size: 0.75rem; }
        .evidence-block { display: none; background: var(--bg); border: 1px solid var(--surface-2); border-radius: 8px; padding: 0.75rem; margin-top: 0.5rem; font-family: monospace; font-size: 0.75rem; color: var(--text-muted); max-height: 200px; overflow-y: auto; white-space: pre-wrap; }
        .pagination { display: flex; gap: 0.5rem; justify-content: center; margin-top: 1rem; }
        .pagination a, .pagination span { padding: 0.5rem 0.75rem; border-radius: 6px; font-size: 0.8rem; text-decoration: none; color: var(--text-muted); background: var(--surface); border: 1px solid var(--border); }
        .pagination .active span { background: var(--primary); color: white; border-color: var(--primary); }
        .filters { display: flex; gap: 0.75rem; margin-bottom: 1rem; flex-wrap: wrap; }
        .filters a { padding: 0.35rem 0.75rem; border-radius: 6px; font-size: 0.8rem; text-decoration: none; color: var(--text-muted); background: var(--surface-2); transition: all 0.2s; }
        .filters a:hover, .filters a.active { background: var(--primary); color: white; }
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
            <a href="{{ route('index-advisor.recommendations') }}" class="active">📋 Recommendations</a>
            <a href="{{ route('index-advisor.code-analysis') }}">🔍 Code Analysis</a>
        </nav>

        <div class="section">
            <h2>📋 All Recommendations</h2>

            <div class="filters">
                <a href="{{ route('index-advisor.recommendations') }}" class="{{ !request('status') && !request('type') ? 'active' : '' }}">All</a>
                <a href="{{ route('index-advisor.recommendations', ['status' => 'pending']) }}" class="{{ request('status') === 'pending' ? 'active' : '' }}">Pending</a>
                <a href="{{ route('index-advisor.recommendations', ['status' => 'generated']) }}" class="{{ request('status') === 'generated' ? 'active' : '' }}">Generated</a>
                <a href="{{ route('index-advisor.recommendations', ['type' => 'COMPOSITE']) }}" class="{{ request('type') === 'COMPOSITE' ? 'active' : '' }}">Composite</a>
                <a href="{{ route('index-advisor.recommendations', ['type' => 'COVERING']) }}" class="{{ request('type') === 'COVERING' ? 'active' : '' }}">Covering</a>
                <a href="{{ route('index-advisor.recommendations', ['type' => 'DROP']) }}" class="{{ request('type') === 'DROP' ? 'active' : '' }}">Drop</a>
                <a href="{{ route('index-advisor.recommendations', ['min_score' => 80]) }}" class="{{ request('min_score') === '80' ? 'active' : '' }}">Critical (80+)</a>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Table</th>
                        <th>Column(s)</th>
                        <th>Type</th>
                        <th>Score</th>
                        <th>Status</th>
                        <th>Evidence</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recommendations as $r)
                        @php
                            $scoreClass = match(true) {
                                $r->score >= 80 => 'score-critical',
                                $r->score >= 60 => 'score-high',
                                $r->score >= 40 => 'score-medium',
                                $r->score >= 20 => 'score-low',
                                default => 'score-ignore',
                            };
                            $typeClass = match($r->index_type) {
                                'INDEX' => 'type-index',
                                'COMPOSITE' => 'type-composite',
                                'COVERING' => 'type-covering',
                                'DROP' => 'type-drop',
                                default => 'type-index',
                            };
                        @endphp
                        <tr>
                            <td>{{ $r->table_name }}</td>
                            <td>{{ Str::limit($r->column_name, 30) }}</td>
                            <td><span class="type-badge {{ $typeClass }}">{{ $r->index_type }}</span></td>
                            <td><span class="score-badge {{ $scoreClass }}">{{ $r->score }}</span></td>
                            <td><span class="status-badge status-{{ strtolower($r->status) }}">{{ ucfirst($r->status) }}</span></td>
                            <td>
                                <span class="evidence-toggle" onclick="toggleEvidence({{ $r->id }})">View</span>
                                <div class="evidence-block" id="evidence-{{ $r->id }}">{{ json_encode(json_decode($r->evidence ?? '{}'), JSON_PRETTY_PRINT) }}</div>
                            </td>
                            <td>
                                @if($r->status === 'pending')
                                    <form method="POST" action="{{ route('index-advisor.dismiss', $r->id) }}" style="display: inline;">
                                        @csrf
                                        <button type="submit" class="btn btn-dismiss" onclick="return confirm('Dismiss this recommendation?')">✕ Dismiss</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 2rem; color: var(--text-muted);">No recommendations found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if(method_exists($recommendations, 'links'))
                <div class="pagination">
                    {{ $recommendations->appends(request()->query())->links() }}
                </div>
            @endif
        </div>
    </div>

    <script>
        function toggleEvidence(id) {
            const el = document.getElementById('evidence-' + id);
            el.style.display = el.style.display === 'block' ? 'none' : 'block';
        }
    </script>
</body>
</html>
