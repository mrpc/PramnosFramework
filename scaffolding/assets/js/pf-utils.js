/**
 * PramnosFramework UI utilities — loaded by all scaffold themes.
 *
 * All behaviour is wired through data-attributes and delegated event listeners
 * so that no inline event handlers (onclick="…") are needed. Inline handlers
 * are blocked by the framework's Content-Security-Policy (script-src 'self'
 * 'nonce-…') because a nonce cannot apply to attribute handlers — keep new
 * behaviours here rather than inline in views.
 *
 * Attributes handled:
 *   data-confirm="message"      — show confirm() before following a link or submitting a button;
 *                                 on a <form>, once when it is sent, not at clicks inside it
 *   data-pf-fill-setting        — copy this row's setting name/value into the edit form
 *                                 below it (with data-pf-fill-value)
 *   data-copy-prev              — copy the value of the immediately preceding <input> to clipboard
 *   data-toggle-type="inputId"  — toggle password/text on the target <input>
 *   data-modal-show="elementId" — remove class "hidden" from target element
 *   data-modal-hide="elementId" — add class "hidden" to target element
 *   data-href="url"             — make a whole element (e.g. a table row) clickable;
 *                                 clicks on nested links/buttons/inputs are ignored
 *   data-stats-open             — open the stats modal and load JSON from data-stats-url
 *   data-stats-url="url"        — (with data-stats-open) endpoint returning the stats JSON
 *   data-stats-close            — close the stats modal
 *   data-pf-modal[="label"]     — on a link: open its page in a <dialog>, fetched with
 *                                 X-Pf-Fragment: 1 (the content without the theme); a plain
 *                                 navigation where there is no <dialog> or the fetch fails
 *
 * Same-origin API calls made from here send `X-CSRF-Token` from `<meta name="csrf">`
 * (window.pfApiHeaders) — that is what authenticates a page against its own API.
 *
 *   meta[name=csrf-refresh]     — where a page ten minutes old asks for the session's current
 *                                 CSRF token, on coming back into view and before a form is
 *                                 sent; window.pfRefreshCsrf()
 *
 *   data-pf-omnibox             — cross-entity search box (see Html\SearchBox); the
 *                                 endpoint, minimum length, debounce and the loading /
 *                                 empty strings come from data-pf-omnibox-* attributes
 *   data-pf-notifications       — the notification bell (see Html\NotificationBell): the
 *                                 count, the list, read and read-all, polled while the tab
 *                                 is visible and refreshed on `pf:notifications:refresh`
 *   meta[name=pf-realtime]      — the page's live connection (window.pfRealtime): the Pusher
 *                                 protocol to broadcast:serve or Pusher/Reverb; the bell
 *                                 listens on it for notification.created
 *   meta[name=pf-client-errors] — where uncaught errors and rejections are reported
 *                                 (see Logs\ClientErrors); window.pfReportError(error)
 *   data-pf-dashboard           — the dashboard each person arranges (DashboardController):
 *                                 move, resize, hide, add, drag; each change saved at once
 *   data-pf-chart-figure        — a chart (see Html\Chart): drawn with Chart.js in the
 *                                 theme's colours, again when the theme changes
 *
 * Stats modal markup contract (rendered by the view, styled by the theme):
 *   #pf-stats-overlay  — the full-screen overlay (toggled via style.display)
 *   #pf-stats-body     — the container the fetched stats are rendered into
 */
