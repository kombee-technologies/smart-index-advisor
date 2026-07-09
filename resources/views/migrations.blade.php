@extends('index-advisor::layout')

@section('title', 'Index Advisor — Generate Migrations')
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
/* ── Migration page specific ── */
.migration-canvas { gap: 24px; }

.migration-header { display: flex; flex-direction: column; gap: 16px; }
.migration-header__top { display: flex; flex-direction: column; gap: 4px; }
.migration-header__subtitle { font-size: 14px; color: #94a3b8; line-height: 20px; }

.migration-action-bar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }

.btn--deselect-all {
    display: inline-flex; align-items: center; justify-content: center;
    background: #1e293b; border: 1px solid #334155; color: #e2e8f0;
    padding: 0 17px; height: 38px; border-radius: var(--radius);
    font-size: 13px; font-family: inherit; cursor: pointer;
    transition: background 0.15s;
}
.btn--deselect-all:hover { background: #273449; color: #fff; }

.migration-filter-bar {
    display: flex; align-items: center; gap: 16px; padding: 8px;
    background: var(--bg-filter-bar); border: 1px solid var(--border);
    border-radius: var(--radius-lg); flex-wrap: wrap;
}
.migration-filter-bar .search-input { width: 208px; flex: 0 0 208px; }
.migration-filter-bar__dropdowns { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.migration-filter-bar .filter-bar__metrics { margin-left: auto; }

/* Migration inventory */
.migration-inventory { display: flex; flex-direction: column; gap: 12px; flex: 1; min-height: 0; }

.migration-table-wrap {
    flex: 1; min-height: 360px; overflow: auto;
    background: #201f22; border: 1px solid var(--border); border-radius: var(--radius-lg);
}
.migration-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
.migration-table thead th {
    position: sticky; top: 0; z-index: 1; background: #201f22;
    border-bottom: 1px solid var(--border);
    padding: 12px; font-size: 12px; font-weight: 500; color: #64748b;
    text-align: left; vertical-align: middle; white-space: nowrap;
}
.migration-table tbody td {
    padding: 12px; border-bottom: 1px solid var(--border);
    font-size: 13px; vertical-align: middle;
}
.migration-table tbody tr:last-child td { border-bottom: none; }
.migration-table tbody tr:hover { background: rgba(255,255,255,0.02); }

.migration-table .col-check  { width: 56px; text-align: center; }
.migration-table .col-pri    { width: 60px; }
.migration-table .col-table  { width: 130px; color: #cbd5e1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.migration-table .col-column { width: 130px; color: #cbd5e1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.migration-table .col-type   { width: 80px; }
.migration-table .col-status { width: 100px; }
.migration-table .col-suggestion { width: auto; }

/* Row checkboxes */
.migration-check {
    appearance: none; width: 18px; height: 18px; border: 1px solid #64748b;
    border-radius: var(--radius); background: transparent;
    cursor: pointer; position: relative; vertical-align: middle;
    transition: background 0.15s, border-color 0.15s;
}
.migration-check:checked {
    background: var(--accent-primary); border-color: var(--accent-primary);
}
.migration-check:checked::after {
    content: ""; position: absolute; left: 5px; top: 2px;
    width: 5px; height: 9px; border: solid #fff;
    border-width: 0 2px 2px 0; transform: rotate(45deg);
}
.migration-check:disabled { opacity: 0.35; cursor: not-allowed; }

/* Priority badge */
.pri-badge {
    display: inline-flex; align-items: center; justify-content: center;
    flex-direction: column; gap: 1px;
    width: 44px; height: 44px; border-radius: var(--radius);
    font-size: 12px; font-weight: 600;
    background: rgba(234,179,8,0.1); border: 1px solid rgba(234,179,8,0.3); color: #eab308;
}
.pri-badge__priority { font-size: 12px; font-weight: 700; line-height: 1; }
.pri-badge__pts      { font-size: 9px;  font-weight: 600; opacity: 0.85;  line-height: 1; }
.pri-badge--p1 { background: rgba(255,180,171,0.1); border-color: rgba(255,180,171,0.3); color: #ffb4ab; }
.pri-badge--p2 { background: rgba(255,183,134,0.1); border-color: rgba(255,183,134,0.3); color: #ffb786; }
.pri-badge--p3 { background: rgba(192,193,255,0.1); border-color: rgba(192,193,255,0.3); color: #c0c1ff; }
.pri-badge--p4 { background: rgba(173,198,255,0.1); border-color: rgba(173,198,255,0.3); color: #adc6ff; }

/* Type tags */
.type-tag {
    display: inline-block; padding: 0 8px; height: 18px; line-height: 18px;
    border-radius: var(--radius); font-size: 11px; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.02em;
}
.type-tag--drop       { background: #7f1d1d; color: #fca5a5; }
.type-tag--index      { background: rgba(173,198,255,0.15); color: var(--accent-blue); }
.type-tag--composite  { background: rgba(192,193,255,0.15); color: var(--medium); }
.type-tag--redundant  { background: rgba(234,179,8,0.12); color: #eab308; }

/* Status tags */
.status-tag {
    display: inline-block; padding: 0 8px; height: 18px; line-height: 18px;
    border-radius: var(--radius); font-size: 11px; font-weight: 500;
    background: #334155; color: #cbd5e1;
}
.status-tag--generated { background: rgba(59,130,246,0.12); color: #60a5fa; }
.status-tag--applied   { background: rgba(16,185,129,0.12); color: var(--status-green); }
.status-tag--dismissed { background: rgba(255,255,255,0.04); color: var(--text-muted); text-decoration: line-through; }

/* SQL suggestion */
.sql-suggestion {
    font-family: var(--font-mono, monospace); font-size: 12px; color: #94a3b8;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block;
}

/* Generation result panel */
.generation-result {
    background: #201f22; border: 1px solid var(--border);
    border-radius: var(--radius-lg); padding: 17px;
    display: flex; flex-direction: column; gap: 4px;
}
.generation-result__header {
    display: flex; align-items: center; justify-content: space-between;
    padding-bottom: 8px; border-bottom: 1px solid rgba(59,73,76,0.2);
}
.generation-result__title { font-size: 12px; font-weight: 500; color: #dae2fd; letter-spacing: 0.04em; }
.generation-result__dismiss {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 12px; color: #dae2fd; cursor: pointer; user-select: none;
    background: none; border: none; font-family: inherit; padding: 0;
}
.generation-result__dismiss:hover { opacity: 0.85; }
.generation-result__terminal {
    background: rgba(0,0,0,0.4); padding: 16px;
    display: flex; flex-direction: column; gap: 10px;
}
.generation-result__line {
    font-family: var(--font-mono, monospace); font-size: 12px;
    line-height: 16px; color: #84b4ff; white-space: pre-wrap;
}

/* Migration pagination */
.migration-pagination { padding-top: 24px; border-top: 1px solid var(--border); }

/* Confirm modal */
.confirm-modal-overlay {
    display: none; position: fixed; inset: 0; z-index: 600;
    align-items: center; justify-content: center; padding: 24px;
    background: rgba(0,0,0,0.65);
}
.confirm-modal-overlay.is-open { display: flex; }
.confirm-modal {
    background: #0f172a; border: 1px solid rgba(255,255,255,0.08);
    border-radius: 16px; width: 100%; max-width: 440px; padding: 24px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.5);
    animation: modal-fade-in 0.2s ease-out;
}
@keyframes modal-fade-in { from { opacity:0; transform:scale(0.96); } to { opacity:1; transform:scale(1); } }
.confirm-modal__title { font-size: 16px; font-weight: 600; margin-bottom: 10px; color: #f8fafc; }
.confirm-modal__desc  { font-size: 14px; color: #94a3b8; margin-bottom: 20px; line-height: 1.5; }
.confirm-modal__actions { display: flex; justify-content: flex-end; gap: 10px; }
.confirm-btn {
    display: inline-flex; align-items: center; justify-content: center;
    height: 38px; padding: 0 20px; font-size: 13px; font-weight: 500;
    font-family: inherit; border-radius: var(--radius-lg); cursor: pointer; border: none;
}
.confirm-btn--secondary { background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); color: #f8fafc; }
.confirm-btn--primary   { background: var(--accent-primary); color: #fff; }
.confirm-btn--secondary:hover { background: rgba(255,255,255,0.1); }
.confirm-btn--primary:hover   { background: var(--accent-primary-hover); }

@media (max-width: 900px) {
    .migration-filter-bar { flex-direction: column; align-items: stretch; }
    .migration-filter-bar .search-input { width: 100%; flex: 1 1 auto; }
    .migration-filter-bar .filter-bar__metrics { margin-left: 0; }
}
@media (max-width: 768px) {
    .migration-table .col-column, .migration-table .col-type { display: none; }
    .migration-table thead .col-column, .migration-table thead .col-type { display: none; }
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

{{-- Page header --}}
<section class="migration-header">
    <div class="migration-header__top">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <span>Index Advisor</span>
            <svg class="breadcrumb__sep" viewBox="0 0 5 8" fill="currentColor"><path d="M0 0l5 4-5 4V0z"/></svg>
            <span class="breadcrumb__current">Migration</span>
        </nav>
        <h1 class="page-title">Generate Migrations</h1>
        <p class="migration-header__subtitle">Review pending index changes and generate SQL migrations for your database.</p>
    </div>

    {{-- Action bar --}}
    <div class="migration-action-bar">
        <button type="button" class="btn--deselect-all" id="btnSelectDeselectAll" onclick="toggleSelectDeselectAll()">Select All</button>
        <button type="button" class="btn btn--primary" id="btnGenerateTop" onclick="openConfirmModal()" disabled style="display:inline-flex;align-items:center;gap:8px;height:38px;padding:0 16px;">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.33" style="width:16px;height:16px;flex-shrink:0;"><path d="M2 2v4h4M14 14v-4h-4M13.3 6A5.3 5.3 0 0 0 3 5.3L2 2M2.7 10A5.3 5.3 0 0 0 13 10.7l1 3.3"/></svg>
            Generate Selected (<span id="selectedCountTop">0</span>)
        </button>
    </div>

    {{-- Filter bar --}}
    <div class="migration-filter-bar filter-bar">
        <div class="search-input">
            <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="6" cy="6" r="4.5"/><path d="M9.5 9.5L13 13"/></svg>
            <input type="search" id="searchInput" placeholder="Filter by table or column…" aria-label="Filter by table or column" oninput="renderTable()">
        </div>
        <div class="migration-filter-bar__dropdowns">
            <select id="filterType" class="select" onchange="renderTable()" aria-label="Filter by type">
                <option value="all">All Types</option>
                <option value="INDEX">Single Column</option>
                <option value="COMPOSITE">Composite</option>
                <option value="DROP">Unused (DROP)</option>
                <option value="REDUNDANT_CHECK">Redundant</option>
            </select>
            <select id="filterSeverity" class="select" onchange="renderTable()" aria-label="Filter by severity">
                <option value="all">All Severities</option>
                <option value="critical">Critical (80+)</option>
                <option value="high">High (60–79)</option>
                <option value="medium">Medium (40–59)</option>
                <option value="low">Low (&lt; 40)</option>
            </select>
            <select id="filterStatus" class="select" onchange="renderTable()" aria-label="Filter by status">
                <option value="pending" selected>Pending Only</option>
                <option value="all">All Statuses</option>
                <option value="generated">Already Generated</option>
                <option value="applied">Applied</option>
            </select>
        </div>
        <div class="filter-bar__metrics" id="migSummary">—</div>
    </div>
</section>

{{-- Migration inventory --}}
<div class="migration-inventory">
    <div class="migration-table-wrap">
        <table class="migration-table">
            <thead>
                <tr>
                    <th class="col-check">
                        <input type="checkbox" class="migration-check" id="headerCheckbox" onchange="toggleSelectAll(this.checked)" aria-label="Select all">
                    </th>
                    <th class="col-pri">Pri</th>
                    <th class="col-table">Table</th>
                    <th class="col-column">Column</th>
                    <th class="col-type">Type</th>
                    <th class="col-status">Status</th>
                    <th class="col-suggestion">Suggestion</th>
                </tr>
            </thead>
            <tbody id="migTableBody">
                <tr>
                    <td colspan="7" style="text-align:center;padding:48px;color:var(--text-muted);">
                        <svg class="spinner" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:block;margin:0 auto 8px;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/>
                        </svg>
                        Loading migration candidates…
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Generation result panel --}}
    <div id="resultPanel" class="generation-result" style="display:none;">
        <div class="generation-result__header">
            <span class="generation-result__title">GENERATION RESULT</span>
            <button type="button" class="generation-result__dismiss" onclick="document.getElementById('resultPanel').style.display='none'">
                DISMISS
                <svg viewBox="0 0 10 10" fill="currentColor"><path d="M1 1l8 8M9 1L1 9" stroke="currentColor" stroke-width="1.2" fill="none"/></svg>
            </button>
        </div>
        <div class="generation-result__terminal">
            <pre id="resultOutput" class="generation-result__line" style="margin:0;"></pre>
        </div>
    </div>
</div>

{{-- Pagination --}}
<footer class="pagination-footer migration-pagination" id="migPaginationFooter" style="display:none;">
    <span class="pagination-footer__info" id="migPaginationInfo">Showing 1–10 of 0</span>
    <div class="pagination">
        <div class="pagination__pages" id="migPaginationPages"></div>
        <select class="select per-page" id="migPerPage" aria-label="Rows per page" onchange="changeMigPerPage(this.value)">
            <option value="10" selected>10 / page</option>
            <option value="25">25 / page</option>
            <option value="50">50 / page</option>
        </select>
    </div>
</footer>

{{-- Confirm modal --}}
<div class="confirm-modal-overlay" id="confirmModal">
    <div class="confirm-modal">
        <h3 class="confirm-modal__title">Generate Migrations</h3>
        <p class="confirm-modal__desc">
            Are you sure you want to generate migration files for <strong id="confirmCount" style="color:var(--accent-blue);">0</strong> selected recommendation(s)?
        </p>
        <div class="confirm-modal__actions">
            <button class="confirm-btn confirm-btn--secondary" onclick="closeConfirmModal()">Cancel</button>
            <button class="confirm-btn confirm-btn--primary"   onclick="confirmGenerate()">Confirm &amp; Generate</button>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
let candidates   = [];
let selectedIds  = new Set();
let migPage      = 1;
let migPerPage_  = 10;

document.addEventListener('DOMContentLoaded', fetchCandidates);
document.getElementById('btnRunAnalysis').addEventListener('click', runMigAnalysis);

async function fetchCandidates() {
    try {
        const res = await fetch(`${BASE_PATH}/api/migration-candidates`);
        const data = await res.json();
        candidates = (data.recommendations || []).map(r => { r.id = Number(r.id); return r; });
        renderTable();
    } catch (e) {
        document.getElementById('migTableBody').innerHTML =
            `<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--critical);">Failed to load candidates: ${escapeHtml(e.message)}</td></tr>`;
    }
}

/* ─── Filtering ─── */
function getFiltered() {
    const q      = (document.getElementById('searchInput')?.value || '').toLowerCase();
    const type   = document.getElementById('filterType')?.value   || 'all';
    const sev    = document.getElementById('filterSeverity')?.value || 'all';
    const status = document.getElementById('filterStatus')?.value  || 'pending';

    return candidates.filter(r => {
        if (q && !r.table_name.toLowerCase().includes(q) && !r.column_name.toLowerCase().includes(q)) return false;
        if (type   !== 'all' && r.index_type !== type) return false;
        if (status !== 'all' && r.status     !== status) return false;
        if (sev === 'critical' && r.score < 80) return false;
        if (sev === 'high'     && (r.score < 60 || r.score >= 80)) return false;
        if (sev === 'medium'   && (r.score < 40 || r.score >= 60)) return false;
        if (sev === 'low'      && r.score >= 40) return false;
        return true;
    });
}

function renderTable() {
    migPage = 1; // always reset to page 1 when filters change
    // also clear selections that no longer match the filter
    const filtered = getFiltered();
    const filteredIds = new Set(filtered.map(r => r.id));
    selectedIds.forEach(id => { if (!filteredIds.has(id)) selectedIds.delete(id); });
    renderTablePage();
}

function renderTablePage() {
    const filtered = getFiltered();
    const total    = filtered.length;
    const pending  = filtered.filter(r => r.status === 'pending').length;
    const generated= filtered.filter(r => r.status === 'generated').length;
    const summary  = document.getElementById('migSummary');
    summary.innerHTML = `<span>${total} candidate(s)</span><span class="filter-bar__sep">·</span><span style="color:var(--text-muted)">${pending} pending</span><span class="filter-bar__sep">·</span><span style="color:#60a5fa">${generated} already generated</span>`;

    const tbody = document.getElementById('migTableBody');
    const footer = document.getElementById('migPaginationFooter');

    if (filtered.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:48px;color:var(--text-muted);">No candidates match current filters.</td></tr>`;
        footer.style.display = 'none';
        return;
    }

    const start = (migPage - 1) * migPerPage_;
    const page  = filtered.slice(start, start + migPerPage_);
    const pages = Math.ceil(total / migPerPage_);

    tbody.innerHTML = page.map(r => buildMigRow(r)).join('');
    footer.style.display = 'flex';
    document.getElementById('migPaginationInfo').textContent = `Showing ${start+1}–${Math.min(start+migPerPage_,total)} of ${total}`;
    renderMigPagination(pages);
    updateSelectionState();
}

function buildMigRow(r) {
    const isPending  = r.status === 'pending';
    const checked    = selectedIds.has(r.id) ? 'checked' : '';
    const disabled   = isPending ? '' : 'disabled';

    const sevClass   = r.score >= 80 ? 'p1' : r.score >= 60 ? 'p2' : r.score >= 40 ? 'p3' : 'p4';
    const sevLabel   = r.score >= 80 ? 'P1' : r.score >= 60 ? 'P2' : r.score >= 40 ? 'P3' : 'P4';
    const sevPts     = `${r.score ?? 0} pts`;

    const typeMap = { INDEX:'index', COMPOSITE:'composite', DROP:'drop', REDUNDANT_CHECK:'redundant' };
    const typeTag = `<span class="type-tag type-tag--${typeMap[r.index_type] || 'index'}">${r.index_type.replace('_CHECK','')}</span>`;

    const statusMap = { generated:'generated', applied:'applied', dismissed:'dismissed' };
    const statusTag = `<span class="status-tag ${statusMap[r.status] ? 'status-tag--'+statusMap[r.status] : ''}">${r.status}</span>`;

    const ev = r.evidence || {};
    let ddl = '';
    if (r.index_type === 'DROP') {
        ddl = `DROP INDEX ${ev.constraint_name || ev.index_name || `idx_${r.table_name}_${r.column_name}`}`;
    } else if (r.index_type === 'COMPOSITE') {
        ddl = `CREATE INDEX ON ${r.table_name} (${r.column_name})`;
    } else {
        ddl = `CREATE INDEX ON ${r.table_name} (${r.column_name})`;
    }

    return `<tr class="${isPending ? '' : 'mig-row-disabled'}" style="${isPending ? '' : 'opacity:0.5'}">
        <td class="col-check">
            <input type="checkbox" class="migration-check mig-row-check" data-id="${r.id}"
                ${checked} ${disabled}
                onchange="toggleSelection(${r.id}, this.checked)"
                aria-label="Select row">
        </td>
        <td class="col-pri"><span class="pri-badge pri-badge--${sevClass}" title="Priority ${sevLabel} · Score: ${r.score ?? 0} pts"><span class="pri-badge__priority">${sevLabel}</span><span class="pri-badge__pts">${sevPts}</span></span></td>
        <td class="col-table" title="${escapeAttr(r.table_name)}">${escapeHtml(r.table_name)}</td>
        <td class="col-column" title="${escapeAttr(r.column_name)}">${escapeHtml(r.column_name)}</td>
        <td class="col-type">${typeTag}</td>
        <td class="col-status">${statusTag}</td>
        <td class="col-suggestion"><span class="sql-suggestion" title="${escapeAttr(ddl)}">${escapeHtml(ddl)}</span></td>
    </tr>`;
}

/* ─── Pagination ─── */
function renderMigPagination(pages) {
    const el = document.getElementById('migPaginationPages');
    if (!el || pages < 1) return;
    let range = [];
    if (pages <= 7) {
        range = Array.from({length: pages}, (_, i) => i + 1);
    } else if (migPage <= 4) {
        range = [1, 2, 3, 4, 5, '...', pages];
    } else if (migPage >= pages - 3) {
        range = [1, '...', pages-4, pages-3, pages-2, pages-1, pages].filter(p => p === '...' || p >= 1);
    } else {
        range = [1, '...', migPage-1, migPage, migPage+1, '...', pages];
    }
    let html = '';
    html += `<button type="button" class="page-btn page-btn--nav" ${migPage===1?'disabled':''} onclick="goMigPage(${migPage-1})" aria-label="Previous"><svg viewBox="0 0 7 11" fill="currentColor"><path d="M6 0L1 5.5 6 11V0z"/></svg></button>`;
    range.forEach(p => {
        if (p === '...') {
            html += `<span class="page-ellipsis">…</span>`;
        } else {
            html += `<button type="button" class="page-btn ${p===migPage?'page-btn--active':''}" onclick="goMigPage(${p})">${p}</button>`;
        }
    });
    html += `<button type="button" class="page-btn page-btn--nav" ${migPage===pages?'disabled':''} onclick="goMigPage(${migPage+1})" aria-label="Next"><svg viewBox="0 0 7 11" fill="currentColor"><path d="M1 0l5 5.5L1 11V0z"/></svg></button>`;
    el.innerHTML = html;
}

function goMigPage(p) {
    const pages = Math.ceil(getFiltered().length / migPerPage_);
    if (p < 1 || p > pages) return;
    migPage = p;
    renderTablePage();
}

/* ─── Selection ─── */
function toggleSelection(id, checked) {
    if (checked) selectedIds.add(id); else selectedIds.delete(id);
    updateSelectionState();
}

function toggleSelectDeselectAll() {
    const filtered = getFiltered().filter(r => r.status === 'pending');
    const allSel   = filtered.length > 0 && filtered.every(r => selectedIds.has(r.id));
    // If all currently selected → deselect all; otherwise → select all
    filtered.forEach(r => { if (allSel) selectedIds.delete(r.id); else selectedIds.add(r.id); });
    document.querySelectorAll('.mig-row-check:not([disabled])').forEach(cb => {
        cb.checked = selectedIds.has(Number(cb.dataset.id));
    });
    updateSelectionState();
}

function toggleSelectAll(checked) {
    const filtered = getFiltered().filter(r => r.status === 'pending');
    filtered.forEach(r => { if (checked) selectedIds.add(r.id); else selectedIds.delete(r.id); });
    document.querySelectorAll('.mig-row-check:not([disabled])').forEach(cb => {
        cb.checked = selectedIds.has(Number(cb.dataset.id));
    });
    updateSelectionState();
}

function deselectAll() {
    selectedIds.clear();
    document.querySelectorAll('.mig-row-check:not([disabled])').forEach(cb => { cb.checked = false; });
    const hdr = document.getElementById('headerCheckbox');
    if (hdr) hdr.checked = false;
    updateSelectionState();
}

function updateSelectionState() {
    const count   = selectedIds.size;
    const filtered = getFiltered().filter(r => r.status === 'pending');
    const allSel  = filtered.length > 0 && filtered.every(r => selectedIds.has(r.id));

    // Update count displays
    ['selectedCount','selectedCountTop'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.textContent = count;
    });

    // Enable/disable generate buttons
    const btnTop = document.getElementById('btnGenerateTop');
    const btnSel = document.getElementById('btnGenerateSelected');
    if (btnTop) btnTop.disabled = count === 0;
    if (btnSel) btnSel.disabled = count === 0;

    // Toggle Select All / Deselect All label
    const btnToggle = document.getElementById('btnSelectDeselectAll');
    if (btnToggle) btnToggle.textContent = allSel && filtered.length > 0 ? 'Deselect All' : 'Select All';

    // Sync header checkbox
    const hdr = document.getElementById('headerCheckbox');
    if (hdr) hdr.checked = allSel;
}

/* ─── Confirm modal ─── */
function openConfirmModal() {
    if (selectedIds.size === 0) { showToast('No recommendations selected.'); return; }
    document.getElementById('confirmCount').textContent = selectedIds.size;
    document.getElementById('confirmModal').classList.add('is-open');
}
function closeConfirmModal() { document.getElementById('confirmModal').classList.remove('is-open'); }

async function confirmGenerate() {
    closeConfirmModal();
    const ids  = Array.from(selectedIds);
    const btn  = document.getElementById('btnGenerateSelected');
    const topB = document.getElementById('btnGenerateTop');
    setButtonBusy(btn, true, 'Generating…', '');
    if (topB) { topB.disabled = true; topB.querySelector('span').textContent = 'Generating…'; }
    showTerminal(`Generating migrations for ${ids.length} selected recommendation(s)…`);

    try {
        const res = await fetch(`${BASE_PATH}/api/generate-selected-migrations`, {
            method: 'POST', headers: apiHeaders(), body: JSON.stringify({ ids }),
        });
        const data = await res.json();

        try {
            const result = await resolveIndexAdvisorTaskResponse(data);
            writeTerminal(result.output || '(no output)');
            showToast(`${result.generated_count || ids.length} migration(s) generated.`);
            const rp = document.getElementById('resultPanel');
            document.getElementById('resultOutput').textContent = result.output || 'Migrations generated successfully.';
            rp.style.display = 'flex';
            selectedIds.clear();
            await fetchCandidates();
            setTimeout(() => closeTerminal(), 2200);
        } catch (e) {
            writeTerminal('ERROR: ' + e.message);
            showToast(e.message || 'Migration generation failed.');
        }
    } catch (e) {
        writeTerminal('ERROR: ' + e.message);
        showToast('Migration generation failed.');
    } finally {
        setButtonBusy(btn, false, 'Generating…', '');
        if (topB) { topB.disabled = selectedIds.size === 0; topB.querySelector('span').textContent = `Generate Selected (${selectedIds.size})`; }
        updateSelectionState();
    }
}

/* ─── Run Analysis ─── */
async function runMigAnalysis() {
    const btn = document.getElementById('btnRunAnalysis');
    setButtonBusy(btn, true, 'Analyzing…', 'Run Analysis');
    const skipCode    = document.getElementById('chkSkipCode')?.checked    ?? false;
    const skipExplain = document.getElementById('chkSkipExplain')?.checked ?? true;
    const skipLocalDb = document.getElementById('chkSkipLocalDb')?.checked ?? false;
    const flags = [skipCode && 'code skipped', skipExplain && 'EXPLAIN skipped', skipLocalDb && 'local DB skipped'].filter(Boolean);
    showTerminal('Running Index Advisor pipeline' + (flags.length ? ' (' + flags.join(', ') + ')' : '') + '…');
    try {
        const { ok, data, status, raw } = await parseJsonResponse(await fetch(`${BASE_PATH}/api/run`, {
            method: 'POST', headers: apiHeaders(),
            body: JSON.stringify({ skip_explain: skipExplain, skip_code_analysis: skipCode, skip_local_db: skipLocalDb }),
        }));
        if (!data) { writeTerminal('ERROR: HTTP ' + status + '\n' + (raw||'').slice(0,2000)); showToast('Run Analysis failed'); return; }
        try {
            const result = await resolveIndexAdvisorTaskResponse(data);
            writeTerminal(result.output || '(no output)');
            showToast('Analysis complete');
            await fetchCandidates();
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
