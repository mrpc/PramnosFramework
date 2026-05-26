/**
 * PramnosFramework UI utilities — loaded by all scaffold themes.
 *
 * Attributes handled:
 *   data-confirm="message"      — show confirm() before following a link or submitting a button
 *   data-copy-prev              — copy the value of the immediately preceding <input> to clipboard
 *   data-toggle-type="inputId"  — toggle password/text on the target <input>
 *   data-modal-show="elementId" — remove class "hidden" from target element
 *   data-modal-hide="elementId" — add class "hidden" to target element
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
    });
})();
