/**
 * The Pramnos debug toolbar — one renderer, two ways in.
 *
 * There used to be two: ~970 lines of PHP building HTML, CSS and inline JS for
 * server-rendered pages, and a separate ~560-line JavaScript panel for SPA
 * projects, drawing the same tables from the same collector data. They drifted,
 * and then the same bug had to be fixed twice — the `✕` that hid nothing was one
 * mistake written in two languages.
 *
 * So this file draws the toolbar, and nothing else does. It is delivered two ways:
 *
 *   - **Server-rendered pages.** `DebugBar::render()` emits this script inline,
 *     preceded by `<div id="pramnos-debug-data" hidden>` holding the request's
 *     collector data as JSON. A `<div>` rather than a `<script type=…>` because a
 *     data island in a script element is a grey area under a strict CSP, and this
 *     one has to work on every install.
 *   - **SPA projects.** The same source is scaffolded as `lib/debug.js` with an
 *     ESM `export` appended, and `lib/api.js` calls `record()` for every
 *     response. There is no data island: the first entry arrives with the first
 *     API call.
 *
 * The model is the same either way: a list of **entries**, each one request with
 * the collector payload it produced. Selecting an entry shows its tabs. That is
 * what makes a SPA get every tab a server-rendered page has — the payload
 * `ApiDebugPayload::build()` attaches already carries every collector; only the
 * drawing was missing.
 *
 * Three rules, because this runs inside somebody else's application:
 *   - the original `fetch`/`XMLHttpRequest` is always called and its result
 *     returned unchanged;
 *   - response bodies are only read through `clone()`;
 *   - every entry point is wrapped in try/catch. A toolbar that breaks the page
 *     it measures is worse than no toolbar.
 */
