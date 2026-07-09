<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Smart Smart Index Advisor')</title>
    @include('smart-index-advisor::partials.theme-styles')
    @stack('head')
</head>
<body>
@php $active = $active ?? 'recommendations'; @endphp

{{-- CSS-only state inputs (used by pages that need them) --}}
@stack('state-inputs')

<div class="app">

    {{-- Sidebar --}}
    <aside class="sidebar">
        <div class="sidebar__brand">
            <h1>Workspace</h1>
            <p>Production Cluster</p>
        </div>
        <nav class="sidebar__nav">
            <a href="{{ $basePath }}" class="nav-link {{ $active === 'recommendations' ? 'active' : '' }}">
                <svg viewBox="0 0 15 20" fill="currentColor" aria-hidden="true"><path d="M0 0h15v4H0V0zm0 8h15v4H0V8zm0 8h15v4H0v-4z"/></svg>
                Recommendations
            </a>
            <a href="{{ $basePath }}/queries" class="nav-link {{ $active === 'queries' ? 'active' : '' }}">
                <svg viewBox="0 0 20 16" fill="currentColor" aria-hidden="true"><path d="M0 0h20v2H0V0zm0 7h14v2H0V7zm0 7h18v2H0v-2z"/></svg>
                Query Log
            </a>
            <a href="{{ $basePath }}/migrations" class="nav-link {{ $active === 'migrations' ? 'active' : '' }}">
                <svg viewBox="0 0 19 19" fill="currentColor" aria-hidden="true"><path d="M9.5 0L0 5.5v8L9.5 19 19 13.5v-8L9.5 0zm0 2.3l6.9 3.8v7.6L9.5 17.5 2.6 13.7V6.1L9.5 2.3z"/></svg>
                Migrations
            </a>
            <a href="{{ $basePath }}/overview" class="nav-link {{ $active === 'overview' ? 'active' : '' }}">
                <svg viewBox="0 0 16 20" fill="currentColor" aria-hidden="true"><path d="M8 0C3.6 0 0 3.6 0 8s3.6 8 8 8 8-3.6 8-8-3.6-8-8-8zm0 14c-3.3 0-6-2.7-6-6s2.7-6 6-6 6 2.7 6 6-2.7 6-6 6z"/></svg>
                Overview
            </a>
            <a href="{{ $basePath }}/upload" class="nav-link {{ $active === 'upload' ? 'active' : '' }}">
                <svg viewBox="0 0 19 14" fill="currentColor" aria-hidden="true"><path d="M9.5 0 6 5h2.5V10h2V5H13L9.5 0zM0 12v2h19v-2H0z"/></svg>
                Upload CSV
            </a>
        </nav>
    </aside>

    {{-- Main area --}}
    <div class="main">

        {{-- Top bar --}}
        <header class="topbar">
            <span class="topbar__title">@yield('topbar-title', 'Smart Index Advisor')</span>
            <div class="topbar__actions">
                @yield('topbar-actions')
            </div>
        </header>

        {{-- Page content --}}
        <div class="canvas">
            @yield('content')
        </div>

    </div>
</div>

<div id="toast" class="toast hidden" role="status"></div>

<script>
    window.IndexAdvisor = {
        basePath: @json($basePath),
        csrf: @json(csrf_token()),
    };
    const BASE_PATH = window.IndexAdvisor.basePath;

    function showToast(message) {
        const el = document.getElementById('toast');
        el.textContent = message;
        el.classList.remove('hidden');
        clearTimeout(window._toastTimer);
        window._toastTimer = setTimeout(() => el.classList.add('hidden'), 2800);
    }

    function apiHeaders(json = true) {
        const h = {
            'X-CSRF-TOKEN': window.IndexAdvisor.csrf,
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'application/json',
        };
        if (json) h['Content-Type'] = 'application/json';
        return h;
    }

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function escapeAttr(text) {
        return escapeHtml(text).replace(/'/g, '&#39;');
    }

    function setButtonBusy(btn, busy, busyLabel, idleLabel) {
        if (!btn) return;
        const span = btn.querySelector('span');
        btn.disabled = busy;
        if (span) span.textContent = busy ? busyLabel : idleLabel;
        btn.classList.toggle('is-busy', busy);
    }

    async function parseJsonResponse(response) {
        const text = await response.text();
        try {
            return { ok: response.ok, status: response.status, data: JSON.parse(text) };
        } catch {
            return { ok: false, status: response.status, data: null, raw: text };
        }
    }

    async function pollIndexAdvisorTask(runId, { intervalMs = 1500, maxAttempts = 120 } = {}) {
        for (let attempt = 0; attempt < maxAttempts; attempt++) {
            const { data } = await parseJsonResponse(await fetch(`${BASE_PATH}/api/tasks/${runId}`, {
                headers: apiHeaders(false),
            }));

            if (!data) {
                throw new Error('Failed to poll task status.');
            }

            if (data.status === 'pending' || data.status === 'running') {
                await new Promise(resolve => setTimeout(resolve, intervalMs));
                continue;
            }

            return data;
        }

        throw new Error('Task timed out.');
    }

    async function resolveIndexAdvisorTaskResponse(data) {
        if (data?.queued && data?.run_id) {
            const result = await pollIndexAdvisorTask(data.run_id);

            if (!result.success) {
                throw new Error(result.error || 'Task failed.');
            }

            return result;
        }

        if (!data?.success) {
            throw new Error(data?.error || 'Request failed.');
        }

        return data;
    }

    // Auto-run checkbox visual toggle
    document.addEventListener('change', function(e) {
        const cb = e.target;
        if (!cb.classList.contains('auto-run-cb')) return;
        const label = cb.closest('.auto-run');
        if (label) {
            const box = label.querySelector('.auto-run__box');
            if (box) box.classList.toggle('is-checked', cb.checked);
        }
        updateRunAnalysisState();
    });

    function updateRunAnalysisState() {
        const anyChecked = document.querySelectorAll('.auto-run-cb:checked').length > 0;
        document.querySelectorAll('.btn-run-analysis').forEach(btn => {
            btn.classList.toggle('is-active', anyChecked);
            btn.disabled = !anyChecked;
        });
    }

    document.addEventListener('DOMContentLoaded', updateRunAnalysisState);

    // Auto-run checkbox visual toggle
    document.addEventListener('change', function(e) {
        const cb = e.target.closest('input[type="checkbox"][data-auto-run]');
        if (!cb) return;
        const label = cb.closest('.auto-run');
        if (label) {
            const box = label.querySelector('.auto-run__box');
            if (box) box.classList.toggle('is-checked', cb.checked);
        }
        // Update Run Analysis button state
        const anyChecked = document.querySelectorAll('input[data-auto-run]:checked').length > 0;
        document.querySelectorAll('.btn-run-analysis').forEach(btn => {
            btn.classList.toggle('is-active', anyChecked);
        });
    });
</script>
@stack('scripts')
</body>
</html>

