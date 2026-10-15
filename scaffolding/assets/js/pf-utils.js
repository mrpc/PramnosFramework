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
 *   data-confirm="message"      — show confirm() before following a link or submitting a button
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
 *
 * Same-origin API calls made from here send `X-CSRF-Token` from `<meta name="csrf">`
 * (window.pfApiHeaders) — that is what authenticates a page against its own API.
 *
 *   data-pf-omnibox             — cross-entity search box (see Html\SearchBox); the
 *                                 endpoint, minimum length, debounce and the loading /
 *                                 empty strings come from data-pf-omnibox-* attributes
 *   data-pf-notifications       — the notification bell (see Html\NotificationBell): the
 *                                 count, the list, read and read-all, polled while the tab
 *                                 is visible and refreshed on `pf:notifications:refresh`
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
    // <meta name="pf-client-errors" content="…/ClientErrors"> — errors the page hits, reported
    //
    // An uncaught error and an unhandled promise rejection are sent to the address in the meta
    // tag (\Pramnos\Application\Controllers\ClientErrorsController), which keeps one row per
    // distinct error with a count. Without the tag nothing is sent. Each distinct error is sent
    // once per page, and at most ten per page, so a loop that throws costs ten requests, not a
    // flood. `window.pfReportError(error)` reports one the page caught itself.
    // ══════════════════════════════════════════════════════════════════════════
    (function () {
        var meta = document.querySelector('meta[name="pf-client-errors"]');
        var url  = meta ? meta.getAttribute('content') : '';
        if (!url) { return; }
        var release = meta.getAttribute('data-release') || '';
        var sent = {};
        var count = 0;

        function send(report) {
            // A script from another origin reports only this, with nothing to act on.
            if (!report.message || report.message === 'Script error.') { return; }
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
        var confirmEl = e.target.closest('[data-confirm]');
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

    // Close the stats modal with the Escape key for keyboard accessibility.
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeStats(); }
    });

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
        var figures = document.querySelectorAll('[data-pf-chart-figure]');
        if (!figures.length) { return; }

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

        function drawAll() { Array.prototype.forEach.call(figures, draw); }

        drawAll();
        // Chart.js may arrive after this script: the page's load event comes after both.
        window.addEventListener('load', function () {
            Array.prototype.forEach.call(figures, function (fig) { if (!fig._pfChart) { draw(fig); } });
        });
        if (window.MutationObserver) {
            new window.MutationObserver(drawAll).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'class'] });
        }
        if (window.matchMedia) {
            var scheme = window.matchMedia('(prefers-color-scheme: dark)');
            if (scheme.addEventListener) { scheme.addEventListener('change', drawAll); }
        }
    })();

    // ══════════════════════════════════════════════════════════════════════════
    // data-pf-dashboard — a dashboard each person arranges (DashboardController)
    //
    // "Customise" shows each card's tools: move up and down, wide or narrow, hide — buttons, so a
    // keyboard does all of it — and in the same mode a card can be dragged into place. Each change
    // is saved at once (POST data-save, JSON, with the page's CSRF token); adding a card saves and
    // reloads, because its contents are drawn on the server. The server keeps only what this
    // person may have, so nothing here is a gate.
    // ══════════════════════════════════════════════════════════════════════════
    (function () {
        var board = document.querySelector('[data-pf-dashboard]');
        if (!board) { return; }
        var editButton = document.querySelector('[data-pf-dashboard-edit]');
        var bar    = document.querySelector('[data-pf-dashboard-bar]');
        var status = document.querySelector('[data-pf-dashboard-status]');
        var empty  = board.querySelector('[data-pf-dashboard-empty]');
        var editing = false;
        var dragged = null;

        function cards() { return Array.prototype.slice.call(board.querySelectorAll('[data-pf-widget]')); }
        function say(text) { if (status) { status.textContent = text; } }
        function layout() {
            return cards().map(function (card) {
                return { id: card.getAttribute('data-pf-widget'), size: card.getAttribute('data-size') === 'full' ? 'full' : 'half' };
            });
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
                if (then && then.reload) { window.location.reload(); }
            }).catch(function () {
                say('The dashboard could not be saved.');
            });
        }

        function setEditing(on) {
            editing = on;
            if (editButton) { editButton.setAttribute('aria-pressed', on ? 'true' : 'false'); }
            if (bar) { bar.hidden = !on; }
            cards().forEach(function (card) {
                var tools = card.querySelector('[data-pf-widget-tools]');
                if (tools) { tools.hidden = !on; }
                if (on) { card.setAttribute('draggable', 'true'); } else { card.removeAttribute('draggable'); }
            });
        }

        if (editButton) { editButton.addEventListener('click', function () { setEditing(!editing); }); }
        var done = document.querySelector('[data-pf-dashboard-done]');
        if (done) { done.addEventListener('click', function () { setEditing(false); if (editButton && editButton.focus) { editButton.focus(); } }); }

        board.addEventListener('click', function (e) {
            var button = e.target.closest ? e.target.closest('[data-pf-widget-move], [data-pf-widget-resize], [data-pf-widget-hide]') : null;
            if (!button) { return; }
            var card  = button.closest('[data-pf-widget]');
            var title = card.getAttribute('aria-label') || '';
            if (button.hasAttribute('data-pf-widget-move')) {
                var list = cards();
                var at   = list.indexOf(card) + parseInt(button.getAttribute('data-pf-widget-move'), 10);
                if (at < 0 || at >= list.length) { return; }
                var other = list[at];
                if (at < list.indexOf(card)) { board.insertBefore(card, other); } else { board.insertBefore(card, other.nextSibling); }
                if (button.focus) { button.focus(); }
                say(title + ': ' + (at + 1) + ' / ' + list.length);
            } else if (button.hasAttribute('data-pf-widget-resize')) {
                var full = card.getAttribute('data-size') !== 'full';
                card.setAttribute('data-size', full ? 'full' : 'half');
                card.classList.toggle('pf-dashboard-full', full);
                say(title + ': ' + (full ? 'wide' : 'narrow'));
            } else {
                var next = cards()[cards().indexOf(card) + 1] || cards()[cards().indexOf(card) - 1];
                card.parentNode.removeChild(card);
                say(title + ': hidden');
                var focus = next ? next.querySelector('[data-pf-widget-hide]') : editButton;
                if (focus && focus.focus) { focus.focus(); }
            }
            save();
        });

        var add = document.querySelector('[data-pf-dashboard-add]');
        if (add) {
            add.addEventListener('change', function () {
                if (!add.value) { return; }
                save({ add: { id: add.value, size: 'half' }, reload: true });
            });
        }

        // Dragging: the same order the buttons give, for a mouse.
        board.addEventListener('dragstart', function (e) {
            dragged = e.target.closest ? e.target.closest('[data-pf-widget]') : null;
            if (!editing || !dragged) { dragged = null; return; }
            dragged.classList.add('pf-dashboard-dragging');
            if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', dragged.getAttribute('data-pf-widget')); }
        });
        board.addEventListener('dragover', function (e) {
            if (!dragged) { return; }
            e.preventDefault();
            var over = e.target.closest ? e.target.closest('[data-pf-widget]') : null;
            if (!over || over === dragged) { return; }
            var list = cards();
            if (list.indexOf(over) < list.indexOf(dragged)) { board.insertBefore(dragged, over); } else { board.insertBefore(dragged, over.nextSibling); }
        });
        board.addEventListener('dragend', function () {
            if (!dragged) { return; }
            dragged.classList.remove('pf-dashboard-dragging');
            dragged = null;
            save();
        });
    })();

})();