(function () {
    'use strict';

    // Delivered twice on one page (a partial that re-emits it, a SPA shell that
    // also server-renders) must not mean two toolbars.
    if (typeof window === 'undefined' || window.__pramnosDebugBar) {
        return;
    }

    /** How many requests are kept. Enough to see a pattern, not to hoard. */
    var HISTORY = 50;

    /** Where the "I hid the bar" choice lives, shared by both deliveries. */
    var HIDDEN_KEY = 'pramnos.debugbar.hidden';

    /** Keys whose values are replaced before they can be read or screenshotted. */
    var SECRET = /pass|secret|token|apikey|api_key|authorization|cookie|csrf/i;

    /** Segment colours for the timeline (Catppuccin). */
    var PALETTE = ['#89b4fa', '#cba6f7', '#a6e3a1', '#f9e2af', '#fab387', '#f38ba8', '#94e2d5'];

    /**
     * Tab order, and which payload key feeds each.
     *
     * Fixed rather than derived from the payload's key order: a collector added
     * by an application would otherwise land wherever PHP happened to put it,
     * and the tab a reader wants must not move between requests.
     */
    var TABS = [
        { key: 'queries',    label: 'SQL' },
        { key: 'timers',     label: 'Time' },
        { key: 'route',      label: 'Route' },
        { key: 'session',    label: 'Session' },
        { key: 'logs',       label: 'Logs' },
        { key: 'views',      label: 'Views' },
        { key: 'models',     label: 'Models' },
        { key: 'migrations', label: 'Migrations' },
        { key: 'exceptions', label: 'Exceptions' }
    ];

    /** @type {Array<Object>} One per request, oldest first. */
    var entries = [];

    var selected = -1;          // entry whose tabs are shown; -1 = none yet
    var activeTab = null;       // 'requests' or a payload key; null = panel closed
    var root = null;
    var tabsEl = null;
    var panelEl = null;
    var infoEl = null;
    var handleEl = null;
    var styleEl = null;

    // ── Escaping ────────────────────────────────────────────────────────────

    /** Escape for use as text. */
    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    /** Escape for use inside a double-quoted attribute. */
    function escAttr(value) {
        return esc(value).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // ── Payload readers ─────────────────────────────────────────────────────

    /**
     * Server time for an entry.
     *
     * `payload.request.time` is the reliable copy: the top-level `time` and
     * `memory` keys are overwritten by any collector registered under the same
     * name, and one is — MemoryCollector, whose data is an object. Reading the
     * top-level key printed "[object Object]MB".
     */
    function serverMs(entry) {
        var p = entry.payload || {};
        if (p.request && typeof p.request.time === 'number') {
            return p.request.time;
        }
        return typeof p.time === 'number' ? p.time : null;
    }

    /** Peak memory in MB, from the copy no collector can overwrite. */
    function memoryMb(entry) {
        var p = entry.payload || {};
        return p.request && typeof p.request.memory === 'number' ? p.request.memory : null;
    }

    /** The statements an entry carries, however the collector labelled them. */
    function queriesOf(entry) {
        var q = (entry.payload || {}).queries;
        if (!q) {
            return [];
        }
        return q.queries || q.statements || [];
    }

    /** How many statements ran. */
    function queryCount(entry) {
        var q = (entry.payload || {}).queries;
        if (!q) {
            return null;
        }
        return typeof q.count === 'number' ? q.count : queriesOf(entry).length;
    }

    /** Local wall clock to the millisecond, to line up against a server log. */
    function clockTime(date) {
        function pad(n, w) {
            return String(n).padStart(w, '0');
        }
        return pad(date.getHours(), 2) + ':' + pad(date.getMinutes(), 2) + ':'
            + pad(date.getSeconds(), 2) + '.' + pad(date.getMilliseconds(), 3);
    }

    /** Replace secret-looking values, at any depth. */
    function mask(value) {
        if (Array.isArray(value)) {
            return value.map(mask);
        }
        if (value && typeof value === 'object') {
            var out = {};
            Object.keys(value).forEach(function (key) {
                out[key] = SECRET.test(key) ? '***' : mask(value[key]);
            });
            return out;
        }
        return value;
    }

    // ── Recording ───────────────────────────────────────────────────────────

    /**
     * Note one request.
     *
     * A payload is what proves the toolbar is meant to exist: the server only
     * attaches one in development. Once one has arrived, later requests are
     * recorded whether or not they carry their own — a 204 from a save has no
     * body to put a payload in, and it is exactly the call somebody wants to
     * see. In production none ever arrives, so nothing is recorded, the DOM is
     * never touched, and no toolbar appears.
     *
     * @param {string}      method
     * @param {string}      path
     * @param {number}      status
     * @param {Object|null} payload  The `_debug` data, or null
     * @param {Object}      [extra]  { ms, body, kind }
     */
    function record(method, path, status, payload, extra) {
        try {
            extra = extra || {};
            if (!payload && entries.length === 0) {
                return;
            }

            entries.push({
                kind: extra.kind || 'xhr',
                method: method,
                path: path,
                status: status,
                payload: payload || null,
                at: new Date(),
                ms: typeof extra.ms === 'number' ? extra.ms : null,
                body: extra.body === undefined ? null : extra.body
            });
            if (entries.length > HISTORY) {
                entries.shift();
                if (selected > 0) {
                    selected--;
                }
            }

            // A new request becomes the selected one only while the reader is not
            // looking at something else; pulling the panel out from under them
            // mid-read is worse than a stale selection.
            if (activeTab === null || activeTab === 'requests' || selected === entries.length - 2) {
                selected = entries.length - 1;
            }

            ensureBar();
            render();
        } catch (e) {
            /* instrumentation never breaks the page */
        }
    }

    // ── Chrome ──────────────────────────────────────────────────────────────

    /** The stylesheet, injected once. */
    function css() {
        return ''
        + '#pramnos-debugbar{position:fixed;bottom:0;left:0;right:0;z-index:2147483000;'
        + 'font:12px/1.4 ui-monospace,SFMono-Regular,Menlo,monospace;color:#cdd6f4;'
        + 'background:#1e1e2e;border-top:2px solid #89b4fa}'
        + '#pdb-bar{display:flex;align-items:center;padding:0 8px;height:28px;gap:4px;'
        + 'overflow-x:auto;white-space:nowrap}'
        + '#pdb-brand{color:#89b4fa;font-weight:bold;margin-right:8px;flex-shrink:0}'
        + '.pdb-tab{background:none;border:none;color:#cdd6f4;cursor:pointer;padding:2px 8px;'
        + 'border-radius:4px;font:inherit;flex-shrink:0}'
        + '.pdb-tab:hover,.pdb-tab.pdb-active{background:#313244;color:#89b4fa}'
        + '.pdb-tab-count{background:#45475a;border-radius:8px;padding:0 5px;margin-left:4px;font-size:10px}'
        + '#pdb-info{margin-left:auto;display:flex;gap:5px;flex-shrink:0;align-items:center}'
        + '.pdb-chip{font-size:10px;color:#6c7086;padding:1px 6px;background:#313244;border-radius:3px}'
        + '.pdb-devpanel{color:#a6e3a1;text-decoration:none;padding:2px 8px;font:inherit;flex-shrink:0;margin-left:6px}'
        + '.pdb-devpanel:hover{color:#cba6f7}'
        + '.pdb-close{background:none;border:none;color:#f38ba8;cursor:pointer;margin-left:4px;'
        + 'font:inherit;flex-shrink:0}'
        + '#pdb-restore{position:fixed;right:8px;bottom:8px;z-index:2147483000;display:none;'
        + 'background:#1e1e2e;color:#89b4fa;border:1px solid #313244;border-radius:6px;padding:2px 7px;'
        + 'cursor:pointer;font:12px/1.4 ui-monospace,monospace;box-shadow:0 2px 6px rgba(0,0,0,.4)}'
        + '#pdb-restore:hover{color:#cba6f7;border-color:#cba6f7}'
        + '#pdb-panel{max-height:320px;overflow:auto;padding:8px 12px;background:#181825;'
        + 'border-top:1px solid #313244;display:none}'
        + '#pdb-panel p{margin:0 0 6px}'
        + '.pdb-table{width:100%;border-collapse:collapse;font-size:11px}'
        + '.pdb-table th{background:#313244;padding:4px 8px;text-align:left;color:#89b4fa}'
        + '.pdb-table td{padding:3px 8px;border-bottom:1px solid #1e1e2e;vertical-align:top}'
        + '.pdb-row{cursor:pointer}'
        + '.pdb-row:hover td{background:#1e1e2e}'
        + '.pdb-row.pdb-selected td{background:#313244}'
        + '.pdb-time{white-space:nowrap;color:#a6e3a1;min-width:50px}'
        + '.pdb-slow .pdb-time{color:#f38ba8}'
        + '.pdb-cached{color:#a6e3a1!important;font-size:9px;letter-spacing:.05em;font-weight:bold}'
        + '.pdb-sql{word-break:break-all}'
        + '.pdb-copy{background:none;border:1px solid #45475a;color:#6c7086;cursor:pointer;'
        + 'font:10px ui-monospace,monospace;padding:0 3px;border-radius:2px;line-height:14px;'
        + 'vertical-align:middle}'
        + '.pdb-copy:hover{background:#313244;color:#cba6f7;border-color:#cba6f7}'
        + '.pdb-copy.pdb-copied{color:#a6e3a1;border-color:#a6e3a1}'
        + '.pdb-copy-all{color:#cba6f7;padding:1px 6px;margin-left:6px}'
        + '.pdb-dl{display:grid;grid-template-columns:auto 1fr;gap:2px 12px;margin:0}'
        + '.pdb-dl dt{color:#6c7086}'
        + '.pdb-dl dd{margin:0}'
        + '.pdb-timeline{position:relative;height:16px;background:#11111b;border-radius:3px;overflow:hidden}'
        + '.pdb-tl-seg{position:absolute;top:0;height:16px;font-size:9px;line-height:16px;'
        + 'text-align:center;color:#11111b;overflow:hidden}'
        + '.pdb-s-2{color:#a6e3a1}.pdb-s-3{color:#89b4fa}.pdb-s-4{color:#fab387}'
        + '.pdb-s-5{color:#f38ba8}.pdb-s-0{color:#f38ba8}'
        + '.pdb-level-error,.pdb-level-critical{color:#f38ba8}'
        + '.pdb-level-warning{color:#fab387}'
        + '.pdb-pre{margin:2px 0 0;white-space:pre-wrap;word-break:break-all;background:#1e1e2e;'
        + 'padding:6px;border-radius:3px;max-height:240px;overflow:auto}'
        + '.pdb-muted{color:#6c7086}';
    }

    /** Build the bar the first time there is something to show. */
    function ensureBar() {
        if (root) {
            return;
        }

        styleEl = document.createElement('style');
        styleEl.textContent = css();

        root = document.createElement('div');
        root.id = 'pramnos-debugbar';
        root.innerHTML = '<div id="pdb-bar"><span id="pdb-brand">&#9881; Pramnos</span>'
            + '<span id="pdb-tabs"></span><span id="pdb-info"></span>'
            // The DevPanel is a framework route, so the link is worth having in
            // both deliveries: reaching it otherwise means remembering the URL.
            + '<a class="pdb-devpanel" href="/devpanel" title="DevPanel">&#128270; DevPanel</a>'
            + '<button class="pdb-close" id="pdb-close-btn" title="Hide the toolbar">&#x2715;</button></div>'
            + '<div id="pdb-panel"></div>';

        // Hiding the bar has to leave something to bring it back, and it has to
        // live outside the bar: nested inside, hiding the bar would hide the only
        // way back.
        handleEl = document.createElement('button');
        handleEl.id = 'pdb-restore';
        handleEl.title = 'Show the Pramnos toolbar';
        handleEl.textContent = '⚙';
        handleEl.addEventListener('click', function () {
            setHidden(false);
        });

        document.head.appendChild(styleEl);
        document.body.appendChild(root);
        document.body.appendChild(handleEl);

        tabsEl = root.querySelector('#pdb-tabs');
        panelEl = root.querySelector('#pdb-panel');
        infoEl = root.querySelector('#pdb-info');

        // One delegated listener: rows and buttons are redrawn constantly, and
        // per-element handlers would leak with them.
        root.addEventListener('click', onClick);

        setHidden(isHiddenStored());
    }

    /** Whether the bar was hidden on a previous page. */
    function isHiddenStored() {
        try {
            return localStorage.getItem(HIDDEN_KEY) === '1';
        } catch (e) {
            // Reading storage throws outright in Safari's private mode and on a
            // blocked origin. Not remembering is not a reason to fail.
            return false;
        }
    }

    /**
     * Show or hide the whole bar, and remember which.
     *
     * The page's bottom padding is released with the bar, by the same code path
     * that hides it — when those were separate, a hidden toolbar left a 36px gap
     * that nothing on screen explained.
     */
    function setHidden(hidden) {
        if (!root) {
            return;
        }

        root.style.display = hidden ? 'none' : '';
        if (handleEl) {
            handleEl.style.display = hidden ? 'block' : 'none';
        }
        document.body.style.paddingBottom = hidden ? '' : '30px';

        try {
            if (hidden) {
                localStorage.setItem(HIDDEN_KEY, '1');
            } else {
                localStorage.removeItem(HIDDEN_KEY);
            }
        } catch (e) {
            /* see isHiddenStored */
        }
    }

    // ── Rendering ───────────────────────────────────────────────────────────

    /** Redraw the tabs, the info strip and the open panel. */
    function render() {
        if (!root) {
            return;
        }

        var entry = entries[selected] || null;

        var html = '<button class="pdb-tab' + (activeTab === 'requests' ? ' pdb-active' : '')
            + '" data-panel="requests">requests<span class="pdb-tab-count">'
            + entries.length + '</span></button>';

        TABS.forEach(function (tab) {
            var data = entry && entry.payload ? entry.payload[tab.key] : null;
            if (!data) {
                return;
            }
            var count = tabCount(tab.key, data);
            html += '<button class="pdb-tab' + (activeTab === tab.key ? ' pdb-active' : '')
                + '" data-panel="' + tab.key + '">' + esc(tab.label)
                + (count === null ? '' : '<span class="pdb-tab-count">' + count + '</span>')
                + '</button>';
        });

        tabsEl.innerHTML = html;
        infoEl.innerHTML = infoStrip(entry);

        if (activeTab === null) {
            panelEl.style.display = 'none';
            return;
        }

        panelEl.style.display = 'block';
        panelEl.innerHTML = activeTab === 'requests'
            ? renderRequests()
            : renderTab(activeTab, entry);
    }

    /** The number worth putting on a tab, or null when a count means nothing. */
    function tabCount(key, data) {
        switch (key) {
            case 'queries':
                return typeof data.count === 'number' ? data.count : (data.queries || []).length;
            case 'logs':
                return typeof data.count === 'number' ? data.count : (data.entries || []).length;
            case 'views':
            case 'models':
            case 'exceptions':
                return typeof data.count === 'number' ? data.count : null;
            case 'migrations':
                return (data.this_request || []).length || null;
            case 'session':
                return data.active ? Object.keys(data.data || {}).length : null;
            default:
                return null;
        }
    }

    /** Time, memory and route for the selected entry. */
    function infoStrip(entry) {
        if (!entry) {
            return '';
        }
        var bits = [];
        var ms = serverMs(entry);
        if (ms !== null) {
            bits.push('<span class="pdb-chip">' + ms + 'ms server</span>');
        }
        if (entry.ms !== null && entry.ms !== undefined) {
            bits.push('<span class="pdb-chip">' + entry.ms + 'ms client</span>');
        }
        var mb = memoryMb(entry);
        if (mb !== null) {
            bits.push('<span class="pdb-chip">' + mb + 'MB</span>');
        }
        bits.push('<span class="pdb-chip">' + esc(entry.method) + ' ' + esc(entry.path) + '</span>');
        return bits.join('');
    }

    /** The list of requests — the tab that makes the others make sense. */
    function renderRequests() {
        var rows = '';
        for (var i = entries.length - 1; i >= 0; i--) {
            var e = entries[i];
            var q = queryCount(e);
            var ms = serverMs(e);
            rows += '<tr class="pdb-row' + (i === selected ? ' pdb-selected' : '') + '" data-entry="' + i + '">'
                + '<td class="pdb-muted">' + clockTime(e.at) + '</td>'
                + '<td>' + esc(e.method) + '</td>'
                + '<td class="pdb-sql">' + esc(e.path) + (e.kind === 'page' ? ' <span class="pdb-muted">(page)</span>' : '') + '</td>'
                + '<td class="pdb-s-' + String(e.status).charAt(0) + '">' + esc(e.status) + '</td>'
                + '<td class="pdb-time">' + (ms === null ? '—' : ms + 'ms') + '</td>'
                + '<td class="pdb-time">' + (q === null ? '—' : q) + '</td>'
                + '</tr>';
        }

        return '<p class="pdb-muted">Click a request to see what it did.</p>'
            + '<table class="pdb-table"><thead><tr><th>At</th><th>Method</th><th>Path</th>'
            + '<th>Status</th><th>Server</th><th>SQL</th></tr></thead><tbody>' + rows + '</tbody></table>';
    }

    /** Draw one collector's data. */
    function renderTab(key, entry) {
        var data = entry && entry.payload ? entry.payload[key] : null;
        if (!data) {
            return '<p class="pdb-muted">Nothing recorded for this request.</p>';
        }
        if (data.error) {
            // A collector that threw is reported, not hidden: instrumentation
            // failing silently is how a blank panel gets misread as "no data".
            return '<p style="color:#f38ba8">This collector failed: ' + esc(data.error) + '</p>';
        }

        switch (key) {
            case 'queries':    return renderQueries(data);
            case 'timers':     return renderTimers(data);
            case 'route':      return renderKeyValue(data);
            case 'session':    return renderSession(data);
            case 'logs':       return renderLogs(data);
            case 'views':      return renderViews(data);
            case 'models':     return renderModels(data);
            case 'migrations': return renderMigrations(data);
            case 'exceptions': return renderExceptions(data);
            default:           return '<pre class="pdb-pre">' + esc(JSON.stringify(mask(data), null, 2)) + '</pre>';
        }
    }

    /** A copy button carrying the given text. */
    function copyButton(text, label) {
        return '<button class="pdb-copy" title="Copy" data-copy="' + escAttr(text) + '">'
            + (label || '⎘') + '</button>';
    }

    function renderQueries(data) {
        var list = data.queries || data.statements || [];
        var rows = '';
        var all = [];

        list.forEach(function (q) {
            var sql = q.sql || q.query || q.statement || '';
            var ms = q.time === undefined ? (q.duration || 0) : q.time;
            var cached = !!q.from_cache;
            // A cached statement took no time because it did not run. Showing it
            // as 0ms reads as "instant", and the difference between those two is
            // the reason for opening the panel.
            rows += '<tr class="' + (!cached && ms > 100 ? 'pdb-slow' : '') + '">'
                + (cached ? '<td class="pdb-time pdb-cached">CACHE</td>' : '<td class="pdb-time">' + ms + 'ms</td>')
                + '<td class="pdb-sql">' + copyButton(sql) + ' ' + esc(sql) + '</td></tr>';
            all.push('-- ' + (cached ? 'CACHE' : ms + 'ms') + '\n' + sql + ';');
        });

        var count = typeof data.count === 'number' ? data.count : list.length;
        var cachedCount = data.cached || 0;
        var info = cachedCount > 0 ? ' (' + (count - cachedCount) + ' live · ' + cachedCount + ' from cache)' : '';
        var truncated = data.truncated
            ? ' <span class="pdb-muted">' + data.truncated + ' more not shown</span>'
            : '';
        var copyAll = count > 0
            ? ' ' + copyButton(all.join('\n\n'), '⎘ Copy all').replace('class="pdb-copy"', 'class="pdb-copy pdb-copy-all"')
            : '';

        return '<p><strong>' + count + ' queries' + info + '</strong> — ' + (data.total_ms || 0)
            + 'ms total' + copyAll + truncated + '</p>'
            + '<table class="pdb-table"><thead><tr><th>Time</th><th>SQL</th></tr></thead><tbody>'
            + rows + '</tbody></table>';
    }

    function renderTimers(data) {
        var total = Number(data.request_ms || 0);
        var named = data.named_timers || [];
        var html = '<p><strong>Request:</strong> ' + total + 'ms '
            + '<span class="pdb-muted">started ' + esc(data.start_time || '') + '</span></p>';

        if (!named.length) {
            return html;
        }

        html += '<div class="pdb-timeline">';
        named.forEach(function (t, i) {
            var left = total > 0 ? Math.min(100, (t.offset_ms / total) * 100) : 0;
            var width = total > 0 ? Math.max(0.5, Math.min(100 - left, (t.ms / total) * 100)) : 1;
            html += '<div class="pdb-tl-seg" style="left:' + left.toFixed(2) + '%;width:'
                + width.toFixed(2) + '%;background:' + PALETTE[i % PALETTE.length] + '" title="'
                + escAttr(t.name + ': ' + t.ms + 'ms') + '">' + (width > 4 ? esc(t.name) : '') + '</div>';
        });
        html += '</div>';

        var rows = '';
        named.forEach(function (t, i) {
            var pct = total > 0 ? Math.round((t.ms / total) * 1000) / 10 : 0;
            rows += '<tr><td><span style="display:inline-block;width:10px;height:10px;border-radius:2px;'
                + 'background:' + PALETTE[i % PALETTE.length] + ';margin-right:4px"></span>' + esc(t.name) + '</td>'
                + '<td class="pdb-time">' + t.ms + 'ms</td><td class="pdb-muted">' + pct + '%</td></tr>';
        });

        return html + '<table class="pdb-table" style="margin-top:8px"><thead><tr><th>Phase</th>'
            + '<th>Duration</th><th>%</th></tr></thead><tbody>' + rows + '</tbody></table>';
    }

    function renderKeyValue(data) {
        var rows = '';
        Object.keys(data).forEach(function (k) {
            var v = data[k];
            rows += '<tr><td>' + esc(k) + '</td><td class="pdb-sql">'
                + esc(Array.isArray(v) ? v.join(', ') : (v && typeof v === 'object' ? JSON.stringify(v) : v))
                + '</td></tr>';
        });
        return '<table class="pdb-table"><tbody>' + rows + '</tbody></table>';
    }

    function renderSession(data) {
        if (!data.active) {
            return '<p class="pdb-muted">No active session.</p>';
        }
        var rows = '';
        var values = data.data || {};
        Object.keys(values).forEach(function (k) {
            rows += '<tr><td>' + esc(k) + '</td><td class="pdb-sql">' + esc(values[k]) + '</td></tr>';
        });
        return '<p><strong>Session ID:</strong> ' + esc(data.session_id || '') + '</p>'
            + '<table class="pdb-table"><thead><tr><th>Key</th><th>Value</th></tr></thead><tbody>'
            + rows + '</tbody></table>';
    }

    function renderLogs(data) {
        var rows = '';
        (data.entries || []).forEach(function (e) {
            var level = e.level || 'info';
            var at = e.time ? clockTime(new Date(Number(e.time) * 1000)) : '';
            rows += '<tr><td class="pdb-muted">' + esc(at) + '</td>'
                + '<td class="pdb-level-' + esc(level) + '">' + esc(level) + '</td>'
                + '<td class="pdb-sql">' + esc(e.message || '') + '</td></tr>';
        });
        return '<table class="pdb-table"><thead><tr><th>Time</th><th>Level</th><th>Message</th></tr>'
            + '</thead><tbody>' + (rows || '<tr><td colspan="3" class="pdb-muted">No log entries</td></tr>')
            + '</tbody></table>';
    }

    function renderViews(data) {
        var rows = '';
        (data.views || []).forEach(function (v) {
            var ms = v.render_ms || 0;
            var cached = !!v.from_cache;
            rows += '<tr class="' + (!cached && ms > 50 ? 'pdb-slow' : '') + '">'
                + (cached ? '<td class="pdb-time pdb-cached">CACHE</td>' : '<td class="pdb-time">' + ms + 'ms</td>')
                + '<td>' + esc(v.view || '') + '</td><td class="pdb-sql">' + esc(v.template || '') + '</td></tr>';
        });
        var count = data.count || 0;
        var cachedCount = data.cached || 0;
        var info = cachedCount > 0 ? ' (' + (count - cachedCount) + ' rendered · ' + cachedCount + ' from cache)' : '';
        return '<p><strong>' + count + ' template(s)' + info + '</strong></p>'
            + '<table class="pdb-table"><thead><tr><th>Time</th><th>View</th><th>Template</th></tr></thead>'
            + '<tbody>' + (rows || '<tr><td colspan="3" class="pdb-muted">No views rendered</td></tr>')
            + '</tbody></table>';
    }

    function renderModels(data) {
        var rows = '';
        (data.operations || []).forEach(function (op) {
            rows += '<tr><td>' + esc(op.class || '') + '</td><td>' + esc(op.table || '') + '</td>'
                + '<td>' + esc(op.op || '') + '</td><td>' + esc(op.key === undefined || op.key === null ? '—' : op.key)
                + '</td></tr>';
        });
        return '<p><strong>' + (data.count || 0) + ' model class(es)</strong> — '
            + (data.ops || 0) + ' operation(s)</p>'
            + '<table class="pdb-table"><thead><tr><th>Class</th><th>Table</th><th>Op</th><th>Key</th></tr>'
            + '</thead><tbody>' + (rows || '<tr><td colspan="4" class="pdb-muted">No model operations</td></tr>')
            + '</tbody></table>';
    }

    function renderMigrations(data) {
        var ran = data.this_request || [];
        if (!ran.length) {
            return '<p class="pdb-muted">No migrations ran this request.</p>';
        }
        var rows = '';
        ran.forEach(function (m) {
            var failed = m.status === 'failed';
            rows += '<tr class="' + (failed ? 'pdb-slow' : '') + '">'
                + (failed
                    ? '<td class="pdb-time" style="color:#f38ba8">FAILED</td>'
                    : '<td class="pdb-time">' + (m.ms || 0) + 'ms</td>')
                + '<td>' + esc(m.slug || '') + '</td></tr>';
        });
        return '<p><strong>' + ran.length + ' migration(s) ran this request</strong></p>'
            + '<table class="pdb-table"><thead><tr><th>Time</th><th>Migration</th></tr></thead><tbody>'
            + rows + '</tbody></table>';
    }

    function renderExceptions(data) {
        var rows = '';
        (data.items || []).forEach(function (item) {
            rows += '<tr><td style="color:#f38ba8;white-space:nowrap">'
                + (item.type === 'php_error' ? 'PHP' : 'EXC') + '</td>'
                + '<td style="color:#fab387">' + esc(item.class || '') + '</td>'
                + '<td>' + esc(item.message || '') + '</td>'
                + '<td class="pdb-sql">' + esc(item.file || '') + ':' + esc(item.line || 0) + '</td></tr>';
        });
        return '<p><strong>' + (data.count || 0) + ' exception(s) / error(s)</strong></p>'
            + '<table class="pdb-table"><thead><tr><th>Type</th><th>Class</th><th>Message</th>'
            + '<th>Location</th></tr></thead><tbody>'
            + (rows || '<tr><td colspan="4" style="color:#a6e3a1">No exceptions</td></tr>')
            + '</tbody></table>';
    }

    // ── Interaction ─────────────────────────────────────────────────────────

    /** Handle every click inside the bar. */
    function onClick(event) {
        try {
            var copy = event.target.closest('.pdb-copy');
            if (copy) {
                event.stopPropagation();
                copyText(copy.dataset.copy, copy);
                return;
            }

            var tab = event.target.closest('.pdb-tab');
            if (tab) {
                // Clicking the open tab closes the panel — the bar stays, which is
                // what the ✕ is for.
                activeTab = activeTab === tab.dataset.panel ? null : tab.dataset.panel;
                render();
                return;
            }

            if (event.target.closest('#pdb-close-btn')) {
                setHidden(true);
                return;
            }

            var row = event.target.closest('.pdb-row');
            if (row) {
                selected = Number(row.dataset.entry);
                // Land on the tab that answers "what did it do", not back on the
                // list they just chose from.
                activeTab = 'queries';
                render();
            }
        } catch (e) {
            /* a click handler that throws must not take the page with it */
        }
    }

    /** Put text on the clipboard, and say so on the button. */
    function copyText(text, button) {
        var original = button.innerHTML;
        var done = function () {
            button.classList.add('pdb-copied');
            button.textContent = '✓';
            setTimeout(function () {
                button.classList.remove('pdb-copied');
                button.innerHTML = original;
            }, 1500);
        };

        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(done, done);
            return;
        }

        var area = document.createElement('textarea');
        area.value = text;
        area.style.cssText = 'position:fixed;opacity:0';
        document.body.appendChild(area);
        area.select();
        try {
            document.execCommand('copy');
        } catch (e) {
            /* clipboard refused; the button simply does not confirm */
        }
        document.body.removeChild(area);
        done();
    }

    // ── The requests a page makes after it renders ───────────────────────────

    /**
     * Wrap fetch and XMLHttpRequest so later requests are recorded too.
     *
     * Everything else about a server-rendered page describes the request that
     * built it. But a datatable pages and sorts, a form saves, a widget polls —
     * and those ran queries nobody was watching.
     */
    function wrapTransports() {
        var nativeFetch = window.fetch;
        if (typeof nativeFetch === 'function') {
            window.fetch = function (input, init) {
                var started = now();
                var method = (init && init.method) || (input && input.method) || 'GET';
                var url = typeof input === 'string' ? input : (input && input.url) || '';
                var result = nativeFetch.apply(this, arguments);

                try {
                    return result.then(function (response) {
                        harvest(method, url, response, Math.round(now() - started));
                        return response;
                    });
                } catch (e) {
                    return result;
                }
            };
        }

        var NativeXhr = window.XMLHttpRequest;
        if (typeof NativeXhr === 'function') {
            var open = NativeXhr.prototype.open;
            var send = NativeXhr.prototype.send;

            NativeXhr.prototype.open = function (method, url) {
                try {
                    this.__pdbMethod = method;
                    this.__pdbUrl = url;
                } catch (e) {
                    /* a frozen instance is not a reason to fail the request */
                }
                return open.apply(this, arguments);
            };

            NativeXhr.prototype.send = function () {
                var xhr = this;
                var started = now();
                try {
                    xhr.addEventListener('load', function () {
                        try {
                            var payload = fromText(xhr.responseText);
                            record(xhr.__pdbMethod || 'GET', xhr.__pdbUrl || '', xhr.status,
                                payload, { ms: Math.round(now() - started) });
                        } catch (e) {
                            /* see above */
                        }
                    });
                } catch (e) {
                    /* see above */
                }
                return send.apply(this, arguments);
            };
        }
    }

    /** Monotonic-ish milliseconds, wherever they come from. */
    function now() {
        return typeof performance !== 'undefined' && performance.now
            ? performance.now()
            : Date.now();
    }

    /**
     * Read a response's `_debug`, without consuming the body.
     *
     * Only through `clone()`: the application still has to be able to read its
     * own response.
     */
    function harvest(method, url, response, ms) {
        try {
            var header = response.headers && response.headers.get
                ? response.headers.get('X-Pramnos-Debug')
                : null;

            response.clone().text().then(function (text) {
                var payload = fromText(text);
                if (!payload && header) {
                    // A 204, a redirect, an HTML fragment or a top-level JSON
                    // array has nowhere to put a `_debug` key. The header carries
                    // a summary — never statements, because headers land in access
                    // logs and every proxy in between.
                    payload = summaryFromHeader(header);
                }
                record(method, url, response.status, payload, { ms: ms });
            }, function () {
                record(method, url, response.status, header ? summaryFromHeader(header) : null, { ms: ms });
            });
        } catch (e) {
            /* never interfere with the response */
        }
    }

    /** The `_debug` key of a JSON object body, or null. */
    function fromText(text) {
        try {
            var parsed = JSON.parse(text);
            return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
                ? (parsed._debug || null)
                : null;
        } catch (e) {
            return null;
        }
    }

    /** Turn the `X-Pramnos-Debug` summary into a payload shape. */
    function summaryFromHeader(header) {
        var out = {};
        String(header).split(';').forEach(function (part) {
            var pair = part.split('=');
            if (pair.length === 2) {
                out[pair[0].trim()] = pair[1].trim();
            }
        });

        var ms = parseFloat(out.time);
        return {
            request: {
                time: isNaN(ms) ? null : ms,
                memory: out.memory ? parseFloat(out.memory) : null
            },
            queries: out.queries === undefined ? undefined : { count: Number(out.queries), queries: [] },
            route: out.route ? { route: out.route } : undefined
        };
    }

    // ── Boot ────────────────────────────────────────────────────────────────

    /**
     * Seed the page's own request from the data island, if there is one.
     *
     * Read from a `<div hidden>` rather than a `<script type="application/json">`
     * so there is nothing for a strict Content-Security-Policy to weigh up: a
     * data island inside a script element is handled differently by different
     * browsers, and this has to work on every install.
     */
    function boot() {
        try {
            var island = document.getElementById('pramnos-debug-data');
            if (!island) {
                // No island means a SPA: its shell never went through the
                // middleware, so there is no page request to seed — and no
                // transport wrapping either. The application's API client calls
                // record() itself, and wrapping fetch as well would record every
                // one of those twice.
                return;
            }

            var payload = JSON.parse(island.textContent || '{}');
            record(
                payload.request_method || 'GET',
                payload.request_path || (location.pathname + location.search),
                payload.status_code || 200,
                payload,
                { kind: 'page', ms: null }
            );
            wrapTransports();
        } catch (e) {
            /* a toolbar that cannot boot must still leave the page alone */
        }
    }

    window.__pramnosDebugBar = { record: record, boot: boot };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
