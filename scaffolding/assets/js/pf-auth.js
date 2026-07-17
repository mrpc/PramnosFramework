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

    function wirePasskeyButtons() {
        document.querySelectorAll('[data-pf-passkey-login],[data-pf-passkey-stepup]').forEach(wirePasskeyButton);
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

    function init() {
        wireOtpInputs();
        wirePasswordPolicyForms();
        wirePasskeyButtons();
        wirePasskeyManagers();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
