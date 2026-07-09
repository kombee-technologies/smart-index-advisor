@extends('smart-index-advisor::layout')

@section('title', 'Upload CSV — Smart Index Advisor')
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
/* ── Upload page ── */
.upload-canvas { gap: 32px; }

/* Import info card */
.import-info {
    background: var(--bg-card);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 12px;
    padding: 25px;
    display: flex;
    flex-direction: column;
    gap: 15px;
}
.import-info__heading { display: flex; align-items: center; gap: 10px; }
.import-info__icon    { width: 32px; height: 32px; flex-shrink: 0; color: var(--text-primary); }
.import-info__title   { font-size: 16px; font-weight: 600; color: var(--text-primary); }
.import-info__desc    { font-size: 14px; line-height: 1.5; color: #94a3b8; }

.format-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
.format-card {
    background: rgba(15,23,42,0.2); border: 1px solid rgba(255,255,255,0.06);
    border-radius: 8px; padding: 14px 18px;
    display: flex; flex-direction: column; gap: 10px;
    transition: border-color 0.15s;
}
.format-card:hover { border-color: rgba(255,255,255,0.12); }
.format-card__title { font-size: 13px; font-weight: 600; color: #f8fafc; }
.format-card__desc  { font-size: 13px; line-height: 1.45; color: #94a3b8; }
.format-card__tags  { display: flex; flex-direction: column; gap: 4px; }
.format-tag {
    display: inline-flex; align-self: flex-start;
    padding: 3px 7px; border-radius: 4px;
    background: rgba(255,255,255,0.06);
    font-family: var(--font-mono, monospace);
    font-size: 11px; line-height: 16px; color: #06b6d4;
}

/* Drop zone */
.drop-zone {
    background: var(--bg-card);
    border: 2px dashed rgba(6,182,212,0.35);
    border-radius: 12px;
    padding: 40px 28px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 14px;
    min-height: 200px;
    cursor: pointer;
    transition: border-color 0.2s, background 0.2s;
    text-align: center;
    position: relative;
}
.drop-zone.dz-drag-hover,
.drop-zone:hover {
    border-color: #06b6d4;
    background: rgba(6,182,212,0.04);
    box-shadow: 0 0 24px rgba(6,182,212,0.08);
}
.drop-zone.dz-started { border-style: solid; border-color: var(--accent-primary); }
.drop-zone__icon { color: #06b6d4; width: 48px; height: 48px; flex-shrink: 0; }
.drop-zone__title { font-size: 16px; font-weight: 500; color: var(--text-primary); }
.drop-zone__hint  { font-size: 13px; color: var(--text-muted); }
.drop-zone__browse {
    display: inline-flex; align-items: center; justify-content: center;
    height: 34px; padding: 0 18px; border-radius: var(--radius);
    background: rgba(6,182,212,0.12); border: 1px solid rgba(6,182,212,0.3);
    color: #06b6d4; font-size: 13px; font-weight: 500; font-family: inherit;
    cursor: pointer; transition: background 0.15s;
}
.drop-zone__browse:hover { background: rgba(6,182,212,0.2); }

/* Dropzone previews */
.dz-preview { display: none !important; }   /* hide default previews */

/* Upload progress bar (custom) */
.upload-progress-wrap {
    display: none; width: 100%; max-width: 340px;
    background: rgba(255,255,255,0.06); border-radius: 999px; height: 4px; overflow: hidden;
}
.upload-progress-wrap.active { display: block; }
.upload-progress-bar {
    height: 4px; background: #06b6d4; border-radius: 999px;
    width: 0; transition: width 0.2s;
}
.upload-status-text { font-size: 12px; color: var(--text-muted); }

/* Import logs */
.import-logs { display: flex; flex-direction: column; gap: 14px; }
.import-logs__header {
    display: flex; align-items: center; justify-content: space-between; gap: 14px;
}
.import-logs__title { font-size: 14px; font-weight: 600; color: #f8fafc; }

.btn-clear-logs {
    display: inline-flex; align-items: center; justify-content: center;
    height: 37px; padding: 0 24px; border: none; border-radius: var(--radius);
    background: var(--accent-primary); color: #fff;
    font-family: inherit; font-size: 13px; font-weight: 500;
    cursor: pointer; transition: background 0.15s;
}
.btn-clear-logs:hover { background: var(--accent-primary-hover); }

/* Import console entry */
.import-console {
    background: var(--bg-card);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 12px; overflow: hidden;
}
.import-console__status {
    display: flex; align-items: center; gap: 8px; padding: 12px 20px;
    background: rgba(255,255,255,0.02); border-bottom: 1px solid rgba(255,255,255,0.06);
}
.import-console__status-icon { width: 16px; height: 16px; flex-shrink: 0; }
.import-console__status-text { font-size: 12px; font-weight: 500; line-height: 1.4; }
.import-console__terminal {
    padding: 20px; display: flex; flex-direction: column; gap: 14px; min-height: 80px;
}
.import-console__line {
    font-family: var(--font-mono, monospace); font-size: 13px;
    line-height: 1.55; color: #38bdf8; white-space: pre-wrap; word-break: break-word; margin: 0;
}
.import-console__banner-box {
    border: 1.5px solid #06b6d4;
    border-radius: 6px;
    padding: 10px 20px;
    text-align: center;
    font-family: var(--font-mono, monospace);
    font-size: 13px;
    font-weight: 600;
    color: #7dd3fc;
    letter-spacing: 0.5px;
    background: rgba(6, 182, 212, 0.06);
    margin: 0;
}
.import-console__line--error   { color: var(--critical); }
.import-console__line--success { color: var(--status-green); }

@media (max-width: 900px) {
    .format-grid { grid-template-columns: 1fr; }
    .import-logs__header { flex-direction: column; align-items: flex-start; }
    .drop-zone { padding: 28px 16px; }
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

    {{-- Import info card --}}
    <section class="import-info upload-canvas" aria-labelledby="import-info-title">
        <div class="import-info__heading">
            <svg class="import-info__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
                <line x1="12" y1="18" x2="12" y2="12"/>
                <line x1="9"  y1="15" x2="15" y2="15"/>
            </svg>
            <h2 class="import-info__title" id="import-info-title">Import Database Performance Statistics</h2>
        </div>
        <p class="import-info__desc">Import query metrics, sequential scan details, or unused indexes from production or UAT instances. The system auto-detects the format by reading the file headers and runs the analysis process locally.</p>
        <div class="format-grid">
            <article class="format-card">
                <h3 class="format-card__title">1. Slow Query Stats</h3>
                <p class="format-card__desc">Import query execution times from query logs or statement statistics.</p>
                <div class="format-card__tags">
                    <span class="format-tag">Required: query / sql_sample</span>
                    <span class="format-tag">Optional: calls, avg_duration_ms</span>
                </div>
            </article>
            <article class="format-card">
                <h3 class="format-card__title">2. Sequential Scans</h3>
                <p class="format-card__desc">Identify tables being fully scanned repeatedly from table statistics.</p>
                <div class="format-card__tags">
                    <span class="format-tag">Required: table_name, seq_scan</span>
                    <span class="format-tag">Optional: seq_tup_read, n_live_tup</span>
                </div>
            </article>
            <article class="format-card">
                <h3 class="format-card__title">3. Unused Indexes</h3>
                <p class="format-card__desc">Identify candidates for removal to lower database write overheads.</p>
                <div class="format-card__tags">
                    <span class="format-tag">Required: table_name, index_name</span>
                    <span class="format-tag">Optional: column_name, index_scans</span>
                </div>
            </article>
        </div>
    </section>

    {{-- Drop zone (Dropzone.js will attach to #ia-dropzone) --}}
    <div id="ia-dropzone" class="drop-zone" aria-label="File upload drop zone">
        <svg class="drop-zone__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z"/>
        </svg>
        <p class="drop-zone__title">Drag &amp; drop CSV or JSON files here</p>
        <p class="drop-zone__hint">Supports .csv · .json · .txt &nbsp;|&nbsp; Max 5 MB per file</p>
        <button type="button" class="drop-zone__browse" id="dz-browse-btn">Browse files</button>
        {{-- Hidden fallback input for browsing --}}
        <input type="file" id="dz-file-input" accept=".csv,.json,.txt" multiple
            style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;">
        <div class="upload-progress-wrap" id="uploadProgressWrap">
            <div class="upload-progress-bar" id="uploadProgressBar"></div>
        </div>
        <span class="upload-status-text" id="uploadStatusText"></span>
    </div>

    {{-- Import logs --}}
    <section class="import-logs" id="importLogsSection" style="display:none;" aria-labelledby="import-logs-title">
        <div class="import-logs__header">
            <h3 class="import-logs__title" id="import-logs-title">Import Logs &amp; Terminal Outputs</h3>
            <button type="button" class="btn-clear-logs" onclick="clearAllLogs()">Remove all files &amp; Clear logs</button>
        </div>
        <div id="importLogsList" style="display:flex;flex-direction:column;gap:12px;"></div>
    </section>

@endsection

@push('scripts')
<script>
/* ─────────────────────────────────────────
   Dropzone implementation (vanilla, no lib)
───────────────────────────────────────────*/
const UPLOAD_URL  = '{{ route("index-advisor.handle-upload") }}';
const MAX_SIZE_MB = 5;

const dropZoneEl  = document.getElementById('ia-dropzone');
const fileInput   = document.getElementById('dz-file-input');
const browseBtn   = document.getElementById('dz-browse-btn');
const progressWrap= document.getElementById('uploadProgressWrap');
const progressBar = document.getElementById('uploadProgressBar');
const statusText  = document.getElementById('uploadStatusText');
const logSection  = document.getElementById('importLogsSection');
const logList     = document.getElementById('importLogsList');

let uploading = false;

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('btnRunAnalysis').addEventListener('click', runUploadAnalysis);
});

async function runUploadAnalysis() {
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

/* ── Browse button ── */
browseBtn.addEventListener('click', e => { e.stopPropagation(); fileInput.click(); });
fileInput.addEventListener('change', () => { if (fileInput.files.length) handleFiles(Array.from(fileInput.files)); });

/* ── Click on drop zone (but not on button) ── */
dropZoneEl.addEventListener('click', e => {
    if (e.target === browseBtn || browseBtn.contains(e.target)) return;
    fileInput.click();
});

/* ── Drag events ── */
dropZoneEl.addEventListener('dragover',  e => { e.preventDefault(); dropZoneEl.classList.add('dz-drag-hover'); });
dropZoneEl.addEventListener('dragleave', e => { dropZoneEl.classList.remove('dz-drag-hover'); });
dropZoneEl.addEventListener('drop', e => {
    e.preventDefault();
    dropZoneEl.classList.remove('dz-drag-hover');
    const files = Array.from(e.dataTransfer.files);
    if (files.length) handleFiles(files);
});

/* ── Handle files ── */
async function handleFiles(files) {
    const valid = files.filter(f => {
        const ext = f.name.split('.').pop().toLowerCase();
        if (!['csv','json','txt'].includes(ext)) {
            addLog(f.name, false, `Unsupported file type: .${ext}. Please use .csv, .json, or .txt`);
            return false;
        }
        if (f.size > MAX_SIZE_MB * 1024 * 1024) {
            addLog(f.name, false, `File too large: ${(f.size/1024/1024).toFixed(1)} MB (max ${MAX_SIZE_MB} MB)`);
            return false;
        }
        return true;
    });
    if (!valid.length) return;

    logSection.style.display = 'flex';
    dropZoneEl.classList.add('dz-started');

    for (let i = 0; i < valid.length; i++) {
        await uploadFile(valid[i], i, valid.length);
    }

    dropZoneEl.classList.remove('dz-started');
    progressWrap.classList.remove('active');
    statusText.textContent = '';
    fileInput.value = '';
}

async function uploadFile(file, idx, total) {
    uploading = true;
    progressWrap.classList.add('active');
    setProgress(0);
    statusText.textContent = `Uploading ${file.name} (${idx+1}/${total})…`;

    const formData = new FormData();
    formData.append('file', file);
    formData.append('_token', window.IndexAdvisor.csrf);

    return new Promise(resolve => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', UPLOAD_URL, true);
        xhr.setRequestHeader('X-CSRF-TOKEN', window.IndexAdvisor.csrf);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Accept', 'application/json');

        xhr.upload.addEventListener('progress', e => {
            if (e.lengthComputable) setProgress(Math.round(e.loaded / e.total * 90));
        });

        xhr.addEventListener('load', () => {
            setProgress(100);
            statusText.textContent = '';
            uploading = false;
            let response;
            try { response = JSON.parse(xhr.responseText); } catch(e) { response = { message: xhr.responseText }; }

            const isOk      = xhr.status >= 200 && xhr.status < 300;
            const imported  = response.imported_count ?? 0;
            const isSuccess = isOk && imported > 0;
            const logText   = response.details || response.message || (isOk ? 'Import complete.' : `HTTP ${xhr.status}`);

            addLog(file.name, isSuccess, logText);
            resolve();
        });

        xhr.addEventListener('error', () => {
            setProgress(0);
            statusText.textContent = '';
            uploading = false;
            addLog(file.name, false, 'Network error — could not reach the server.');
            resolve();
        });

        xhr.send(formData);
    });
}

function setProgress(pct) {
    progressBar.style.width = pct + '%';
}

/* ── Log entry builder ── */
function addLog(filename, isSuccess, logText) {
    // Strip the Artisan-generated banner lines from logText to avoid displaying it twice
    // (the frontend already renders its own styled CSS banner above the log output)
    logText = logText
        .split('\n')
        .filter(line => !/[╔╗╚╝║═]/.test(line))
        .join('\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();

    const statusColor = isSuccess ? 'var(--status-green)' : 'var(--critical)';
    const statusLabel = isSuccess
        ? `${escapeHtml(filename)} — Imported Successfully`
        : `${escapeHtml(filename)} — Import Failed`;

    const iconPath = isSuccess
        ? 'M13.5 4.5L6 12 2.5 8.5'
        : 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z';

    // Remove existing card for same file
    const old = logList.querySelector(`[data-filename="${CSS.escape(filename)}"]`);
    if (old) old.remove();

    const card = document.createElement('div');
    card.className = 'import-console';
    card.dataset.filename = filename;
    card.innerHTML = `
        <div class="import-console__status">
            <svg class="import-console__status-icon" viewBox="0 0 16 16" fill="none"
                stroke="${statusColor}" stroke-width="1.5" aria-hidden="true">
                <path d="${iconPath}"/>
            </svg>
            <span class="import-console__status-text" style="color:${statusColor}">${statusLabel}</span>
        </div>
        <div class="import-console__terminal">
            <div class="import-console__banner-box">Smart Index Advisor — Import Stats</div>
            <pre class="import-console__line ${isSuccess ? 'import-console__line--success' : 'import-console__line--error'}">${escapeHtml(logText)}</pre>
        </div>`;
    logList.insertBefore(card, logList.firstChild);
    logSection.style.display = 'flex';
}

/* ── Clear all ── */
function clearAllLogs() {
    logList.innerHTML = '';
    logSection.style.display = 'none';
    dropZoneEl.classList.remove('dz-started');
    fileInput.value = '';
    progressWrap.classList.remove('active');
    setProgress(0);
    statusText.textContent = '';
}
</script>
@endpush
