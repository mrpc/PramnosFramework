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
 *   data-pf-refresh-on-return   — on an element with an id: when the reader comes back to the
 *                                 page (the tab is visible or the window focused again), fetch
 *                                 the page as a fragment and put in the element's fresh content
 *                                 and aria-label; nothing is fetched while none is on the page
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
 *   data-pf-composer            — the tools above a message form (Html\MessageComposer): saved
 *                                 messages, placeholders, writing with AI, the signature
 *   data-pf-tool="name"         — on a <form>: a tool an AI agent in the browser can call (WebMCP),
 *                                 listed in window.pfTools and the debug bar's Tools tab
 *   meta[name=pf-realtime]      — the page's live connection (window.pfRealtime): the Pusher
 *                                 protocol to broadcast:serve or Pusher/Reverb; the bell
 *                                 listens on it for notification.created
 *   meta[name=pf-client-errors] — where uncaught errors and rejections are reported
 *                                 (see Logs\ClientErrors); window.pfReportError(error)
 *   data-pf-dashboard           — the dashboard each person arranges (DashboardController):
 *                                 move, resize, hide, add, drag; each change saved at once
 *   data-pf-chart-figure        — a chart (see Html\Chart): drawn with Chart.js in the
 *                                 theme's colours, again when the theme changes
 *   data-pf-show-when="f:a b"   — inside a form, shown only while its field `f` is `a` or `b`
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

    // ── data-pf-filter-rows: a search field that hides the rows it does not match ──
    // `data-pf-filter-rows="#table"` names the table; each row is matched on its
    // `data-pf-row-text`, or on its text where it has none.
    document.addEventListener('input', function (e) {
        var field = e.target;
        if (!field || !field.getAttribute || field.getAttribute('data-pf-filter-rows') === null) { return; }
        var table = document.querySelector(field.getAttribute('data-pf-filter-rows'));
        if (!table) { return; }
        var words = String(field.value || '').toLowerCase().trim().split(/\s+/).filter(Boolean);
        Array.prototype.forEach.call(table.querySelectorAll('tbody tr'), function (row) {
            var text = (row.getAttribute('data-pf-row-text') || row.textContent || '').toLowerCase();
            row.hidden = !words.every(function (w) { return text.indexOf(w) !== -1; });
        });
    });

    // ── data-pf-show-when: a field shown only while another says so ──────────
    // `data-pf-show-when="cadence:weekly monthly_weekday"` on an element inside a form shows
    // it only while the form's `cadence` field holds one of those values. Without the script,
    // everything stays shown; a hidden field is still sent, and the server reads what it uses.
    var showWhen = function (form) {
        Array.prototype.forEach.call(form.querySelectorAll('[data-pf-show-when]'), function (el) {
            var spec  = String(el.getAttribute('data-pf-show-when') || '');
            var at    = spec.indexOf(':');
            var field = at > 0 ? form.querySelector('[name="' + spec.slice(0, at) + '"]') : null;
            if (!field) { return; }
            el.hidden = spec.slice(at + 1).trim().split(/\s+/).indexOf(String(field.value)) === -1;
        });
    };
    Array.prototype.forEach.call(document.querySelectorAll('form'), function (form) {
        if (form.querySelector('[data-pf-show-when]')) { showWhen(form); }
    });
    document.addEventListener('change', function (e) {
        var form = e.target && (e.target.form || (e.target.closest && e.target.closest('form')));
        if (form && form.querySelector && form.querySelector('[data-pf-show-when]')) { showWhen(form); }
    });

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

    // ── data-pf-refresh-on-return ───────────────────────────────────────────
    //
    // A figure that changes because of what the reader went off to do — the clicks of a message
    // whose link they just followed in another window. When they come back, the page is fetched
    // again as a fragment and each marked element takes its fresh content and label, matched by
    // id; the rest of the page, the open tab included, stays as it is. A failed fetch leaves
    // the page alone.
    var returnRefreshAt = 0;
    function refreshOnReturn() {
        var marked = document.querySelectorAll('[data-pf-refresh-on-return][id]');
        // Focus and visibility both fire on one return: one fetch, not two.
        if (!marked.length || Date.now() - returnRefreshAt < 1000) { return; }
        returnRefreshAt = Date.now();
        fetch(window.location.href, { headers: { 'X-Pf-Fragment': '1' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.text() : Promise.reject(new Error('HTTP ' + r.status)); })
            .then(function (html) {
                var fresh = new DOMParser().parseFromString(html, 'text/html');
                Array.prototype.forEach.call(marked, function (el) {
                    var now = fresh.getElementById(el.id);
                    if (!now) { return; }
                    if (now.hasAttribute('aria-label')) { el.setAttribute('aria-label', now.getAttribute('aria-label')); }
                    el.innerHTML = now.innerHTML;
                });
            })
            .catch(function () { /* offline, or the page is gone: what is shown stays */ });
    }
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') { refreshOnReturn(); }
    });
    window.addEventListener('focus', refreshOnReturn);

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
    //   window.pfRealtime.channel('private-conversation.7').whisper('typing', { id: 5 })   // heard as client-typing
    //   window.pfRealtime.channel('presence-chat').listen('pusher:subscription_succeeded', fn)   // { members }
    //       .listen('pusher:member_added', fn).listen('pusher:member_removed', fn)            // { id, info, members }
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

        /**
         * An event to this page's listeners, with `{ lead }`: whether this tab is the one of the
         * browser's that should act once for all — play a sound. The shared worker names one;
         * a page on its own is the lead while it is visible.
         */
        function deliver(channel, event, data, lead) {
            var listeners = channels[channel];
            var meta = { lead: lead === undefined ? !document.hidden : !!lead };
            (listeners && listeners[event] || []).forEach(function (fn) { fn(data, meta); });
        }

        function missed() {
            document.dispatchEvent(new CustomEvent('pf:notifications:refresh', { bubbles: true }));
        }

        // One connection for every tab of the site, held by a SharedWorker running this same
        // file; this page signs the channels it asks for and hears their events. Where there is
        // no SharedWorker (some mobile browsers), or no address to start one from, the page
        // keeps a connection of its own.
        var worker = null;
        var port = null;
        if (typeof window.SharedWorker === 'function' && SELF_SRC) {
            try {
                worker = new window.SharedWorker(SELF_SRC, { name: 'pf-realtime' });
                port = worker.port;
            } catch (e) {
                port = null;
            }
        }

        // What the connection is doing, as the worker last said (or this page's own).
        var status = { socket: 'connecting', socketId: '', subscribed: [], error: null };
        var fallback = null;
        var hooks = {
            sign: sign,
            onEvent: deliver,
            onMissed: missed,
            onStatus: function (state) { status = state; },
        };

        var connection = null;
        /** A connection of the page's own: the worker could not be started, or there is none. */
        function ownConnection(reason) {
            if (port) {
                try { port.close(); } catch (e) { /* gone */ }
                port = null;
            }
            if (typeof window.WebSocket !== 'function') { return null; }
            fallback = reason || null;
            connection = realtimeConnection(config, hooks);
            Object.keys(channels).forEach(function (name) { connection.subscribe(name); });
            return connection;
        }

        if (port) {
            var ready = false;
            // A worker whose script did not load — a wrong MIME type, a CSP on the script, a 404 —
            // says nothing at all: without its `ready` in a few seconds, the page connects itself.
            var watchdog = window.setTimeout(function () {
                if (!ready && port) { ownConnection('The shared worker did not start'); }
            }, 5000);
            if (worker) {
                worker.onerror = function () {
                    window.clearTimeout(watchdog);
                    if (port) { ownConnection('The shared worker failed to load'); }
                };
            }
            port.onmessage = function (message) {
                var m = message.data || {};
                if (m.type === 'ready') {
                    ready = true;
                    window.clearTimeout(watchdog);
                } else if (m.type === 'sign') {
                    sign(m.channel, m.socketId, function (signed) {
                        if (port) { port.postMessage({ type: 'signed', channel: m.channel, signed: signed }); }
                    });
                } else if (m.type === 'event') {
                    deliver(m.channel, m.event, m.data, m.lead);
                } else if (m.type === 'missed') {
                    missed();
                } else if (m.type === 'status') {
                    hooks.onStatus(m.status || {});
                }
            };
            if (typeof port.start === 'function') { port.start(); }
            port.postMessage({ type: 'hello', config: config, visible: !document.hidden });
            window.addEventListener('pagehide', function () { if (port) { port.postMessage({ type: 'bye' }); } });
            // Which tab the worker names to act once for all: the visible one, else the last seen.
            document.addEventListener('visibilitychange', function () {
                if (port) { port.postMessage({ type: 'visible', visible: !document.hidden }); }
            });
            connection = {
                subscribe: function (name) { if (port) { port.postMessage({ type: 'subscribe', channel: name }); } },
                whisper: function (name, event, data) { if (port) { port.postMessage({ type: 'whisper', channel: name, event: event, data: data }); } },
                close: function () { if (port) { port.postMessage({ type: 'bye' }); } },
            };
        } else if (!ownConnection(null)) {
            return;
        }

        window.pfRealtime = {
            transport: config.transport,
            get shared() { return !!port; },
            /**
             * What the connection is doing: `socket` (connecting, open, closed), its id, the
             * channels the server confirmed, and the last error — the worker's, where it is shared
             * — with `shared`, and `fallback`: why a page that tried to share connects itself.
             */
            status: function () {
                return JSON.parse(JSON.stringify(Object.assign({}, status, { shared: !!port, fallback: fallback })));
            },
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
                    /** Stop hearing an event with this listener. */
                    stopListening: function (event, fn) {
                        listeners[event] = (listeners[event] || []).filter(function (one) { return one !== fn; });
                        return this;
                    },
                    /**
                     * A `client-` event to the channel's other subscribers: `whisper('typing', {…})`
                     * is heard as `client-typing`. Needs the server's client events turned on.
                     */
                    whisper: function (event, data) {
                        if (connection && typeof connection.whisper === 'function') { connection.whisper(name, event, data); }
                        return this;
                    },
                };
            },
            close: function () { if (connection) { connection.close(); } },
        };
    })();

    // ══════════════════════════════════════════════════════════════════════════
    // data-pf-composer — the tools above a message form (\Pramnos\Html\MessageComposer)
    //
    // On a container — usually the form — with `data-pf-composer-url` (the Composer controller),
    // `data-pf-composer-scope`, `data-pf-editor-fields` ([{token, label}]) and, optionally,
    // `data-pf-composer-language`; inside it the subject `[data-pf-composer-subject]` and the
    // body `[data-pf-composer-body]`, in whichever editor it has — rich, TinyMCE, builtin or
    // none, all reached through one adapter. Saved messages fill the subject and the body; a
    // placeholder goes in at the cursor of whichever of the two had the focus last, never filled
    // here — a field with a `value` (a message to one person) inserts the value instead; "Write
    // with AI" and "Save this message" open panels under the toolbar, never a browser dialog;
    // "With my signature" adds the signature to the body when the form is sent, once.
    // ══════════════════════════════════════════════════════════════════════════
    (function () {
        /** The body, whatever editor it is in: get, set, insert at the cursor. */
        function bodyOf(field) {
            function rich() { return window.pfRichEditor && window.pfRichEditor.for ? window.pfRichEditor.for(field) : null; }
            function tiny() { return window.tinymce && field.id ? window.tinymce.get(field.id) : null; }
            function insertInField(text) {
                var start = field.selectionStart == null ? field.value.length : field.selectionStart;
                var end = field.selectionEnd == null ? start : field.selectionEnd;
                field.value = field.value.slice(0, start) + text + field.value.slice(end);
                field.selectionStart = field.selectionEnd = start + text.length;
            }
            return {
                get: function () {
                    var r = rich(); if (r) { return r.getHTML(); }
                    var t = tiny(); if (t) { return t.getContent(); }
                    return window.PfEditor && window.PfEditor.getHTML ? window.PfEditor.getHTML(field) : field.value;
                },
                set: function (html) {
                    var r = rich(); if (r) { r.editor.commands.setContent(html || '', { emitUpdate: true }); field.value = r.getHTML(); return; }
                    var t = tiny(); if (t) { t.setContent(html || ''); t.save(); return; }
                    if (window.PfEditor && window.PfEditor.setHTML) { window.PfEditor.setHTML(field, html || ''); } else { field.value = html || ''; }
                },
                insert: function (text) {
                    var r = rich(); if (r) { r.editor.chain().focus().insertContent(text).run(); return; }
                    var t = tiny(); if (t) { t.insertContent(text); return; }
                    if (window.PfEditor && window.PfEditor.insert) { window.PfEditor.insert(field, text); } else { insertInField(text); }
                },
            };
        }

        /** A POST to the composer's controller, with the page's token; the JSON answer. */
        function post(url, data) {
            var body = Object.keys(data).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); }).join('&');
            return window.fetch(url, {
                method: 'POST', credentials: 'same-origin', body: body,
                headers: pfApiHeaders({ 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' }),
            }).then(function (r) { return r.json().catch(function () { return { error: 'HTTP ' + r.status }; }); });
        }

        function wire(box) {
            var url = (box.getAttribute('data-pf-composer-url') || '').replace(/\/+$/, '');
            var scope = box.getAttribute('data-pf-composer-scope') || '';
            var subject = box.querySelector('[data-pf-composer-subject]');
            var field = box.querySelector('[data-pf-composer-body]');
            if (!field) { return; }
            var body = bodyOf(field);
            var bar = box.querySelector('[data-pf-composer-words]');
            var words = {};
            try { words = JSON.parse(bar ? bar.getAttribute('data-pf-composer-words') : '{}') || {}; } catch (e) { words = {}; }
            var fields = [];
            try { fields = JSON.parse(box.getAttribute('data-pf-editor-fields') || '[]') || []; } catch (e) { fields = []; }
            // The values this form put in for their tokens — from the menu, or filling a saved
            // message — so that saving puts the tokens back: a message saved from one person's form
            // greets the next reader by their own name, not by this one's.
            var placed = {};
            function filled(token) {
                var f = fields.filter(function (x) { return x.token === token && x.value != null && String(x.value) !== ''; })[0];
                return f ? String(f.value) : null;
            }
            /** Every placed value back to its token — in text, never inside a tag's markup. */
            function tokensBack(text) {
                // ponytail: matched by value, so the same words typed by hand around a placed one go
                // back too; marks in the markup would tell them apart, and no editor keeps them alike.
                var values = Object.keys(placed).sort(function (a, b) { return b.length - a.length; });
                if (!values.length) { return text; }
                var escape = function (v) { return v.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); };
                var any = new RegExp('(^|[^\\p{L}\\p{N}])(' + values.map(escape).join('|') + ')(?=[^\\p{L}\\p{N}]|$)', 'gu');
                return String(text).split(/(<[^>]*>)/).map(function (part, i) {
                    return i % 2 ? part : part.replace(any, function (m, lead, value) { return lead + placed[value]; });
                }).join('');
            }
            /** A saved message's tokens filled with this form's values, each remembered. */
            function valuesIn(text) {
                return String(text || '').replace(/\{[A-Za-z_][A-Za-z0-9_]*\}/g, function (token) {
                    var value = filled(token);
                    if (value === null) { return token; }
                    placed[value] = token;
                    return value;
                });
            }
            var lastFocus = 'body';
            box.addEventListener('focusin', function (e) {
                if (subject && e.target === subject) { lastFocus = 'subject'; } else if (e.target !== subject && !(e.target.closest && e.target.closest('.pf-composer-toolbar'))) { lastFocus = 'body'; }
            });

            var status = box.querySelector('[data-pf-composer-status]');
            function say(text) { if (status) { status.textContent = text || ''; } }
            function panel(name) { return box.querySelector('[data-pf-composer-' + name + '-panel]'); }
            function open(p, on) {
                Array.prototype.forEach.call(box.querySelectorAll('.pf-composer-panel'), function (x) { x.hidden = true; });
                if (p) { p.hidden = !on; }
            }
            Array.prototype.forEach.call(box.querySelectorAll('[data-pf-composer-cancel]'), function (b) {
                b.addEventListener('click', function () { open(b.closest('.pf-composer-panel'), false); });
            });

            // ── The fields: a dropdown (or a row); the value where one is given, the token otherwise;
            //    pressing one keeps the cursor where it was ──
            var holder = box.querySelector('[data-pf-composer-fields]');
            if (holder) {
                var menu = holder.closest('details');
                if (menu) {
                    menu.querySelector('summary').addEventListener('mousedown', function (e) { e.preventDefault(); });
                    // Closed by a choice, its own toggle, or Escape; a <details> does not close on a
                    // click elsewhere.
                    document.addEventListener('keydown', function (e) {
                        if (e.key === 'Escape' && menu.open) { menu.open = false; }
                    });
                }
                fields.forEach(function (f) {
                    var text = f.value != null ? String(f.value) : f.token;
                    if (text !== f.token && text !== '') { placed[text] = f.token; }
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'pf-composer-field';
                    if (menu) { b.setAttribute('role', 'menuitem'); }
                    b.textContent = f.label || f.token;
                    b.title = text;
                    b.setAttribute('data-token', f.token);
                    b.addEventListener('mousedown', function (e) { e.preventDefault(); });
                    b.addEventListener('click', function () {
                        if (lastFocus === 'subject' && subject) {
                            var start = subject.selectionStart == null ? subject.value.length : subject.selectionStart;
                            var end = subject.selectionEnd == null ? start : subject.selectionEnd;
                            subject.value = subject.value.slice(0, start) + text + subject.value.slice(end);
                            subject.selectionStart = subject.selectionEnd = start + text.length;
                        } else {
                            body.insert(text);
                        }
                        if (menu) { menu.open = false; }
                    });
                    holder.appendChild(b);
                });
            }

            // ── Saved messages ──
            var picker = box.querySelector('[data-pf-composer-saved]');
            var forgetButton = box.querySelector('[data-pf-composer-forget]');
            var saved = [];
            var canShare = false;
            function load() {
                if (!picker || !url) { return Promise.resolve(); }
                return window.fetch(url + '/saved?scope=' + encodeURIComponent(scope), { credentials: 'same-origin', headers: pfApiHeaders({ Accept: 'application/json' }) })
                    .then(function (r) { return r.ok ? r.json() : { messages: [] }; })
                    .then(function (d) {
                        saved = (d && d.messages) || [];
                        canShare = !!(d && d.canShare);
                        var sharedLabel = box.querySelector('[data-pf-composer-shared-label]');
                        if (sharedLabel) { sharedLabel.hidden = !canShare; }
                        // Nothing to choose from: no list, only "Save this message".
                        picker.hidden = saved.length === 0;
                        while (picker.options.length > 1) { picker.remove(1); }
                        saved.forEach(function (m) {
                            var o = document.createElement('option');
                            o.value = String(m.id);
                            o.textContent = m.name + (m.shared ? ' — ' + (words.shared || 'Shared') : '');
                            picker.appendChild(o);
                        });
                    }).catch(function () { /* the form works without them */ });
            }
            function chosen() {
                return picker ? saved.filter(function (m) { return String(m.id) === picker.value; })[0] || null : null;
            }
            if (picker) {
                picker.addEventListener('change', function () {
                    var m = chosen();
                    if (forgetButton) { forgetButton.hidden = !(m && m.canForget); delete forgetButton.dataset.armed; }
                    if (!m) { return; }
                    if (subject) { subject.value = valuesIn(m.subject); }
                    body.set(valuesIn(m.body));
                });
            }
            var savePanel = panel('save');
            var saveButton = box.querySelector('[data-pf-composer-save]');
            if (saveButton && savePanel) {
                var nameField = savePanel.querySelector('[data-pf-composer-name]');
                var sharedBox = savePanel.querySelector('[data-pf-composer-shared]');
                saveButton.addEventListener('click', function () {
                    var current = chosen();
                    nameField.value = current ? current.name : (subject ? subject.value : '');
                    if (sharedBox) { sharedBox.checked = !!(current && current.shared); }
                    open(savePanel, savePanel.hidden);
                    if (!savePanel.hidden) { nameField.focus(); }
                });
                savePanel.querySelector('[data-pf-composer-save-go]').addEventListener('click', function () {
                    var name = nameField.value.trim();
                    if (!name) { say(words.name || 'A name is needed.'); nameField.focus(); return; }
                    var shared = canShare && sharedBox && sharedBox.checked;
                    post(url + '/save', { name: name, subject: subject ? tokensBack(subject.value) : '', body: tokensBack(body.get()), scope: scope, shared: shared ? '1' : '0' })
                        .then(function (d) {
                            if (!d || d.error) { say((d && d.error) || words.failed); return; }
                            open(savePanel, false);
                            say(words.saved || 'Saved.');
                            return load().then(function () { picker.value = String(d.saved); });
                        });
                });
            }
            if (forgetButton) {
                // Twice to forget: the first press asks, in the button's own words.
                var forgetLabel = forgetButton.textContent;
                forgetButton.addEventListener('click', function () {
                    var m = chosen();
                    if (!m) { return; }
                    if (!forgetButton.dataset.armed) {
                        forgetButton.dataset.armed = '1';
                        forgetButton.textContent = words.forget || 'Click again to forget it';
                        return;
                    }
                    delete forgetButton.dataset.armed;
                    forgetButton.textContent = forgetLabel;
                    post(url + '/forget', { id: String(m.id) }).then(function (d) {
                        if (!d || d.error) { say((d && d.error) || words.failed); return; }
                        forgetButton.hidden = true;
                        return load();
                    });
                });
            }
            load();

            // ── Write with AI: an instruction, or nothing to improve what is written; Undo puts
            //    back the subject and the body it replaced ──
            var writePanel = panel('write');
            var writeButton = box.querySelector('[data-pf-composer-write]');
            if (writeButton && writePanel) {
                var instruction = writePanel.querySelector('[data-pf-composer-instruction]');
                var go = writePanel.querySelector('[data-pf-composer-write-go]');
                var undo = writePanel.querySelector('[data-pf-composer-undo]');
                var before = null;
                var label = function () { go.textContent = instruction.value.trim() ? (words.write || 'Write') : (words.improve || 'Improve'); };
                instruction.addEventListener('input', label);
                writeButton.addEventListener('click', function () {
                    open(writePanel, writePanel.hidden);
                    if (!writePanel.hidden) { label(); instruction.focus(); }
                });
                go.addEventListener('click', function () {
                    go.disabled = true;
                    writePanel.setAttribute('aria-busy', 'true');
                    go.textContent = words.writing || '…';
                    say('');
                    var was = { subject: subject ? subject.value : '', body: body.get() };
                    post(url + '/write', {
                        instruction: instruction.value.trim(), subject: was.subject, body: was.body,
                        language: box.getAttribute('data-pf-composer-language') || '',
                        fields: JSON.stringify(fields.map(function (f) { return f.token; })),
                    }).then(function (d) {
                        if (!d || d.error) { say((d && d.error) || words.failed); return; }
                        before = was;
                        if (subject && d.subject) { subject.value = d.subject; }
                        if (d.body) { body.set(d.body); }
                        undo.hidden = false;
                    }).catch(function () { say(words.failed || 'That did not work.'); })
                        .then(function () { go.disabled = false; writePanel.removeAttribute('aria-busy'); label(); });
                });
                undo.addEventListener('click', function () {
                    if (!before) { return; }
                    if (subject) { subject.value = before.subject; }
                    body.set(before.body);
                    before = null;
                    undo.hidden = true;
                });
            }

            // ── The signature, added when the form is sent; once ──
            var signature = box.querySelector('[data-pf-composer-signature]');
            var form = box.tagName === 'FORM' ? box : box.closest('form');
            if (signature && form) {
                form.addEventListener('submit', function () {
                    var sig = signature.getAttribute('data-signature') || '';
                    if (!signature.checked || sig === '') { return; }
                    var html = body.get();
                    if (html.indexOf(sig) === -1) { body.set(html + sig); }
                }, true);
            }
        }

        Array.prototype.forEach.call(document.querySelectorAll('[data-pf-composer]'), function (box) {
            // After the editors: they enhance on DOMContentLoaded too, and the adapter asks them.
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function () { wire(box); });
            } else {
                wire(box);
            }
        });
    })();

    // ══════════════════════════════════════════════════════════════════════════
    // data-pf-tool — the page's tools for an AI agent in the browser (WebMCP)
    //
    //   <form method="post" action="/admin/Invoices/move" data-pf-tool="move_invoice"
    //         data-pf-tool-description="Move an invoice to another customer">
    //
    // A marked form is a tool: its fields are the tool's input, described by their label (or
    // data-pf-tool-param, aria-label, placeholder) and typed by their control — a number, a
    // box to tick, a choice of options — with `required` kept. Calling it fills the form and
    // sends it as the page would, with its CSRF field, asking for the answering page's content
    // alone (X-Pf-Fragment: 1); the result is the status, the address it ended on and the
    // text of that page. A POST form is marked consequential, a GET one read-only, and
    // data-pf-tool-readonly says a POST changes nothing.
    //
    // window.pfTools lists them, runs one (the debug bar does), takes a tool written in script
    // (`register({name, description, inputSchema, execute})`) and keeps the last calls. Where
    // the browser has WebMCP — document.modelContext, navigator.modelContext before it — each
    // tool is also registered with it; without, nothing is offered to an agent and the list is
    // still there for the debug bar. Every call dispatches `pf:tool:call` on the document, and
    // the list changing `pf:tools:change`.
    // ══════════════════════════════════════════════════════════════════════════
    (function () {
        var tools = {};
        var calls = [];
        var CALLS = 50;
        var RESULT_TEXT = 4000;

        function modelContext() {
            return document.modelContext || (window.navigator && window.navigator.modelContext) || null;
        }

        function changed() {
            document.dispatchEvent(new CustomEvent('pf:tools:change', { bubbles: true }));
        }

        /** The controls a tool fills: named, enabled, and not the form's own plumbing. */
        function controlsOf(form) {
            return Array.prototype.filter.call(form.querySelectorAll('input, select, textarea'), function (el) {
                var type = (el.getAttribute('type') || '').toLowerCase();
                return el.getAttribute('name') && !el.disabled
                    && ['hidden', 'submit', 'button', 'reset', 'image', 'file', 'password'].indexOf(type) === -1;
            });
        }

        /** What a field is called for an agent: its own description, its label, or its name. */
        function describe(el, form) {
            var label = el.getAttribute('id') ? form.querySelector('label[for="' + el.getAttribute('id') + '"]') : null;
            label = label || (el.closest ? el.closest('label') : null);
            return (el.getAttribute('data-pf-tool-param')
                || (label && label.textContent ? label.textContent.trim() : '')
                || el.getAttribute('aria-label') || el.getAttribute('placeholder') || el.getAttribute('name')).trim();
        }

        /** The JSON Schema of a form's fields. */
        function schemaOf(form) {
            var properties = {};
            var required = [];
            var seen = {};
            controlsOf(form).forEach(function (el) {
                var name = el.getAttribute('name');
                var key = name.replace(/\[\]$/, '');
                var type = (el.getAttribute('type') || '').toLowerCase();
                var tag = el.tagName.toLowerCase();
                var prop = properties[key] || { description: describe(el, form) };
                if (tag === 'select') {
                    var values = Array.prototype.map.call(el.querySelectorAll('option'), function (o) {
                        return o.getAttribute('value') !== null ? o.getAttribute('value') : o.textContent;
                    });
                    prop = el.multiple ? { type: 'array', items: { type: 'string', enum: values }, description: prop.description }
                        : { type: 'string', enum: values, description: prop.description };
                } else if (type === 'checkbox' && (seen[key] || /\[\]$/.test(name))) {
                    prop.type = 'array';
                    prop.items = prop.items || { type: 'string', enum: [] };
                    prop.items.enum.push(el.getAttribute('value') || 'on');
                    delete prop.enum;
                } else if (type === 'checkbox') {
                    prop.type = 'boolean';
                } else if (type === 'radio') {
                    prop.type = 'string';
                    prop.enum = (prop.enum || []).concat([el.getAttribute('value') || 'on']);
                } else if (type === 'number' || type === 'range') {
                    prop.type = 'number';
                    if (el.getAttribute('min') !== null) { prop.minimum = Number(el.getAttribute('min')); }
                    if (el.getAttribute('max') !== null) { prop.maximum = Number(el.getAttribute('max')); }
                } else {
                    prop.type = 'string';
                    if (['email', 'url', 'date', 'time', 'datetime-local'].indexOf(type) !== -1) {
                        prop.format = type === 'datetime-local' ? 'date-time' : type;
                    }
                    if (el.getAttribute('maxlength') !== null) { prop.maxLength = Number(el.getAttribute('maxlength')); }
                }
                seen[key] = true;
                properties[key] = prop;
                if (el.required && required.indexOf(key) === -1) { required.push(key); }
            });

            return { type: 'object', properties: properties, required: required };
        }

        /** Put an agent's input into the form's fields. */
        function fill(form, input) {
            controlsOf(form).forEach(function (el) {
                var key = el.getAttribute('name').replace(/\[\]$/, '');
                if (!input || !Object.prototype.hasOwnProperty.call(input, key)) { return; }
                var value = input[key];
                var type = (el.getAttribute('type') || '').toLowerCase();
                if (type === 'checkbox') {
                    el.checked = Array.isArray(value) ? value.map(String).indexOf(el.getAttribute('value') || 'on') !== -1 : !!value;
                } else if (type === 'radio') {
                    el.checked = String(value) === (el.getAttribute('value') || 'on');
                } else if (el.tagName.toLowerCase() === 'select') {
                    var wanted = (Array.isArray(value) ? value : [value]).map(String);
                    Array.prototype.forEach.call(el.querySelectorAll('option'), function (o) {
                        o.selected = wanted.indexOf(o.getAttribute('value') !== null ? o.getAttribute('value') : o.textContent) !== -1;
                    });
                } else {
                    el.value = value === null || value === undefined ? '' : String(value);
                }
            });
        }

        var ENTITIES = { '&nbsp;': ' ', '&amp;': '&', '&lt;': '<', '&gt;': '>', '&quot;': '"' };

        /**
         * A page's text, for an agent to read: the markup and its scripts left out.
         *
         * Entities decode in one pass, by lookup, not as five chained `.replace()` calls —
         * chaining decodes `&amp;lt;` (the literal text "&lt;") in two steps: `&amp;` first
         * becomes `&`, leaving `&lt;` for the *next* `.replace()` to decode a second time,
         * into a real `<` the source never had (CodeQL js/double-escaping). One pass can only
         * ever match each source character once.
         */
        function textOf(html) {
            return String(html).replace(/<(script|style)\b[\s\S]*?<\/\1>/gi, ' ').replace(/<[^>]+>/g, ' ')
                .replace(/&(?:nbsp|amp|lt|gt|quot);|&#0?39;/g, function (m) { return ENTITIES[m] || "'"; })
                .replace(/\s+/g, ' ').trim();
        }

        /** What the form would send: its named, enabled fields — hidden ones included — as pairs. */
        function pairsOf(form) {
            var pairs = [];
            Array.prototype.forEach.call(form.querySelectorAll('input, select, textarea'), function (el) {
                var name = el.getAttribute('name');
                var type = (el.getAttribute('type') || '').toLowerCase();
                if (!name || el.disabled || ['submit', 'button', 'reset', 'image', 'file'].indexOf(type) !== -1) { return; }
                if ((type === 'checkbox' || type === 'radio') && !el.checked) { return; }
                if (el.tagName.toLowerCase() === 'select') {
                    Array.prototype.forEach.call(el.querySelectorAll('option'), function (o) {
                        if (o.selected) { pairs.push([name, o.getAttribute('value') !== null ? o.getAttribute('value') : o.textContent]); }
                    });
                    return;
                }
                pairs.push([name, type === 'checkbox' || type === 'radio' ? (el.getAttribute('value') || 'on') : String(el.value === undefined ? '' : el.value)]);
            });

            return pairs.map(function (p) { return encodeURIComponent(p[0]) + '=' + encodeURIComponent(p[1]); }).join('&');
        }

        /** Send a form as the page would, asking for the answer's content alone. */
        function send(form, signal) {
            var method = (form.getAttribute('method') || 'get').toUpperCase();
            var action = form.getAttribute('action') || window.location.href;
            var data = pairsOf(form);
            var init = { method: method, credentials: 'same-origin', headers: { 'X-Pf-Fragment': '1' }, signal: signal };
            if (method === 'GET') {
                // As a browser does: the fields replace the address's query.
                action = action.split('#')[0].split('?')[0] + (data === '' ? '' : '?' + data);
            } else {
                init.headers['Content-Type'] = 'application/x-www-form-urlencoded';
                init.body = data;
            }

            return window.fetch(action, init).then(function (response) {
                return response.text().then(function (html) {
                    var text = textOf(html);
                    return { ok: response.ok, status: response.status, url: response.url || action,
                        text: text.length > RESULT_TEXT ? text.slice(0, RESULT_TEXT) + '…' : text };
                });
            });
        }

        /** A form as a tool. */
        function formTool(form) {
            var post = (form.getAttribute('method') || 'get').toUpperCase() !== 'GET';
            var readOnly = !post || form.getAttribute('data-pf-tool-readonly') !== null;

            return {
                name: form.getAttribute('data-pf-tool'),
                title: form.getAttribute('data-pf-tool-title') || undefined,
                description: form.getAttribute('data-pf-tool-description') || form.getAttribute('aria-label') || form.getAttribute('data-pf-tool'),
                inputSchema: schemaOf(form),
                annotations: { readOnlyHint: readOnly, consequentialHint: !readOnly },
                execute: function (input, options) {
                    fill(form, input || {});
                    // An agent may call long after the page loaded: a POST takes the session's
                    // current token first, as a form sent by hand does (pfRefreshCsrf).
                    var fresh = post && typeof window.pfRefreshCsrf === 'function'
                        ? Promise.resolve(window.pfRefreshCsrf()).catch(function () { /* send as it is */ })
                        : Promise.resolve();
                    return fresh.then(function () { return send(form, options && options.signal); });
                },
            };
        }

        /**
         * Offer a tool: kept in the list, registered with the browser's WebMCP where there is
         * one. A second tool under a name replaces the first.
         */
        function register(tool, source) {
            if (!tool || !tool.name || typeof tool.execute !== 'function') { return null; }
            unregister(tool.name);
            var entry = { tool: tool, source: source || 'script', controller: null };
            var offered = {
                name: tool.name,
                title: tool.title,
                description: tool.description || tool.name,
                inputSchema: tool.inputSchema || { type: 'object', properties: {} },
                annotations: tool.annotations,
                execute: function (input, options) { return run(tool.name, input, 'agent', options); },
            };
            var context = modelContext();
            if (context && typeof context.registerTool === 'function') {
                try {
                    entry.controller = typeof AbortController === 'function' ? new AbortController() : null;
                    var registered = context.registerTool(offered, entry.controller ? { signal: entry.controller.signal } : {});
                    if (registered && typeof registered.catch === 'function') {
                        registered.catch(function (e) { entry.error = String(e && e.message || e); changed(); });
                    }
                } catch (e) {
                    entry.error = String(e && e.message || e);
                }
            }
            tools[tool.name] = entry;
            changed();

            return function () { unregister(tool.name); };
        }

        function unregister(name) {
            var entry = tools[name];
            if (!entry) { return; }
            if (entry.controller) { entry.controller.abort(); }
            delete tools[name];
            changed();
        }

        /** Run a tool, keep the call, and say so — whoever asked: an agent, or the page. */
        function run(name, input, by, options) {
            var entry = tools[name];
            var call = { name: name, input: input === undefined ? {} : input, by: by || 'page', at: new Date(), ms: null, result: undefined, error: null };
            calls.push(call);
            if (calls.length > CALLS) { calls.shift(); }
            if (!entry) {
                call.error = 'No tool named ' + name;
                document.dispatchEvent(new CustomEvent('pf:tool:call', { bubbles: true, detail: call }));
                return Promise.reject(new Error(call.error));
            }
            var started = Date.now();
            var finished = function () {
                call.ms = Date.now() - started;
                document.dispatchEvent(new CustomEvent('pf:tool:call', { bubbles: true, detail: call }));
            };

            return Promise.resolve().then(function () {
                return entry.tool.execute(call.input, options || {});
            }).then(function (result) {
                call.result = result;
                finished();
                return result;
            }, function (e) {
                call.error = String(e && e.message || e);
                finished();
                throw e;
            });
        }

        /**
         * The page's search box (data-pf-omnibox, Html\SearchBox) as a tool: the same endpoint,
         * answering with the matches by kind — what an agent asks first, "find X".
         */
        function searchTool(box) {
            var url = box.getAttribute('data-pf-omnibox-url') || '';
            return {
                name: 'site_search',
                description: 'Search this application for records of every kind it indexes — people, orders, pages — by words in them. Answers with the matches by kind, each with a title, a line about it, and its address.',
                inputSchema: { type: 'object', properties: { q: { type: 'string', description: 'The words to look for' } }, required: ['q'] },
                annotations: { readOnlyHint: true, consequentialHint: false },
                execute: function (input, options) {
                    return window.fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(String((input && input.q) || '')), {
                        credentials: 'same-origin',
                        headers: pfApiHeaders({ Accept: 'application/json' }),
                        signal: options && options.signal,
                    }).then(function (response) {
                        if (!response.ok) { throw new Error('The search answered ' + response.status); }
                        return response.json();
                    }).then(function (payload) {
                        return { groups: ((payload && payload.groups) || []).map(function (group) {
                            return { kind: group.label, total: group.total, results: (group.results || []).map(function (row) {
                                return { title: row.title, about: row.subtitle || '', url: row.url || null };
                            }) };
                        }) };
                    });
                },
            };
        }

        /** Offer every marked form in `root` not offered yet, and the page's search box. */
        function scan(root) {
            var box = (root || document).querySelector('[data-pf-omnibox][data-pf-omnibox-url]');
            if (box && !tools.site_search) { register(searchTool(box), 'search'); }
            Array.prototype.forEach.call((root || document).querySelectorAll('form[data-pf-tool]'), function (form) {
                var name = form.getAttribute('data-pf-tool');
                if (name && (!tools[name] || tools[name].form !== form)) {
                    register(formTool(form), 'form');
                    tools[name].form = form;
                }
            });
        }

        window.pfTools = {
            /** Whether this browser offers the tools to an agent (WebMCP). */
            get supported() { return !!(modelContext() && typeof modelContext().registerTool === 'function'); },
            /** The tools, as offered: name, description, inputSchema, annotations, and where from. */
            list: function () {
                return Object.keys(tools).map(function (name) {
                    var t = tools[name].tool;
                    return { name: name, title: t.title, description: t.description, inputSchema: t.inputSchema,
                        annotations: t.annotations, source: tools[name].source, error: tools[name].error || null };
                });
            },
            /** Run one, as the page — the debug bar's Run. */
            call: function (name, input) { return run(name, input, 'page'); },
            /** The last calls, oldest first. */
            calls: function () { return calls.slice(); },
            register: function (tool) { return register(tool, 'script'); },
            unregister: unregister,
            scan: scan,
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () { scan(document); });
        } else {
            scan(document);
        }
        // A form that arrives in a dialog is a tool while the dialog shows it.
        document.addEventListener('pf:modal:open', function (e) {
            if (e.detail && e.detail.dialog) { scan(e.detail.dialog); }
        });
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
                unread = n;
                if (titleFlash && !flashing) { document.title = titled(n); }
                if (!count) { return; }
                count.textContent = n > 99 ? '99+' : String(n);
                count.hidden = n <= 0;
                toggle.setAttribute('aria-label', (toggle.getAttribute('data-label') || 'Notifications') + (n > 0 ? ' (' + n + ')' : ''));
            }

            // ── A notification arriving: the tab's title, a sound, and pf:notification ──
            //
            // data-title-flash (on unless "0"): the title carries "(N) " while N are unread, and
            // when one arrives it alternates with that notification's title — until the tab is
            // looked at, or for ten seconds if it already is — then is put back. data-sound: a
            // sound's address, or "beep" for a short tone made here; played once per browser,
            // by the tab the shared connection names (the visible one, else the last seen), and
            // silently not at all where the browser refuses to play without a click.
            var titleFlash = bell.getAttribute('data-title-flash') !== '0';
            var sound      = bell.getAttribute('data-sound') || '';
            var baseTitle  = document.title.replace(/^\(\d+\+?\) /, '');
            var unread     = 0;
            var flashing   = null;

            function titled(n) {
                return (n > 0 ? '(' + (n > 99 ? '99+' : n) + ') ' : '') + baseTitle;
            }

            function stopFlash() {
                if (flashing) { window.clearInterval(flashing.timer); window.clearTimeout(flashing.end); }
                flashing = null;
                document.title = titled(unread);
            }

            function flash(text) {
                if (!titleFlash || !text) { return; }
                stopFlash();
                var on = false;
                flashing = { timer: window.setInterval(function () {
                    on = !on;
                    document.title = on ? '(' + (unread > 99 ? '99+' : unread) + ') ' + text : titled(unread);
                }, 1000), end: null };
                if (!document.hidden) { flashing.end = window.setTimeout(stopFlash, 10000); }
            }

            function play() {
                try {
                    if (sound === 'beep') {
                        var Audio_ = window.AudioContext || window.webkitAudioContext;
                        if (!Audio_) { return; }
                        var context = new Audio_();
                        var tone = context.createOscillator();
                        var gain = context.createGain();
                        tone.frequency.value = 880;
                        gain.gain.setValueAtTime(0.15, context.currentTime);
                        gain.gain.exponentialRampToValueAtTime(0.001, context.currentTime + 0.4);
                        tone.connect(gain);
                        gain.connect(context.destination);
                        tone.start();
                        tone.stop(context.currentTime + 0.4);
                    } else if (sound && typeof window.Audio === 'function') {
                        var played = new window.Audio(sound).play();
                        if (played && typeof played.catch === 'function') { played.catch(function () { /* no click yet: refused */ }); }
                    }
                } catch (e) {
                    /* a sound is not worth an error */
                }
            }

            /**
             * One arrived: refresh, then read it with this page's session, and tell the page.
             *
             * The title flashes and the sound plays only for a notification found by its id and
             * still unread: one written already read — kept for the record, a change the user made
             * themselves — or read in another tab before this one asked, is not news. Never a
             * guess at another item when the id is not among the newest.
             */
            function arrived(data, meta) {
                refresh();
                var id = data && data.id ? String(data.id) : '';
                get('?per_page=5').then(function (d) {
                    var items = d.items || [];
                    var it = id ? items.filter(function (one) { return String(one.id) === id; })[0] || null : null;
                    showCount(d.unread || 0);
                    document.dispatchEvent(new CustomEvent('pf:notification', { bubbles: true, detail: {
                        id: it ? it.id : id, type: it ? it.type : '', title: it ? it.title : '', data: it ? it.data : {},
                        read: it ? !!it.read : null, item: it,
                    } }));
                    if (!it || it.read || !(d.unread > 0)) { return; }
                    flash(it.title || it.message);
                    if (sound && meta && meta.lead) { play(); }
                }).catch(function () { /* the count is refreshed; the rest waits for the next read */ });
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
                if (document.hidden) { stop(); } else { stopFlash(); refresh(); start(); }
            });
            document.addEventListener('pf:notifications:refresh', refresh);

            var channel = bell.getAttribute('data-channel');
            var rt = window.pfRealtime;
            if (channel && rt) {
                if (rt.transport === 'sse' && rt.on) {
                    rt.on('notification.created', refresh);
                } else if (rt.channel) {
                    rt.channel(channel).listen('notification.created', arrived);
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
                // __proto__/constructor/prototype: a chart config is server-rendered JSON
                // today, but this is a general-purpose deep merge a scaffolded app can call
                // with anything — skipping these three is what keeps a key that walks the
                // prototype chain from reassigning it instead of setting an own property
                // (CodeQL js/prototype-pollution-utility).
                if (key === '__proto__' || key === 'constructor' || key === 'prototype') {
                    return;
                }
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
        var confirmed = {};
        var presence = {};   // presence channel -> { id: info }, the members as the server last said
        var lastError = null;

        /** Tell whoever holds this connection what it is doing. */
        function report(socketState) {
            if (typeof hooks.onStatus !== 'function') { return; }
            hooks.onStatus({ socket: socketState, socketId: socketId, subscribed: Object.keys(confirmed), error: lastError });
        }

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
                } else if (!signed && names[name]) {
                    lastError = 'The auth endpoint did not sign ' + name;
                    report(established ? 'open' : 'connecting');
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
                lastError = 'WebSocket refused: ' + String(e && e.message || e);
                report('closed');
                return;
            }
            report('connecting');
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
                    confirmed = {};
                    report('open');
                    Object.keys(names).forEach(join);
                    if (missed) {
                        missed = false;
                        hooks.onMissed();
                    }
                    return;
                }
                if (frame.event === 'pusher:ping') { send('pusher:pong', {}); return; }
                if (frame.event === 'pusher_internal:subscription_succeeded') {
                    confirmed[frame.channel] = true;
                    report('open');
                    // A presence channel says who is in it: its listeners hear the members.
                    if (frame.channel.indexOf('presence-') === 0 && names[frame.channel]) {
                        presence[frame.channel] = Object.assign({}, (data && data.presence && data.presence.hash) || {});
                        hooks.onEvent(frame.channel, 'pusher:subscription_succeeded', { members: presence[frame.channel] });
                    }
                    return;
                }
                if ((frame.event === 'pusher_internal:member_added' || frame.event === 'pusher_internal:member_removed') && presence[frame.channel]) {
                    var who = String((data && data.user_id) || '');
                    // A member id of __proto__/constructor/prototype would walk the prototype
                    // chain instead of setting an own property on presence[frame.channel] —
                    // the server names this id, but a channel's own members (whoever else is
                    // in it) are what the realtime server echoes back as `user_id`, not a
                    // value this client minted (CodeQL js/prototype-polluting-assignment).
                    if (who === '__proto__' || who === 'constructor' || who === 'prototype') {
                        return;
                    }
                    if (frame.event === 'pusher_internal:member_added') {
                        presence[frame.channel][who] = (data && data.user_info) || {};
                    } else {
                        delete presence[frame.channel][who];
                    }
                    hooks.onEvent(frame.channel, frame.event.replace('pusher_internal:', 'pusher:'), { id: who, info: (data && data.user_info) || {}, members: presence[frame.channel] });
                    return;
                }
                if (frame.event === 'pusher:error' || frame.event === 'pusher:subscription_error') {
                    lastError = frame.event + ': ' + (typeof data === 'object' && data ? (data.message || JSON.stringify(data)) : String(data));
                    report(established ? 'open' : 'connecting');
                    return;
                }
                if (!frame.event || frame.event.indexOf('pusher') === 0 || !names[frame.channel]) { return; }
                hooks.onEvent(frame.channel, frame.event, data);
            };
            socket.onclose = function () {
                quiet();
                confirmed = {};
                presence = {};
                report('closed');
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
                delete confirmed[name];
                delete presence[name];
                if (established) { send('pusher:unsubscribe', { channel: name }); }
            },
            /**
             * A `client-` event to the channel's other subscribers — typing, say. Only on a
             * private or presence channel the server confirmed, and only where the server allows
             * client events; otherwise nothing, which is right for a cue nobody must rely on.
             */
            whisper: function (name, event, data) {
                if (!established || !confirmed[name] || name.indexOf('public') === 0 || (name.indexOf('private-') !== 0 && name.indexOf('presence-') !== 0)) { return; }
                try { socket.send(JSON.stringify({ event: 'client-' + event, channel: name, data: data })); } catch (e) { /* the close handler deals with it */ }
            },
            /** A presence channel's members as last told, or null before the server said. */
            members: function (name) {
                return presence[name] ? Object.assign({}, presence[name]) : null;
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
        var lastStatus = { socket: 'connecting', socketId: '', subscribed: [], error: null };
        var seen = [];   // [{ port, visible, at }] — when each tab was last visible

        /** The tab to act once for all: a visible one, the most recently seen; else the most recently seen. */
        function lead(candidates) {
            var best = null;
            seen.forEach(function (s) {
                if (candidates.indexOf(s.port) === -1) { return; }
                if (!best || (s.visible && !best.visible) || (s.visible === best.visible && s.at > best.at)) { best = s; }
            });
            return best ? best.port : candidates[0];
        }

        function sighting(port, visible) {
            var s = seen.filter(function (one) { return one.port === port; })[0];
            if (!s) { s = { port: port, visible: false, at: 0 }; seen.push(s); }
            s.visible = !!visible;
            if (visible) { s.at = Date.now(); }
        }

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
                var ports = listening[channel] || [];
                var first = lead(ports);
                ports.forEach(function (port) {
                    port.postMessage({ type: 'event', channel: channel, event: event, data: data, lead: port === first });
                });
            },
            onMissed: function () {
                ports.forEach(function (port) { port.postMessage({ type: 'missed' }); });
            },
            onStatus: function (state) {
                lastStatus = state;
                ports.forEach(function (port) { port.postMessage({ type: 'status', status: state }); });
            },
        };

        self.onconnect = function (event) {
            var port = event.ports[0];
            ports.push(port);
            port.onmessage = function (message) {
                var m = message.data || {};
                if (m.type === 'visible') {
                    sighting(port, m.visible);
                } else if (m.type === 'hello') {
                    sighting(port, m.visible);
                    // Alive: the tab stops waiting for a worker that never started, and hears
                    // what the connection is doing so far.
                    port.postMessage({ type: 'ready' });
                    port.postMessage({ type: 'status', status: lastStatus });
                    if (!connection && m.config) { connection = realtimeConnection(m.config, hooks); }
                } else if (m.type === 'subscribe' && connection) {
                    listening[m.channel] = without(listening[m.channel] || [], port).concat([port]);
                    connection.subscribe(m.channel);
                    // A tab joining a presence channel the connection already holds: it was not
                    // there when the server said who is in it, so it is told now.
                    var members = connection.members(m.channel);
                    if (members) {
                        port.postMessage({ type: 'event', channel: m.channel, event: 'pusher:subscription_succeeded', data: { members: members }, lead: false });
                    }
                } else if (m.type === 'whisper' && connection) {
                    connection.whisper(m.channel, m.event, m.data);
                } else if (m.type === 'signed' && signing[m.channel] && signing[m.channel].port === port) {
                    var pending = signing[m.channel];
                    delete signing[m.channel];
                    pending.done(m.signed);
                } else if (m.type === 'bye') {
                    ports = without(ports, port);
                    seen = seen.filter(function (one) { return one.port !== port; });
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

/*
 * pfAssistant — the analyst's living picture (Assist\AvatarSets). Every `.pf-assistant-avatar` on
 * the page carries `data-state` and a `pf-assistant--{state}` class; this changes them, and
 * nothing else — the movement is the set's CSS. A state that ends by itself (`success`,
 * `greeting`, `notify`) goes back to `idle` after the set's duration (`data-durations`, ms).
 * `pf-assistant:state` is dispatched on the document with `{ state }`, so the application can
 * follow it; `pfAssistant.state('thinking')` lets it drive it too. `within` limits the change to
 * the pictures inside an element.
 */
(function () {
    // Not in a worker: the shared connection's worker loads this file too.
    if (typeof window === 'undefined' || typeof document === 'undefined') { return; }
    var STATES = ['idle', 'listening', 'thinking', 'talking', 'success', 'error', 'offline', 'greeting', 'notify'];
    var timers = new Map();

    function apply(avatar, state) {
        STATES.forEach(function (s) { avatar.classList.remove('pf-assistant--' + s); });
        avatar.classList.add('pf-assistant--' + state);
        avatar.setAttribute('data-state', state);
        if (timers.has(avatar)) {
            window.clearTimeout(timers.get(avatar));
            timers.delete(avatar);
        }
        var durations = {};
        try { durations = JSON.parse(avatar.getAttribute('data-durations') || '{}') || {}; } catch (e) { durations = {}; }
        if (durations[state] > 0) {
            timers.set(avatar, window.setTimeout(function () {
                timers.delete(avatar);
                if (avatar.getAttribute('data-state') === state) { apply(avatar, 'idle'); }
            }, durations[state]));
        }
    }

    function state(name, within) {
        if (STATES.indexOf(name) === -1) { return; }
        var root = within && within.querySelectorAll ? within : document;
        Array.prototype.forEach.call(root.querySelectorAll('.pf-assistant-avatar'), function (avatar) {
            // Offline stays offline: the analyst cannot answer here, whatever the page does.
            if (avatar.getAttribute('data-state') === 'offline' && avatar.hasAttribute('data-offline')) { return; }
            apply(avatar, name);
        });
        document.dispatchEvent(new CustomEvent('pf-assistant:state', { bubbles: true, detail: { state: name } }));
    }

    window.pfAssistant = { state: state, states: STATES.slice() };

    // A scheduled task's answer arrived for the reader: the analyst says so.
    document.addEventListener('pf:notification', function (event) {
        var type = String((event.detail && event.detail.type) || '');
        if (/RoutineReady|routine/i.test(type)) { state('notify'); }
    });
})();

/*
 * data-pf-people — a field of names, comma-separated (who to share with, a report's readers). As
 * a name is typed after the last comma, the page's API is asked who matches it — `GET <url>?q=` —
 * and the people it answers are offered, without those already written. The address is the
 * field's own `data-pf-people="<url>"`, or that of the page around it (`data-pf-people-url`);
 * without one, nothing is asked.
 *
 * Asked sparingly: two characters at least, a pause of PAUSE_MS after the last key, the request
 * before it cancelled when a new one leaves, every answer kept for its term, and an answer shown
 * only while the field still says what it was asked for. Typing a whole name is one request.
 */
(function () {
    if (typeof document === 'undefined') { return; }

    var PAUSE_MS = 300;
    /** What each address answered, by term. */
    var answered = {};
    var waiting = null;
    var asking = null;

    /** The name being typed: what follows the last comma, and the names written before it. */
    function typing(field) {
        var parts = String(field.value).split(',');
        var typed = parts.pop().trim();
        var written = parts.map(function (part) { return part.trim(); }).filter(Boolean);

        return { typed: typed, written: written };
    }

    /** The offered list beside a field: a browser shows an option only where it starts with what is in the field. */
    function offer(field, people) {
        var id = 'pf-people-offered';
        var shown = document.getElementById(id);
        var now = typing(field);
        var lead = now.written.length ? now.written.join(', ') + ', ' : '';
        if (!shown) {
            shown = document.createElement('datalist');
            shown.setAttribute('id', id);
            document.body.appendChild(shown);
        }
        field.setAttribute('list', id);
        shown.textContent = '';
        people.filter(function (one) { return now.written.indexOf(one.value) === -1; }).forEach(function (one) {
            var option = document.createElement('option');
            option.setAttribute('value', lead + one.value);
            option.textContent = one.label || one.value;
            shown.appendChild(option);
        });
    }

    document.addEventListener('input', function (event) {
        var field = event.target;
        if (!field || !field.hasAttribute || !field.hasAttribute('data-pf-people')) { return; }
        var page = field.closest ? field.closest('[data-pf-people-url]') : null;
        var url = field.getAttribute('data-pf-people') || (page ? page.getAttribute('data-pf-people-url') : '');
        if (!url) { return; }

        var term = typing(field).typed;
        window.clearTimeout(waiting);
        if (term.length < 2) {
            offer(field, []);

            return;
        }
        var cache = answered[url] || (answered[url] = {});
        if (cache[term]) {
            offer(field, cache[term]);

            return;
        }
        waiting = window.setTimeout(function () {
            if (asking) { asking.abort(); }
            asking = typeof AbortController === 'function' ? new AbortController() : null;
            window.fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(term), {
                headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: asking ? asking.signal : undefined
            }).then(function (r) { return r.json(); }).then(function (body) {
                var people = (body && body.data && body.data.people) || [];
                cache[term] = people;
                // Shown only if the field still asks for it: a slower answer to an older term is not.
                if (typing(field).typed === term) { offer(field, people); }
            }).catch(function () { /* cancelled, or not answered: the field stays a plain one */ });
        }, PAUSE_MS);
    });
}());
