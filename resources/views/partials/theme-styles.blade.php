<style>
    @import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap");

    :root {
        --bg-app: #131315;
        --bg-sidebar: #201f22;
        --bg-card: #201f22;
        --bg-card-header: #2a2a2c;
        --bg-input: rgba(0, 0, 0, 0.4);
        --bg-secondary-bar: #1c1b1d;
        --bg-filter-bar: #2a2a2c;
        --border: #424654;
        --text-primary: #e5e1e4;
        --text-muted: #c2c6d6;
        --text-dim: #bdbdbd;
        --accent-blue: #adc6ff;
        --accent-primary: #3b82f6;
        --accent-primary-hover: #2563eb;
        --accent-link: #3b82f6;
        --critical: #ffb4ab;
        --high: #ffb786;
        --low: #adc6ff;
        --medium: #c0c1ff;
        --unknown: #8c909f;
        --tag-drop: #06b6d4;
        --tag-index: #ffb4ab;
        --status-green: #10b981;
        --status-yellow: #facc15;
        --terminal-info: #00daf3;
        --nav-active: #3b82f6;
        --sidebar-width: 280px;
        --header-height: 64px;
        --radius-sm: 2px;
        --radius: 4px;
        --radius-lg: 8px;
        --font: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        --font-mono: "JetBrains Mono", "Consolas", "Monaco", "Courier New", monospace;

        /* Legacy compat */
        --primary: #3b82f6;
        --primary-glow: rgba(59, 130, 246, 0.15);
        --primary-hover: #2563eb;
        --success: #10b981;
        --success-glow: rgba(16, 185, 129, 0.1);
        --danger: #ef4444;
        --bg-color: #131315;
        --surface-color: #201f22;
        --surface-hover: #2a2a2c;
        --border-color: #424654;
        --border-hover: rgba(255, 255, 255, 0.12);
        --text-secondary: #c2c6d6;
        --transition-smooth: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
        font-family: var(--font);
        background: var(--bg-app);
        color: var(--text-primary);
        font-size: 14px;
        line-height: 1.4;
        min-height: 100vh;
        overflow-x: hidden;
    }

    /* ── Layout ── */
    .app {
        display: flex;
        min-height: 100vh;
    }

    /* ── Sidebar ── */
    .sidebar {
        width: var(--sidebar-width);
        flex-shrink: 0;
        background: var(--bg-sidebar);
        border-right: 1px solid var(--border);
        display: flex;
        flex-direction: column;
        padding: 16px;
        position: fixed;
        top: 0;
        left: 0;
        bottom: 0;
        z-index: 100;
    }

    .sidebar__brand {
        margin-bottom: 24px;
    }

    .sidebar__brand h1 {
        font-size: 20px;
        font-weight: 600;
        color: var(--text-primary);
        line-height: 1.3;
    }

    .sidebar__brand p {
        font-size: 13px;
        color: var(--text-muted);
        margin-top: 2px;
    }

    .sidebar__nav {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .nav-link {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 12px;
        border-radius: var(--radius-lg);
        color: var(--text-muted);
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
        transition: background 0.15s, color 0.15s;
    }

    .nav-link svg {
        width: 18px;
        height: 18px;
        flex-shrink: 0;
    }

    .nav-link:hover {
        background: rgba(255, 255, 255, 0.05);
        color: var(--text-primary);
    }

    .nav-link.active,
    .nav-link--active {
        background: var(--nav-active);
        color: #fff;
    }

    .nav-link.active:hover,
    .nav-link--active:hover {
        background: var(--accent-primary-hover);
        color: #fff;
    }

    /* ── Main area ── */
    .main {
        flex: 1;
        margin-left: var(--sidebar-width);
        display: flex;
        flex-direction: column;
        min-height: 100vh;
    }

    /* ── Top app bar ── */
    .topbar {
        height: var(--header-height);
        background: var(--bg-app);
        border-bottom: 1px solid #353538;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 24px;
        position: sticky;
        top: 0;
        z-index: 50;
        flex-shrink: 0;
    }

    .topbar__title {
        font-size: 18px;
        font-weight: 600;
        color: var(--text-primary);
    }

    .topbar__actions {
        display: flex;
        align-items: center;
    }

    .topbar__toolbar {
        display: flex;
        align-items: center;
        gap: 20px;
    }

    .topbar__analysis-group {
        display: flex;
        align-items: center;
        height: 42px;
        background: rgba(55, 64, 85, 0.5);
        border: 1px solid rgba(59, 73, 76, 0.2);
        border-radius: var(--radius);
        padding: 0 5px 0 0;
    }

    .auto-run-label {
        display: flex;
        align-items: center;
        gap: 8px;
        height: 32px;
        margin: 5px 0 5px 5px;
        padding: 4px 16px 4px 11px;
        cursor: pointer;
        user-select: none;
        border-right: 1px solid rgba(59, 73, 76, 0.2);
    }

    .auto-run-label input[type="checkbox"] {
        accent-color: var(--accent-primary);
        width: 14px;
        height: 14px;
    }

    .auto-run-label span {
        font-size: 12px;
        color: #bac9cc;
        white-space: nowrap;
    }

    /* Three-option auto-run with custom checkbox box */
    .auto-run {
        display: flex;
        align-items: center;
        gap: 8px;
        height: 32px;
        margin: 5px 0 5px 5px;
        padding: 4px 16px 4px 11px;
        cursor: pointer;
        user-select: none;
        border-right: 1px solid rgba(59, 73, 76, 0.2);
    }

    .auto-run__box {
        width: 16px;
        height: 16px;
        flex-shrink: 0;
        border: 1px solid #849394;
        background: transparent;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.15s, border-color 0.15s;
        position: relative;
    }

    .auto-run__box::after {
        content: "";
        width: 8px;
        height: 6px;
        opacity: 0;
        transform: scale(0.6);
        transition: opacity 0.15s, transform 0.15s;
        background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 8'%3E%3Cpath d='M1 3.5L3.5 6.5 9 1' fill='none' stroke='%23fff' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") center / contain no-repeat;
    }

    .auto-run__box.is-checked {
        background: #3b82f6;
        border-color: #3b82f6;
    }

    .auto-run__box.is-checked::after {
        opacity: 1;
        transform: scale(1);
    }

    .auto-run__text {
        font-size: 12px;
        line-height: 16px;
        color: #bac9cc;
        white-space: nowrap;
    }

    .btn-run-analysis.is-active {
        cursor: pointer;
        pointer-events: auto;
        border-color: #3b82f6;
        color: #3b82f6;
        opacity: 1;
    }

    .btn-run-analysis.is-active:hover {
        background: rgba(59, 130, 246, 0.08);
    }

    /* ── Canvas ── */
    .canvas {
        padding: 24px;
        display: flex;
        flex-direction: column;
        gap: 16px;
        flex: 1;
    }

    /* ── Buttons ── */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 8px 20px;
        border-radius: var(--radius);
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        border: none;
        font-family: inherit;
        text-decoration: none;
        transition: background 0.15s, opacity 0.15s;
    }

    .btn--primary, .btn-primary {
        background: var(--accent-primary);
        color: #fff;
    }

    .btn--primary:hover, .btn-primary:hover:not(:disabled) {
        background: var(--accent-primary-hover);
    }

    .btn--secondary, .btn-secondary {
        background: rgba(255, 255, 255, 0.04);
        color: var(--text-primary);
        border: 1px solid var(--border);
    }

    .btn--secondary:hover, .btn-secondary:hover:not(:disabled) {
        background: rgba(255, 255, 255, 0.08);
    }

    .btn--outline {
        background: transparent;
        color: var(--text-primary);
        border: 1px solid var(--border);
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .btn--outline:hover {
        background: rgba(255, 255, 255, 0.06);
        border-color: var(--accent-primary);
        color: var(--accent-primary);
    }

    .btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .btn.is-busy {
        opacity: 0.7;
        cursor: wait;
    }

    /* Three auto-run option labels */
    .auto-run {
        display: flex;
        align-items: center;
        gap: 8px;
        height: 32px;
        margin: 5px 0 5px 5px;
        padding: 4px 16px 4px 11px;
        cursor: pointer;
        user-select: none;
        border-right: 1px solid rgba(59, 73, 76, 0.2);
    }

    .auto-run__box {
        width: 16px;
        height: 16px;
        flex-shrink: 0;
        border: 1px solid #849394;
        background: transparent;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.15s, border-color 0.15s;
        position: relative;
    }

    .auto-run__box::after {
        content: "";
        width: 8px;
        height: 6px;
        opacity: 0;
        transform: scale(0.6);
        transition: opacity 0.15s, transform 0.15s;
        background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 8'%3E%3Cpath d='M1 3.5L3.5 6.5 9 1' fill='none' stroke='%23fff' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") center / contain no-repeat;
    }

    .auto-run__box.is-checked {
        background: #3b82f6;
        border-color: #3b82f6;
    }

    .auto-run__box.is-checked::after {
        opacity: 1;
        transform: scale(1);
    }

    .auto-run__text {
        font-size: 12px;
        line-height: 16px;
        color: #bac9cc;
        white-space: nowrap;
    }

    .btn-run-analysis {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 32px;
        margin: 5px 0;
        padding: 0 20px;
        border-radius: var(--radius);
        font-size: 13px;
        font-weight: 500;
        font-family: inherit;
        cursor: not-allowed;
        pointer-events: none;
        background: transparent;
        border: 1px solid #505050;
        color: #7a8488;
        white-space: nowrap;
        opacity: 0.6;
        transition: border-color 0.15s, color 0.15s, background 0.15s, opacity 0.15s;
    }

    .btn-run-analysis.is-active {
        cursor: pointer;
        pointer-events: auto;
        border-color: #3b82f6;
        color: #3b82f6;
        opacity: 1;
    }

    .btn-run-analysis.is-active:hover {
        background: rgba(59, 130, 246, 0.08);
    }

    .btn-generate {
        height: 32px;
        padding: 0 20px;
        font-size: 13px;
    }

    /* ── KPI Cards ── */
    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 16px;
    }

    .kpi-card {
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 17px;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .kpi-card__label {
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.04em;
        color: var(--text-muted);
        text-transform: uppercase;
    }

    .kpi-card__value {
        font-size: 28px;
        font-weight: 600;
        line-height: 1.2;
    }

    .kpi-card__value--total { color: var(--accent-blue); }
    .kpi-card__value--critical { color: var(--critical); }
    .kpi-card__value--high { color: var(--high); }
    .kpi-card__value--applied { color: var(--text-primary); }

    /* Legacy stat-card compat */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 16px;
    }

    .stat-card {
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 17px;
        position: relative;
        overflow: hidden;
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0;
        width: 3px; height: 100%;
        background: var(--text-muted);
    }

    .stat-card.primary::before { background: var(--accent-primary); }
    .stat-card.critical::before { background: var(--critical); }
    .stat-card.high::before { background: var(--high); }
    .stat-card.success::before { background: var(--status-green); }

    .stat-label {
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--text-muted);
        margin-bottom: 6px;
    }

    .stat-value {
        font-size: 28px;
        font-weight: 700;
        color: var(--text-primary);
    }

    /* ── Severity Legend ── */
    .severity-legend {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 16px;
        padding: 9px 12px;
        background: var(--bg-secondary-bar);
        border: 1px solid var(--border);
        border-radius: var(--radius);
    }

    .severity-legend__title {
        font-size: 13px;
        color: var(--text-primary);
        font-weight: 500;
    }

    .legend-item {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 12px;
        color: var(--text-muted);
    }

    .legend-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    .legend-dot--low { background: var(--low); }
    .legend-dot--medium { background: var(--medium); }
    .legend-dot--high { background: var(--high); }
    .legend-dot--critical { background: var(--critical); }
    .legend-dot--unknown { background: var(--unknown); }

    /* ── Filter Bar ── */
    .filter-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
        padding: 8px;
        background: var(--bg-filter-bar);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
    }

    .filter-bar__filters {
        display: flex;
        align-items: center;
        gap: 8px;
        flex: 1;
        flex-wrap: wrap;
    }

    .filter-bar__metrics {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 13px;
        color: #9ca3af;
        white-space: nowrap;
    }

    .filter-bar__sep { color: #4b5563; }

    .filter-bar__link {
        color: var(--accent-link);
        text-decoration: none;
    }

    .filter-bar__link:hover { text-decoration: underline; }

    /* ── Search Input ── */
    /* .search-input is the WRAPPER div (contains icon + input) */
    .search-input, .search-wrapper, .search-input-wrap {
        position: relative;
        flex: 0 1 220px;
        min-width: 160px;
    }

    .search-input svg,
    .search-wrapper svg,
    .search-input-wrap svg {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        width: 14px;
        height: 14px;
        color: var(--text-muted);
        pointer-events: none;
        z-index: 1;
    }

    /* The actual <input> inside any search wrapper */
    .search-input input,
    .search-wrapper input,
    .search-input-wrap input {
        width: 100%;
        height: 37px;
        padding: 0 12px 0 40px;
        background: var(--bg-input);
        border: none;
        border-radius: var(--radius-lg);
        color: var(--text-primary);
        font-size: 13px;
        font-family: inherit;
        outline: none;
        transition: box-shadow 0.15s;
    }

    .search-input input:focus,
    .search-wrapper input:focus,
    .search-input-wrap input:focus {
        border-color: var(--accent-primary);
        box-shadow: 0 0 0 1px var(--accent-primary);
    }

    .search-input input::placeholder,
    .search-wrapper input::placeholder,
    .search-input-wrap input::placeholder { color: #9ca3af; }

    /* ── Select ── */
    .select, .select-filter {
        height: 37px;
        padding: 0 32px 0 10px;
        background: var(--bg-input);
        border: none;
        border-radius: var(--radius-lg);
        color: #fff;
        font-size: 13px;
        font-family: inherit;
        cursor: pointer;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='5' viewBox='0 0 8 5'%3E%3Cpath d='M0 0l4 5 4-5z' fill='%239ca3af'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 10px center;
        background-color: var(--bg-input);
        outline: none;
    }

    .select:focus, .select-filter:focus {
        border-color: var(--accent-primary);
        box-shadow: 0 0 0 1px var(--accent-primary);
    }

    /* ── Breadcrumb ── */
    .breadcrumb {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 12px;
        color: var(--text-muted);
        margin-bottom: 4px;
    }

    .breadcrumb__sep {
        width: 5px;
        height: 8px;
        opacity: 0.7;
    }

    .breadcrumb__current { color: var(--text-primary); }

    .page-title {
        font-size: 28px;
        font-weight: 600;
        color: var(--text-primary);
        line-height: 1.2;
        margin-bottom: 0;
    }

    .page-intro {
        font-size: 14px;
        color: var(--text-muted);
        margin: 0;
        line-height: 1.5;
    }

    /* ── Recommendation Cards ── */
    .rec-list { display: flex; flex-direction: column; gap: 8px; flex: 1; }

    .rec-card {
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        overflow: hidden;
        transition: border-color 0.15s;
    }

    .rec-card:hover { border-color: #5a6270; }

    .rec-header {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 14px 16px;
        background: var(--bg-card-header);
        cursor: pointer;
        user-select: none;
    }

    .severity-badge {
        width: 48px;
        height: 48px;
        border-radius: var(--radius);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-direction: column;
        gap: 1px;
        font-size: 13px;
        font-weight: 700;
        flex-shrink: 0;
        border: 1px solid;
    }
    .severity-badge__priority { font-size: 12px; font-weight: 700; line-height: 1; }
    .severity-badge__pts      { font-size: 10px; font-weight: 600; opacity: 0.85; line-height: 1; }

    .severity-badge.critical { background: rgba(255, 180, 171, 0.1); border-color: var(--critical); color: var(--critical); }
    .severity-badge.high     { background: rgba(255, 183, 134, 0.1); border-color: var(--high);     color: var(--high); }
    .severity-badge.medium   { background: rgba(192, 193, 255, 0.1); border-color: var(--medium);   color: var(--medium); }
    .severity-badge.low      { background: rgba(173, 198, 255, 0.1); border-color: var(--low);      color: var(--low); }

    .rec-info { display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; }
    .meta-details { display: flex; flex-direction: column; gap: 4px; min-width: 0; }

    .table-col-title {
        font-size: 14px;
        font-weight: 500;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .col-highlight { color: var(--accent-blue); }

    .rec-badges {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 6px;
    }

    .badge {
        display: inline-flex;
        align-items: center;
        padding: 2px 8px;
        border-radius: var(--radius-sm);
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.02em;
        text-transform: uppercase;
        border: 1px solid transparent;
    }

    .badge-type-index { background: rgba(173, 198, 255, 0.1); color: var(--accent-blue); border-color: rgba(173, 198, 255, 0.2); }
    .badge-type-composite { background: rgba(192, 193, 255, 0.1); color: var(--medium); border-color: rgba(192, 193, 255, 0.2); }
    .badge-type-drop { background: rgba(6, 182, 212, 0.1); color: var(--tag-drop); border-color: rgba(6, 182, 212, 0.3); }
    .badge-type-redundant,
    .badge-type-redundant_check { background: rgba(250, 204, 21, 0.08); color: #facc15; border-color: rgba(250, 204, 21, 0.2); }

    .badge-status-pending { background: #353538; color: var(--text-muted); border-color: var(--border); text-transform: lowercase; }
    .badge-status-generated { background: rgba(59, 130, 246, 0.1); color: #60a5fa; border-color: rgba(59, 130, 246, 0.2); text-transform: lowercase; }
    .badge-status-applied { background: rgba(16, 185, 129, 0.1); color: var(--status-green); border-color: rgba(16, 185, 129, 0.2); text-transform: lowercase; }
    .badge-status-dismissed { background: rgba(255, 255, 255, 0.02); color: var(--text-muted); border-color: var(--border); text-decoration: line-through; text-transform: lowercase; }

    .chevron-icon {
        width: 16px;
        height: 16px;
        color: var(--text-muted);
        flex-shrink: 0;
        transition: transform 0.2s;
    }

    .rec-card.expanded .chevron-icon { transform: rotate(180deg); }

    /* ── Detail Panel ── */
    .rec-detail {
        display: none;
        border-top: 1px solid var(--border);
        background: #09090b;
        padding: 20px;
    }

    .rec-card.expanded .rec-detail { display: block; }

    .detail-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 16px;
    }

    .detail-title {
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--text-muted);
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .evidence-list { display: flex; flex-direction: column; gap: 6px; }

    .evidence-item {
        background: #18181b;
        border: 1px solid #27272a;
        border-radius: var(--radius-sm);
        padding: 8px 10px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 12px;
    }

    .evidence-lbl { color: var(--text-muted); font-weight: 500; }
    .evidence-val { font-weight: 600; color: var(--accent-blue); }
    .evidence-val.pts {
        background: rgba(255, 255, 255, 0.04);
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 11px;
        color: var(--text-primary);
    }

    .detail-row-actions {
        margin-top: 16px;
        border-top: 1px solid var(--border);
        padding-top: 14px;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }

    /* ── Code / SQL ── */
    .code-container {
        background: #111113;
        border: 1px solid #27272a;
        border-radius: var(--radius-sm);
        overflow: hidden;
    }

    .code-header {
        padding: 6px 10px;
        background: #1f1f23;
        border-bottom: 1px solid #27272a;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 11px;
        color: var(--text-muted);
    }

    .btn-copy {
        background: transparent;
        border: none;
        color: var(--text-muted);
        cursor: pointer;
        font-family: inherit;
        font-size: 12px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 4px;
        transition: color 0.15s;
    }

    .btn-copy:hover { color: var(--accent-primary); }

    .code-block {
        padding: 14px;
        font-family: "Consolas", "Monaco", "Courier New", monospace;
        font-size: 12px;
        line-height: 1.5;
        color: #e5e1e4;
        overflow-x: auto;
        white-space: pre-wrap;
        max-height: 300px;
    }

    .explain-plan {
        max-height: 220px;
        overflow-y: auto;
        font-family: "Consolas", "Monaco", "Courier New", monospace;
        font-size: 11px;
        color: var(--text-secondary);
        background: #111113;
        padding: 12px;
        border-radius: var(--radius-sm);
        border: 1px solid #27272a;
    }

    /* ── Process / Terminal ── */
    .terminal-panel {
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        overflow: hidden;
        display: none;
    }

    .terminal-panel.active { display: block; }

    .terminal-header {
        background: var(--bg-card-header);
        border-bottom: 1px solid var(--border);
        padding: 10px 16px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .terminal-title {
        font-size: 12px;
        font-weight: 500;
        color: #dae2fd;
        letter-spacing: 0.01em;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .terminal-body {
        padding: 14px;
        font-family: "Consolas", "Monaco", "Courier New", monospace;
        font-size: 12px;
        color: var(--terminal-info);
        max-height: 260px;
        overflow-y: auto;
        white-space: pre-wrap;
        line-height: 1.6;
        background: rgba(0, 0, 0, 0.4);
    }

    /* ── Panel Card ── */
    .panel-card {
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        overflow: hidden;
    }

    .panel-card-header {
        padding: 12px 16px;
        border-bottom: 1px solid var(--border);
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--text-muted);
    }

    .panel-card-body { padding: 16px; }

    /* ── Tables ── */
    .table-scroll { overflow-x: auto; width: 100%; }

    .data-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    .data-table th,
    .data-table td {
        padding: 10px 14px;
        text-align: left;
        border-bottom: 1px solid var(--border);
        vertical-align: middle;
    }

    .data-table th {
        color: var(--text-muted);
        font-size: 11px;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        background: #201f22;
        position: sticky;
        top: 0;
        z-index: 1;
    }

    .data-table tbody tr:last-child td { border-bottom: none; }
    .data-table tbody tr:hover td { background: rgba(255, 255, 255, 0.02); }
    .data-table .sql-cell { max-width: 420px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .mono {
        font-family: "Consolas", "Monaco", "Courier New", monospace;
        font-size: 12px;
    }

    /* ── Env grid ── */
    .env-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; }

    /* ── Pagination ── */
    .pagination-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 20px 0 0;
        border-top: 1px solid var(--border);
        flex-wrap: wrap;
        gap: 12px;
    }

    .pagination-footer__info { font-size: 13px; color: var(--text-muted); }

    .pagination { display: flex; align-items: center; gap: 16px; }
    .pagination__pages { display: flex; align-items: center; gap: 4px; }

    .page-btn {
        min-width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border: none;
        border-radius: var(--radius-sm);
        background: transparent;
        color: var(--text-muted);
        font-size: 14px;
        font-family: inherit;
        cursor: pointer;
        padding: 0 8px;
    }

    .page-btn:hover:not(:disabled) { background: rgba(255, 255, 255, 0.05); }
    .page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
    .page-btn--active { background: #4d8eff; color: #00285d; font-weight: 600; }
    .page-ellipsis { color: var(--text-muted); padding: 0 4px; font-size: 14px; }

    .per-page {
        height: 32px;
        padding: 0 12px;
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
    }

    /* ── Modal ── */
    .modal {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.65);
        backdrop-filter: blur(4px);
        z-index: 600;
        align-items: center;
        justify-content: center;
        padding: 24px;
    }

    .modal.open { display: flex; }

    .modal-content {
        background: #0f172a;
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 16px;
        width: 100%;
        max-width: 480px;
        padding: 24px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
        animation: modalSlide 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes modalSlide {
        from { transform: translateY(12px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    .modal-title { font-size: 16px; font-weight: 600; margin-bottom: 10px; }
    .modal-desc { font-size: 14px; color: var(--text-muted); margin-bottom: 16px; line-height: 1.5; }

    .textarea-input {
        width: 100%;
        background: rgba(8, 12, 20, 0.6);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        padding: 10px 12px;
        color: var(--text-primary);
        font-family: inherit;
        font-size: 13px;
        outline: none;
        resize: vertical;
        min-height: 100px;
        margin-bottom: 16px;
    }

    .textarea-input:focus { border-color: var(--accent-primary); }
    .modal-actions { display: flex; justify-content: flex-end; gap: 10px; }

    /* ── Toast ── */
    .toast {
        position: fixed;
        bottom: 20px;
        right: 20px;
        z-index: 700;
        padding: 10px 18px;
        border-radius: var(--radius-lg);
        background: var(--bg-card);
        border: 1px solid var(--accent-primary);
        color: var(--text-primary);
        font-size: 13px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
        transition: opacity 0.2s, transform 0.2s;
    }

    .toast.hidden { opacity: 0; pointer-events: none; transform: translateY(8px); }

    /* ── Spinners / utils ── */
    .spinner { animation: spin 1s linear infinite; }
    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

    .pulse { animation: pulse-anim 2s infinite; }
    @keyframes pulse-anim { 0%, 100% { opacity: 0.6; } 50% { opacity: 1; } }

    .empty-state {
        text-align: center;
        padding: 48px 24px;
        background: rgba(15, 23, 42, 0.15);
        border: 1px dashed var(--border);
        border-radius: var(--radius-lg);
        color: var(--text-muted);
    }

    .empty-icon { width: 40px; height: 40px; color: var(--text-muted); margin: 0 auto 14px; }

    code.inline {
        font-family: "Consolas", "Monaco", "Courier New", monospace;
        font-size: 0.85em;
        color: var(--accent-primary);
        background: rgba(59, 130, 246, 0.08);
        padding: 1px 5px;
        border-radius: 3px;
    }

    /* ── Responsive ── */
    @media (max-width: 1200px) {
        .kpi-grid, .stats-grid { grid-template-columns: repeat(2, 1fr); }
    }

    @media (max-width: 900px) {
        .sidebar { transform: translateX(-100%); }
        .main { margin-left: 0; }
        .detail-grid { grid-template-columns: 1fr; }
    }

    @media (max-width: 600px) {
        .kpi-grid, .stats-grid { grid-template-columns: 1fr; }
        .pagination-footer { flex-direction: column; align-items: flex-start; }
    }
</style>
