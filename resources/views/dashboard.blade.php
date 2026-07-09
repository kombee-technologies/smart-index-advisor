@extends('smart-index-advisor::layout')

@section('title', 'Smart Index Advisor — Recommendations')
@section('topbar-title', 'Smart Index Advisor')

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
        <button type="button" id="btnRunPipeline" class="btn-run-analysis is-active">
            <span>Run Analysis</span>
        </button>
    </div>
    <a href="{{ $basePath }}/export-report" class="btn btn--outline btn-download-report" style="text-decoration:none; margin-right:8px;" title="Download CSV Report">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right:4px;">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
            <polyline points="7 10 12 15 17 10"/>
            <line x1="12" y1="15" x2="12" y2="3"/>
        </svg>
        <span>Download Report</span>
    </a>
    <a href="{{ $basePath }}/migrations" class="btn btn--primary btn-generate" style="text-decoration:none;">
        <span>Generate Migrations</span>
    </a>
</div>
@endsection

@push('head')
<style>
/* ── Rec card (theme style: label+chevron, no expand) ── */
.rec-card--clickable {
    display: block;
    cursor: pointer;
    text-decoration: none;
}
.rec-card__header {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px;
    background: var(--bg-card-header);
}
.severity-badge--p1 { border-color: var(--critical); color: var(--critical); background: rgba(255,180,171,0.1); }
.severity-badge--p2 { border-color: var(--high);     color: var(--high);     background: rgba(255,183,134,0.1); }
.severity-badge--p3 { border-color: var(--medium);   color: var(--medium);   background: rgba(192,193,255,0.1); }
.severity-badge--p4 { border-color: var(--low);      color: var(--low);      background: rgba(173,198,255,0.1); }
.severity-badge { flex-direction: column !important; gap: 1px; height: 48px !important; min-width: 48px; }
.severity-badge__priority { font-size: 12px; font-weight: 700; line-height: 1; }
.severity-badge__pts      { font-size: 10px; font-weight: 600; opacity: 0.85; line-height: 1; }

