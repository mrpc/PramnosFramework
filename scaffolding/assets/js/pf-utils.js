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
 * Stats modal markup contract (rendered by the view, styled by the theme):
 *   #pf-stats-overlay  — the full-screen overlay (toggled via style.display)
 *   #pf-stats-body     — the container the fetched stats are rendered into
 */
(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        // ── data-confirm ──────────────────────────────────────────────────────
        var confirmEl = e.target.closest('[data-confirm]');
        if (confirmEl && !confirm(confirmEl.dataset.confirm)) {
            e.preventDefault();
            e.stopPropagation();
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

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
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
})();
