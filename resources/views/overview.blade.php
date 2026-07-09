@extends('index-advisor::layout')

@section('title', 'Index Advisor — Overview')

@section('topbar-title', 'Index Advisor')

@section('topbar-actions')
<div class="topbar__toolbar">
    <div class="topbar__analysis-group">
        <label class="auto-run auto-run--skip-code" for="chkSkipCode">
            <input type="checkbox" id="chkSkipCode" class="auto-run-cb" style="display:none;">
            <span class="auto-run__box" aria-hidden="true"></span>
            <span class="auto-run__text">Skip Code Analysis</span>
        </label>
        <label class="auto-run auto-run--skip-explain" for="chkSkipExplain">
            <input type="checkbox" id="chkSkipExplain" class="auto-run-cb" style="display:none;" checked>
            <span class="auto-run__box is-checked" aria-hidden="true"></span>
            <span class="auto-run__text">Skip Explain (faster)</span>
        </label>
        <label class="auto-run auto-run--skip-local-db" for="chkSkipLocalDb">
            <input type="checkbox" id="chkSkipLocalDb" class="auto-run-cb" style="display:none;">
            <span class="auto-run__box" aria-hidden="true"></span>
            <span class="auto-run__text">Skip Local DB</span>
        </label>
        <button type="button" class="btn-run-analysis is-active" id="btnRunAnalysis">
            <span>Run Analysis</span>
        </button>
    </div>
    <a href="{{ $basePath }}/migrations" class="btn btn--primary btn-generate" style="text-decoration:none;">
        <span>Generate Migrations</span>
    </a>
</div>
@endsection

