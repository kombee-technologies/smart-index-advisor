@extends('smart-index-advisor::layout')

@section('title', 'Smart Index Advisor — Query Log')
@section('topbar-title', 'Query Log')

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
/* ── Monitoring banner ── */
.monitoring-banner {
    display: flex; align-items: center; justify-content: space-between;
    height: 37px; padding: 0 16px;
    background: #0e0e10; border-bottom: 1px solid #242833; flex-shrink: 0;
}
.monitoring-banner__left  { display: flex; align-items: center; gap: 12px; }
.monitoring-banner__status{ display: flex; align-items: center; gap: 8px; }
.monitoring-banner__dot   {
    width: 8px; height: 8px; border-radius: 50%;
    background: #10b981; box-shadow: 0 0 8px rgba(16,185,129,0.5); flex-shrink: 0;
}
.monitoring-banner__text    { font-size: 13px; color: var(--text-primary); }
.monitoring-banner__updated { font-size: 12px; color: var(--text-muted); }
.monitoring-banner__right   { display: flex; align-items: center; }
.monitoring-toggle { display: flex; align-items: center; gap: 8px; cursor: pointer; user-select: none; }
.monitoring-toggle__box {
    width: 16px; height: 16px; border-radius: 4px;
    border: 1px solid #424654; background: var(--bg-app); flex-shrink: 0;
    transition: background 0.15s, border-color 0.15s; position: relative;
}
.monitoring-toggle__box.is-checked { background: var(--accent-primary); border-color: var(--accent-primary); }
.monitoring-toggle__box.is-checked::after {
    content: ''; position: absolute;
    left: 4px; top: 1px; width: 5px; height: 9px;
    border: solid #fff; border-width: 0 2px 2px 0; transform: rotate(45deg);
}
.monitoring-toggle__text { font-size: 12px; color: var(--text-muted); }

/* ── Query canvas overrides ── */
.canvas.query-log-canvas { padding: 0; gap: 0; }

