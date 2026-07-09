<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Index Advisor — Code Analysis</title>
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
        .section h2 { font-size: 1.1rem; font-weight: 600; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th { text-align: left; padding: 0.75rem; border-bottom: 2px solid var(--border); color: var(--text-muted); font-weight: 600; font-size: 0.75rem; text-transform: uppercase; }
        td { padding: 0.75rem; border-bottom: 1px solid var(--surface-2); }
        tr:hover { background: var(--surface-2); }
        .type-badge { display: inline-block; padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.7rem; font-weight: 600; background: rgba(99,102,241,0.15); color: var(--primary-light); }
        .code-snippet { background: var(--bg); padding: 0.5rem; border-radius: 6px; font-family: monospace; font-size: 0.75rem; color: var(--text-muted); max-width: 400px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; border: 1px solid var(--border); }
        .pagination { display: flex; gap: 0.5rem; justify-content: center; margin-top: 1rem; }
        .pagination a, .pagination span { padding: 0.5rem 0.75rem; border-radius: 6px; font-size: 0.8rem; text-decoration: none; color: var(--text-muted); background: var(--surface); border: 1px solid var(--border); }
        .pagination .active span { background: var(--primary); color: white; border-color: var(--primary); }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="header">
        <h1>🏗️ Smart Index Advisor</h1>
    </div>

    <div class="container">
        <nav class="nav">
            <a href="{{ route('index-advisor.dashboard') }}">📊 Dashboard</a>
            <a href="{{ route('index-advisor.recommendations') }}">📋 Recommendations</a>
            <a href="{{ route('index-advisor.code-analysis') }}" class="active">🔍 Code Analysis</a>
        </nav>

        <div class="section">
            <h2>
                <span>🔍 Static Code Analysis Results</span>
                <span style="font-size: 0.85rem; font-weight: normal; color: var(--text-muted);">
                    Showing {{ $patterns->firstItem() }} to {{ $patterns->lastItem() }} of {{ $patterns->total() }} patterns
                </span>
            </h2>

            <table>
                <thead>
                    <tr>
                        <th>Table</th>
                        <th>Column</th>
                        <th>Expression Type</th>
                        <th>Occurrences</th>
                        <th>Sample File Location</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($patterns as $p)
                        <tr>
                            <td><strong>{{ $p->table_name }}</strong></td>
                            <td>{{ $p->column_name }}</td>
                            <td><span class="type-badge">{{ $p->expression_type }}</span></td>
                            <td>{{ $p->occurrence_count }}</td>
                            <td>
                                <div class="code-snippet" title="{{ $p->sample_file }}:{{ $p->sample_line }}">
                                    {{ $p->sample_file }}:{{ $p->sample_line }}
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 2rem; color: var(--text-muted);">No code patterns found. Run `php artisan index-advisor:analyze-code`</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if(method_exists($patterns, 'links'))
                <div class="pagination">
                    {{ $patterns->links() }}
                </div>
            @endif
        </div>
    </div>
</body>
</html>
