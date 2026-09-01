/*!
 * pf-auth.js — auth-UI behaviours for PramnosFramework's built-in login flow.
 *
 * Data-attribute driven, so views carry no logic — they just mark elements and
 * this script wires them on DOMContentLoaded:
 *
 *   [data-pf-otp]                     input: digits-only + auto-submit at maxlength
 *   [data-pf-password-policy]         form:  client-side password-policy check
 *     [data-pf-password-error]          (in-form) message box, revealed on failure
 *   [data-pf-passkey-login]           button: primary passwordless login ceremony
 *   [data-pf-passkey-stepup]          button: second-factor passkey step-up ceremony
 *     both read data-options-url / data-verify-url / data-redirect / data-error
 *   [data-pf-progress]                form:  disable + mark the submit button busy on submit
 *   [data-pf-passkey-manage]          container: list / add / rename / revoke passkeys
 *     reads data-base; contains [data-pf-passkey-list] [data-pf-passkey-add]
 *     [data-pf-passkey-message]
 *
 * Passkey behaviours depend on window.PramnosWebAuthn (pf-webauthn.js); when it
 * is absent or unsupported the passkey controls hide themselves so the page
 * degrades to password + code.
 */
(function () {
    'use strict';

    /** HTML-escape a value for safe insertion as text content. */
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /** Reveal a hidden element regardless of the theme's hide mechanism. */
    function reveal(el) {
        if (!el) { return; }
        el.classList.remove('d-none', 'hidden');
        el.style.display = '';
    }

    /** POST a JSON body same-origin (session cookie included). */
    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body || {})
        });
    }

    function webauthnReady() {
        return window.PramnosWebAuthn && window.PramnosWebAuthn.supported();
    }

    // ── OTP input: digits-only + auto-submit when full ──────────────────────────
    function wireOtpInputs() {
        document.querySelectorAll('[data-pf-otp]').forEach(function (input) {
            input.addEventListener('input', function () {
                this.value = this.value.replace(/[^0-9]/g, '');
                var max = parseInt(this.getAttribute('maxlength') || '6', 10);
                if (this.value.length === max && this.form) {
                    var self = this;
                    setTimeout(function () { if (self.value.length === max) { self.form.submit(); } }, 100);
                }
            });
        });
    }

    // ── Password-policy check (mirrors the server rules) ────────────────────────
    function passwordPolicyError(pw, confirm) {
        if (pw.length < 8)                 { return 'Your password must be at least 8 characters long.'; }
        if (!/[0-9]/.test(pw))             { return 'Your password must contain at least one number.'; }
        if (!/[^A-Za-z0-9]/.test(pw))      { return 'Your password must contain at least one symbol.'; }
        if (confirm !== null && pw !== confirm) { return 'The two passwords do not match.'; }
        return '';
    }

    function wirePasswordPolicyForms() {
        document.querySelectorAll('form[data-pf-password-policy]').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                // Accept either the reset form's `password` field or the
                // change-password form's `new_password` field.
                var pwEl = form.querySelector('[name="password"], [name="new_password"]');
                var cfEl = form.querySelector('[name="confirm_password"]');
                if (!pwEl) { return; }
                var msg = passwordPolicyError(pwEl.value || '', cfEl ? (cfEl.value || '') : null);
                if (msg) {
                    e.preventDefault();
                    var box = form.querySelector('[data-pf-password-error]')
                        || document.querySelector('[data-pf-password-error]');
                    if (box) { box.textContent = msg; reveal(box); }
                }
            });
        });
    }

    // ── Passkey ceremony buttons (login + step-up share the same wiring) ────────
    function wirePasskeyButton(btn) {
        var optionsUrl = btn.getAttribute('data-options-url');
        var verifyUrl  = btn.getAttribute('data-verify-url');
        var redirect   = btn.getAttribute('data-redirect') || '/';
        var errSel     = btn.getAttribute('data-error');
        var wrapSel    = btn.getAttribute('data-wrap');

        if (!webauthnReady() || !optionsUrl || !verifyUrl) {
            var wrapHide = wrapSel && document.querySelector(wrapSel);
            (wrapHide || btn).style.display = 'none';
            return;
        }
        // Reveal an initially-hidden wrapper now that passkeys are usable.
        if (wrapSel) { reveal(document.querySelector(wrapSel)); }

        btn.addEventListener('click', function () {
            btn.disabled = true;
            window.PramnosWebAuthn.authenticate(optionsUrl, verifyUrl)
                .then(function (r) { window.location = (r && r.redirect) || redirect; })
                .catch(function () {
                    btn.disabled = false;
                    var box = errSel ? document.querySelector(errSel) : null;
                    if (box) { box.textContent = 'Passkey sign-in failed or was cancelled.'; reveal(box); }
                });
        });
    }

    /**
     * Offer the passkey inside the username field's autofill, not only behind the button.
     *
     * Started once on load and left waiting: the ceremony settles when somebody picks a passkey from
     * the autofill list, which may be never. That is the point — signing in becomes one tap on a
     * suggestion instead of noticing a second button and choosing it.
     *
     * Two conditions, both deliberate:
     *
     *  - **only the login button**, never step-up. Step-up already knows who the person is; there is
     *    no username field to offer anything inside.
     *  - **only when a username field says `webauthn`.** The `autocomplete="username webauthn"` token
     *    is what the specification uses to mark the field the suggestion belongs in, so a page that
     *    has not opted in is left exactly as it was.
     *
     * Failures are swallowed on purpose. Nobody asked for this ceremony, so an error message about
     * it would appear on a form where the visitor is quietly typing a password — and the button and
     * the password form both still work.
     */
    function startConditionalPasskey() {
        if (!webauthnReady() || typeof window.PramnosWebAuthn.conditional !== 'function') {
            return;
        }

        var field = document.querySelector('input[autocomplete~="webauthn"]');
        var btn   = document.querySelector('[data-pf-passkey-login]');

        if (!field || !btn) {
            return;
        }

        var optionsUrl = btn.getAttribute('data-options-url');
        var verifyUrl  = btn.getAttribute('data-verify-url');
        var redirect   = btn.getAttribute('data-redirect') || '/';

        if (!optionsUrl || !verifyUrl) {
            return;
        }

        window.PramnosWebAuthn.conditional(optionsUrl, verifyUrl)
            .then(function (result) {
                if (result) {
                    window.location = result.redirect || redirect;
                }
            })
            .catch(function () {
                // See above: an unasked-for ceremony reports nothing.
            });
    }

    function wirePasskeyButtons() {
        document.querySelectorAll('[data-pf-passkey-login],[data-pf-passkey-stepup]').forEach(wirePasskeyButton);
        startConditionalPasskey();
    }

    // ── Passkey management page (list / add / rename / revoke) ──────────────────
    function wirePasskeyManager(root) {
        var base   = root.getAttribute('data-base') || '';
        var listEl = root.querySelector('[data-pf-passkey-list]');
        var addBtn = root.querySelector('[data-pf-passkey-add]');
        var msgEl  = root.querySelector('[data-pf-passkey-message]');

        function message(text, ok) {
            if (!msgEl) { return; }
            msgEl.textContent = text;
            reveal(msgEl);
            msgEl.setAttribute('data-ok', ok ? '1' : '0');
        }

        function render(items) {
            if (!listEl) { return; }
            if (!items.length) {
                listEl.innerHTML = '<li class="pf-pk-empty">No passkeys yet. Add one to sign in without a password.</li>';
                return;
            }
            listEl.innerHTML = '';
            items.forEach(function (pk) {
                var li = document.createElement('li');
                li.className = 'pf-pk-item';
                var used = pk.last_used_at ? ('Last used ' + esc(pk.last_used_at)) : 'Never used';
                li.innerHTML = '<div class="pf-pk-meta"><strong>' + esc(pk.name || 'Passkey') + '</strong>'
                    + '<small>Added ' + esc(pk.created_at) + ' · ' + used + '</small></div>';
                var actions = document.createElement('div');
                actions.className = 'pf-pk-actions';
                var rename = document.createElement('button');
                rename.type = 'button'; rename.className = 'pf-pk-rename'; rename.textContent = 'Rename';
                rename.onclick = function () {
                    var name = prompt('New name for this passkey:', pk.name || '');
                    if (name === null || name.trim() === '') { return; }
                    postJson(base + '/rename', { id: pk.id, name: name.trim() }).then(load);
                };
                var revoke = document.createElement('button');
                revoke.type = 'button'; revoke.className = 'pf-pk-revoke'; revoke.textContent = 'Remove';
                revoke.onclick = function () {
                    if (!confirm('Remove this passkey? You will no longer be able to sign in with it.')) { return; }
                    postJson(base + '/revoke', { id: pk.id }).then(load);
                };
                actions.appendChild(rename); actions.appendChild(revoke);
                li.appendChild(actions);
                listEl.appendChild(li);
            });
        }

        function load() {
            fetch(base + '/list', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) { render(d.passkeys || []); })
                .catch(function () { if (listEl) { listEl.innerHTML = '<li class="pf-pk-error">Could not load passkeys.</li>'; } });
        }

        if (!webauthnReady()) {
            if (addBtn) { addBtn.style.display = 'none'; }
            message('This browser does not support passkeys.', false);
        } else if (addBtn) {
            addBtn.addEventListener('click', function () {
                var label = prompt('Name this passkey (e.g. "My laptop"):', '');
                // Cancel on the prompt returns null — abort before starting the
                // WebAuthn ceremony so no passkey request reaches the browser.
                if (label === null) { return; }
                addBtn.disabled = true;
                window.PramnosWebAuthn.register(base + '/registerOptions', base + '/register', { label: label })
                    .then(function () { message('Passkey added.', true); load(); })
                    .catch(function () { message('Could not add passkey. Please try again.', false); })
                    .finally(function () { addBtn.disabled = false; });
            });
        }
        load();
    }

    function wirePasskeyManagers() {
        document.querySelectorAll('[data-pf-passkey-manage]').forEach(wirePasskeyManager);
    }


    // ── Submit progress ────────────────────────────────────────────────────────
    /**
     * Mark a form's submit buttons busy: disabled, `aria-busy`, and a label that says so.
     *
     * Exported on `window.PramnosAuth` because the human-check script holds a submit for a moment
     * before re-submitting it, and that hold is the case this exists for — see below.
     */
    function markSubmitBusy(form) {
        if (!form || form.getAttribute('data-pf-busy') !== null) { return; }
        form.setAttribute('data-pf-busy', '');
        form.setAttribute('aria-busy', 'true');

        form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]')
            .forEach(function (button) {
                var busyLabel = button.getAttribute('data-pf-busy-label');

                // A trailing ellipsis rather than a spinner class: this script ships with three
                // themes and is copied into projects with none of them, and a class only one theme
                // styles is an invisible indicator. The disabled state is what stops the second
                // press; the label is what says why.
                if (busyLabel === null) {
                    busyLabel = (button.tagName === 'INPUT' ? button.value : button.textContent)
                        .trim() + '…';
                }

                if (button.tagName === 'INPUT') {
                    button.value = busyLabel;
                } else {
                    button.textContent = busyLabel;
                }

                button.classList.add('pf-busy');

                // Safe to disable here: a disabled submit button is left out of the submitted
                // form data, but both callers are already past that point — one runs a tick after
                // the submit event, the other has prevented the default and will re-submit
                // programmatically, where no button participates at all.
                button.disabled = true;
            });
    }

    function wireSubmitProgress() {
        document.querySelectorAll('form[data-pf-progress]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                /*
                 * Deferred by a tick so every other submit listener on this form has run.
                 *
                 * `defaultPrevented` then distinguishes the two things a listener may have done.
                 * A validation handler that refused the submit leaves the person on the page with
                 * a form they must fix — disabling its button would be a dead form. The human-check
                 * script also prevents the default, but only to *hold* the submit while a proof
                 * finishes, and it marks the form busy itself for exactly that reason.
                 */
                setTimeout(function () {
                    if (event.defaultPrevented) { return; }
                    markSubmitBusy(form);
                }, 0);
            });
        });
    }

    function init() {
        wireOtpInputs();
        wirePasswordPolicyForms();
        wireSubmitProgress();
        wirePasskeyButtons();
        wirePasskeyManagers();
    }

    window.PramnosAuth = window.PramnosAuth || {};
    window.PramnosAuth.markSubmitBusy = markSubmitBusy;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