/* ── Process status inside canvas ── */
.query-process-status {
    display: none; flex-direction: column; gap: 4px;
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: var(--radius-lg); padding: 17px; margin: 16px 16px 0;
}
.query-process-status.active { display: flex; }
.query-process-status__header {
    display: flex; align-items: center; justify-content: space-between;
    padding-bottom: 8px; border-bottom: 1px solid rgba(59,73,76,0.2);
}
.query-process-status__title { font-size: 12px; font-weight: 500; color: #dae2fd; }
.query-process-status__close { font-size: 12px; color: #dae2fd; cursor: pointer; background: none; border: none; font-family: inherit; }
.query-process-status__terminal {
    background: rgba(0,0,0,0.4); padding: 14px;
    display: flex; flex-direction: column; gap: 4px;
}
.query-process-status__line { font-family: var(--font-mono, monospace); font-size: 12px; line-height: 16px; }
.query-process-status__line--info  { color: #00daf3; }
.query-process-status__line--error { color: var(--critical); }

/* ── Query log area ── */
.query-log-area {
    display: flex; flex-direction: column; flex: 1; min-height: 0; overflow: hidden;
    border-top: 1px solid #242833;
}

/* ── Query filter bar ── */
.query-filter-bar {
    display: flex; align-items: center; gap: 8px; padding: 10px 12px;
    background: #2a2a2c; border-bottom: 1px solid #242833;
    flex-wrap: wrap; flex-shrink: 0;
}
.query-filter-bar .search-input { width: 220px; flex: 0 0 220px; }

/* ── Query table ── */
.query-table-wrap { flex: 1; overflow: auto; background: var(--bg-app); min-height: 300px; }
.query-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
.query-table thead th {
    position: sticky; top: 0; z-index: 2; background: #0e0e10;
    border-bottom: 1px solid #242833;
    padding: 8px 12px; font-size: 12px; font-weight: 500; color: var(--text-muted);
    text-align: left; white-space: nowrap; user-select: none; cursor: pointer;
}
.query-table thead th.col-num  { text-align: right; }
.query-table thead th.col-scan { text-align: center; }
.query-table thead th.col-last { text-align: right; }
.query-table tbody tr { border-bottom: 1px solid #242833; }
.query-table tbody tr:hover { background: rgba(173,198,255,0.04); }
.query-table tbody tr.query-row--critical { background: rgba(255,180,171,0.04); }
.query-table tbody td { padding: 14px 12px; font-size: 12px; color: var(--text-muted); vertical-align: middle; }
.query-table .col-sql  { width: 38%; }
.query-table .col-num  { width: 90px; text-align: right; }
.query-table .col-scan { width: 105px; text-align: center; }
.query-table .col-last { width: 95px; text-align: right; font-size: 11px; }
.sql-text {
    font-family: var(--font-mono, monospace); font-size: 12px;
    color: var(--text-primary); white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; display: block;
}

/* ── MS colour coding ── */
.ms--critical { color: #ffb4ab !important; font-weight: 600; }
.ms--high     { color: #ffb786 !important; font-weight: 500; }
.ms--medium   { color: #facc15 !important; }
.ms--normal   { color: var(--text-muted); }
.ms--low      { color: #adc6ff; }

/* ── Scan badges ── */
.scan-badge { display: inline-block; padding: 3px 7px; border-radius: 2px; font-size: 11px; font-weight: 500; line-height: 1.2; white-space: nowrap; }
.scan-badge--full    { background: rgba(147,0,10,0.2);  border: 1px solid rgba(255,180,171,0.3); color: #ffb4ab; }
.scan-badge--index   { background: rgba(77,142,255,0.2); border: 1px solid rgba(173,198,255,0.3); color: #adc6ff; }
.scan-badge--unknown { background: #353538; border: 1px solid #242833; color: var(--text-muted); }

/* ── Pagination ── */
.query-pagination {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 12px; background: #0e0e10; border-top: 1px solid #242833; flex-shrink: 0;
}
.query-pagination__info { font-size: 12px; color: var(--text-muted); }
.query-pagination__controls { display: flex; align-items: center; gap: 12px; }
.query-pagination__rows { display: flex; align-items: center; gap: 8px; }
.query-pagination__rows-label { font-size: 12px; color: var(--text-muted); white-space: nowrap; }
.query-pagination__nav { display: flex; align-items: center; gap: 4px; }
.query-pagination__page-info { font-size: 12px; color: var(--text-muted); padding: 0 8px; white-space: nowrap; }
.query-page-btn {
    width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;
    border: none; border-radius: var(--radius-sm); background: transparent;
    color: var(--text-muted); cursor: pointer; font-size: 13px; font-family: inherit;
    transition: background 0.15s;
}
.query-page-btn:hover:not(:disabled) { background: rgba(255,255,255,0.06); color: var(--text-primary); }
.query-page-btn:disabled { opacity: 0.35; cursor: not-allowed; }
.query-page-btn svg { width: 8px; height: 12px; }

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
.query-table tbody tr.q-clickable { cursor: pointer; }
.query-table tbody tr.q-clickable:hover { background: rgba(173,198,255,0.08); }

@media (max-width: 900px) {
    .monitoring-banner { flex-direction: column; align-items: flex-start; height: auto; padding: 10px 16px; gap: 8px; }
    .query-filter-bar .search-input { width: 100%; flex: 1 1 100%; }
    .sql-drawer__stats { grid-template-columns: repeat(2, 1fr); }
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

<div id="sqlDrawerOverlay" class="sql-drawer-overlay" role="dialog" aria-modal="true" aria-label="Query details" aria-hidden="true">
    <div class="sql-drawer-backdrop" onclick="closeQueryDrawer()"></div>
    <aside class="drawer--sql-detail">
        <header class="sql-drawer__header">
            <div class="sql-drawer__header-row">
                <h2 class="sql-drawer__title">Query Details</h2>
                <button type="button" class="sql-drawer__close" onclick="closeQueryDrawer()" aria-label="Close">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="currentColor" aria-hidden="true"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="1.5" fill="none"/></svg>
                </button>
            </div>
        </header>
        <div class="sql-drawer__body">
            <section class="sql-drawer__section" aria-label="Query statistics">
                <div class="sql-drawer__stats">
                    <div class="sql-stat-card">
                        <span class="sql-stat-card__label">Type</span>
                        <span class="sql-stat-card__value sql-stat-card__value--accent" id="qdType">—</span>
                    </div>
                    <div class="sql-stat-card">
                        <span class="sql-stat-card__label">Executions</span>
                        <span class="sql-stat-card__value" id="qdExec">—</span>
                    </div>
                    <div class="sql-stat-card">
                        <span class="sql-stat-card__label">Avg Duration</span>
                        <span class="sql-stat-card__value" id="qdAvg">—</span>
                    </div>
                    <div class="sql-stat-card">
                        <span class="sql-stat-card__label">Last Seen</span>
                        <span class="sql-stat-card__value" id="qdLast">—</span>
                    </div>
                </div>
            </section>
            <section class="sql-drawer__section" aria-label="SQL statement">
                <div class="sql-drawer__section-head">
                    <h3 class="sql-drawer__section-title">SQL Statement</h3>
                    <button type="button" class="sql-copy-btn" onclick="copyDrawerSql()">
                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                            <rect x="3.5" y="3.5" width="7" height="7" rx="0.5" stroke="currentColor"/>
                            <path d="M1.5 1.5h6.75v6.87" stroke="currentColor"/>
                        </svg>
                        Copy SQL
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
/* ─────────────────────────────
   State
───────────────────────────── */
let allRows    = [];
let filtered   = [];
let showSystem = false;
let qPage      = 1;
let qPerPage   = 50;
let sortCol    = 'avg_ms';
let sortDir    = 'desc';

/* ─────────────────────────────
   Build DOM on load
───────────────────────────── */
document.addEventListener('DOMContentLoaded', function () {
    const canvas = document.querySelector('.canvas');
    if (!canvas) return;
    canvas.classList.add('query-log-canvas');

    /* Monitoring banner */
    const banner = document.createElement('div');
    banner.className = 'monitoring-banner';
    banner.innerHTML = `
        <div class="monitoring-banner__left">
            <div class="monitoring-banner__status">
                <span class="monitoring-banner__dot" aria-hidden="true"></span>
                <span class="monitoring-banner__text">Showing top <span id="qCountDisplay">50</span> slowest queries</span>
            </div>
            <span class="monitoring-banner__updated" id="qUpdatedAt">Updated just now</span>
        </div>
        <div class="monitoring-banner__right">
            <label class="monitoring-toggle" onclick="toggleSysQueries()">
                <span class="monitoring-toggle__box" id="sysToggleBox"></span>
                <span class="monitoring-toggle__text">Show system queries</span>
            </label>
        </div>`;
    canvas.appendChild(banner);

    /* Query log area */
    const area = document.createElement('div');
    area.className = 'query-log-area';
    area.innerHTML = `
        <div class="query-filter-bar">
            <div class="search-input" style="flex:0 1 220px;min-width:160px;">
                <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="6" cy="6" r="4.5"/><path d="M9.5 9.5L13 13"/></svg>
                <input type="search" id="qSearch" placeholder="Filter Queries…" oninput="applyQFilters()" aria-label="Filter queries">
            </div>
            <select class="select" id="qLimitSelect" onchange="loadQueryLog()" aria-label="Top N">
                <option value="25">Top 25</option>
                <option value="50" selected>Top 50</option>
                <option value="100">Top 100</option>
                <option value="200">Top 200</option>
            </select>
            <select class="select" id="qFilterDuration" onchange="applyQFilters()" aria-label="Duration">
                <option value="">Duration</option>
                <option value="50">&gt; 50ms</option>
                <option value="100">&gt; 100ms</option>
                <option value="500">&gt; 500ms</option>
                <option value="1000">&gt; 1s</option>
            </select>
            <select class="select" id="qFilterExec" onchange="applyQFilters()" aria-label="Executions">
                <option value="">Executions</option>
                <option value="5">&gt; 5</option>
                <option value="10">&gt; 10</option>
                <option value="50">&gt; 50</option>
                <option value="100">&gt; 100</option>
            </select>
            <select class="select" id="qFilterScan" onchange="applyQFilters()" aria-label="Scan type">
                <option value="">Scan Type</option>
                <option value="full">Full Scan</option>
                <option value="index">Index Scan</option>
                <option value="unknown">Unknown</option>
            </select>
            <select class="select" id="qFilterTime" onchange="loadQueryLog()" aria-label="Time range">
                <option value="">All time</option>
                <option value="1">Last 1h</option>
                <option value="24">Last 24h</option>
                <option value="168">Last 7d</option>
            </select>
        </div>
        <div class="query-table-wrap">
            <table class="query-table" id="qTable">
                <thead>
                    <tr>
                        <th class="col-sql" onclick="sortBy('sql')">SQL Sample</th>
                        <th class="col-num" onclick="sortBy('avg_ms')" title="Sort by Avg ms">Avg MS ↕</th>
                        <th class="col-num" onclick="sortBy('max_ms')" title="Sort by Max ms">Max MS</th>
                        <th class="col-num" onclick="sortBy('total_ms')" title="Sort by Total ms">Total MS</th>
                        <th class="col-num" onclick="sortBy('exec')" title="Sort by executions">Executions</th>
                        <th class="col-scan">Scan</th>
                        <th class="col-last" onclick="sortBy('last_seen')" title="Sort by last seen">Last Seen</th>
                    </tr>
                </thead>
                <tbody id="qTableBody">
                    <tr><td colspan="7" style="color:var(--text-muted);padding:40px;text-align:center;">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <footer class="query-pagination">
            <span class="query-pagination__info" id="qPagInfo">—</span>
            <div class="query-pagination__controls">
                <div class="query-pagination__rows">
                    <span class="query-pagination__rows-label">Rows per page:</span>
                    <select class="select per-page" id="qPerPageSelect" onchange="changeQPerPage(+this.value)" aria-label="Rows per page">
                        <option value="25">25</option>
                        <option value="50" selected>50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div class="query-pagination__nav">
                    <button type="button" class="query-page-btn" id="qBtnFirst"  onclick="goQPage(1)"         title="First page" disabled>
                        <svg viewBox="0 0 9 8" fill="currentColor"><path d="M4 0L0 4l4 4V0zm4 0v8H6V0h2z"/></svg>
                    </button>
                    <button type="button" class="query-page-btn" id="qBtnPrev"   onclick="goQPage(qPage-1)"   title="Previous page" disabled>
                        <svg viewBox="0 0 7 11" fill="currentColor"><path d="M6 0L1 5.5 6 11V0z"/></svg>
                    </button>
                    <span class="query-pagination__page-info" id="qPageInfo">Page 1 of 1</span>
                    <button type="button" class="query-page-btn" id="qBtnNext"   onclick="goQPage(qPage+1)"   title="Next page" disabled>
                        <svg viewBox="0 0 7 11" fill="currentColor"><path d="M1 0l5 5.5L1 11V0z"/></svg>
                    </button>
                    <button type="button" class="query-page-btn" id="qBtnLast"   onclick="goQPage(totalQPages())" title="Last page" disabled>
                        <svg viewBox="0 0 9 8" fill="currentColor"><path d="M5 0l4 4-4 4V0zm-5 0v8h2V0H0z"/></svg>
                    </button>
                </div>
            </div>
        </footer>`;
    canvas.appendChild(area);

    loadQueryLog();

    document.getElementById('btnRunAnalysis').addEventListener('click', runQueryAnalysis);
});

/* ─────────────────────────────
   Data loading
───────────────────────────── */
async function loadQueryLog() {
    const limit  = document.getElementById('qLimitSelect')?.value  || 50;
    const hours  = document.getElementById('qFilterTime')?.value   || '';
    const sys    = showSystem ? '1' : '0';
    const tbody  = document.getElementById('qTableBody');
    if (tbody) tbody.innerHTML = `<tr><td colspan="7" style="color:var(--text-muted);padding:40px;text-align:center;">Loading…</td></tr>`;

    let url = `${BASE_PATH}/api/query-log?limit=${limit}&include_system=${sys}`;
    if (hours) url += `&hours=${hours}`;

    try {
        const res  = await fetch(url);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        allRows    = data.queries || [];

        const countEl = document.getElementById('qCountDisplay');
        if (countEl) countEl.textContent = limit;
        updateQTimestamp(allRows);
        applyQFilters();
    } catch (e) {
        const tbody = document.getElementById('qTableBody');
        if (tbody) tbody.innerHTML = `<tr><td colspan="7" style="color:var(--critical);padding:40px;text-align:center;">Failed: ${escapeHtml(e.message)}</td></tr>`;
    }
}

function updateQTimestamp(rows) {
    // Show the most recent last_seen_at from the data, not current time
    const el = document.getElementById('qUpdatedAt');
    if (!el) return;
    if (rows && rows.length > 0) {
        // Find the most recent last_seen_at across all rows
        const latest = rows.reduce((max, q) => {
            const t = q.last_seen_at ? new Date(q.last_seen_at).getTime() : 0;
            return t > max ? t : max;
        }, 0);
        if (latest > 0) {
            const dt = new Date(latest);
            const pad = n => String(n).padStart(2,'0');
            el.textContent = 'Updated ' + dt.toLocaleDateString() + ' ' + pad(dt.getHours()) + ':' + pad(dt.getMinutes()) + ':' + pad(dt.getSeconds());
        } else { el.textContent = 'Updated just now'; }
    } else { el.textContent = 'No data'; }
}

function applyQFilters() {
    qPage = 1;
    const search  = (document.getElementById('qSearch')?.value       || '').toLowerCase();
    const minMs   = parseFloat(document.getElementById('qFilterDuration')?.value || 0);
    const minExec = parseInt(document.getElementById('qFilterExec')?.value       || 0);
    const scan    = document.getElementById('qFilterScan')?.value    || '';

    filtered = allRows.filter(q => {
        if (search  && !(q.sql_sample || '').toLowerCase().includes(search)) return false;
        const avg = avgMsOf(q);
        if (minMs   && avg   < minMs)   return false;
        if (minExec && (q.execution_count||0) < minExec) return false;
        if (scan === 'full'    && q.has_full_scan !== true)  return false;
        if (scan === 'index'   && q.has_full_scan !== false) return false;
        if (scan === 'unknown' && q.has_full_scan != null)   return false;
        return true;
    });

    // Sort
    filtered.sort((a, b) => {
        let av, bv;
        switch (sortCol) {
            case 'avg_ms':    av = avgMsOf(a);                      bv = avgMsOf(b);  break;
            case 'max_ms':    av = a.max_duration_ms   || 0;        bv = b.max_duration_ms   || 0; break;
            case 'total_ms':  av = a.total_duration_ms || 0;        bv = b.total_duration_ms || 0; break;
            case 'exec':      av = a.execution_count   || 0;        bv = b.execution_count   || 0; break;
            case 'last_seen': av = new Date(a.last_seen_at||0).getTime(); bv = new Date(b.last_seen_at||0).getTime(); break;
            default:          av = (a.sql_sample||'').toLowerCase();      bv = (b.sql_sample||'').toLowerCase();
        }
        return sortDir === 'asc' ? (av > bv ? 1 : -1) : (av < bv ? 1 : -1);
    });

    renderQPage();
}

function avgMsOf(q) {
    return q.execution_count > 0
        ? q.total_duration_ms / q.execution_count
        : parseFloat(q.avg_duration_ms || 0);
}

function sortBy(col) {
    if (sortCol === col) sortDir = sortDir === 'asc' ? 'desc' : 'asc';
    else { sortCol = col; sortDir = 'desc'; }
    applyQFilters();
}

/* ─────────────────────────────
   Pagination
───────────────────────────── */
function totalQPages() { return Math.max(1, Math.ceil(filtered.length / qPerPage)); }

function goQPage(p) {
    const max = totalQPages();
    if (p < 1 || p > max) return;
    qPage = p;
    renderQPage();
}

function changeQPerPage(val) {
    qPerPage = val;
    qPage    = 1;
    renderQPage();
}

function renderQPage() {
    const tbody = document.getElementById('qTableBody');
    if (!tbody) return;

    const total = filtered.length;
    const pages = totalQPages();
    const start = (qPage - 1) * qPerPage;
    const page  = filtered.slice(start, start + qPerPage);

    // Update info
    const infoEl = document.getElementById('qPagInfo');
    if (infoEl) infoEl.textContent = `${start+1}–${Math.min(start+qPerPage, total)} of ${total} queries`;
    const pageEl = document.getElementById('qPageInfo');
    if (pageEl) pageEl.textContent = `Page ${qPage} of ${pages}`;

    // Update nav buttons
    ['qBtnFirst','qBtnPrev'].forEach(id => { const b = document.getElementById(id); if (b) b.disabled = qPage <= 1; });
    ['qBtnNext','qBtnLast'].forEach(id  => { const b = document.getElementById(id); if (b) b.disabled = qPage >= pages; });

    if (total === 0) {
        tbody.innerHTML = `<tr><td colspan="7" style="padding:40px;color:var(--text-muted);text-align:center;">No queries match the current filters.</td></tr>`;
        return;
    }

    tbody.innerHTML = page.map((q, i) => {
        const avg    = avgMsOf(q);
        const avgStr = avg.toFixed(1);
        const maxMs  = q.max_duration_ms  || '—';
        const totMs  = Math.round(q.total_duration_ms || 0);

        // Colour thresholds: ≥500ms critical, ≥100ms high, ≥50ms medium, <50ms low
        const msClass = avg >= 500 ? 'ms--critical'
                      : avg >= 100 ? 'ms--high'
                      : avg >= 50  ? 'ms--medium'
                      : 'ms--low';
        const rowClass = avg >= 500 ? 'query-row--critical' : '';

        let scanBadge;
        if      (q.has_full_scan === true)  scanBadge = '<span class="scan-badge scan-badge--full">Full Scan</span>';
        else if (q.has_full_scan === false) scanBadge = '<span class="scan-badge scan-badge--index">Index Scan</span>';
        else                               scanBadge = '<span class="scan-badge scan-badge--unknown">Unknown</span>';

        const lastSeen = q.last_seen_at ? relativeTime(new Date(q.last_seen_at)) : '—';

        const rowIndex = start + i;
        return `<tr class="${rowClass} q-clickable" onclick="openQueryDrawer(${rowIndex})">
            <td class="col-sql"><span class="sql-text" title="${escapeAttr(q.sql_sample||'')}">${escapeHtml(q.sql_sample||'')}</span></td>
            <td class="col-num ${msClass}">${avgStr}ms</td>
            <td class="col-num" style="color:var(--text-muted)">${maxMs}ms</td>
            <td class="col-num" style="color:var(--text-muted)">${totMs.toLocaleString()}ms</td>
            <td class="col-num" style="color:var(--text-muted)">${Number(q.execution_count||0).toLocaleString()}</td>
            <td class="col-scan">${scanBadge}</td>
            <td class="col-last" style="font-size:11px;color:var(--text-muted);">${lastSeen}</td>
        </tr>`;
    }).join('');
}

/* Relative time helper */
function relativeTime(date) {
    const diff = Math.floor((Date.now() - date.getTime()) / 1000);
    if (diff < 60)    return `${diff}s ago`;
    if (diff < 3600)  return `${Math.floor(diff/60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff/3600)}h ago`;
    return date.toLocaleDateString();
}

/* ─────────────────────────────
   System queries toggle
───────────────────────────── */
function toggleSysQueries() {
    showSystem = !showSystem;
    const box = document.getElementById('sysToggleBox');
    if (box) box.classList.toggle('is-checked', showSystem);
    loadQueryLog();
}

function queryTypeOf(sql) {
    const s = String(sql || '').trim().toUpperCase();
    if (s.startsWith('SELECT')) return 'SELECT';
    if (s.startsWith('UPDATE')) return 'UPDATE';
    if (s.startsWith('INSERT')) return 'INSERT';
    if (s.startsWith('DELETE')) return 'DELETE';
    return 'SQL';
}

function openQueryDrawer(index) {
    const q = filtered[index];
    if (!q) return;

    document.getElementById('qdType').textContent = queryTypeOf(q.sql_sample);
    document.getElementById('qdExec').textContent = Number(q.execution_count || 0).toLocaleString();
    document.getElementById('qdAvg').textContent = `${avgMsOf(q).toFixed(1)}ms`;
    document.getElementById('qdLast').textContent = q.last_seen_at ? relativeTime(new Date(q.last_seen_at)) : '—';

    // Format SQL with line numbers
    const sql = q.sql_sample || '';
    const formattedSql = formatSqlForDisplay(sql);
    document.getElementById('qdSql').textContent = formattedSql;

    // Generate line numbers
    const lines = formattedSql.split('\n');
    document.getElementById('qdSqlLines').textContent = lines.map((_, i) => i + 1).join('\n');

    // Reset expand state
    const panel = document.getElementById('sqlCodePanel');
    if (panel) panel.classList.remove('is-expanded');

    const overlay = document.getElementById('sqlDrawerOverlay');
    overlay.classList.add('is-open');
    overlay.setAttribute('aria-hidden', 'false');
}

function closeQueryDrawer() {
    const overlay = document.getElementById('sqlDrawerOverlay');
    overlay.classList.remove('is-open');
    overlay.setAttribute('aria-hidden', 'true');
}

function copyDrawerSql() {
    const text = document.getElementById('qdSql').textContent || '';
    navigator.clipboard.writeText(text).then(() => showToast('SQL copied'));
}

function toggleSqlExpand() {
    const panel = document.getElementById('sqlCodePanel');
    if (panel) panel.classList.toggle('is-expanded');
}

/** Format a SQL string for readable display with line breaks */
function formatSqlForDisplay(sql) {
    if (!sql) return '';
    // Add line breaks before major SQL keywords for readability
    return sql
        .replace(/\s+(SELECT|FROM|WHERE|AND|OR|JOIN|LEFT JOIN|RIGHT JOIN|INNER JOIN|OUTER JOIN|ON|ORDER BY|GROUP BY|HAVING|LIMIT|OFFSET|INSERT INTO|UPDATE|SET|DELETE FROM|VALUES|UNION|EXCEPT|INTERSECT)\b/gi,
            (match, keyword) => '\n' + keyword)
        .trim();
}

function showTerminal(msg) { document.getElementById('terminalPanel').classList.add('active'); document.getElementById('terminalLog').innerText = msg + '\n\n'; }
function writeTerminal(t)  { const el = document.getElementById('terminalLog'); el.innerText += t; el.scrollTop = el.scrollHeight; }
function closeTerminal()   { document.getElementById('terminalPanel').classList.remove('active'); }

async function runQueryAnalysis() {
    const btn = document.getElementById('btnRunAnalysis');
    setButtonBusy(btn, true, 'Analyzing…', 'Run Analysis');
    const skipCode    = document.getElementById('chkSkipCode')?.checked    ?? false;
    const skipExplain = document.getElementById('chkSkipExplain')?.checked ?? true;
    const skipLocalDb = document.getElementById('chkSkipLocalDb')?.checked ?? false;
    const flags = [skipCode && 'code skipped', skipExplain && 'EXPLAIN skipped', skipLocalDb && 'local DB skipped'].filter(Boolean);
    showTerminal('Running Smart Index Advisor pipeline' + (flags.length ? ' (' + flags.join(', ') + ')' : '') + '…');
    try {
        const { ok, status, data, raw } = await parseJsonResponse(await fetch(`${BASE_PATH}/api/run`, {
            method: 'POST', headers: apiHeaders(),
            body: JSON.stringify({ skip_explain: skipExplain, skip_code_analysis: skipCode, skip_local_db: skipLocalDb }),
        }));
        if (!data) { writeTerminal('ERROR: HTTP ' + status + '\n' + (raw||'').slice(0,2000)); showToast('Run Analysis failed'); return; }
        try {
            const result = await resolveIndexAdvisorTaskResponse(data);
            writeTerminal(result.output || '(no output)');
            showToast('Analysis complete');
            await loadQueryLog();
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
</script>
@endpush