.rec-card__body  { flex: 1; min-width: 0; }
.rec-card__title-row { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
.rec-card__table  { font-size: 14px; font-weight: 500; color: var(--text-primary); }
.rec-card__sep    { color: var(--text-muted); font-size: 10px; }
.rec-card__column { font-size: 14px; color: var(--medium); }
.rec-card__meta   { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; }
.rec-card__timestamp { font-size: 11px; color: var(--text-dim); }
.rec-card__chevron   { flex-shrink: 0; color: var(--text-muted); }

.tag {
    display: inline-flex; align-items: center;
    padding: 3px 9px; border-radius: var(--radius-sm);
    font-size: 11px; font-weight: 600; letter-spacing: 0.02em; text-transform: uppercase;
}
.tag--drop    { background: rgba(6,182,212,0.1);   border: 1px solid var(--tag-drop);              color: var(--tag-drop); }
.tag--index   { background: rgba(255,180,171,0.1); border: 1px solid rgba(255,180,171,0.2);        color: var(--tag-index); }
.tag--composite{ background: rgba(192,193,255,0.1); border: 1px solid rgba(192,193,255,0.3);       color: var(--medium); }
.tag--redundant{ background: rgba(250,204,21,0.08); border: 1px solid rgba(250,204,21,0.2);        color: #facc15; text-transform: uppercase; }
.tag--status  { background: #353538; border: 1px solid var(--border); color: var(--text-muted); text-transform: lowercase; font-weight: 500; }
.tag--db-ok   { background: #353538; border: 1px solid var(--border); color: var(--status-green); text-transform: none; font-weight: 500; }
.tag--db-warn { background: #353538; border: 1px solid var(--border); color: var(--status-yellow); text-transform: none; font-weight: 500; }

/* ── Drawer overlay ── */
.drawer-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 500;
}
.drawer-overlay.is-open { display: block; }

.drawer-backdrop {
    position: absolute; inset: 0;
    background: rgba(0,0,0,0.55);
    cursor: pointer;
}
.drawer {
    position: absolute; top: 0; right: 0; bottom: 0;
    z-index: 1; width: 600px; max-width: 100%;
    display: flex; flex-direction: column;
    pointer-events: auto;
    background: #18181b;
    border-left: 1px solid var(--border);
    box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
    animation: drawer-slide-in 0.25s ease-out;
}
@keyframes drawer-slide-in {
    from { transform: translateX(100%); }
    to   { transform: translateX(0); }
}

.drawer__header {
    flex-shrink: 0; padding: 16px;
    background: #2a2a2c; border-bottom: 1px solid var(--border);
}
.drawer__header-top {
    display: flex; align-items: flex-start;
    justify-content: space-between; gap: 16px; margin-bottom: 4px;
}
.drawer__badges { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 8px; }
.drawer-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 9px; font-size: 11px; font-weight: 600;
    letter-spacing: 0.02em; text-transform: uppercase; border-radius: var(--radius-sm);
}
.drawer-badge--critical { background: rgba(147,0,10,0.1); border: 1px solid rgba(255,180,171,0.3); color: var(--critical); }
.drawer-badge--high     { background: rgba(223,116,18,0.1); border: 1px solid #df7412; color: var(--high); }
.drawer-badge--medium   { background: rgba(192,193,255,0.1); border: 1px solid rgba(192,193,255,0.3); color: var(--medium); }
.drawer-badge--low      { background: rgba(173,198,255,0.1); border: 1px solid rgba(173,198,255,0.3); color: var(--low); }
.drawer-badge--index    { background: rgba(173,198,255,0.1); border: 1px solid var(--accent-blue); color: var(--accent-blue); }
.drawer-badge--drop     { background: rgba(6,182,212,0.1); border: 1px solid var(--tag-drop); color: var(--tag-drop); }
.drawer-badge--redundant{ background: rgba(250,204,21,0.08); border: 1px solid rgba(250,204,21,0.2); color: #facc15; }

.drawer__close {
    display: flex; align-items: center; justify-content: center;
    width: 22px; height: 22px; flex-shrink: 0;
    color: var(--text-muted); cursor: pointer; border-radius: var(--radius);
    background: none; border: none;
}
.drawer__close:hover { color: var(--text-primary); background: rgba(255,255,255,0.06); }

.drawer__title { font-size: 24px; font-weight: 500; color: var(--text-primary); margin: 8px 0 4px; line-height: 1.25; }

.drawer__meta {
    display: flex; flex-wrap: wrap; align-items: center;
    gap: 8px; font-size: 13px; color: var(--text-muted); margin-bottom: 12px;
}
.drawer__meta-item { display: inline-flex; align-items: center; gap: 4px; }
.drawer__meta-item--accent { color: var(--accent-blue); }
.drawer__meta-sep { color: var(--text-muted); }

.drawer__actions {
    display: flex; gap: 24px; padding-top: 12px;
    border-top: 1px solid rgba(66,70,84,0.5);
}
.drawer-btn {
    flex: 1; display: flex; align-items: center; justify-content: center;
    height: 32px; font-size: 13px; font-weight: 500; border-radius: var(--radius-sm);
    cursor: pointer; user-select: none; border: none; font-family: inherit;
}
.drawer-btn--secondary { background: #201f22; border: 1px solid var(--border); color: #9ca3af; }
.drawer-btn--primary   { background: var(--accent-primary); color: #fff; }
.drawer-btn--danger    { background: #f43f5e; color: #fff; }
.drawer-btn--secondary:hover { background: #2a2a2c; }
.drawer-btn--primary:hover   { background: var(--accent-primary-hover); }

.drawer__body {
    flex: 1; overflow-y: auto; padding: 24px;
    background: #09090b; display: flex; flex-direction: column; gap: 24px;
}
.drawer-section { display: flex; flex-direction: column; gap: 8px; }
.drawer-section__title {
    font-size: 14px; font-weight: 600; color: var(--text-primary);
    margin-bottom: 0; padding-bottom: 8px; border-bottom: 1px solid #27272a;
    text-transform: uppercase; letter-spacing: 0.04em;
}
.drawer-section__title--muted {
    display: flex; align-items: center; gap: 8px;
    font-size: 13px; font-weight: 500; color: #8b919f;
    border-bottom: none; padding-bottom: 0; text-transform: none; letter-spacing: 0;
}

/* Score grid */
.score-grid { border: 1px solid #27272a; border-radius: var(--radius-sm); overflow: hidden; }
.score-grid--dark { background: #1a1d24; border-color: transparent; }
.score-row {
    display: flex; align-items: center; justify-content: space-between;
    gap: 16px; padding: 8px 12px; min-height: 44px;
    background: #18181b; border-bottom: 1px solid #27272a;
}
.score-row:last-child { border-bottom: none; }
.score-row--alert   { background: rgba(239,68,68,0.1); }
.score-row--warn    { background: rgba(251,191,36,0.15); min-height: 33px; }
.score-row--verdict { border-top: 1px solid #facc15; min-height: 48px; background: #18181b; }
.score-row__label   { display: flex; flex-direction: column; gap: 4px; }
.score-row__key     { font-size: 12px; color: var(--text-muted); }
.score-row__val     { font-size: 13px; color: var(--text-primary); }
.score-row__val--critical { color: var(--critical); }
.score-row__pts     { font-size: 12px; font-weight: 500; white-space: nowrap; flex-shrink: 0; }
.score-row__pts--high     { color: var(--high); }
.score-row__pts--critical { color: var(--critical); }
.score-row__pts--blue     { color: var(--accent-blue); }
.score-row__pts--muted    { color: #e2a379; }
.score-row__verdict       { font-size: 12px; color: #facc15; text-align: right; }
.score-row__verdict--muted { color: #64748b; }

/* Query blocks inside drawer */
.query-block { border: 1px solid #27272a; border-radius: var(--radius-sm); overflow: hidden; margin-bottom: 12px; }
.query-block:last-child { margin-bottom: 0; }
.query-block__head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 8px 12px; background: #1f1f23; border-bottom: 1px solid #27272a;
    font-size: 12px; color: var(--text-primary);
}
.query-block__stats { font-size: 12px; color: var(--text-muted); }
.query-block__code {
    padding: 14px; margin: 0; font-family: var(--font-mono, monospace);
    font-size: 12px; line-height: 1.5; color: #e5e1e4; background: #111113;
    white-space: pre-wrap; word-break: break-word;
}
.query-block__explain {
    display: flex; align-items: flex-start; gap: 8px; padding: 8px 12px;
    font-size: 12px; line-height: 1.4; color: var(--text-muted);
    background: rgba(39,39,42,0.3); border-top: 1px solid #27272a;
}
.query-block__explain svg { flex-shrink: 0; margin-top: 2px; }
.query-block__plan { border-top: 1px solid #27272a; background: rgba(39,39,42,0.3); padding: 10px 12px; }
.query-block__plan-code {
    margin: 0; padding: 10px; font-family: var(--font-mono, monospace);
    font-size: 11px; line-height: 1.45; color: #b8b8b8; background: #060e20;
    border: 1px solid rgba(59,73,92,0.4); border-radius: var(--radius);
    white-space: pre-wrap; word-break: break-word; overflow-x: auto;
}

/* Migration block inside drawer */
.migration-block { border: 1px solid #27272a; border-radius: var(--radius); overflow: hidden; background: #111113; }
.migration-block__head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 6px 10px; background: #1a1a1c; border-bottom: 1px solid #27272a;
    font-size: 12px; color: var(--text-muted);
}
.migration-block__code {
    padding: 14px; margin: 0; font-family: var(--font-mono, monospace);
    font-size: 12px; line-height: 1.5; color: #e5e1e4; white-space: pre-wrap; word-break: break-word;
}

/* Modal overlays */
.modal-overlay {
    display: none; position: fixed; inset: 0; z-index: 600;
    align-items: center; justify-content: center; padding: 24px;
    background: rgba(0,0,0,0.65);
}
.modal-overlay.is-open { display: flex; }
.modal-inner {
    position: relative; z-index: 1; width: 100%; max-width: 540px;
    display: flex; flex-direction: column;
    background: #0f172a; border: 1px solid rgba(255,255,255,0.08);
    border-radius: 16px; overflow: hidden;
    animation: modal-fade-in 0.2s ease-out;
}
@keyframes modal-fade-in {
    from { opacity:0; transform: scale(0.96); }
    to   { opacity:1; transform: scale(1); }
}
.modal-inner__header {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 16px; padding: 16px; background: #1c1b1d; border-bottom: 1px solid var(--border);
}
.modal-inner__title    { font-size: 16px; font-weight: 600; color: #f8fafc; line-height: 1.4; }
.modal-inner__subtitle { font-size: 14px; color: #94a3b8; margin-top: 4px; line-height: 1.4; }
.modal-inner__body     { padding: 16px; background: #1c1b1d; }
.modal-inner__textarea {
    width: 100%; min-height: 180px; padding: 9px; font-family: inherit;
    font-size: 14px; color: var(--text-primary); background: #111113;
    border: 1px solid #27272b; border-radius: var(--radius-sm); resize: vertical;
    outline: none;
}
.modal-inner__textarea:focus { border-color: #424654; }
.modal-inner__footer {
    display: flex; align-items: center; gap: 12px; padding: 16px;
    background: #1c1b1d; border-top: 1px solid var(--border);
    justify-content: flex-end;
}
.modal-btn {
    display: inline-flex; align-items: center; justify-content: center;
    height: 38px; padding: 0 21px; font-size: 13px; font-weight: 500;
    font-family: inherit; border-radius: var(--radius-lg); cursor: pointer;
    border: none; user-select: none; white-space: nowrap;
}
.modal-btn--secondary { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.06); color: #f8fafc; }
.modal-btn--secondary:hover { background: rgba(255,255,255,0.08); }
.modal-btn--danger    { background: #f43f5e; color: #fff; }
.modal-btn--danger:hover { background: #e11d48; }
.modal-btn--primary   { background: var(--accent-primary); color: #fff; }
.modal-btn--primary:hover { background: var(--accent-primary-hover); }

/* Dismiss reason text */
.drawer__dismiss-info {
    margin-top: 8px; font-size: 13px; color: var(--text-muted);
    font-style: italic; padding: 10px 14px; background: rgba(255,255,255,0.02);
    border: 1px solid var(--border); border-radius: var(--radius);
}
.drawer__applied-info {
    margin-top: 8px; font-size: 13px; color: var(--status-green); font-weight: 500;
    padding: 10px 14px; background: rgba(16,185,129,0.05);
    border: 1px solid rgba(16,185,129,0.2); border-radius: var(--radius);
}

/* Pagination */
.inventory { display: flex; flex-direction: column; gap: 8px; flex: 1; }

/* ── Page header spacing ── */
.page-header { display: flex; flex-direction: column; gap: 16px; }
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

{{-- Page header + KPIs --}}
<section class="page-header" style="display:flex;flex-direction:column;gap:16px;">
    <div>
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <span>Smart Index Advisor</span>
            <svg class="breadcrumb__sep" viewBox="0 0 5 8" fill="currentColor"><path d="M0 0l5 4-5 4V0z"/></svg>
            <span class="breadcrumb__current">Recommendations</span>
        </nav>
        <h1 class="page-title">Recommendations</h1>
    </div>

    {{-- KPI Grid --}}
    <div class="kpi-grid">
        <div class="kpi-card" title="All recommendations across all statuses">
            <span class="kpi-card__label">Pending Suggestions</span>
            <span id="statTotal" class="kpi-card__value kpi-card__value--total">—</span>
            <span id="statTotalSub" style="font-size:11px;color:var(--text-muted);margin-top:2px;">of <span id="statGrandTotal">—</span> total</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-card__label">Critical Pending</span>
            <span id="statCritical" class="kpi-card__value kpi-card__value--critical">—</span>
            <span style="font-size:11px;color:var(--text-muted);margin-top:2px;">score ≥ 80 pts</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-card__label">High Pending</span>
            <span id="statHigh" class="kpi-card__value kpi-card__value--high">—</span>
            <span style="font-size:11px;color:var(--text-muted);margin-top:2px;">score 60–79 pts</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-card__label">Applied</span>
            <span id="statApplied" class="kpi-card__value kpi-card__value--applied">—</span>
            <span style="font-size:11px;color:var(--text-muted);margin-top:2px;">marked as applied</span>
        </div>
    </div>

    {{-- Severity legend --}}
    <div class="severity-legend">
        <span class="severity-legend__title">Severity:</span>
        <span class="legend-item"><span class="legend-dot legend-dot--low"></span> Low</span>
        <span class="legend-item"><span class="legend-dot legend-dot--medium"></span> Medium</span>
        <span class="legend-item"><span class="legend-dot legend-dot--high"></span> High</span>
        <span class="legend-item"><span class="legend-dot legend-dot--critical"></span> Critical</span>
        <span class="legend-item"><span class="legend-dot legend-dot--unknown"></span> Unknown</span>
    </div>

    {{-- Filter bar --}}
    <div class="filter-bar">
        <div class="filter-bar__filters">
            <div class="search-input">
                <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="6" cy="6" r="4.5"/><path d="M9.5 9.5L13 13"/></svg>
                <input type="search" id="searchInput" placeholder="Filter by table or column…" aria-label="Filter by table or column" oninput="applyFilters()">
            </div>
            <select id="filterType" class="select" aria-label="Filter by type" onchange="applyFilters()">
                <option value="all">All Index Types</option>
                <option value="INDEX">Single Column</option>
                <option value="COMPOSITE">Composite</option>
                <option value="DROP">Unused (DROP)</option>
                <option value="REDUNDANT_CHECK">Redundant</option>
            </select>
            <select id="filterSeverity" class="select" aria-label="Filter by severity" onchange="applyFilters()">
                <option value="all">All Severities</option>
                <option value="critical">Critical (80+)</option>
                <option value="high">High (60–79)</option>
                <option value="medium">Medium (40–59)</option>
                <option value="low">Low (&lt; 40)</option>
            </select>
            <select id="filterStatus" class="select" aria-label="Filter by status" onchange="applyFilters()">
                <option value="pending" selected>Pending Only</option>
                <option value="all">All statuses</option>
                <option value="generated">Migration generated</option>
                <option value="applied">Applied</option>
                <option value="dismissed">Dismissed</option>
            </select>
        </div>
        <div class="filter-bar__metrics" id="listSummary">
            <span id="metaCandidates">—</span>
            <span class="filter-bar__sep">·</span>
            <span id="metaPending">—</span>
            <span class="filter-bar__sep">·</span>
            <a href="#" class="filter-bar__link" id="metaGenerated" onclick="setStatusFilter('generated');return false;">0 already generated</a>
        </div>
    </div>
</section>

{{-- Recommendation inventory --}}
<section class="inventory" id="recommendationsList" aria-label="Recommendation inventory">
    <div class="empty-state pulse">
        <svg class="empty-icon spinner" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/>
        </svg>
        <p>Loading index recommendations…</p>
    </div>
</section>

{{-- Pagination --}}
<footer class="pagination-footer" id="paginationFooter" style="display:none;">
    <span class="pagination-footer__info" id="paginationInfo">Showing 1–6 of 0</span>
    <div class="pagination">
        <div class="pagination__pages" id="paginationPages"></div>
        <select class="select per-page" id="perPageSelect" aria-label="Items per page" onchange="changePerPage(this.value)">
            <option value="6">6 / page</option>
            <option value="10">10 / page</option>
            <option value="25" selected>25 / page</option>
            <option value="50">50 / page</option>
        </select>
    </div>
</footer>

@endsection

{{-- Drawer overlay (rendered outside .canvas, inside .main via append) --}}
@push('scripts')
<script>
/* ─────────────────────────────────────────────
   State
───────────────────────────────────────────── */
let recommendations = [];
let stats = {};
let currentPage = 1;
let perPage = 25;
let currentDrawerRec = null;
let queriesCache = {};

document.addEventListener('DOMContentLoaded', () => {
    fetchData();
    document.getElementById('btnRunPipeline').addEventListener('click', runAnalysisPipeline);
    // Inject drawer + modals into body
    injectDrawerAndModals();
});

/* ─────────────────────────────────────────────
   Drawer HTML injection
───────────────────────────────────────────── */
function injectDrawerAndModals() {
    const el = document.createElement('div');
    el.id = 'drawerRoot';
    el.innerHTML = `
    <!-- Drawer overlay -->
    <div class="drawer-overlay" id="drawerOverlay" role="dialog" aria-modal="true" aria-label="Recommendation details">
        <div class="drawer-backdrop" onclick="closeDrawer()"></div>
        <aside class="drawer" id="drawerPanel">
            <header class="drawer__header">
                <div class="drawer__header-top">
                    <div class="drawer__badges" id="drawerBadges"></div>
                    <button class="drawer__close" onclick="closeDrawer()" aria-label="Close">
                        <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor"><path d="M1 1l10 10M11 1L1 11" stroke="currentColor" stroke-width="1.5" fill="none"/></svg>
                    </button>
                </div>
                <h2 class="drawer__title" id="drawerTitle"></h2>
                <div class="drawer__meta" id="drawerMeta"></div>
                <div class="drawer__actions" id="drawerActions"></div>
            </header>
            <div class="drawer__body" id="drawerBody"></div>
        </aside>
    </div>

    <!-- Dismiss modal -->
    <div class="modal-overlay" id="dismissModal">
        <div class="modal-inner">
            <div class="modal-inner__header">
                <div>
                    <div class="modal-inner__title">Dismiss Recommendation</div>
                    <div class="modal-inner__subtitle">This will flag it as ignored so it's skipped in future runs and migrations.</div>
                </div>
                <button class="drawer__close" onclick="closeDismissModal()" aria-label="Close">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor"><path d="M1 1l10 10M11 1L1 11" stroke="currentColor" stroke-width="1.5" fill="none"/></svg>
                </button>
            </div>
            <div class="modal-inner__body">
                <textarea id="dismissReason" class="modal-inner__textarea" placeholder="Reason for dismissal (e.g. 'Boolean column with low cardinality', 'Rarely queried table')…"></textarea>
            </div>
            <div class="modal-inner__footer">
                <button class="modal-btn modal-btn--secondary" onclick="closeDismissModal()">Cancel</button>
                <button class="modal-btn modal-btn--danger" onclick="submitDismiss()">Confirm Dismiss</button>
            </div>
        </div>
    </div>

    <!-- Mark Applied modal -->
    <div class="modal-overlay" id="appliedModal">
        <div class="modal-inner" style="max-width:383px;">
            <div class="modal-inner__header">
                <div>
                    <div class="modal-inner__title">Mark as Applied</div>
                    <div class="modal-inner__subtitle">Confirm you have applied this index change to the database.</div>
                </div>
                <button class="drawer__close" onclick="closeAppliedModal()" aria-label="Close">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor"><path d="M1 1l10 10M11 1L1 11" stroke="currentColor" stroke-width="1.5" fill="none"/></svg>
                </button>
            </div>
            <div class="modal-inner__footer" style="justify-content:stretch;">
                <button class="modal-btn modal-btn--secondary" style="flex:1;" onclick="closeAppliedModal()">Cancel</button>
                <button class="modal-btn modal-btn--primary" style="flex:1;" onclick="submitMarkApplied()">Confirm Applied</button>
            </div>
        </div>
    </div>`;
    document.body.appendChild(el);
}

/* ─────────────────────────────────────────────
   Data loading
───────────────────────────────────────────── */
async function fetchData() {
    try {
        const res = await fetch(`${BASE_PATH}/api/recommendations`);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        recommendations = data.recommendations || [];
        stats = data.stats || {};
        updateStatsUI();
        applyFilters();
    } catch (e) {
        document.getElementById('recommendationsList').innerHTML = `
            <div class="empty-state" style="border-color:rgba(244,63,94,0.3)">
                <p style="color:var(--critical);font-weight:600">Failed to load recommendations</p>
                <p style="font-size:12px;color:var(--text-muted);margin-top:6px">${escapeHtml(e.message)}</p>
            </div>`;
    }
}

function updateStatsUI() {
    document.getElementById('statTotal').textContent      = stats.pending  ?? 0;
    document.getElementById('statGrandTotal').textContent = stats.total    ?? 0;
    document.getElementById('statCritical').textContent   = stats.critical ?? 0;
    document.getElementById('statHigh').textContent       = stats.high     ?? 0;
    document.getElementById('statApplied').textContent    = stats.applied  ?? 0;
}

/* ─────────────────────────────────────────────
   Filtering & rendering
───────────────────────────────────────────── */
function applyFilters() {
    currentPage = 1;
    renderRecommendations();
}

function setStatusFilter(val) {
    document.getElementById('filterStatus').value = val;
    applyFilters();
}

function getFiltered() {
    const q = (document.getElementById('searchInput')?.value || '').toLowerCase();
    const status   = document.getElementById('filterStatus')?.value   || 'all';
    const type     = document.getElementById('filterType')?.value     || 'all';
    const severity = document.getElementById('filterSeverity')?.value || 'all';

    return recommendations.filter(rec => {
        if (q && !rec.table_name.toLowerCase().includes(q) && !rec.column_name.toLowerCase().includes(q)) return false;
        if (status !== 'all' && rec.status !== status) return false;
        if (type   !== 'all' && rec.index_type !== type) return false;
        if (severity === 'critical' && rec.score < 80) return false;
        if (severity === 'high'     && (rec.score < 60 || rec.score >= 80)) return false;
        if (severity === 'medium'   && (rec.score < 40 || rec.score >= 60)) return false;
        if (severity === 'low'      && rec.score >= 40) return false;
        return true;
    });
}

function renderRecommendations() {
    const filtered = getFiltered();
    const total    = filtered.length;
    const pending  = filtered.filter(r => r.status === 'pending').length;
    const generated= filtered.filter(r => r.status === 'generated').length;

    document.getElementById('metaCandidates').textContent = `${total} candidate(s)`;
    document.getElementById('metaPending').textContent    = `${pending} pending`;
    document.getElementById('metaGenerated').textContent  = `${generated} already generated`;

    const container = document.getElementById('recommendationsList');
    const footer    = document.getElementById('paginationFooter');

    if (filtered.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <svg class="empty-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z"/>
                </svg>
                <p>No recommendations match the current filters.</p>
            </div>`;
        footer.style.display = 'none';
        return;
    }

    const start  = (currentPage - 1) * perPage;
    const page   = filtered.slice(start, start + perPage);
    const pages  = Math.ceil(total / perPage);

    container.innerHTML = page.map(rec => buildRecCard(rec)).join('');
    footer.style.display = 'flex';
    document.getElementById('paginationInfo').textContent = `Showing ${start + 1}–${Math.min(start + perPage, total)} of ${total}`;
    renderPagination(pages);
}

function buildRecCard(rec) {
    const sevClass = rec.score >= 80 ? 'p1' : rec.score >= 60 ? 'p2' : rec.score >= 40 ? 'p3' : 'p4';
    const sevLabel = rec.score >= 80 ? 'P1' : rec.score >= 60 ? 'P2' : rec.score >= 40 ? 'P3' : 'P4';
    const sevPts   = `${rec.score ?? 0} pts`;

    const typeTag = buildTypeTag(rec.index_type);
    const liveTag = buildLiveTag(rec);
    const statusTag = `<span class="tag tag--status">${escapeHtml(rec.status)}</span>`;
    const staleTag = rec.schema_stale ? `<span class="tag" style="background:rgba(244,63,94,0.12);color:var(--critical);border:1px solid rgba(244,63,94,0.3);">Stale</span>` : '';
    const ts = `<span class="rec-card__timestamp">Last update: ${new Date(rec.updated_at).toLocaleString()}</span>`;

    const colHtml = rec.index_type === 'COMPOSITE'
        ? rec.column_name.split(',').map(c => `<span class="rec-card__column">${escapeHtml(c.trim())}</span>`).join('<span class="rec-card__sep" style="margin:0 2px;">+</span>')
        : `<span class="rec-card__column">${escapeHtml(rec.column_name)}</span>`;

    return `
    <div class="rec-card rec-card--clickable" onclick="openDrawer(${rec.id})" style="cursor:pointer;">
        <div class="rec-card__header">
            <div class="severity-badge severity-badge--${sevClass}" title="Priority ${sevLabel} · Score: ${rec.score ?? 0} pts">
                <span class="severity-badge__priority">${sevLabel}</span>
                <span class="severity-badge__pts">${sevPts}</span>
            </div>
            <div class="rec-card__body">
                <div class="rec-card__title-row">
                    <span class="rec-card__table">${escapeHtml(rec.table_name)}</span>
                    <span class="rec-card__sep">›</span>
                    ${colHtml}
                </div>
                <div class="rec-card__meta">
                    ${typeTag}
                    ${statusTag}
                    ${liveTag}
                    ${staleTag}
                    ${ts}
                </div>
            </div>
            <svg class="rec-card__chevron" width="12" height="8" viewBox="0 0 12 8" fill="currentColor" aria-hidden="true"><path d="M1 1l4 4 4-4"/></svg>
        </div>
    </div>`;
}

function buildTypeTag(type) {
    if (!type) return '';
    const map = {
        'INDEX':          'tag--index',
        'COMPOSITE':      'tag--composite',
        'DROP':           'tag--drop',
        'REDUNDANT_CHECK':'tag--redundant',
    };
    const cls = map[type] || 'tag--index';
    const label = type.replace('_CHECK', '');
    return `<span class="tag ${cls}">${escapeHtml(label)}</span>`;
}

function buildLiveTag(rec) {
    const indexes = rec.live_indexes || [];
    if (indexes.length === 0) {
        return `<span class="tag tag--db-ok">Live DB: no index</span>`;
    }
    const names = indexes.map(i => {
        let n = i.index_name || '?';
        if (i.is_primary) n += ' (PK)';
        else if (i.is_unique) n += ' (unique)';
        return n;
    }).join(', ');
    const short = names.length > 55 ? names.slice(0, 55) + '…' : names;
    return `<span class="tag tag--db-warn" title="${escapeAttr(names)}">Live DB: indexed — ${escapeHtml(short)}</span>`;
}

/* ─────────────────────────────────────────────
   Pagination
───────────────────────────────────────────── */
function renderPagination(pages) {
    const el = document.getElementById('paginationPages');
    let html = `<button type="button" class="page-btn page-btn--nav" aria-label="Previous page" ${currentPage===1?'disabled':''} onclick="goPage(${currentPage-1})">
        <svg viewBox="0 0 7 11" fill="currentColor"><path d="M6 0L1 5.5 6 11V0z"/></svg></button>`;

    const range = getPaginationRange(currentPage, pages);
    range.forEach(p => {
        if (p === '…') {
            html += `<span class="page-ellipsis">…</span>`;
        } else {
            html += `<button type="button" class="page-btn ${p===currentPage?'page-btn--active':''}" onclick="goPage(${p})">${p}</button>`;
        }
    });

    html += `<button type="button" class="page-btn page-btn--nav" aria-label="Next page" ${currentPage===pages?'disabled':''} onclick="goPage(${currentPage+1})">
        <svg viewBox="0 0 7 11" fill="currentColor"><path d="M1 0l5 5.5L1 11V0z"/></svg></button>`;
    el.innerHTML = html;
}

function getPaginationRange(current, total) {
    if (total <= 7) return Array.from({length: total}, (_, i) => i + 1);
    if (current <= 4) return [1,2,3,4,5,'…',total];
    if (current >= total - 3) return [1,'…',total-4,total-3,total-2,total-1,total];
    return [1,'…',current-1,current,current+1,'…',total];
}

function goPage(p) {
    const filtered = getFiltered();
    const pages = Math.ceil(filtered.length / perPage);
    if (p < 1 || p > pages) return;
    currentPage = p;
    renderRecommendations();
    document.getElementById('recommendationsList').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function changePerPage(val) {
    perPage = parseInt(val);
    currentPage = 1;
    renderRecommendations();
}

/* ─────────────────────────────────────────────
   Drawer
───────────────────────────────────────────── */
function openDrawer(id) {
    const rec = recommendations.find(r => r.id === id);
    if (!rec) return;
    currentDrawerRec = rec;

    const sevLabel = rec.score >= 80 ? 'Critical' : rec.score >= 60 ? 'High' : rec.score >= 40 ? 'Medium' : 'Low';
    const sevBadgeClass = rec.score >= 80 ? 'critical' : rec.score >= 60 ? 'high' : rec.score >= 40 ? 'medium' : 'low';
    const typeBadge = buildDrawerTypeBadge(rec.index_type);

    document.getElementById('drawerBadges').innerHTML = `
        <span class="drawer-badge drawer-badge--${sevBadgeClass}">${sevLabel}</span>
        ${typeBadge}`;

    document.getElementById('drawerTitle').textContent = rec.table_name;

    document.getElementById('drawerMeta').innerHTML = `
        <span class="drawer__meta-item">
            <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor"><ellipse cx="6" cy="3" rx="5" ry="2"/><path d="M1 3v6c0 1.1 2.2 2 5 2s5-.9 5-2V3"/></svg>
            Production
        </span>
        <span class="drawer__meta-sep">·</span>
        <span class="drawer__meta-item drawer__meta-item--accent">
            <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor"><rect x="1" y="2" width="10" height="8" rx="1" fill="none" stroke="currentColor"/><path d="M3 5h6M3 7h4"/></svg>
            ${escapeHtml(rec.column_name)}
        </span>
        <span class="drawer__meta-sep">·</span>
        <span class="drawer__meta-item">${escapeHtml(rec.table_name)}</span>`;

    // Actions
    let actionsHtml = '';
    if (rec.status === 'pending' || rec.status === 'generated') {
        actionsHtml = `
            <button class="drawer-btn drawer-btn--secondary" onclick="openDismissModal()">Dismiss</button>
            <button class="drawer-btn drawer-btn--primary"   onclick="openAppliedModal()">Mark Applied</button>`;
    } else if (rec.status === 'dismissed') {
        actionsHtml = `<button class="drawer-btn drawer-btn--secondary" onclick="openDismissModal()" style="flex:0 0 auto;padding:0 20px;">Update Reason</button>`;
    }
    document.getElementById('drawerActions').innerHTML = actionsHtml;

    // Body
    const evidence   = buildEvidenceHtml(rec);
    const queryHtml  = `<div id="drawerQuerySection"><p style="font-size:13px;color:var(--text-muted);" class="pulse">Loading queries…</p></div>`;

    document.getElementById('drawerBody').innerHTML = `
        <section class="drawer-section">
            <h3 class="drawer-section__title">Scoring Evidence</h3>
            ${evidence}
        </section>
        <section class="drawer-section">
            <h3 class="drawer-section__title">Correlated Query &amp; Explain</h3>
            ${queryHtml}
        </section>
        ${rec.status === 'dismissed' ? `<div class="drawer__dismiss-info">Dismissed: ${escapeHtml(rec.evidence?.dismiss_reason || 'No reason provided.')}</div>` : ''}
        ${rec.status === 'applied'   ? `<div class="drawer__applied-info">✓ Applied on ${escapeHtml(rec.evidence?.applied_at || 'N/A')}</div>` : ''}`;

    document.getElementById('drawerOverlay').classList.add('is-open');
    document.body.style.overflow = 'hidden';

    // Load queries async
    loadQueriesForDrawer(id);
}

function buildDrawerTypeBadge(type) {
    const map = {
        'INDEX':           ['drawer-badge--index',    'INDEX'],
        'COMPOSITE':       ['drawer-badge--index',    'COMPOSITE INDEX'],
        'DROP':            ['drawer-badge--drop',     'DROP INDEX'],
        'REDUNDANT_CHECK': ['drawer-badge--redundant','REDUNDANT'],
    };
    const [cls, label] = map[type] || ['drawer-badge--index', type];
    return `<span class="drawer-badge ${cls}">${escapeHtml(label)}</span>`;
}

function closeDrawer() {
    document.getElementById('drawerOverlay').classList.remove('is-open');
    document.body.style.overflow = '';
    currentDrawerRec = null;
}

/* ─────────────────────────────────────────────
   Evidence HTML
───────────────────────────────────────────── */
function buildEvidenceHtml(rec) {
    const ev = rec.evidence || {};
    const rows = [];

    if (ev.exec_count !== undefined) {
        rows.push(scoreRow('Query Frequency',        `${Number(ev.exec_count).toLocaleString()} executions`, `+${ev.exec_score_pts ?? 0} Score`, 'high'));
    }
    if (ev.avg_ms !== undefined) {
        rows.push(scoreRow('Avg Duration',           `${ev.avg_ms}ms`, `+${ev.slow_pts ?? 0} Score`, 'high'));
    }
    if (ev.max_duration_ms !== undefined) {
        const spikeClass = ev.max_duration_spike ? 'critical' : 'blue';
        rows.push(scoreRow('Worst-Case Spike' + (ev.max_duration_spike ? ' ⚡' : ''), `${ev.max_duration_ms}ms`, `+${ev.max_duration_pts ?? 0} Score`, spikeClass));
    }
    if (ev.full_scan !== undefined) {
        const alert = ev.full_scan;
        rows.push(scoreRowAlert(
            alert ? 'Table Scan Cost' : '✓ No Full Table Scan',
            alert ? 'Sequential' : 'Index used',
            `+${ev.full_scan_pts ?? 0} Score`, 'blue', alert
        ));
    }
    if (ev.clause_pts !== undefined) {
        rows.push(scoreRow('SQL Clause', (ev.query_type || 'WHERE').toUpperCase() + ' filter', `+${ev.clause_pts} Score`, 'blue'));
    }
    if (ev.fk_heuristic) {
        rows.push(scoreRow('FK Heuristic', 'Column ends in _id', '+5 Score', 'blue'));
    }
    if (ev.no_existing_index) {
        rows.push(scoreRow('No Existing Index on Column', 'None', '+5 Score', 'blue'));
    }
    if (ev.already_indexed) {
        rows.push(`<div class="score-row score-row--warn">
            <span class="score-row__key">Column Already Indexed (REDUNDANT)</span>
            <span class="score-row__pts score-row__pts--muted">+0 Score</span>
        </div>`);
    }
    if (ev.table_row_count !== undefined) {
        rows.push(scoreRow('Table Size', ev.table_row_count > 100000 ? 'Large' : 'Normal', `${Number(ev.table_row_count).toLocaleString()} rows`, 'blue'));
    }
    if (ev.column_cardinality !== undefined) {
        rows.push(scoreRow('Column Cardinality (distinct values)', ev.column_cardinality > 10000 ? 'High' : 'Normal', Number(ev.column_cardinality).toLocaleString(), 'blue'));
    }
    // live indexes
    const liveIdx = ev.live_indexes || rec.live_indexes || [];
    if (liveIdx.length > 0) {
        const names = liveIdx.map(i => i.index_name + (i.is_primary ? ' (PK)' : '')).join(', ');
        rows.push(scoreRow('Live DB indexes (now)', 'Indexed', escapeHtml(names.length > 40 ? names.slice(0,40)+'…' : names), 'blue'));
    } else if (ev.live_indexed === false || (ev.live_schema_checked_at && liveIdx.length === 0)) {
        rows.push(scoreRow('Live DB indexes (now)', 'None', 'None on this column', 'blue'));
    }
    if (rec.index_type === 'DROP') {
        rows.push(scoreRow('Unused Index (idx_scan = 0)', '', 'Score: 70 (fixed)', 'critical'));
        if (ev.index_name) {
            rows.push(scoreRow('Index Name', '', escapeHtml(ev.index_name), 'blue'));
        }
    }
    // composite columns
    function safeArr(v) { if (!v) return []; if (Array.isArray(v)) return v; if (typeof v === 'object') return Object.values(v); return []; }
    const cols = safeArr(ev.columns);
    if (cols.length > 0) {
        rows.push(scoreRow('Composite Columns', '', cols.join(' + '), 'blue'));
    }

    // Add a total score row to make drawer match the badge
    rows.push(scoreRow('Total Score', '', `+${rec.score ?? 0} pts`, 'critical'));

    const verdictText = ev.verdict || '';
    if (verdictText) {
        const vc = verdictText.includes('CRITICAL') ? 'critical' : verdictText.includes('HIGH') ? 'high' : verdictText.includes('LOW') ? 'muted' : 'muted';
        rows.push(`<div class="score-row score-row--verdict">
            <span class="score-row__key">Verdict</span>
            <span class="score-row__verdict score-row__verdict--${vc}">${escapeHtml(verdictText)}</span>
        </div>`);
    }

    if (rows.length === 0) {
        rows.push(`<div class="score-row"><span class="score-row__key" style="color:var(--text-muted)">No evidence data stored.</span></div>`);
    }

    return `<div class="score-grid">${rows.join('')}</div>`;
}

function scoreRow(key, val, pts, ptsClass) {
    return `<div class="score-row">
        <div class="score-row__label">
            <span class="score-row__key">${key}</span>
            ${val ? `<span class="score-row__val">${val}</span>` : ''}
        </div>
        <span class="score-row__pts score-row__pts--${ptsClass}">${pts}</span>
    </div>`;
}

function scoreRowAlert(key, val, pts, ptsClass, isAlert) {
    return `<div class="score-row ${isAlert ? 'score-row--alert' : ''}">
        <div class="score-row__label">
            <span class="score-row__key">${isAlert ? '⚠️ ' : ''}${key}</span>
            <span class="score-row__val ${isAlert ? 'score-row__val--critical' : ''}">${val}</span>
        </div>
        <span class="score-row__pts score-row__pts--${ptsClass}">${pts}</span>
    </div>`;
}

/* ─────────────────────────────────────────────
   Queries inside drawer
───────────────────────────────────────────── */
async function loadQueriesForDrawer(id) {
    if (queriesCache[id]) {
        document.getElementById('drawerQuerySection').innerHTML = renderQueriesHtml(queriesCache[id], id);
        return;
    }
    try {
        const res = await fetch(`${BASE_PATH}/api/recommendations/${id}/queries`);
        const data = await res.json();
        queriesCache[id] = data.queries || [];
        document.getElementById('drawerQuerySection').innerHTML = renderQueriesHtml(queriesCache[id], id);
    } catch (e) {
        document.getElementById('drawerQuerySection').innerHTML = `<p style="color:var(--critical);font-size:13px;">Failed to load queries.</p>`;
    }
}

function renderQueriesHtml(queries, recId) {
    if (!queries || queries.length === 0) {
        return `<div class="query-block">
            <div class="query-block__explain">
                <svg width="11" height="11" viewBox="0 0 11 11" fill="currentColor"><circle cx="5.5" cy="5.5" r="5" fill="none" stroke="currentColor"/><path d="M5.5 3v3.5M5.5 8h.01"/></svg>
                No runtime query samples captured yet. Run <code style="color:var(--accent-blue)">php artisan index-advisor:ingest-slow-log</code> then open this panel.
            </div>
        </div>`;
    }

    return queries.map((q, idx) => {
        const avgMs = q.execution_count > 0 ? (q.total_duration_ms / q.execution_count).toFixed(1) : (q.avg_duration_ms || '0');
        let explainHtml = '';
        if (q.explain != null) {
            let planText = '';
            try { planText = typeof q.explain === 'string' ? q.explain : JSON.stringify(q.explain, null, 2); } catch(e) { planText = String(q.explain); }
            const hasSeqScan = planText.includes('Seq Scan') || planText.includes('"access_type": "ALL"');
            explainHtml = `<div class="query-block__plan">
                <div style="font-size:11px;color:#dae2fd;margin-bottom:6px;">${hasSeqScan ? '⚠ FULL SCAN' : '✓ INDEX USED'}</div>
                <pre class="query-block__plan-code">${escapeHtml(planText)}</pre>
            </div>`;
        } else {
            explainHtml = `<div class="query-block__explain query-block__explain--empty">
                <svg width="11" height="11" viewBox="0 0 11 11" fill="currentColor"><circle cx="5.5" cy="5.5" r="5" fill="none" stroke="currentColor"/><path d="M5.5 3v3.5M5.5 8h.01"/></svg>
                No EXPLAIN plan captured for this query. Run <code style="color:var(--accent-blue)">php artisan index-advisor:run-explain</code> to generate plans.
            </div>`;
        }

        // Recommended migration block for the last query slot
        const rec = currentDrawerRec;
        let migBlock = '';
        if (idx === queries.length - 1 && rec && (rec.index_type === 'INDEX' || rec.index_type === 'COMPOSITE' || rec.index_type === 'DROP')) {
            const ddl = rec.index_type === 'DROP'
                ? `DROP INDEX CONCURRENTLY ${rec.evidence?.index_name || `idx_${rec.table_name}_${rec.column_name}`};`
                : `CREATE INDEX CONCURRENTLY idx_${rec.table_name.toLowerCase()}_${rec.column_name.toLowerCase().replace(/,\s*/g,'_')}\n  ON ${rec.table_name} (${rec.column_name});`;
            migBlock = `
            <section class="drawer-section" style="margin-top:16px;">
                <h3 class="drawer-section__title drawer-section__title--muted">
                    <svg width="13" height="8" viewBox="0 0 13 8" fill="currentColor"><path d="M0 0h13v2H0V0zm0 3h9v2H0V3zm0 3h11v2H0V6z"/></svg>
                    Recommended Migration
                </h3>
                <div class="migration-block">
                    <div class="migration-block__head"><span>SQL</span></div>
                    <pre class="migration-block__code">${escapeHtml(ddl)}</pre>
                </div>
            </section>`;
        }

        return `<div class="query-block" style="margin-bottom:${idx < queries.length-1 ? '12px' : '0'};">
            <div class="query-block__head">
                <span>Query ${idx+1}</span>
                <span class="query-block__stats">Avg: ${avgMs}ms · ${Number(q.execution_count||0).toLocaleString()} calls</span>
            </div>
            <pre class="query-block__code">${escapeHtml(q.sql_sample || '')}</pre>
            ${explainHtml}
        </div>${migBlock}`;
    }).join('');
}

/* ─────────────────────────────────────────────
   Dismiss + Mark Applied
───────────────────────────────────────────── */
function openDismissModal()  { document.getElementById('dismissModal').classList.add('is-open'); document.getElementById('dismissReason').value = ''; }
function closeDismissModal() { document.getElementById('dismissModal').classList.remove('is-open'); }

async function submitDismiss() {
    if (!currentDrawerRec) return;
    const reason = document.getElementById('dismissReason').value;
    closeDismissModal();
    try {
        const res = await fetch(`${BASE_PATH}/api/recommendations/${currentDrawerRec.id}/dismiss`, {
            method: 'POST', headers: apiHeaders(), body: JSON.stringify({ reason })
        });
        const data = await res.json();
        if (data.success) { closeDrawer(); await fetchData(); showToast('Recommendation dismissed.'); }
        else showToast(data.error || 'Failed to dismiss.');
    } catch (e) { showToast('Failed to dismiss.'); }
}

function openAppliedModal()  { document.getElementById('appliedModal').classList.add('is-open'); }
function closeAppliedModal() { document.getElementById('appliedModal').classList.remove('is-open'); }

async function submitMarkApplied() {
    if (!currentDrawerRec) return;
    closeAppliedModal();
    try {
        const res = await fetch(`${BASE_PATH}/api/recommendations/${currentDrawerRec.id}/apply`, {
            method: 'POST', headers: apiHeaders()
        });
        const data = await res.json();
        if (data.success) { closeDrawer(); await fetchData(); showToast('Marked as applied.'); }
        else showToast(data.error || 'Failed to mark applied.');
    } catch (e) { showToast('Failed to mark applied.'); }
}

/* ─────────────────────────────────────────────
   Run Analysis pipeline
───────────────────────────────────────────── */
async function runAnalysisPipeline() {
    const btn = document.getElementById('btnRunPipeline');
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
        if (!data) { writeTerminal('ERROR: HTTP ' + status + '\n' + (raw || '').slice(0, 2000)); showToast('Run Analysis failed'); return; }
        try {
            const result = await resolveIndexAdvisorTaskResponse(data);
            writeTerminal(result.output || '(no output)');
            showToast('Analysis complete');
            await fetchData();
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
</script>
@endpush