@push('head')
<style>
    .overview-canvas {
        display: flex;
        flex-direction: column;
        gap: 20px;
        flex: 1;
        min-height: 0;
    }
    .overview-page-header {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
    }
    .overview-page-header__left {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .overview-page-header .page-title {
        font-size: 24px;
        line-height: 28px;
    }
    .overview-subtitle {
        font-size: 14px;
        line-height: 20px;
        color: var(--text-muted);
    }
    .overview-updated {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
        color: var(--text-muted);
        white-space: nowrap;
    }
    .overview-updated svg {
        width: 14px;
        height: 14px;
        flex-shrink: 0;
    }
    .overview-section-title {
        font-size: 13px;
        font-weight: 500;
        color: var(--text-primary);
        padding: 8px 0;
    }
    .kpi-strip {
        display: flex;
        align-items: stretch;
        background: var(--bg-card);
        border-radius: var(--radius-lg);
        padding: 16px;
        gap: 16px;
    }
    .kpi-strip__item {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 4px;
        text-align: center;
        min-width: 0;
    }
    .kpi-strip__item + .kpi-strip__item {
        border-left: 1px solid #353438;
        padding-left: 16px;
    }
    .kpi-strip__label {
        font-size: 13px;
        color: var(--text-muted);
    }
    .kpi-strip__value {
        font-size: 22px;
        font-weight: 600;
        line-height: 28px;
        color: var(--text-primary);
    }
    .kpi-strip__value--accent {
        color: var(--accent-blue);
    }
    .env-strip {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        background: var(--bg-card);
        border-radius: var(--radius-lg);
        padding: 16px;
    }
    .env-chip {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        min-width: 175px;
        flex: 1;
        padding: 13px 17px;
        background: rgba(15, 23, 42, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.06);
        border-radius: var(--radius-lg);
        font-size: 13px;
    }
    .env-chip__label {
        color: #f8fafc;
    }
    .env-chip__value {
        color: #06b6d4;
        font-weight: 500;
    }
    .env-chip--wide {
        flex: 2;
        min-width: 220px;
    }
    .code-columns-header {
        display: flex;
        align-items: center;
        gap: 25px;
        flex-wrap: wrap;
        padding: 8px 0 16px;
    }
    .code-columns-header__title {
        font-size: 13px;
        font-weight: 500;
        color: var(--text-primary);
    }
    .code-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
    }
    .code-tab {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 32px;
        padding: 0 13px;
        border-radius: var(--radius);
        border: 1px solid #505050;
        background: transparent;
        color: #bac8cc;
        font-size: 13px;
        font-family: inherit;
        cursor: pointer;
        user-select: none;
        white-space: nowrap;
    }
    .code-tab.active {
        background: var(--accent-primary);
        border-color: var(--accent-primary);
        color: var(--text-primary);
    }
    .advisor-panel {
        display: none;
    }
    .advisor-panel.active {
        display: block;
    }
    .advisor-card {
        background: var(--bg-card);
        border-radius: var(--radius-lg);
        overflow: hidden;
    }
    .advisor-card__header {
        padding: 24px 24px 0;
        border-bottom: 1px solid #353438;
    }
    .advisor-card__title {
        font-size: 14px;
        font-weight: 500;
        color: var(--text-primary);
        margin-bottom: 4px;
    }
    .advisor-card__subtitle {
        font-size: 12px;
        color: var(--text-muted);
        padding-bottom: 24px;
    }
    .advisor-table-wrap {
        overflow: auto;
    }
    .advisor-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }
    .advisor-table th,
    .advisor-table td {
        padding: 12px 16px;
        border-bottom: 1px solid #353438;
        font-size: 13px;
        vertical-align: middle;
    }
    .advisor-table th {
        font-weight: 500;
        color: var(--text-muted);
        font-size: 12px;
        text-align: left;
    }
    .advisor-table th:last-child,
    .advisor-table td:last-child {
        border-right: none;
    }
    .advisor-table tbody tr:last-child td {
        border-bottom: none;
    }
    .advisor-table .col-column {
        width: 20%;
        color: var(--text-primary);
    }
    .advisor-table .col-table {
        width: 20%;
        color: var(--text-primary);
    }
    .advisor-table .col-clause {
        width: 15%;
        text-align: left;
        color: var(--text-primary);
    }
    .advisor-table .col-source {
        width: 55%;
        text-align: left;
        color: var(--text-primary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .advisor-table td.col-source {
        font-family: var(--font-mono, monospace);
        color: var(--text-primary);
    }
    .advisor-table td.col-source:hover {
        color: var(--accent-blue);
        cursor: pointer;
    }
    .advisor-table .col-exec { width: 15%; color: var(--text-primary); }
    .advisor-table .col-ms { width: 15%; color: var(--text-primary); }
    .advisor-table .col-sql { width: 55%; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-family: var(--font-mono, monospace); }
    .advisor-table .col-source-type { width: 15%; color: var(--text-primary); }
    .advisor-table .col-driver { width: 15%; color: var(--text-primary); }
    .advisor-table .col-scan { width: 10%; color: var(--text-primary); }
    .advisor-table .col-time { width: 20%; color: var(--text-primary); }
    .advisor-table .col-type { width: 25%; color: var(--text-primary); }
    .advisor-table .col-score { width: 25%; color: var(--text-primary); }
    .advisor-panel__placeholder {
        padding: 48px 24px;
        text-align: center;
        color: var(--text-muted);
        font-size: 14px;
    }
    
    /* ── Query details drawer (theme-matched) ── */
    .sql-drawer-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 500;
    }
    .sql-drawer-overlay.is-open { display: block; }
    .sql-drawer-backdrop {
        position: absolute;
        inset: 0;
        background: rgba(0,0,0,0.55);
        cursor: pointer;
    }
    .drawer--sql-detail {
        position: absolute;
        top: 0; right: 0; bottom: 0;
        z-index: 1;
        width: 600px; max-width: 100%;
        display: flex;
        flex-direction: column;
        pointer-events: auto;
        background: var(--bg-app);
        border-left: 1px solid var(--border);
        box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
        animation: sql-drawer-slide-in 0.25s ease-out;
        font-family: var(--font);
    }
    @keyframes sql-drawer-slide-in {
        from { transform: translateX(100%); }
        to   { transform: translateX(0); }
    }
    .sql-drawer__header {
        flex-shrink: 0;
        padding: 16px;
        border-bottom: 1px solid var(--border);
    }
    .sql-drawer__header-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
    }
    .sql-drawer__title {
        font-family: var(--font);
        font-size: 16px;
        font-weight: 500;
        line-height: 24px;
        color: var(--text-primary);
    }
    .sql-drawer__close {
        width: 28px; height: 28px;
        border: none;
        border-radius: var(--radius, 4px);
        background: none;
        color: var(--text-muted);
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
    }
    .sql-drawer__close:hover { color: var(--text-primary); background: rgba(255,255,255,0.08); }
    .sql-drawer__body {
        flex: 1;
        overflow-y: auto;
        padding: 16px;
        display: flex;
        flex-direction: column;
        gap: 32px;
    }
    .sql-drawer__stats {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 16px;
    }
    .sql-stat-card {
        display: flex;
        flex-direction: column;
        gap: 4px;
        padding: 13px;
        background: #0e0e10;
        border: 1px solid var(--border);
        border-radius: var(--radius-lg, 8px);
        min-width: 0;
    }
    .sql-stat-card__label {
        font-family: var(--font);
        font-size: 12px;
        line-height: 17px;
        color: var(--text-muted);
    }
    .sql-stat-card__value {
        font-family: var(--font);
        font-size: 16px;
        font-weight: 500;
        line-height: 24px;
        color: var(--text-primary);
    }
    .sql-stat-card__value--accent {
        color: var(--accent-blue, #adc6ff);
    }
    .sql-drawer__section-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 16px;
    }
    .sql-drawer__section-title {
        font-family: var(--font);
        font-size: 14px;
        font-weight: 500;
        line-height: 24px;
        color: var(--text-muted);
    }
    .sql-copy-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        height: 24px;
        padding: 0 13px;
        font-family: var(--font);
        font-size: 12px;
        font-weight: 500;
        color: var(--text-primary);
        background: #2a2a2c;
        border: 1px solid var(--border);
        border-radius: 6px;
        cursor: pointer;
        white-space: nowrap;
    }
    .sql-copy-btn:hover {
        background: #353538;
    }
    .sql-copy-btn svg {
        flex-shrink: 0;
    }
    .sql-code-panel {
        position: relative;
        background: #0e0e10;
        border: 1px solid var(--border);
        border-radius: var(--radius-lg, 8px);
        padding: 17px;
    }
    .sql-code-panel__expand {
        position: absolute;
        top: 9px; right: 9px;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 25px; height: 27px;
        padding: 7px;
        background: #202122;
        border: 1px solid var(--border);
        border-radius: 6px;
        color: var(--text-primary);
        cursor: pointer;
    }
    .sql-code-panel__expand:hover {
        background: #2a2a2c;
    }
    .sql-code-panel__inner {
        display: flex;
        gap: 16px;
        overflow-x: auto;
    }
    .sql-code-panel__lines {
        flex-shrink: 0;
        margin: 0; padding: 0;
        font-family: var(--font-mono, monospace);
        font-size: 12px;
        line-height: 16px;
        color: var(--text-muted);
        opacity: 0.3;
        text-align: right;
        user-select: none;
    }
    .sql-code-panel__code {
        flex: 1;
        min-width: 0;
        margin: 0; padding: 0;
        font-family: var(--font-mono, monospace);
        font-size: 12px;
        line-height: 16px;
        color: var(--accent-blue, #adc6ff);
        white-space: pre;
        overflow-x: auto;
    }
    .sql-code-panel.is-expanded {
        max-height: none;
    }
    .sql-code-panel.is-expanded .sql-code-panel__expand svg {
        transform: rotate(180deg);
    }
    .advisor-table tbody tr.q-clickable { cursor: pointer; }
    .advisor-table tbody tr.q-clickable:hover { background: rgba(173,198,255,0.08); }

    @media (max-width: 900px) {
        .kpi-strip {
            flex-wrap: wrap;
        }
        .kpi-strip__item {
            flex: 1 1 40%;
            border-left: none;
            padding-left: 0;
        }
        .kpi-strip__item:nth-child(odd) {
            border-left: none;
        }
        .env-chip {
            min-width: 100%;
        }
    }
    @media (max-width: 600px) {
        .code-columns-header {
            flex-direction: column;
            align-items: flex-start;
        }
        .advisor-table .col-clause,
        .advisor-table .col-source,
        .advisor-table .col-sql,
        .advisor-table .col-time {
            display: none;
        }
        .advisor-table th.col-clause,
        .advisor-table th.col-source,
        .advisor-table th.col-sql,
        .advisor-table th.col-time {
            display: none;
        }
    }
</style>
@endpush

@section('content')

{{-- Terminal / Process log --}}
<div id="terminalPanel" class="terminal-panel">
    <div class="terminal-header">
        <div class="terminal-title">
            <svg class="spinner" width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/>
            </svg>
            <span>Process Status</span>
        </div>
        <button class="btn-copy" onclick="closeTerminal()">Close log</button>
    </div>
    <div id="terminalLog" class="terminal-body"></div>
</div>

<div class="overview-canvas">
    {{-- Page header --}}
    <div class="overview-page-header">
        <div class="overview-page-header__left">
            <h1 class="page-title">Overview</h1>
            <p class="overview-subtitle">Index health and advisor pipeline status for this workspace.</p>
        </div>
        <div class="overview-updated">
            <svg viewBox="0 0 14 14" fill="currentColor" aria-hidden="true"><path d="M7 0a7 7 0 1 0 7 7A7 7 0 0 0 7 0Zm0 12.6A5.6 5.6 0 1 1 12.6 7 5.6 5.6 0 0 1 7 12.6Zm.7-9.1v3.85l2.8 1.68-.7 1.17L6.3 7.7V3.5Z"/></svg>
            <span id="lastScanTime">Loading…</span>
        </div>
    </div>

    {{-- KPI Grid --}}
    <div class="kpi-grid">
        <div class="kpi-card">
            <span class="kpi-card__label">Total Suggestions</span>
            <span id="sRec" class="kpi-card__value kpi-card__value--total">—</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-card__label">Critical Pending</span>
            <span id="sCrit" class="kpi-card__value kpi-card__value--critical">—</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-card__label">High Pending</span>
            <span id="sHigh" class="kpi-card__value kpi-card__value--high">—</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-card__label">Applied</span>
            <span id="sApplied" class="kpi-card__value kpi-card__value--applied">—</span>
        </div>
    </div>

    {{-- Storage tables KPI strip --}}
    <div>
        <p class="overview-section-title">Storage tables (row counts)</p>
        <div class="kpi-strip" id="storageCounts">
            <div class="kpi-strip__item"><span class="kpi-strip__label">Loading…</span></div>
        </div>
    </div>

    {{-- Environment strip --}}
    <div>
        <p class="overview-section-title">Environment</p>
        <div class="env-strip" id="envInfo">
            <div class="env-chip"><span class="env-chip__label">Loading…</span></div>
        </div>
    </div>

    {{-- Code Columns with tabs --}}
    <div>
        <div class="code-columns-header">
            <h2 class="code-columns-header__title">Code Columns</h2>
            <div class="code-tabs" role="tablist" aria-label="Code column views">
                <button class="code-tab active" onclick="switchTab('tab-columns')" data-tab="tab-columns" role="tab">Advisor Columns</button>
                <button class="code-tab" onclick="switchTab('tab-queries')" data-tab="tab-queries" role="tab">Advisor Queries</button>
                <button class="code-tab" onclick="switchTab('tab-query-stats')" data-tab="tab-query-stats" role="tab">Query Stats</button>
                <button class="code-tab" onclick="switchTab('tab-explains')" data-tab="tab-explains" role="tab">Advisor Explains</button>
                <button class="code-tab" onclick="switchTab('tab-recs')" data-tab="tab-recs" role="tab">Advisor Recommendations</button>
            </div>
        </div>
        <div id="dataSections">
            <div class="advisor-card">
                <p class="advisor-panel__placeholder">Loading overview data…</p>
            </div>
        </div>
    </div>
</div>

<div id="sqlDrawerOverlay" class="sql-drawer-overlay" role="dialog" aria-modal="true" aria-label="Query details" aria-hidden="true">
    <div class="sql-drawer-backdrop" onclick="closeQueryDrawer()"></div>
    <aside class="drawer--sql-detail">
        <header class="sql-drawer__header">
            <div class="sql-drawer__header-row">
                <h2 class="sql-drawer__title" id="qdTitle">Query Details</h2>
                <button type="button" class="sql-drawer__close" onclick="closeQueryDrawer()" aria-label="Close">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="currentColor" aria-hidden="true"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="1.5" fill="none"/></svg>
                </button>
            </div>
        </header>
        <div class="sql-drawer__body">
            <section class="sql-drawer__section" aria-label="Query statistics">
                <div class="sql-drawer__stats" id="qdStats">
                    <!-- Dynamic stats will go here -->
                </div>
            </section>
            <section class="sql-drawer__section" aria-label="SQL statement">
                <div class="sql-drawer__section-head">
                    <h3 class="sql-drawer__section-title">Context / SQL</h3>
                    <button type="button" class="sql-copy-btn" onclick="copyDrawerSql()">
                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                            <rect x="3.5" y="3.5" width="7" height="7" rx="0.5" stroke="currentColor"/>
                            <path d="M1.5 1.5h6.75v6.87" stroke="currentColor"/>
                        </svg>
                        Copy
                    </button>
                </div>
                <div class="sql-code-panel" id="sqlCodePanel">
                    <button type="button" class="sql-code-panel__expand" onclick="toggleSqlExpand()" aria-label="Expand SQL panel">
                        <svg width="11" height="13" viewBox="0 0 11 13" fill="currentColor" aria-hidden="true"><path d="M1 4.5L5.5 1 10 4.5M1 8.5L5.5 12 10 8.5" stroke="currentColor" stroke-width="1.2" fill="none"/></svg>
                    </button>
                    <div class="sql-code-panel__inner">
                        <pre class="sql-code-panel__lines" id="qdSqlLines" aria-hidden="true"></pre>
                        <pre class="sql-code-panel__code" id="qdSql"></pre>
                    </div>
                </div>
            </section>
        </div>
    </aside>
</div>

@endsection

@push('scripts')
<script>
    let cachedData = {};

    document.addEventListener('DOMContentLoaded', () => {
        loadOverview();
        document.getElementById('btnRunAnalysis').addEventListener('click', runOverviewAnalysis);
    });

    async function runOverviewAnalysis() {
        const btn = document.getElementById('btnRunAnalysis');
        setButtonBusy(btn, true, 'Analyzing…', 'Run Analysis');
        const skipCode    = document.getElementById('chkSkipCode')?.checked    ?? false;
        const skipExplain = document.getElementById('chkSkipExplain')?.checked ?? true;
        const skipLocalDb = document.getElementById('chkSkipLocalDb')?.checked ?? false;
        const flags = [skipCode && 'code skipped', skipExplain && 'EXPLAIN skipped', skipLocalDb && 'local DB skipped'].filter(Boolean);
        showTerminal('Running Index Advisor pipeline' + (flags.length ? ' (' + flags.join(', ') + ')' : '') + '…');
        try {
            const { ok, status, data, raw } = await parseJsonResponse(await fetch(`${BASE_PATH}/api/run`, {
                method: 'POST', headers: apiHeaders(),
                body: JSON.stringify({ skip_explain: skipExplain, skip_code_analysis: skipCode, skip_local_db: skipLocalDb }),
            }));
            if (!data) { writeTerminal('ERROR: HTTP ' + status + '\n' + (raw || '').slice(0, 2000)); showToast('Run Analysis failed'); return; }
            try {
                const result = await resolveIndexAdvisorTaskResponse(data);
                writeTerminal(result.output || '(no output)');
                showToast('Analysis complete');
                await loadOverview();
            } catch (e) {
                writeTerminal('ERROR: ' + e.message);
                showToast(e.message || 'Run Analysis failed');
            }
        } catch (e) {
            writeTerminal('ERROR: ' + e.message); showToast('Run Analysis failed');
        } finally {
            setButtonBusy(btn, false, 'Analyzing…', 'Run Analysis');
        }
    }

    function showTerminal(msg) { document.getElementById('terminalPanel').classList.add('active'); document.getElementById('terminalLog').innerText = msg + '\n\n'; }
    function writeTerminal(t)  { const el = document.getElementById('terminalLog'); el.innerText += t; el.scrollTop = el.scrollHeight; }
    function closeTerminal()   { document.getElementById('terminalPanel').classList.remove('active'); }

    function switchTab(id) {
        document.querySelectorAll('.code-tab').forEach(t => t.classList.remove('active'));
        document.querySelector(`.code-tab[data-tab="${id}"]`)?.classList.add('active');
        document.querySelectorAll('.advisor-panel').forEach(p => p.classList.remove('active'));
        document.getElementById(id)?.classList.add('active');
    }

    function queryTypeOf(sql) {
        const s = String(sql || '').trim().toUpperCase();
        if (s.startsWith('SELECT')) return 'SELECT';
        if (s.startsWith('UPDATE')) return 'UPDATE';
        if (s.startsWith('INSERT')) return 'INSERT';
        if (s.startsWith('DELETE')) return 'DELETE';
        return 'SQL';
    }

    function renderAdvisorTable(headers, colClasses, rows, emptyMsg, clickHandlerPrefix) {
        if (!rows || rows.length === 0) {
            return `<p class="advisor-panel__placeholder">${emptyMsg}</p>`;
        }
        const th = headers.map((h, i) => `<th class="${colClasses[i] || ''}">${h}</th>`).join('');
        const tr = rows.map((row, rowIdx) => {
            const tds = row.map((cell, i) => {
                const v = cell == null ? '—' : String(cell);
                const cls = colClasses[i] || '';
                const title = v.length > 80 ? ` title="${escapeAttr(v)}"` : '';
                return `<td class="${cls}"${title}>${escapeHtml(v.length > 120 ? v.slice(0, 120) + '…' : v)}</td>`;
            }).join('');
            const clickAttr = clickHandlerPrefix ? ` class="q-clickable" onclick="openQueryDrawer('${clickHandlerPrefix}', ${rowIdx})"` : '';
            return `<tr${clickAttr}>${tds}</tr>`;
        }).join('');
        return `<div class="advisor-table-wrap"><table class="advisor-table"><thead><tr>${th}</tr></thead><tbody>${tr}</tbody></table></div>`;
    }

    function openQueryDrawer(type, index) {
        const data = cachedData[type] ? cachedData[type][index] : null;
        if (!data) return;

        let title = 'Details', sql = '', stats = {};
        if (type === 'columns') {
            title = 'Column Details';
            sql = data.context || data.source_file || 'No context available';
            stats = {
                'Table': data.table_name,
                'Column': data.column_name,
                'Type': data.query_type || 'Unknown',
                'Clause': data.clause || '='
            };
        } else if (type === 'queries') {
            title = 'Query Details';
            sql = data.sql_sample || '';
            stats = {
                'Type': queryTypeOf(sql),
                'Executions': Number(data.execution_count || 0).toLocaleString(),
                'Avg Duration': `${Math.round(data.total_duration_ms / (data.execution_count||1))}ms`,
                'Last Seen': data.last_seen_at ? new Date(data.last_seen_at).toLocaleTimeString() : '—'
            };
        } else if (type === 'query_stats') {
            title = 'Query Stats';
            sql = data.sql_sample || '';
            stats = {
                'Source': data.source,
                'Driver': data.db_driver,
                'Avg Duration': `${Math.round(data.avg_duration_ms || 0)}ms`,
                'Total Queries': '—'
            };
        } else if (type === 'explains') {
            title = 'Explain Plan Details';
            sql = data.sql_sample || data.fingerprint || '';
            stats = {
                'Scan Type': data.has_full_scan ? 'Full Scan' : 'Index',
                'Driver': data.driver,
                'Analyzed At': data.analyzed_at ? new Date(data.analyzed_at).toLocaleTimeString() : '—'
            };
        } else if (type === 'recommendations') {
            title = 'Recommendation Details';
            sql = `ALTER TABLE ${data.table_name} ADD INDEX (${data.column_name});`;
            stats = {
                'Table': data.table_name,
                'Column': data.column_name,
                'Score': data.score,
                'Type': data.index_type
            };
        }

        document.getElementById('qdTitle').textContent = title;
        document.getElementById('qdSql').textContent = sql;
        document.getElementById('qdSqlLines').textContent = sql.split('\n').map((_, i) => i + 1).join('\n');

        const statsHtml = Object.entries(stats).map(([k, v]) => `
            <div class="sql-stat-card">
                <span class="sql-stat-card__label">${escapeHtml(k)}</span>
                <span class="sql-stat-card__value ${k==='Type'?'sql-stat-card__value--accent':''}">${escapeHtml(String(v))}</span>
            </div>
        `).join('');
        document.getElementById('qdStats').innerHTML = statsHtml;

        document.getElementById('sqlDrawerOverlay').classList.add('is-open');
    }

    function closeQueryDrawer() {
        document.getElementById('sqlDrawerOverlay').classList.remove('is-open');
    }

    function copyDrawerSql() {
        const text = document.getElementById('qdSql').textContent;
        navigator.clipboard.writeText(text).then(() => showToast('Copied to clipboard'));
    }

    function toggleSqlExpand() {
        document.getElementById('sqlCodePanel').classList.toggle('is-expanded');
    }

    async function loadOverview() {
        document.getElementById('lastScanTime').textContent = 'Loading…';

        try {
            const res = await fetch(`${BASE_PATH}/api/overview`);
            if (!res.ok) throw new Error('Overview request failed');
            const d = await res.json();
            const s = d.samples || {};
            const t = d.tables || {};

            cachedData = {
                columns: s.columns || [],
                queries: s.queries || [],
                query_stats: s.query_stats || [],
                explains: s.explains || [],
                recommendations: s.recommendations || []
            };

            document.getElementById('sRec').textContent    = d.recommendations?.total   ?? 0;
            document.getElementById('sCrit').textContent   = d.recommendations?.critical ?? 0;
            document.getElementById('sHigh').textContent   = d.recommendations?.high     ?? 0;
            document.getElementById('sApplied').textContent = d.recommendations?.applied  ?? 0;

            // Real last scan time
            const lastScan = d.last_scan_at || d.updated_at || null;
            if (lastScan) {
                const dt = new Date(lastScan);
                const diff = Math.floor((Date.now() - dt.getTime()) / 1000);
                let rel;
                if (diff < 60)       rel = `${diff}s ago`;
                else if (diff < 3600) rel = `${Math.floor(diff/60)}m ago`;
                else if (diff < 86400)rel = `${Math.floor(diff/3600)}h ago`;
                else                  rel = dt.toLocaleDateString();
                document.getElementById('lastScanTime').textContent = `Last scan: ${rel}`;
            } else {
                document.getElementById('lastScanTime').textContent = d.recommendations?.total > 0
                    ? 'Scan data available' : 'No scan yet';
            }

            // Storage KPI strip
            const storage = [
                ['index_advisor_columns',          t.columns],
                ['index_advisor_queries',           t.queries],
                ['index_advisor_query_stats',       t.query_stats],
                ['index_advisor_explains',          t.explains],
                ['index_advisor_recommendations',   t.recommendations],
            ];
            document.getElementById('storageCounts').innerHTML = storage.map(([name, count]) => `
                <div class="kpi-strip__item">
                    <span class="kpi-strip__label">${escapeHtml(name)}</span>
                    <span class="kpi-strip__value">${Number(count ?? 0).toLocaleString()}</span>
                </div>`).join('');

            // Env strip
            document.getElementById('envInfo').innerHTML = `
                <div class="env-chip"><span class="env-chip__label">DB Driver</span><span class="env-chip__value">${escapeHtml(d.driver ?? '—')}</span></div>
                <div class="env-chip"><span class="env-chip__label">Profile</span><span class="env-chip__value">${escapeHtml(d.profile ?? '—')}</span></div>
                <div class="env-chip env-chip--wide"><span class="env-chip__label">Runtime Logging</span><span class="env-chip__value">${d.enabled ? 'Enabled' : 'Disabled'}</span></div>
                <div class="env-chip env-chip--wide"><span class="env-chip__label">Full table scans (EXPLAIN)</span><span class="env-chip__value">${Number(d.full_scans ?? 0).toLocaleString()}</span></div>`;

            // Build tab sections
            const colRows     = (s.columns || []).map(c  => [c.column_name, c.table_name, c.query_type, c.source_file]);
            const queryRows   = (s.queries  || []).map(q  => [q.execution_count, Math.round(q.total_duration_ms), q.max_duration_ms, q.sql_sample]);
            const statRows    = (s.query_stats || []).map(q => [q.source, q.db_driver, q.avg_duration_ms, q.sql_sample]);
            const explainRows = (s.explains  || []).map(e  => [e.has_full_scan ? 'SEQ' : 'OK', e.driver, e.analyzed_at, e.sql_sample || e.fingerprint]);
            const recRows     = (s.recommendations || []).map(r => [r.table_name, r.column_name, r.index_type, r.score]);

            const sections = [
                { id: 'tab-columns',     title: 'Index Advisor Columns',     subtitle: `Tracked columns from your Laravel codebase · ${colRows.length} / ${t.columns ?? 0} rows`,     html: renderAdvisorTable(['Column', 'Table', 'Clause', 'Source file'], ['col-column', 'col-table', 'col-clause', 'col-source'], colRows, 'No code-scan columns yet. Run Analysis or php artisan index-advisor:analyze-code.', 'columns') },
                { id: 'tab-queries',     title: 'Advisor Queries',           subtitle: `Runtime queries · ${queryRows.length} / ${t.queries ?? 0} rows`,                               html: renderAdvisorTable(['Executions', 'Total ms', 'Max ms', 'SQL'], ['col-exec', 'col-ms', 'col-ms', 'col-sql'], queryRows, 'No runtime queries yet. Enable INDEX_ADVISOR_ENABLED and use the app.', 'queries') },
                { id: 'tab-query-stats', title: 'Query Stats',               subtitle: `Aggregate statistics · ${statRows.length} / ${t.query_stats ?? 0} rows`,                      html: renderAdvisorTable(['Source', 'Driver', 'Avg ms', 'SQL'], ['col-source-type', 'col-driver', 'col-ms', 'col-sql'], statRows, 'No slow-log stats yet. Run Analysis or php artisan index-advisor:ingest-slow-log.', 'query_stats') },
                { id: 'tab-explains',    title: 'Advisor Explains',          subtitle: `EXPLAIN plan results · ${explainRows.length} / ${t.explains ?? 0} rows`,                       html: renderAdvisorTable(['Scan', 'Driver', 'Analyzed', 'SQL / fingerprint'], ['col-scan', 'col-driver', 'col-time', 'col-sql'], explainRows, 'No EXPLAIN plans yet. Uncheck "Skip EXPLAIN" and Run Analysis.', 'explains') },
                { id: 'tab-recs',        title: 'Advisor Recommendations',   subtitle: `See Recommendations page for full list · ${recRows.length} / ${t.recommendations ?? 0} rows`, html: renderAdvisorTable(['Table', 'Column', 'Type', 'Score'], ['col-table', 'col-column', 'col-type', 'col-score'], recRows, 'No recommendations yet. Run Analysis to score candidates.', 'recommendations') },
            ];

            document.getElementById('dataSections').innerHTML = sections.map((sec, idx) => `
                <div id="${sec.id}" class="advisor-panel ${idx === 0 ? 'active' : ''}" role="tabpanel">
                    <div class="advisor-card">
                        <div class="advisor-card__header">
                            <h3 class="advisor-card__title">${escapeHtml(sec.title)}</h3>
                            <p class="advisor-card__subtitle">${escapeHtml(sec.subtitle)}</p>
                        </div>
                        ${sec.html}
                    </div>
                </div>`).join('');

        } catch (e) {
            document.getElementById('dataSections').innerHTML =
                '<div class="advisor-card"><p class="advisor-panel__placeholder" style="color:var(--critical);">Failed to load overview data.</p></div>';
            document.getElementById('lastScanTime').textContent = 'Failed to load';
        }
    }

    function switchTab(tabId) {
        document.querySelectorAll('.code-tab').forEach(btn => btn.classList.toggle('active', btn.dataset.tab === tabId));
        document.querySelectorAll('.advisor-panel').forEach(panel => panel.classList.toggle('active', panel.id === tabId));
    }
</script>
@endpush
