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
                    // endpoints use, so it is the real number and not the page size.
                    if (group.total > rows.length) {
                        html += ' <span class="pf-omnibox-count">'
                            + rows.length + '/' + group.total + '</span>';
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

})();