(function () {
    'use strict';

    // Run as the realtime SharedWorker (no document): only the shared connection, below.
    if (typeof document === 'undefined') {
        if (typeof self !== 'undefined') { realtimeWorker(); }
        return;
    }

    /** This file's own address, which the realtime SharedWorker is started from. */
    var SELF_SRC = document.currentScript && document.currentScript.src ? document.currentScript.src : '';

    /**
     * The headers a same-origin call to this application's own API needs.
     *
     * The API expects an `apikey` header, which a page cannot carry: anything the
     * document can read, a reader of the document can read. What it can prove
     * instead is that it *is* our document — by echoing the CSRF token printed in
     * `<meta name="csrf">`, which a cross-site page cannot read. `ApiAuthMiddleware`
     * accepts that pair in place of a key.
     *
     * With no meta tag the request goes out without the header and the API answers
     * 403: the tag is what a server-rendered theme has to print, not something this
     * can invent.
     */
    function pfApiHeaders(extra) {
        var headers = extra || {};
        var meta = document.querySelector('meta[name="csrf"]');
        if (meta && meta.content) { headers['X-CSRF-Token'] = meta.content; }
        return headers;
    }

    window.pfApiHeaders = pfApiHeaders;

    // ══════════════════════════════════════════════════════════════════════════
    // <meta name="csrf-refresh" content="…/Csrf"> — a page open a while takes the current token
    //
    // A form keeps the token it was drawn with. Signing in in another tab, or a session that
    // expired while the page sat open, gives the session a new one, and the form is then refused
    // with what was typed lost. So once the page's token is ten minutes old, it is asked for again
    // when the tab comes back into view, and before a form carrying one is sent, and every
    // `_csrf_token` field, `<meta name="csrf">` and form-token field (Session::getTokenField(): a
    // hidden input named by the session's 64-hex token) takes the answer. Never on a timer: a page that
    // polled would keep an idle session alive for ever. `window.pfRefreshCsrf()` asks now.
    // ══════════════════════════════════════════════════════════════════════════
    (function () {
        var meta = document.querySelector('meta[name="csrf-refresh"]');
        var url  = meta ? meta.getAttribute('content') : '';
        if (!url || typeof window.fetch !== 'function') { return; }

        var STALE     = 10 * 60 * 1000;
        var fetchedAt = Date.now();
        var pending   = null;

        /** Everything on the page that carries the synchronizer token. */
        function holders() {
            return document.querySelectorAll('input[name="_csrf_token"], meta[name="csrf"]');
        }

        /** The form-token fields within `root`: hidden, and named by the session's token. */
        function formFields(root) {
            return Array.prototype.filter.call((root || document).querySelectorAll('input[type="hidden"]'), function (input) {
                return /^[0-9a-f]{64}$/.test(input.getAttribute('name') || '');
            });
        }

        /** Whether `root` carries a token of either kind. */
        function carries(root) {
            return (root === document ? holders() : root.querySelectorAll('input[name="_csrf_token"]')).length > 0 || formFields(root).length > 0;
        }

        /** Whether the page holds a token, and it is old enough to ask about. */
        function stale() {
            return Date.now() - fetchedAt >= STALE && carries(document);
        }

        /** Ask for the current token and put it everywhere; a failure leaves the page as it was. */
        function refresh() {
            if (pending) { return pending; }
            pending = window.fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
                .then(function (response) { return response.ok ? response.json() : null; })
                .then(function (body) {
                    if (!body || typeof body.token !== 'string' || body.token === '') { return; }
                    Array.prototype.forEach.call(holders(), function (holder) {
                        if (holder.tagName === 'META') { holder.setAttribute('content', body.token); } else { holder.value = body.token; }
                    });
                    var form = body.form;
                    if (form && /^[0-9a-f]{64}$/.test(form.name || '')) {
                        formFields(document).forEach(function (field) {
                            // A form pinned to the address keeps being pinned: its value says which.
                            var pinned = field.value === form.value_ip && form.value_ip !== form.value;
                            field.setAttribute('name', form.name);
                            field.value = pinned ? form.value_ip : form.value;
                        });
                    }
                    fetchedAt = Date.now();
                })
                .catch(function () { /* offline, or the site is down: the form goes as it is */ })
                .then(function () { pending = null; });
            return pending;
        }

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible' && stale()) { refresh(); }
        });

        // Captured, and held from every other handler, so a form a script sends itself does not go
        // once with the old token and again with the new one.
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || form.pfCsrfFresh || !form.querySelectorAll || !carries(form) || !stale()) { return; }
            event.preventDefault();
            event.stopImmediatePropagation();
            var submitter = event.submitter && event.submitter.form === form ? event.submitter : undefined;
            refresh().then(function () {
                form.pfCsrfFresh = true;
                if (typeof form.requestSubmit === 'function') { form.requestSubmit(submitter); } else { form.submit(); }
                form.pfCsrfFresh = false;
            });
        }, true);

        window.pfRefreshCsrf = refresh;
    })();

    // ══════════════════════════════════════════════════════════════════════════
    // <meta name="pf-client-errors" content="…/ClientErrors"> — errors the page hits, reported
    //
    // An uncaught error and an unhandled promise rejection are sent to the address in the meta
    // tag (\Pramnos\Application\Controllers\ClientErrorsController), which keeps one row per
    // distinct error with a count. Without the tag nothing is sent. Each distinct error is sent
    // once per page, and at most ten per page, so a loop that throws costs ten requests, not a
    // flood. `window.pfReportError(error)` reports one the page caught itself. Errors of browser
    // extensions and of other hosts' scripts are not sent: `data-hosts` names the hosts that serve
    // this site's own (a CDN).
    // ══════════════════════════════════════════════════════════════════════════
    (function () {
        var meta = document.querySelector('meta[name="pf-client-errors"]');
        var url  = meta ? meta.getAttribute('content') : '';
        if (!url) { return; }
        var release = meta.getAttribute('data-release') || '';
        // Other hosts that serve this site's own scripts — a CDN: data-hosts="cdn.example.com".
        var hosts = (meta.getAttribute('data-hosts') || '').split(',').map(function (h) { return h.trim().toLowerCase(); }).filter(Boolean);
        var sent = {};
        var count = 0;

        // The host of an address, or '' for none. A regular expression, not URL: a page may have
        // replaced the constructor with a string of its own.
        function hostOf(address) {
            var m = /^[a-z][a-z0-9+.-]*:\/\/([^/?#]+)/i.exec(address || '');
            return m ? m[1].toLowerCase() : '';
        }

        // An error of this site's own code: not a browser extension's, and from this host, one of
        // data-hosts, or an inline script (no file). The others were most of what was reported, and
        // nothing anybody here could fix: a cookie banner, a chat widget, a map, an extension.
        function ours(report) {
            if (/-extension:\/\//i.test((report.source || '') + ' ' + (report.stack || ''))) { return false; }
            var host = hostOf(report.source);

            return host === '' || host === hostOf(location.origin) || hosts.indexOf(host) !== -1;
        }

        function send(report) {
            // A script from another origin reports only this, with nothing to act on.
            if (!report.message || report.message === 'Script error.' || !ours(report)) { return; }
            var key = report.kind + '|' + report.message + '|' + (report.source || '') + '|' + (report.line || '');
            if (sent[key] || count >= 10) { return; }
            sent[key] = true;
            count++;
            report.page = location.origin + location.pathname;
            if (release) { report.release = release; }
            var body = JSON.stringify(report);
            try {
                if (navigator.sendBeacon && navigator.sendBeacon(url, new Blob([body], { type: 'application/json' }))) { return; }
            } catch (e) { /* fall through to fetch */ }
            if (window.fetch) {
                window.fetch(url, { method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/json' }, body: body })
                    .catch(function () { /* a report that cannot be sent is dropped */ });
            }
        }

        function describe(kind, error, extra) {
            return Object.assign({
                kind: kind,
                message: error && error.message ? String(error.message) : String(error),
                stack: error && error.stack ? String(error.stack) : '',
            }, extra || {});
        }

        window.addEventListener('error', function (e) {
            send(describe('error', e.error || e.message, {
                message: String(e.message || (e.error && e.error.message) || ''),
                source: e.filename || '',
                line: e.lineno || null,
                column: e.colno || null,
            }));
        });
        window.addEventListener('unhandledrejection', function (e) {
            send(describe('rejection', e.reason));
        });
        window.pfReportError = function (error) { send(describe('error', error)); };
    })();

    document.addEventListener('click', function (e) {
        // ── data-confirm ──────────────────────────────────────────────────────
        // On what was clicked — a link, a button. A form's own data-confirm asks when it is
        // sent (below), not at every click on a field, a box or a label inside it.
        var confirmEl = e.target.closest('[data-confirm]');
        if (confirmEl && confirmEl.tagName === 'FORM') { confirmEl = null; }
        if (confirmEl && !confirm(confirmEl.dataset.confirm)) {
            e.preventDefault();
            e.stopPropagation();
            return;
        }

        // ── data-pf-fill-setting ──────────────────────────────────────────────
        //
        // Copy a row's values into the form below it. The alternative is a form per
        // row, which is more markup and — worse — a second code path, so an edit and
        // a new value could behave differently.
        var fillEl = e.target.closest('[data-pf-fill-setting]');
        if (fillEl) {
            var nameField  = document.getElementById('pf-setting-name');
            var valueField = document.getElementById('pf-setting-value');
            if (nameField && valueField) {
                nameField.value  = fillEl.getAttribute('data-pf-fill-setting') || '';
                valueField.value = fillEl.getAttribute('data-pf-fill-value') || '';
                valueField.focus();
            }
            e.preventDefault();
            return;
        }

        // ── data-copy-prev ────────────────────────────────────────────────────
        var copyEl = e.target.closest('[data-copy-prev]');
        if (copyEl) {
            var input = copyEl.previousElementSibling;
            if (input && navigator.clipboard) {
                navigator.clipboard.writeText(input.value);
            }
            return;
        }

        // ── data-toggle-type ──────────────────────────────────────────────────
        var toggleEl = e.target.closest('[data-toggle-type]');
        if (toggleEl) {
            var target = document.getElementById(toggleEl.dataset.toggleType);
            if (target) {
                target.type = target.type === 'password' ? 'text' : 'password';
            }
            return;
        }

        // ── data-modal-show ───────────────────────────────────────────────────
        var showEl = e.target.closest('[data-modal-show]');
        if (showEl) {
            var modal = document.getElementById(showEl.dataset.modalShow);
            if (modal) { modal.classList.remove('hidden'); }
            return;
        }

        // ── data-modal-hide ───────────────────────────────────────────────────
        var hideEl = e.target.closest('[data-modal-hide]');
        if (hideEl) {
            var modal2 = document.getElementById(hideEl.dataset.modalHide);
            if (modal2) { modal2.classList.add('hidden'); }
            return;
        }

        // ── data-stats-open ───────────────────────────────────────────────────
        var statsOpenEl = e.target.closest('[data-stats-open]');
        if (statsOpenEl) {
            openStats(statsOpenEl.dataset.statsUrl || '');
            return;
        }

        // ── data-stats-close ──────────────────────────────────────────────────
        // Close on the explicit close control, or on a backdrop click (the
        // overlay element itself, not its dialog child).
        var statsCloseEl = e.target.closest('[data-stats-close]');
        if (statsCloseEl || e.target.id === 'pf-stats-overlay') {
            closeStats();
            return;
        }

        // ── data-href (clickable rows) ────────────────────────────────────────
        var hrefEl = e.target.closest('[data-href]');
        if (hrefEl) {
            // Never hijack clicks that land on a genuinely interactive child —
            // links, buttons and form controls must keep their own behaviour.
            if (e.target.closest('a, button, input, select, textarea, label')) {
                return;
            }
            window.location = hrefEl.dataset.href;
            return;
        }
    });

    // ── data-confirm on a <form>: asked once, when the form is sent ─────────
    // Not when the button that sent it carries its own, which its click has asked already.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.getAttribute || form.getAttribute('data-confirm') === null) { return; }
        if (e.submitter && e.submitter.getAttribute && e.submitter.getAttribute('data-confirm') !== null) { return; }
        if (!confirm(form.getAttribute('data-confirm'))) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);

    // Close the stats modal with the Escape key for keyboard accessibility.
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeStats(); }
    });

    // ── data-pf-modal ────────────────────────────────────────────────────────
    //
    // A link whose page is better read over the one it is on — a task, a day's figures —
    // opens in a <dialog>. The page is fetched with `X-Pf-Fragment: 1`, which the server
    // answers with the content alone, without its theme. A browser with no <dialog>, a
    // modified click, or a fetch that fails is an ordinary navigation, and the address is
    // still a page of its own.
    document.addEventListener('click', function (e) {
        var link = e.target && e.target.closest ? e.target.closest('a[data-pf-modal]') : null;
        if (!link || e.defaultPrevented || e.button > 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
            return;
        }
        var dialog = modalDialog();
        if (!dialog) { return; }
        e.preventDefault();
        var href = link.getAttribute('href');
        fetch(href, { headers: { 'X-Pf-Fragment': '1' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.text() : Promise.reject(new Error('HTTP ' + r.status)); })
            .then(function (html) { openModal(dialog, html, link); })
            .catch(function () { window.location.href = href; });
    });

    /** The page's one modal <dialog>, made on first use; null where the browser has none. */
    function modalDialog() {
        var dialog = document.getElementById('pf-modal');
        if (dialog) { return dialog; }
        dialog = document.createElement('dialog');
        if (typeof dialog.showModal !== 'function') { return null; }
        dialog.id = 'pf-modal';
        dialog.className = 'pf-modal';
        // A click on the backdrop lands on the dialog itself, not on its content.
        dialog.addEventListener('click', function (e) {
            if (e.target === dialog) { dialog.close(); }
        });
        dialog.addEventListener('close', function () {
            dialog.innerHTML = '';
            if (dialog.pfOpener && dialog.pfOpener.focus) { dialog.pfOpener.focus(); }
        });
        document.body.appendChild(dialog);
        return dialog;
    }

    /**
     * Show a fragment: a close button, then the content. Named by its first heading, or by
     * the link's text; showModal() makes the rest of the page inert, so focus stays inside and
     * Escape closes it.
     */
    function openModal(dialog, html, link) {
        dialog.innerHTML = '<form method="dialog" class="pf-modal-close"><button type="submit" aria-label="Close">&times;</button></form>'
            + '<div class="pf-modal-body">' + html + '</div>';
        var heading = dialog.querySelector('h1, h2, h3');
        dialog.removeAttribute('aria-labelledby');
        dialog.removeAttribute('aria-label');
        if (heading) {
            heading.id = heading.id || 'pf-modal-title';
            dialog.setAttribute('aria-labelledby', heading.id);
        } else {
            dialog.setAttribute('aria-label', (link.getAttribute('data-pf-modal') || link.textContent || '').trim());
        }
        dialog.pfOpener = link;
        if (!dialog.open) { dialog.showModal(); }
        document.dispatchEvent(new CustomEvent('pf:modal:open', { detail: { dialog: dialog, url: link.getAttribute('href') } }));
    }

    // ── Stats modal helpers ─────────────────────────────────────────────────

    function escapeHtml(v) {
        return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    function fmtNumber(v, suffix) {
        if (v === null || v === undefined) { return '—'; }
        return (Math.round(v * 10) / 10) + (suffix || '');
    }

    function openStats(url) {
        var overlay = document.getElementById('pf-stats-overlay');
        var body    = document.getElementById('pf-stats-body');
        if (!overlay || !body) { return; }

        overlay.style.display = 'block';
        body.innerHTML = '<p style="color:#888">Loading…</p>';

        if (!url) {
            body.innerHTML = '<p style="color:#dc3545">No stats endpoint configured.</p>';
            return;
        }

        fetch(url, {
            headers: pfApiHeaders({ 'X-Requested-With': 'XMLHttpRequest' }),
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (d) { body.innerHTML = renderStats(d); })
            .catch(function () {
                body.innerHTML = '<p style="color:#dc3545">Failed to load statistics.</p>';
            });
    }

    function closeStats() {
        var overlay = document.getElementById('pf-stats-overlay');
        if (overlay) { overlay.style.display = 'none'; }
    }

    function renderStats(d) {
        var s = (d && d.summary) || {};
        var cards = [
            ['Total Requests', s.total_requests != null ? s.total_requests : '—'],
            ['Error Rate', s.error_rate != null ? fmtNumber(s.error_rate * 100, '%') : '—'],
            ['Avg', fmtNumber(s.avg_execution_ms, ' ms')],
            ['p95', fmtNumber(s.p95_execution_ms, ' ms')],
            ['p99', fmtNumber(s.p99_execution_ms, ' ms')]
        ];
        var html = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:10px;margin-bottom:18px">';
        cards.forEach(function (c) {
            html += '<div style="border:1px solid #eee;border-radius:6px;padding:10px;text-align:center">'
                 +  '<div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.04em">' + escapeHtml(c[0]) + '</div>'
                 +  '<div style="font-size:20px;font-weight:700;margin-top:2px">' + escapeHtml(c[1]) + '</div></div>';
        });
        html += '</div>';

        html += renderStatsTable('Slowest Endpoints', d.top_slow, ['url', 'avg_ms', 'request_count'],
                                 ['Endpoint', 'Avg (ms)', 'Requests']);
        html += renderStatsTable('Most Called Endpoints', d.top_called, ['url', 'request_count'],
                                 ['Endpoint', 'Requests']);
        return html;
    }

    function renderStatsTable(title, rows, keys, headers) {
        var html = '<h4 style="margin:16px 0 6px;font-size:14px">' + escapeHtml(title) + '</h4>';
        if (!rows || !rows.length) {
            return html + '<p style="color:#888;font-size:13px">No data.</p>';
        }
        html += '<table style="width:100%;border-collapse:collapse;font-size:13px"><thead><tr>';
        headers.forEach(function (h) {
            html += '<th style="text-align:left;padding:6px 8px;border-bottom:1px solid #e5e5e5;background:#f5f5f5">' + escapeHtml(h) + '</th>';
        });
        html += '</tr></thead><tbody>';
        rows.forEach(function (row) {
            html += '<tr>';
            keys.forEach(function (k) {
                var v = row[k];
                if (k === 'avg_ms' && v != null) { v = Math.round(v * 10) / 10; }
                // Fall back to the numeric urlid when the URL could not be resolved.
                if (k === 'url' && (v === undefined || v === null || v === '')) {
                    v = row.urlid != null ? '#' + row.urlid : '—';
                }
                html += '<td style="padding:6px 8px;border-bottom:1px solid #f0f0f0">' + escapeHtml(v) + '</td>';
            });
            html += '</tr>';
        });
        return html + '</tbody></table>';
    }

    // ══════════════════════════════════════════════════════════════════════════
    // data-pf-omnibox — cross-entity search
    //
    // Markup comes from \Pramnos\Html\SearchBox, results from the endpoint named in
    // data-pf-omnibox-url (the scaffolded /admin/search, backed by Search\Registry).
    // Nothing here is specific to a UI system: the classes are namespaced and the
    // styles live in style.css, so bootstrap, tailwind and plain themes share this.
    // ══════════════════════════════════════════════════════════════════════════
    (function () {
        var boxes = document.querySelectorAll('[data-pf-omnibox]');
        if (!boxes.length) { return; }

        Array.prototype.forEach.call(boxes, function (box) {
            var input   = box.querySelector('.pf-omnibox-input');
            var results = box.querySelector('.pf-omnibox-results');
            if (!input || !results) { return; }

            var url      = box.getAttribute('data-pf-omnibox-url') || '';
            var minimum  = parseInt(box.getAttribute('data-pf-omnibox-min') || '2', 10);
            var wait     = parseInt(box.getAttribute('data-pf-omnibox-debounce') || '250', 10);
            var loading  = box.getAttribute('data-pf-omnibox-loading') || 'Searching…';
            var empty    = box.getAttribute('data-pf-omnibox-empty') || 'No results';
            var timer    = null;
            var inFlight = null;
            var active   = -1;

            function close() {
                results.hidden = true;
                results.innerHTML = '';
                input.setAttribute('aria-expanded', 'false');
                input.removeAttribute('aria-activedescendant');
                active = -1;
            }

            function open(html) {
                results.innerHTML = html;
                results.hidden = false;
                input.setAttribute('aria-expanded', 'true');
            }

            function options() {
                return results.querySelectorAll('[role="option"]');
            }

            // Moves the visual and the announced selection together. Keeping them in
            // one place is the point: a highlight without aria-activedescendant is a
            // selection only sighted users can see.
            function highlight(index) {
                var items = options();
                if (!items.length) { return; }

                if (active >= 0 && items[active]) {
                    items[active].classList.remove('is-active');
                    items[active].setAttribute('aria-selected', 'false');
                }

                active = (index + items.length) % items.length;
                items[active].classList.add('is-active');
                items[active].setAttribute('aria-selected', 'true');
                input.setAttribute('aria-activedescendant', items[active].id);

                if (items[active].scrollIntoView) {
                    items[active].scrollIntoView({ block: 'nearest' });
                }
            }

            function render(payload) {
                var groups = (payload && payload.groups) || [];
                if (!groups.length) {
                    open('<p class="pf-omnibox-empty">' + escapeHtml(empty) + '</p>');
                    return;
                }

                var html = '';
                var index = 0;

                groups.forEach(function (group) {
                    var rows = group.results || [];
                    if (!rows.length) { return; }

                    html += '<div class="pf-omnibox-group">';
                    html += '<p class="pf-omnibox-group-label">' + escapeHtml(group.label);
                    // "5 of 137" — the group total comes from the same count the list
                    // endpoints use, so it is the real number and not the page size. A source
                    // that does not count says only that there are more: "5+".
                    if (group.total == null ? group.more : group.total > rows.length) {
                        html += ' <span class="pf-omnibox-count">' + rows.length
                            + (group.total == null ? '+' : '/' + group.total) + '</span>';
                    }
                    html += '</p>';

                    rows.forEach(function (row) {
                        var id  = box.id + '-option-' + (index++);
                        var tag = row.url ? 'a' : 'div';
                        html += '<' + tag + ' class="pf-omnibox-option" role="option"'
                            + ' aria-selected="false" id="' + escapeHtml(id) + '"'
                            + (row.url ? ' href="' + escapeHtml(row.url) + '"' : '')
                            + '>';
                        html += '<span class="pf-omnibox-title">' + escapeHtml(row.title) + '</span>';
                        if (row.subtitle) {
                            html += '<span class="pf-omnibox-subtitle">' + escapeHtml(row.subtitle) + '</span>';
                        }
                        html += '</' + tag + '>';
                    });

                    html += '</div>';
                });

                open(html || '<p class="pf-omnibox-empty">' + escapeHtml(empty) + '</p>');
            }

            function search(term) {
                // Abort rather than ignore: without this, a slow response to "an" can
                // land after a fast one to "anna" and replace the newer results.
                if (inFlight) { inFlight.abort(); }
                inFlight = typeof AbortController === 'function' ? new AbortController() : null;

                open('<p class="pf-omnibox-loading">' + escapeHtml(loading) + '</p>');

                fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(term), {
                    credentials: 'same-origin',
                    headers: pfApiHeaders({ 'Accept': 'application/json' }),
                    signal: inFlight ? inFlight.signal : undefined
                }).then(function (response) {
                    if (!response.ok) { throw new Error('HTTP ' + response.status); }
                    return response.json();
                }).then(render).catch(function (error) {
                    if (error && error.name === 'AbortError') { return; }
                    // Shown, not swallowed: a search box that silently returns nothing
                    // when the endpoint 403s is indistinguishable from one with no
                    // matches, and that is the bug people spend an afternoon on.
                    open('<p class="pf-omnibox-empty">' + escapeHtml(empty) + '</p>');
                    if (window.console) { console.warn('Omnibox request failed:', error); }
                });
            }

            input.addEventListener('input', function () {
                var term = input.value.trim();
                if (timer) { clearTimeout(timer); }

                if (term.length < minimum) {
                    if (inFlight) { inFlight.abort(); }
                    close();
                    return;
                }

                timer = setTimeout(function () { search(term); }, wait);
            });

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    close();
                    return;
                }
                if (results.hidden) { return; }

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    highlight(active + 1);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    highlight(active - 1);
                } else if (e.key === 'Enter' && active >= 0) {
                    var items = options();
                    if (items[active]) {
                        e.preventDefault();
                        items[active].click();
                    }
                }
            });

            // Closing on outside click only — not on blur, which fires before the click
            // that follows a result and would cancel the navigation.
            document.addEventListener('click', function (e) {
                if (!box.contains(e.target)) { close(); }
            });
        });
    })();

    /* ── Countdown buttons (data-pf-countdown) ──────────────────────────────────
     *
     * A button that is disabled because of a rate limit, counting down and enabling
     * itself. "Another code in 60s" that never changes is indistinguishable from a broken
     * button: the reader has no way to know whether it is waiting or dead, and the usual
     * response is to reload the page — which is the one action that tells them nothing.
     *
     *   <button disabled
     *           data-pf-countdown="46"
     *           data-pf-countdown-label="Another code in %s"
     *           data-pf-countdown-ready="Send another code">…</button>
     *
     * `%s` is replaced with the remaining seconds. The label is passed in rather than built
     * here so the wording stays in the view, where the translation is.
     *
     * The server remains the authority: this only counts the number the server sent, and
     * enabling the button early would just produce a refusal with the same message. It is
     * cosmetic on purpose — a page left open for an hour re-enables a button whose next
     * click is allowed anyway.
     */
    (function () {
        var buttons = document.querySelectorAll('[data-pf-countdown]');
        if (!buttons.length) { return; }

        Array.prototype.forEach.call(buttons, function (button) {
            var remaining = parseInt(button.getAttribute('data-pf-countdown'), 10);
            if (isNaN(remaining) || remaining <= 0) { return; }

            var waiting = button.getAttribute('data-pf-countdown-label') || '%s';
            var ready = button.getAttribute('data-pf-countdown-ready') || button.textContent.trim();

            // The element whose text changes: an inner <span> when the button has one, so
            // an icon beside the label survives the update.
            var target = button.querySelector('[data-pf-countdown-text]') || button;

            var render = function () {
                target.textContent = waiting.replace('%s', String(remaining));
            };

            render();

            var tick = window.setInterval(function () {
                remaining -= 1;

                if (remaining > 0) {
                    render();
                    return;
                }

                window.clearInterval(tick);
                target.textContent = ready;
                button.removeAttribute('disabled');
                button.removeAttribute('data-pf-countdown');
            }, 1000);
        });
    })();

    // ══════════════════════════════════════════════════════════════════════════
    // meta[name=pf-realtime] — window.pfRealtime, the page's live connection
    //
    // The tag carries \Pramnos\Broadcasting\RealtimeConfig::forClient() for a `websocket`
    // (the framework's broadcast:serve) or `pusher` transport, plus `authEndpoint` — printed by
    // Document for a signed-in page when the application configures one. Without it nothing is
    // defined, and the bell keeps polling. A page that set window.pfRealtime itself keeps its own.
    //
    // The Pusher protocol, directly (realtimeConnection(), below): a private channel is signed by
    // POST authEndpoint with the session and the CSRF token as X-CSRF-Token. Every tab of the site
    // shares one connection, held by this same file run as the SharedWorker `pf-realtime`
    // (realtimeWorker()); a browser without SharedWorker gives each page its own. Pub/sub keeps
    // nothing for a socket that was away, so after a reconnect that followed a drop, each tab gets
    // one `pf:notifications:refresh` — "you may have missed something", not "something arrived".
    //
    //   window.pfRealtime.channel('private-users.5').listen('notification.created', fn)
    // ══════════════════════════════════════════════════════════════════════════
    (function () {
        // A socket of the page's own, or a shared worker that holds one: with neither, nothing.
        if (window.pfRealtime || (typeof window.WebSocket !== 'function' && typeof window.SharedWorker !== 'function')) { return; }
        var meta = document.querySelector('meta[name="pf-realtime"]');
        var config = null;
        try { config = meta ? JSON.parse(meta.getAttribute('content') || '') : null; } catch (e) { config = null; }
        if (!config || (config.transport !== 'websocket' && config.transport !== 'pusher')) { return; }

        var channels = {};
        var authEndpoint = config.authEndpoint || '/broadcasting/auth';

        /** A channel signed by the endpoint, with this page's session and CSRF token. */
        function sign(name, socketId, done) {
            window.fetch(authEndpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: pfApiHeaders({ 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' }),
                body: 'socket_id=' + encodeURIComponent(socketId) + '&channel_name=' + encodeURIComponent(name),
            }).then(function (r) { return r.ok ? r.json() : null; })
                .then(function (signed) { done(signed && signed.auth ? signed : null); })
                .catch(function () { done(null); /* refused or offline: the bell still polls */ });
        }

        function deliver(channel, event, data) {
            var listeners = channels[channel];
            (listeners && listeners[event] || []).forEach(function (fn) { fn(data); });
        }

        function missed() {
            document.dispatchEvent(new CustomEvent('pf:notifications:refresh', { bubbles: true }));
        }

        // One connection for every tab of the site, held by a SharedWorker running this same
        // file; this page signs the channels it asks for and hears their events. Where there is
        // no SharedWorker (some mobile browsers), or no address to start one from, the page
        // keeps a connection of its own.
        var port = null;
        if (typeof window.SharedWorker === 'function' && SELF_SRC) {
            try {
                port = new window.SharedWorker(SELF_SRC, { name: 'pf-realtime' }).port;
            } catch (e) {
                port = null;
            }
        }

        var connection;
        if (port) {
            port.onmessage = function (message) {
                var m = message.data || {};
                if (m.type === 'sign') {
                    sign(m.channel, m.socketId, function (signed) { port.postMessage({ type: 'signed', channel: m.channel, signed: signed }); });
                } else if (m.type === 'event') {
                    deliver(m.channel, m.event, m.data);
                } else if (m.type === 'missed') {
                    missed();
                }
            };
            if (typeof port.start === 'function') { port.start(); }
            port.postMessage({ type: 'hello', config: config });
            window.addEventListener('pagehide', function () { port.postMessage({ type: 'bye' }); });
            connection = {
                subscribe: function (name) { port.postMessage({ type: 'subscribe', channel: name }); },
                close: function () { port.postMessage({ type: 'bye' }); },
            };
        } else if (typeof window.WebSocket === 'function') {
            connection = realtimeConnection(config, { sign: sign, onEvent: deliver, onMissed: missed });
        } else {
            return;
        }

        window.pfRealtime = {
            transport: config.transport,
            shared: !!port,
            channel: function (name) {
                if (!channels[name]) {
                    channels[name] = {};
                    connection.subscribe(name);
                }
                var listeners = channels[name];
                return {
                    listen: function (event, fn) {
                        (listeners[event] = listeners[event] || []).push(fn);
                        return this;
                    },
                };
            },
            close: function () { connection.close(); },
        };
    })();

    // ══════════════════════════════════════════════════════════════════════════
    // data-pf-notifications — the notification bell
    //
    // Markup from \Pramnos\Html\NotificationBell (or a theme's own, with the same data-*), the
    // feed from the endpoint in data-url (\Pramnos\Application\Controllers\NotificationsController,
    // answering with the page's session). The count is polled every data-interval seconds while
    // the tab is visible — a tab in the background asks nothing — and at once when it comes back.
    // Realtime, where the page has it: a `pf:notifications:refresh` event on the document, or a
    // connection in window.pfRealtime and a channel in data-channel, refreshes it at once.
    // ══════════════════════════════════════════════════════════════════════════
    (function () {
        var bells = document.querySelectorAll('[data-pf-notifications]');
        if (!bells.length) { return; }

        Array.prototype.forEach.call(bells, function (bell) {
            var url      = (bell.getAttribute('data-url') || '').replace(/\/+$/, '');
            var interval = Math.max(10, parseInt(bell.getAttribute('data-interval') || '60', 10)) * 1000;
            var toggle   = bell.querySelector('[data-pf-notifications-toggle]');
            var panel    = bell.querySelector('[data-pf-notifications-panel]');
            var list     = bell.querySelector('[data-pf-notifications-list]');
            var count    = bell.querySelector('[data-pf-notifications-count]');
            var empty    = bell.querySelector('[data-pf-notifications-empty]');
            var template = bell.querySelector('template[data-pf-notifications-item]');
            var group    = '';
            var timer    = null;

            if (!url || !toggle || !panel || !list) { return; }

            function get(path) {
                return fetch(url + path, { credentials: 'same-origin', headers: pfApiHeaders({ 'Accept': 'application/json' }) })
                    .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); });
            }

            function post(path) {
                return fetch(url + path, {
                    method: 'POST',
                    credentials: 'same-origin',
                    keepalive: true,
                    headers: pfApiHeaders({ 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' })
                }).then(function (r) { return r.ok ? r.json() : {}; });
            }

            function showCount(n) {
                if (!count) { return; }
                count.textContent = n > 99 ? '99+' : String(n);
                count.hidden = n <= 0;
                toggle.setAttribute('aria-label', (toggle.getAttribute('data-label') || 'Notifications') + (n > 0 ? ' (' + n + ')' : ''));
            }

            function refreshCount() {
                return get('/count').then(function (d) {
                    showCount(d.unread || 0);
                    Object.keys(d.groups || {}).forEach(function (name) {
                        var badge = bell.querySelector('[data-pf-notifications-group-count="' + name + '"]');
                        if (badge) {
                            badge.textContent = String(d.groups[name]);
                            badge.hidden = !d.groups[name];
                        }
                    });
                }).catch(function () {});
            }

            function item(n) {
                var li;
                if (template && template.content && template.content.firstElementChild) {
                    li = template.content.firstElementChild.cloneNode(true);
                } else {
                    li = document.createElement('li');
                    li.className = 'pf-notify-item';
                    li.appendChild(document.createElement('a'));
                    ['title', 'message', 'time'].forEach(function (field) {
                        var part = document.createElement(field === 'time' ? 'time' : 'span');
                        part.className = 'pf-notify-' + field;
                        part.setAttribute('data-field', field);
                        li.firstElementChild.appendChild(part);
                    });
                }
                var link = li.querySelector('a') || li;
                link.setAttribute('href', n.url || '#');
                link.setAttribute('data-pf-notifications-item', n.id);
                if (!n.read) { li.classList.add('is-unread'); }
                var fill = { title: n.title || n.message, message: n.title ? n.message : '', time: n.created_at };
                Object.keys(fill).forEach(function (field) {
                    var part = li.querySelector('[data-field="' + field + '"]');
                    if (part) {
                        part.textContent = fill[field] || '';
                        if (field === 'time') { part.setAttribute('datetime', n.created_at); }
                    }
                });
                return li;
            }

            function load() {
                return get('?per_page=10' + (group ? '&group=' + encodeURIComponent(group) : '')).then(function (d) {
                    list.textContent = '';
                    (d.items || []).forEach(function (n) { list.appendChild(item(n)); });
                    if (empty) { empty.hidden = (d.items || []).length > 0; }
                    showCount(d.unread || 0);
                }).catch(function () {});
            }

            function setOpen(on) {
                panel.hidden = !on;
                toggle.setAttribute('aria-expanded', on ? 'true' : 'false');
                if (on) { load(); }
            }

            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                setOpen(panel.hidden);
            });

            document.addEventListener('click', function (e) {
                if (!panel.hidden && !bell.contains(e.target)) { setOpen(false); }
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !panel.hidden) { setOpen(false); toggle.focus(); }
            });

            bell.addEventListener('click', function (e) {
                var link = e.target.closest ? e.target.closest('[data-pf-notifications-item]') : null;
                if (link) {
                    var href = link.getAttribute('href');
                    e.preventDefault();
                    post('/read?id=' + encodeURIComponent(link.getAttribute('data-pf-notifications-item'))).then(function () {
                        if (href && href !== '#') { window.location.href = href; } else { load(); }
                    });
                    return;
                }
                if (e.target.closest && e.target.closest('[data-pf-notifications-read-all]')) {
                    e.preventDefault();
                    post('/readall' + (group ? '?group=' + encodeURIComponent(group) : '')).then(function () { load(); refreshCount(); });
                    return;
                }
                var tab = e.target.closest ? e.target.closest('[data-pf-notifications-group]') : null;
                if (tab) {
                    e.preventDefault();
                    group = tab.getAttribute('data-pf-notifications-group') || '';
                    Array.prototype.forEach.call(bell.querySelectorAll('[data-pf-notifications-group]'), function (one) {
                        one.setAttribute('aria-selected', one === tab ? 'true' : 'false');
                    });
                    load();
                }
            });

            function refresh() {
                refreshCount();
                if (!panel.hidden) { load(); }
            }

            function start() {
                if (timer === null) { timer = window.setInterval(refreshCount, interval); }
            }

            function stop() {
                if (timer !== null) { window.clearInterval(timer); timer = null; }
            }

            document.addEventListener('visibilitychange', function () {
                if (document.hidden) { stop(); } else { refresh(); start(); }
            });
            document.addEventListener('pf:notifications:refresh', refresh);

            var channel = bell.getAttribute('data-channel');
            var rt = window.pfRealtime;
            if (channel && rt) {
                if (rt.transport === 'sse' && rt.on) {
                    rt.on('notification.created', refresh);
                } else if (rt.channel) {
                    rt.channel(channel).listen('notification.created', refresh);
                }
            }

            if (!document.hidden) { start(); }
        });
    })();

    // ══════════════════════════════════════════════════════════════════════════
    // data-pf-chart-figure — a chart from \Pramnos\Html\Chart
    //
    // The data is in the figure's <script type="application/json">, so there is no inline script
    // for a CSP to block. Colours are read from the theme's tokens when the chart is drawn —
    // daisyUI's --color-*, Bootstrap's --bs-*, the plain theme's --primary-color — so it is drawn
    // again when the theme changes (data-theme, or the OS colour scheme). Numbers are formatted
    // with Intl.NumberFormat in the page's language. Without Chart.js nothing is drawn and the
    // table of the same numbers stays; with it, the table is kept for screen readers only.
    // ══════════════════════════════════════════════════════════════════════════
    (function () {
        // Read when needed, not once: a card fetched into the page later brings its own figures.
        function figures(root) {
            var scope = root || document;
            var found = Array.prototype.slice.call(scope.querySelectorAll ? scope.querySelectorAll('[data-pf-chart-figure]') : []);
            if (scope.matches && scope.matches('[data-pf-chart-figure]')) { found.unshift(scope); }
            return found;
        }

        // Each theme colour, by the names the three UI systems give it, and a last resort.
        var NAMED = {
            primary:   [['--color-primary', '--bs-primary', '--primary-color'], '#2563eb'],
            secondary: [['--color-secondary', '--bs-secondary'], '#7c3aed'],
            accent:    [['--color-accent', '--bs-pink'], '#db2777'],
            info:      [['--color-info', '--bs-info'], '#0891b2'],
            success:   [['--color-success', '--bs-success'], '#16a34a'],
            warning:   [['--color-warning', '--bs-warning'], '#d97706'],
            error:     [['--color-error', '--bs-danger'], '#dc2626'],
        };
        NAMED.danger = NAMED.error;
        var ORDER = ['primary', 'secondary', 'accent', 'info', 'success', 'warning', 'error'];

        function token(el, names, fallback) {
            var style = window.getComputedStyle ? window.getComputedStyle(el) : null;
            for (var i = 0; style && i < names.length; i++) {
                var value = (style.getPropertyValue(names[i]) || '').trim();
                if (value) { return value; }
            }
            return fallback;
        }

        // A colour at an opacity. Any CSS colour the canvas understands — daisyUI's are oklch() —
        // is resolved to rgb by painting it, which is the one parser every browser shares.
        var probe = null;
        function alpha(color, a) {
            if (!probe && document.createElement) {
                var c = document.createElement('canvas');
                probe = c.getContext ? c.getContext('2d', { willReadFrequently: true }) : null;
            }
            if (!probe) { return color; }
            probe.clearRect(0, 0, 1, 1);
            probe.fillStyle = color;
            probe.fillRect(0, 0, 1, 1);
            var d = probe.getImageData(0, 0, 1, 1).data;
            return 'rgba(' + d[0] + ',' + d[1] + ',' + d[2] + ',' + a + ')';
        }

        function color(fig, name, index) {
            var known = NAMED[name || ORDER[index % ORDER.length]];
            return known ? token(fig, known[0], known[1]) : name;
        }

        function merge(target, source) {
            Object.keys(source || {}).forEach(function (key) {
                var value = source[key];
                if (value && typeof value === 'object' && !Array.isArray(value)) {
                    target[key] = merge(target[key] && typeof target[key] === 'object' ? target[key] : {}, value);
                } else {
                    target[key] = value;
                }
            });
            return target;
        }

        function draw(fig) {
            if (typeof window.Chart === 'undefined') { return; }
            var source = fig.querySelector('script[data-pf-chart-config]');
            var box    = fig.querySelector('.pf-chart-canvas');
            var canvas = box ? box.querySelector('canvas') : null;
            if (!source || !canvas) { return; }
            var cfg = JSON.parse(source.textContent);

            var round = /^(pie|doughnut|polarArea)$/.test(cfg.type);
            var text  = token(fig, ['--color-base-content', '--bs-body-color', '--text-main'], (window.getComputedStyle && window.getComputedStyle(fig).color) || '#374151');
            var fmt   = new Intl.NumberFormat(document.documentElement.getAttribute('lang') || undefined, {
                style: cfg.format.style || 'decimal',
                currency: cfg.format.currency,
                maximumFractionDigits: cfg.format.decimals,
            });

            var datasets = cfg.datasets.map(function (set, i) {
                var out = { label: set.label, data: set.data };
                if (set.type) { out.type = set.type; }
                if (round) {
                    out.backgroundColor = set.data.map(function (_, j) { return color(fig, set.colors ? set.colors[j] : null, j); });
                    out.borderColor = token(fig, ['--color-base-100', '--bs-body-bg'], '#ffffff');
                } else {
                    var c = color(fig, set.color, i);
                    var line = (set.type || cfg.type) === 'line' || cfg.type === 'radar';
                    out.borderColor = c;
                    out.backgroundColor = line ? alpha(c, 0.15) : c;
                    out.fill = line && cfg.stacked;
                }
                return out;
            });

            var grid = alpha(text, 0.12);
            var options = {
                responsive: true,
                maintainAspectRatio: false,
                color: text,
                plugins: {
                    legend: { display: datasets.length > 1 || round, labels: { color: text } },
                    tooltip: { callbacks: { label: function (item) {
                        var value = round || cfg.type === 'radar' ? item.raw : item.parsed.y;
                        return (item.dataset.label ? item.dataset.label + ': ' : '') + fmt.format(value);
                    } } },
                },
            };
            if (cfg.type === 'radar') {
                options.scales = { r: { ticks: { color: text, backdropColor: 'transparent', callback: function (v) { return fmt.format(v); } }, grid: { color: grid }, angleLines: { color: grid }, pointLabels: { color: text } } };
            } else if (!round) {
                options.scales = {
                    x: { stacked: cfg.stacked, ticks: { color: text }, grid: { color: grid } },
                    y: { stacked: cfg.stacked, beginAtZero: true, ticks: { color: text, callback: function (v) { return fmt.format(v); } }, grid: { color: grid } },
                };
            }

            if (fig._pfChart) { fig._pfChart.destroy(); }
            box.hidden = false;
            box.style.height = cfg.height + 'px';
            fig._pfChart = new window.Chart(canvas, {
                type: cfg.type,
                data: { labels: cfg.labels, datasets: datasets },
                options: merge(options, cfg.options),
            });
            var table = fig.querySelector('.pf-chart-data');
            if (table) { table.classList.add('pf-visually-hidden'); }
        }

        function drawAll() { figures().forEach(draw); }

        // The figures under `root` not drawn yet — a card a page inserted after it loaded.
        function drawNew(root) { figures(root).forEach(function (fig) { if (!fig._pfChart) { draw(fig); } }); }
        window.pfCharts = { draw: drawNew };

        drawAll();
        // Chart.js may arrive after this script: the page's load event comes after both.
        window.addEventListener('load', function () { drawNew(document); });
        if (window.MutationObserver) {
            new window.MutationObserver(drawAll).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'class'] });
            // Figures inserted later — a dashboard card fetched after a change — are drawn as they arrive.
            if (document.body) {
                new window.MutationObserver(function (records) {
                    (records || []).forEach(function (record) {
                        Array.prototype.forEach.call(record.addedNodes || [], function (node) {
                            if (node.nodeType === 1) { drawNew(node); }
                        });
                    });
                }).observe(document.body, { childList: true, subtree: true });
            }
        }
        if (window.matchMedia) {
            var scheme = window.matchMedia('(prefers-color-scheme: dark)');
            if (scheme.addEventListener) { scheme.addEventListener('change', drawAll); }
        }
    })();

    // The arithmetic of dragging a dashboard card, apart from the page so it can be tested:
    // window.pfDashboard.
    var dashboardMath = {
        /**
         * The width a card dragged to `span` columns of a `cols`-column grid takes: on four each
         * span is a width; on two, one column is small and two keep a wide card's width (m, l
         * and full all fill a row there); on one, nothing changes.
         */
        sizeForSpan: function (span, cols, current) {
            var sizes = ['s', 'm', 'l', 'full'];
            span = Math.max(1, Math.min(cols, Math.round(span)));
            if (cols >= 4) { return sizes[span - 1]; }
            if (cols === 2) { return span === 1 ? 's' : (current === 's' ? 'm' : current); }
            return current;
        },
        /**
         * Whether a card dropped at (x, y) over `rect` goes before it: one that fills its row
         * (`wide`) by the top or bottom half, any other by the left or right.
         */
        dropBefore: function (rect, x, y, wide) {
            return wide ? y < rect.top + rect.height / 2 : x < rect.left + rect.width / 2;
        },
        /**
         * The order with `dragged` moved before or after `target`; null when it is already there,
         * so the same pointer position never moves the placeholder twice.
         */
        placeAt: function (order, dragged, target, before) {
            var list = order.filter(function (id) { return id !== dragged; });
            var at = list.indexOf(target);
            if (at < 0 || target === dragged) { return null; }
            list.splice(before ? at : at + 1, 0, dragged);
            return list.join('\n') === order.join('\n') ? null : list;
        },
        /** Pixels to scroll the window this frame while dragging: near an edge, faster the closer. */
        autoScrollStep: function (y, height, edge, max) {
            if (y < edge) { return -Math.ceil(max * (edge - Math.max(0, y)) / edge); }
            if (y > height - edge) { return Math.ceil(max * (Math.min(height, y) - (height - edge)) / edge); }
            return 0;
        },
    };
    window.pfDashboard = dashboardMath;

    // ══════════════════════════════════════════════════════════════════════════
    // data-pf-dashboard — a dashboard each person arranges (DashboardController)
    //
    // "Customise" shows each card's tools: move up and down, its width (s, m, l, full in turn), its
    // settings, hide — buttons, so a keyboard does all of it — and in the same mode a card is
    // dragged by its grip and resized by its corner, with a finger as with a mouse. Each change is
    // saved at once (POST data-save, JSON, with the page's CSRF token). A card added, or whose
    // settings changed, is drawn by the server (GET data-card?id=) and put in place without a
    // reload; without data-card the page reloads instead. The server keeps only what this person
    // may have, so nothing here is a gate.
    // ══════════════════════════════════════════════════════════════════════════
    (function () {
        var board = document.querySelector('[data-pf-dashboard]');
        if (!board) { return; }
        var editButton = document.querySelector('[data-pf-dashboard-edit]');
        var bar    = document.querySelector('[data-pf-dashboard-bar]');
        var status = document.querySelector('[data-pf-dashboard-status]');
        var empty  = board.querySelector('[data-pf-dashboard-empty]');
        var editing = false;

        var SIZES   = ['s', 'm', 'l', 'full'];
        // What the script says, in the page's language: data-pf-dashboard-say on the board, a JSON
        // object over these.
        var SAY = { saved: 'saved', hidden: 'hidden', failed: 'The dashboard could not be saved.', added: 'added',
            sizes: { s: 'small', m: 'medium', l: 'large', full: 'full width' }, badges: { s: 'S · 1', m: 'M · 2', l: 'L · 3', full: 'Full · 4' } };
        try {
            var given = JSON.parse(board.getAttribute('data-pf-dashboard-say') || '{}');
            Object.keys(given || {}).forEach(function (key) { SAY[key] = given[key]; });
        } catch (err) { /* the page's words could not be read: the defaults stand */ }
        var NAMES   = SAY.sizes;
        var cardUrl = board.getAttribute('data-card') || '';

        function cards() { return Array.prototype.slice.call(board.querySelectorAll('[data-pf-widget]')); }
        function say(text) { if (status) { status.textContent = text; } }
        function sizeOf(card) {
            var size = card.getAttribute('data-size');
            return size === 'half' ? 'm' : (SIZES.indexOf(size) >= 0 ? size : 'm');
        }
        function settingsOf(card) {
            try { var parsed = JSON.parse(card.getAttribute('data-settings') || '{}'); return parsed && typeof parsed === 'object' ? parsed : {}; } catch (err) { return {}; }
        }
        function layout() {
            return cards().map(function (card) {
                return { id: card.getAttribute('data-pf-widget'), size: sizeOf(card), settings: settingsOf(card) };
            });
        }

        /** The server's drawing of one card, as an element — or null. */
        function fetchCard(id) {
            var url = cardUrl + (cardUrl.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(id);
            return window.fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/html' } })
                .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.text(); })
                .then(function (html) {
                    var holder = document.createElement('div');
                    holder.innerHTML = html;
                    return holder.querySelector('[data-pf-widget]');
                });
        }

        /** A card's tools, its grip and its corner, shown while customising. */
        function showTools(card, on) {
            Array.prototype.forEach.call(card.querySelectorAll('[data-pf-widget-tools], [data-pf-widget-size-handle]'), function (tool) { tool.hidden = !on; });
            // Inline, because a theme's utilities (a .btn's touch-action: manipulation) beat any
            // stylesheet rule of ours: a finger on a handle must drag, not scroll.
            Array.prototype.forEach.call(card.querySelectorAll('[data-pf-widget-grip], [data-pf-widget-size-handle], [data-pf-widget-head]'), function (handle) {
                handle.style.touchAction = on ? 'none' : '';
                // Nor does a press there start selecting its text.
                handle.style.userSelect = on ? 'none' : '';
                handle.style.webkitUserSelect = on ? 'none' : '';
            });
        }

        /** Put a freshly drawn card where `old` was, or at the end; tools as the mode has them. */
        function place(fresh, old) {
            if (!fresh) { return; }
            if (old && old.parentNode) { old.parentNode.insertBefore(fresh, old); old.parentNode.removeChild(old); } else { board.insertBefore(fresh, empty || null); }
            showTools(fresh, editing);
            if (empty) { empty.hidden = true; }
            if (window.pfCharts && window.pfCharts.draw) { window.pfCharts.draw(fresh); }
        }

        function save(then) {
            var headers = window.pfApiHeaders ? window.pfApiHeaders({ 'Content-Type': 'application/json' }) : { 'Content-Type': 'application/json' };
            if (empty) { empty.hidden = cards().length > 0; }
            return window.fetch(board.getAttribute('data-save'), {
                method: 'POST', credentials: 'same-origin', headers: headers, body: JSON.stringify({ layout: then && then.add ? layout().concat([then.add]) : layout() }),
            }).then(function (r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.json();
            }).then(function () {
                if (then && then.redraw) {
                    return fetchCard(then.redraw.id).then(function (fresh) { place(fresh, then.redraw.card); });
                }
                if (then && then.reload) { window.location.reload(); }
            }).catch(function () {
                say(SAY.failed);
            });
        }

        function setEditing(on) {
            editing = on;
            if (editButton) { editButton.setAttribute('aria-pressed', on ? 'true' : 'false'); }
            if (bar) { bar.hidden = !on; }
            cards().forEach(function (card) { showTools(card, on); });
        }

        if (editButton) { editButton.addEventListener('click', function () { setEditing(!editing); }); }
        var done = document.querySelector('[data-pf-dashboard-done]');
        if (done) { done.addEventListener('click', function () { setEditing(false); if (editButton && editButton.focus) { editButton.focus(); } }); }

        board.addEventListener('click', function (e) {
            var button = e.target.closest ? e.target.closest('[data-pf-widget-move], [data-pf-widget-resize], [data-pf-widget-hide], [data-pf-widget-settings-toggle]') : null;
            if (!button) { return; }
            var card  = button.closest('[data-pf-widget]');
            var title = card.getAttribute('aria-label') || '';
            if (button.hasAttribute('data-pf-widget-settings-toggle')) {
                var form = card.querySelector('[data-pf-widget-settings]');
                if (form) {
                    form.hidden = !form.hidden;
                    button.setAttribute('aria-expanded', form.hidden ? 'false' : 'true');
                }
                return;
            }
            if (button.hasAttribute('data-pf-widget-move')) {
                var list = cards();
                var at   = list.indexOf(card) + parseInt(button.getAttribute('data-pf-widget-move'), 10);
                if (at < 0 || at >= list.length) { return; }
                var other = list[at];
                if (at < list.indexOf(card)) { board.insertBefore(card, other); } else { board.insertBefore(card, other.nextSibling); }
                if (button.focus) { button.focus(); }
                say(title + ': ' + (at + 1) + ' / ' + list.length);
            } else if (button.hasAttribute('data-pf-widget-resize')) {
                var size = SIZES[(SIZES.indexOf(sizeOf(card)) + 1) % SIZES.length];
                card.setAttribute('data-size', size);
                card.classList.toggle('pf-dashboard-full', size === 'full');
                say(title + ': ' + NAMES[size]);
            } else {
                var next = cards()[cards().indexOf(card) + 1] || cards()[cards().indexOf(card) - 1];
                card.parentNode.removeChild(card);
                say(title + ': ' + SAY.hidden);
                var focus = next ? next.querySelector('[data-pf-widget-hide]') : editButton;
                if (focus && focus.focus) { focus.focus(); }
            }
            save();
            refreshCatalogue();
        });

        // A setting shown only for others' values — `data-pf-when` on its label, `{"period": "custom"}`.
        function showWhen(form) {
            Array.prototype.forEach.call(form.querySelectorAll('[data-pf-when]'), function (label) {
                var when = {};
                try { when = JSON.parse(label.getAttribute('data-pf-when') || '{}') || {}; } catch (err) { when = {}; }
                label.hidden = Object.keys(when).some(function (name) {
                    var field = form.querySelector('[name="' + name + '"]');
                    return !field || field.value !== String(when[name]);
                });
            });
        }
        board.addEventListener('change', function (e) {
            var form = e.target && e.target.closest ? e.target.closest('[data-pf-widget-settings]') : null;
            if (form) { showWhen(form); }
        });

        // A card's settings: kept on the card, saved with the layout, and the card drawn again.
        board.addEventListener('submit', function (e) {
            var form = e.target && e.target.hasAttribute && e.target.hasAttribute('data-pf-widget-settings') ? e.target : null;
            if (!form) { return; }
            e.preventDefault();
            var card = form.closest('[data-pf-widget]');
            var settings = {};
            Array.prototype.forEach.call(form.querySelectorAll('[name]'), function (field) {
                var label = field.closest ? field.closest('[data-pf-when]') : null;
                if (!label || !label.hidden) { settings[field.getAttribute('name')] = field.value; }
            });
            card.setAttribute('data-settings', JSON.stringify(settings));
            say((card.getAttribute('aria-label') || '') + ': ' + SAY.saved);
            save(cardUrl ? { redraw: { id: card.getAttribute('data-pf-widget'), card: card } } : { reload: true });
        });

        // Adding a card: a select of them, or a catalogue — buttons [data-pf-dashboard-add="id"],
        // in a dialog opened by [data-pf-dashboard-catalogue], searched by
        // [data-pf-dashboard-catalogue-search]. A card on the board is not offered; hidden, it is
        // offered again — shown and hidden, never removed.
        function catalogue() { return document.querySelector('[data-pf-dashboard-catalogue-dialog]'); }
        function addCard(id) {
            var dialog = catalogue();
            if (!id || board.querySelector('[data-pf-widget="' + id + '"]')) { return; }
            if (dialog && dialog.close && dialog.open) { dialog.close(); }
            say(id + ': ' + SAY.added);
            save(cardUrl ? { add: { id: id }, redraw: { id: id, card: null } } : { add: { id: id }, reload: true })
                .then(refreshCatalogue);
        }
        function refreshCatalogue() {
            var placed = cards().map(function (card) { return card.getAttribute('data-pf-widget'); });
            Array.prototype.forEach.call(document.querySelectorAll('select[data-pf-dashboard-add] option[value]'), function (option) {
                if (option.value) { option.hidden = placed.indexOf(option.value) >= 0; }
            });
            Array.prototype.forEach.call(document.querySelectorAll('[data-pf-dashboard-option]'), function (item) {
                var on = placed.indexOf(item.getAttribute('data-pf-dashboard-option')) >= 0;
                var button = item.querySelector('[data-pf-dashboard-add]');
                var present = item.querySelector('[data-pf-dashboard-present]');
                if (button) { button.hidden = on; }
                if (present) { present.hidden = !on; }
            });
        }
        var select = document.querySelector('select[data-pf-dashboard-add]');
        if (select) {
            select.addEventListener('change', function () {
                var id = select.value;
                select.value = '';
                addCard(id);
            });
        }
        document.addEventListener('click', function (e) {
            var target = e.target && e.target.closest ? e.target.closest('button[data-pf-dashboard-add], [data-pf-dashboard-catalogue], [data-pf-dashboard-catalogue-close]') : null;
            if (!target) { return; }
            var dialog = catalogue();
            if (target.hasAttribute('data-pf-dashboard-add')) {
                addCard(target.getAttribute('data-pf-dashboard-add'));
            } else if (target.hasAttribute('data-pf-dashboard-catalogue')) {
                if (dialog && dialog.showModal) { dialog.showModal(); } else if (dialog) { dialog.setAttribute('open', ''); }
                var search = dialog ? dialog.querySelector('[data-pf-dashboard-catalogue-search]') : null;
                if (search && search.focus) { search.focus(); }
            } else if (dialog) {
                if (dialog.close) { dialog.close(); } else { dialog.removeAttribute('open'); }
            }
        });
        document.addEventListener('input', function (e) {
            var search = e.target && e.target.hasAttribute && e.target.hasAttribute('data-pf-dashboard-catalogue-search') ? e.target : null;
            if (!search) { return; }
            var words = String(search.value || '').toLowerCase().trim();
            Array.prototype.forEach.call(document.querySelectorAll('[data-pf-dashboard-option]'), function (item) {
                item.hidden = words !== '' && String(item.textContent || '').toLowerCase().indexOf(words) < 0;
            });
        });

        // Dragging, by the grip, and resizing, by the corner — pointer events, so a finger does it
        // as a mouse does. The grip lifts the card: it follows the pointer, a dashed placeholder of
        // its size shows where it lands, and the window scrolls near its top and bottom. The corner
        // resizes by columns, the width written on the card as it is reached. Saved when let go.
        var SIZE_BADGE = SAY.badges;
        function columns() {
            var tracks = String((window.getComputedStyle ? window.getComputedStyle(board).gridTemplateColumns : '') || '').split(' ').filter(Boolean);
            return Math.max(1, tracks.length);
        }

        board.addEventListener('pointerdown', function (ev) {
            if (!editing || ev.button > 0 || !ev.target.closest) { return; }
            var grip = ev.target.closest('[data-pf-widget-grip], [data-pf-widget-size-handle]');
            if (grip) {
                ev.preventDefault();
                begin(ev, grip);
                return;
            }
            // The card's title row is a grip too, but not what is in it — a link, a button, a
            // field — and only once the pointer has moved a few pixels, so a click stays a click.
            var head = ev.target.closest('[data-pf-widget-head]');
            if (!head || ev.target.closest('a, button, input, select, textarea, label, [data-pf-widget-tools]')) { return; }
            function wait(e) {
                if (Math.abs(e.clientX - ev.clientX) + Math.abs(e.clientY - ev.clientY) < 6) { return; }
                stop();
                begin(ev, head)(e);
            }
            // While it waits, the browser's own gestures — dragging a link, selecting text — would
            // take the pointer and cancel it: refused until the press ends or the card lifts.
            function refuse(e) { e.preventDefault(); }
            function stop() {
                document.removeEventListener('pointermove', wait);
                document.removeEventListener('pointerup', stop);
                document.removeEventListener('pointercancel', stop);
                document.removeEventListener('dragstart', refuse);
                document.removeEventListener('selectstart', refuse);
            }
            document.addEventListener('pointermove', wait);
            document.addEventListener('pointerup', stop);
            document.addEventListener('pointercancel', stop);
            document.addEventListener('dragstart', refuse);
            document.addEventListener('selectstart', refuse);
        });

        /** Lift a card by a grip, or take its corner; answers the handler of the pointer's moves. */
        function begin(ev, grip) {
            var card     = grip.closest('[data-pf-widget]');
            var id       = card.getAttribute('data-pf-widget');
            var resizing = grip.hasAttribute('data-pf-widget-size-handle');
            var before   = JSON.stringify(layout());
            var rect     = card.getBoundingClientRect();
            var cols     = columns();
            var gap      = parseFloat((window.getComputedStyle ? window.getComputedStyle(board).columnGap : '') || '') || 0;
            var colWidth = (board.getBoundingClientRect().width - gap * (cols - 1)) / cols;
            var last     = { x: ev.clientX, y: ev.clientY };
            var moved    = { x: ev.clientX, y: ev.clientY };
            var frame    = null;
            var badge    = null;
            var placeholder = null;
            // No text selected along the way — iOS Safari reads only the prefixed property.
            document.body.style.userSelect = 'none';
            document.body.style.webkitUserSelect = 'none';
            if (resizing) {
                card.classList.add('pf-dashboard-resizing');
                card.style.outline = '2px solid currentColor';
                badge = document.createElement('span');
                badge.className = 'pf-dashboard-size-badge';
                badge.textContent = SIZE_BADGE[sizeOf(card)] || '';
                card.appendChild(badge);
            } else {
                placeholder = document.createElement('div');
                placeholder.className = 'pf-dashboard-placeholder';
                placeholder.setAttribute('data-size', sizeOf(card));
                // As tall as the card, up to a third of the window: a tall card's gap would push the rest away.
                placeholder.style.height = Math.min(rect.height, Math.max(160, window.innerHeight / 3)) + 'px';
                board.insertBefore(placeholder, card);
                card.classList.add('pf-dashboard-dragging');
                // What makes it follow the pointer, inline: a theme's `.card { position: relative }`
                // sits in a layer that beats any rule of ours, whatever its specificity.
                card.style.position = 'fixed';
                card.style.zIndex = '60';
                card.style.margin = '0';
                card.style.pointerEvents = 'none';
                card.style.width = rect.width + 'px';
                card.style.height = rect.height + 'px';
                card.style.left = rect.left + 'px';
                card.style.top = rect.top + 'px';
            }
            var offset = { x: ev.clientX - rect.left, y: ev.clientY - rect.top };
            function order() {
                return Array.prototype.filter.call(board.children, function (el) {
                    return el !== card && (el === placeholder || (el.hasAttribute && el.hasAttribute('data-pf-widget')));
                }).map(function (el) { return el === placeholder ? id : el.getAttribute('data-pf-widget'); });
            }
            function placeHere() {
                var over = (document.elementsFromPoint ? document.elementsFromPoint(last.x, last.y) : []).map(function (el) {
                    return el.closest ? el.closest('[data-pf-widget]') : null;
                }).filter(function (c) { return c && c !== card && c.parentNode === board; })[0];
                // Only after a few pixels: a card that moved under a still pointer stays where it went.
                if (!over || Math.abs(last.x - moved.x) + Math.abs(last.y - moved.y) < 6) { return; }
                var r     = over.getBoundingClientRect();
                var wide  = cols === 1 || r.width > board.getBoundingClientRect().width * 0.75;
                var ahead = dashboardMath.dropBefore(r, last.x, last.y, wide);
                if (dashboardMath.placeAt(order(), id, over.getAttribute('data-pf-widget'), ahead)) {
                    board.insertBefore(placeholder, ahead ? over : (over.nextElementSibling !== undefined ? over.nextElementSibling : over.nextSibling));
                    moved = { x: last.x, y: last.y };
                }
            }
            function scroll() {
                var step = dashboardMath.autoScrollStep(last.y, window.innerHeight, 70, 20);
                if (step !== 0 && window.scrollBy) {
                    window.scrollBy(0, step);
                    moved = { x: -1000, y: -1000 };
                    placeHere();
                }
                frame = window.requestAnimationFrame(scroll);
            }
            function move(e) {
                last = { x: e.clientX, y: e.clientY };
                if (resizing) {
                    var span = (rect.width + (e.clientX - ev.clientX) + gap) / (colWidth + gap);
                    var size = dashboardMath.sizeForSpan(span, cols, sizeOf(card));
                    card.setAttribute('data-size', size);
                    card.classList.toggle('pf-dashboard-full', size === 'full');
                    badge.textContent = SIZE_BADGE[size] || '';
                    return;
                }
                card.style.left = (e.clientX - offset.x) + 'px';
                card.style.top = (e.clientY - offset.y) + 'px';
                placeHere();
            }
            function up() {
                document.removeEventListener('pointermove', move);
                document.removeEventListener('pointerup', up);
                document.removeEventListener('pointercancel', up);
                if (frame !== null && window.cancelAnimationFrame) { window.cancelAnimationFrame(frame); }
                document.body.style.userSelect = '';
                document.body.style.webkitUserSelect = '';
                if (placeholder) {
                    board.insertBefore(card, placeholder);
                    board.removeChild(placeholder);
                    card.classList.remove('pf-dashboard-dragging');
                    ['position', 'zIndex', 'margin', 'pointerEvents', 'width', 'height', 'left', 'top'].forEach(function (k) { card.style[k] = ''; });
                }
                if (badge) {
                    card.removeChild(badge);
                    card.classList.remove('pf-dashboard-resizing');
                    card.style.outline = '';
                }
                if (JSON.stringify(layout()) !== before) {
                    say((card.getAttribute('aria-label') || '') + ': ' + (resizing ? NAMES[sizeOf(card)] : (cards().indexOf(card) + 1) + ' / ' + cards().length));
                    save();
                }
            }
            document.addEventListener('pointermove', move);
            document.addEventListener('pointerup', up);
            document.addEventListener('pointercancel', up);
            if (!resizing && window.requestAnimationFrame) { frame = window.requestAnimationFrame(scroll); }

            return move;
        }
    })();

    /**
     * A live connection over the Pusher protocol — the page's own, or the one a SharedWorker
     * holds for every tab. `hooks.sign(name, socketId, done)` signs a private or presence
     * channel, `hooks.onEvent(channel, event, data)` hears a channel's event, and
     * `hooks.onMissed()` is told after a reconnect that followed a drop of an established
     * connection: pub/sub keeps nothing for a socket that was away.
     *
     * The handshake gives a socket id; `pusher:ping` is answered; a socket silent for the
     * server's `activity_timeout` is pinged and, with no answer in 3 s, closed — a dead
     * connection can stay OPEN with nothing arriving. A drop reconnects with a backoff that
     * grows by half each time up to 30 s, jittered so a server restart is not met by every
     * page in the same second.
     */
    function realtimeConnection(config, hooks) {
        var scope = typeof window !== 'undefined' ? window : self;
        var url = config.url || socketUrl(config);
        var names = {};
        var socket = null;
        var socketId = '';
        var established = false;
        var missed = false;
        var closed = false;
        var attempt = 0;
        var activity = 120000;
        var idleTimer = null;
        var pongTimer = null;

        /** The socket's address from forClient()'s fields: the built-in server, or Pusher/Reverb. */
        function socketUrl(c) {
            if (c.transport === 'pusher') {
                var tls = !!c.forceTLS;
                return (tls ? 'wss' : 'ws') + '://' + (c.wsHost || ('ws-' + (c.cluster || 'mt1') + '.pusher.com'))
                    + ':' + (c.wsPort || (tls ? 443 : 80)) + '/app/' + c.key;
            }
            var scheme = c.scheme === 'wss' || c.scheme === 'https' ? 'wss' : 'ws';
            return scheme + '://' + (c.host || scope.location.hostname) + ':' + (c.port || 6001) + '/app/' + (c.appKey || '');
        }

        function send(event, data) {
            try { socket.send(JSON.stringify({ event: event, data: data })); } catch (e) { /* the close handler deals with it */ }
        }

        /** Join a channel on the live socket; a private or presence one signed first. */
        function join(name) {
            if (!established) { return; }
            if (name.indexOf('private-') !== 0 && name.indexOf('presence-') !== 0) {
                send('pusher:subscribe', { channel: name });
                return;
            }
            var asked = socketId;
            hooks.sign(name, socketId, function (signed) {
                if (signed && signed.auth && established && asked === socketId && names[name]) {
                    send('pusher:subscribe', { channel: name, auth: signed.auth, channel_data: signed.channel_data });
                }
            });
        }

        function quiet() {
            scope.clearTimeout(idleTimer);
            scope.clearTimeout(pongTimer);
            idleTimer = pongTimer = null;
        }

        /** Every frame is proof of life; silence for activity_timeout is asked about, then closed. */
        function bump() {
            quiet();
            idleTimer = scope.setTimeout(function () {
                send('pusher:ping', {});
                pongTimer = scope.setTimeout(function () { try { socket.close(); } catch (e) { /* gone */ } }, 3000);
            }, activity);
        }

        function connect() {
            try {
                socket = new scope.WebSocket(url + (url.indexOf('?') === -1 ? '?' : '&') + 'protocol=7&client=pf-utils&version=1.0');
            } catch (e) {
                return;
            }
            socket.onmessage = function (message) {
                var frame;
                try { frame = JSON.parse(message.data); } catch (e) { return; }
                bump();
                var data = frame.data;
                if (typeof data === 'string') { try { data = JSON.parse(data); } catch (e) { /* a plain string */ } }
                if (frame.event === 'pusher:connection_established') {
                    established = true;
                    attempt = 0;
                    socketId = String((data && data.socket_id) || '');
                    var seconds = Number(data && data.activity_timeout);
                    if (isFinite(seconds) && seconds >= 1) { activity = seconds * 1000; }
                    bump();
                    Object.keys(names).forEach(join);
                    if (missed) {
                        missed = false;
                        hooks.onMissed();
                    }
                    return;
                }
                if (frame.event === 'pusher:ping') { send('pusher:pong', {}); return; }
                if (!frame.event || frame.event.indexOf('pusher') === 0 || !names[frame.channel]) { return; }
                hooks.onEvent(frame.channel, frame.event, data);
            };
            socket.onclose = function () {
                quiet();
                // Only a connection that was established can have missed anything.
                if (established) { missed = true; }
                established = false;
                if (closed) { return; }
                var base = Math.min(30000, 1000 * Math.pow(1.5, attempt++));
                scope.setTimeout(connect, base / 2 + Math.random() * base / 2);
            };
        }

        connect();

        return {
            subscribe: function (name) {
                if (names[name]) { return; }
                names[name] = true;
                join(name);
            },
            unsubscribe: function (name) {
                if (!names[name]) { return; }
                delete names[name];
                if (established) { send('pusher:unsubscribe', { channel: name }); }
            },
            close: function () {
                closed = true;
                quiet();
                try { socket.close(); } catch (e) { /* gone */ }
            },
        };
    }

    /**
     * This file run as the SharedWorker `pf-realtime`: one connection for every tab.
     *
     * A tab says `hello` with its config (the first one's is used), `subscribe` for a channel,
     * `signed` with a signature it was asked for, and `bye` when it goes. A private channel is
     * signed by a tab that listens on it — the worker has no CSRF token of its own — and asked of
     * the next one if that tab leaves first. An event goes to every tab on its channel; a channel
     * no tab listens on any more is left; after a reconnect each tab is told it may have missed
     * something.
     */
    function realtimeWorker() {
        var ports = [];
        var listening = {};
        var signing = {};
        var connection = null;

        function without(list, port) {
            return list.filter(function (p) { return p !== port; });
        }

        var hooks = {
            sign: function (name, socketId, done) {
                var port = (listening[name] || [])[0];
                if (!port) { done(null); return; }
                signing[name] = { port: port, socketId: socketId, done: done };
                port.postMessage({ type: 'sign', channel: name, socketId: socketId });
            },
            onEvent: function (channel, event, data) {
                (listening[channel] || []).forEach(function (port) {
                    port.postMessage({ type: 'event', channel: channel, event: event, data: data });
                });
            },
            onMissed: function () {
                ports.forEach(function (port) { port.postMessage({ type: 'missed' }); });
            },
        };

        self.onconnect = function (event) {
            var port = event.ports[0];
            ports.push(port);
            port.onmessage = function (message) {
                var m = message.data || {};
                if (m.type === 'hello' && !connection && m.config) {
                    connection = realtimeConnection(m.config, hooks);
                } else if (m.type === 'subscribe' && connection) {
                    listening[m.channel] = without(listening[m.channel] || [], port).concat([port]);
                    connection.subscribe(m.channel);
                } else if (m.type === 'signed' && signing[m.channel] && signing[m.channel].port === port) {
                    var pending = signing[m.channel];
                    delete signing[m.channel];
                    pending.done(m.signed);
                } else if (m.type === 'bye') {
                    ports = without(ports, port);
                    Object.keys(listening).forEach(function (name) {
                        listening[name] = without(listening[name], port);
                        if (signing[name] && signing[name].port === port) {
                            var pending = signing[name];
                            delete signing[name];
                            hooks.sign(name, pending.socketId, pending.done);
                        }
                        if (listening[name].length === 0) {
                            delete listening[name];
                            if (connection) { connection.unsubscribe(name); }
                        }
                    });
                }
            };
            if (typeof port.start === 'function') { port.start(); }
        };
    }

})();
