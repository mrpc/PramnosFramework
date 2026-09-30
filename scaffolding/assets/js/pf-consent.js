/**
 * EU cookie consent — FRAMEWORK-OWNED, refreshed by `project:resync --js`.
 *
 * One file for every theme and every app style (MVC, SPA, hybrid). Loaded by the
 * tag `\Pramnos\Security\CookieConsent::tag()` emits, which carries the site's
 * configuration in `data-config`; a SPA shell, which cannot read settings, points
 * `data-config-url` at `/cookieconsent` instead.
 *
 * What it does:
 *   - shows a banner until the visitor chooses, with "Accept all" and "Reject all"
 *     equally prominent and a "Customise" dialog listing each category;
 *   - keeps the choice in the `pf_consent` cookie (`v=<policy version>&c=a.b&t=<unix>`),
 *     and asks again when the policy version changes;
 *   - releases `<script type="text/plain" data-consent="analytics" src="...">`
 *     elements once their category is allowed;
 *   - opens the dialog from anything marked `data-consent-open`;
 *   - reports a signed-in visitor's choice to the server for the consent trail.
 *
 * API, on `window.PramnosConsent`:
 *   has(category)            → boolean
 *   onGrant(category, fn)    → runs fn now if allowed, else once it is
 *   open()                   → the preferences dialog
 * and a `pramnos:consent` event on `document` with `detail.granted` after every choice.
 *
 * Google Consent Mode v2: the moment it runs it pushes `consent default` (everything denied
 * but security) to `window.dataLayer`, before any configuration arrives; then `consent
 * update` with the visitor's choice, and a
 * `{event: 'pramnos_consent'}` entry a Tag Manager trigger can fire on. A GTM or gtag.js
 * loaded *after* this script therefore starts from the right state.
 *
 * **Gated scripts must be files.** A `text/plain` script carries no CSP nonce, so an
 * inline one could not run when released. Put the code in a file, or register it
 * with `onGrant()` from a script you already load.
 */
(function () {
    'use strict';

    var COOKIE = 'pf_consent';
    var self = document.currentScript;
    var config = null;
    var granted = null;
    var waiting = {};
    var banner = null;
    var dialog = null;
    var styled = false;

    var DEFAULTS = {
        version: '1',
        days: 180,
        policyUrl: '',
        recordUrl: '',
        categories: [
            { name: 'necessary', label: 'Strictly necessary', description: 'Needed for the site to work. Always on.' },
            { name: 'preferences', label: 'Preferences', description: 'Remember choices you make, such as language or layout.' },
            { name: 'analytics', label: 'Analytics', description: 'Help us understand how the site is used.' },
            { name: 'marketing', label: 'Marketing', description: 'Used to show you relevant advertising.' },
        ],
        text: {
            title: 'We use cookies',
            body: 'We use cookies to make this site work. With your permission we would also use them for the purposes below.',
            acceptAll: 'Accept all',
            rejectAll: 'Reject all',
            customise: 'Customise',
            save: 'Save choices',
            policy: 'Cookie policy',
            settings: 'Cookie settings',
        },
    };

    var CSS = ''
        + '.pf-consent{--pf-c-bg:#fff;--pf-c-fg:#1f2328;--pf-c-muted:#59636e;--pf-c-border:#d1d9e0;'
        + '--pf-c-accent:#0969da;--pf-c-accent-fg:#fff;font:14px/1.5 system-ui,sans-serif;color:var(--pf-c-fg)}'
        + '@media (prefers-color-scheme:dark){.pf-consent{--pf-c-bg:#1f2328;--pf-c-fg:#f0f6fc;--pf-c-muted:#9198a1;'
        + '--pf-c-border:#3d444d;--pf-c-accent:#4493f8}}'
        + '.pf-consent-banner{position:fixed;left:16px;right:16px;bottom:16px;z-index:2147483000;max-width:640px;'
        + 'margin:0 auto;background:var(--pf-c-bg);border:1px solid var(--pf-c-border);border-radius:8px;'
        + 'box-shadow:0 8px 24px rgba(0,0,0,.2);padding:16px}'
        + '.pf-consent h2{font-size:16px;margin:0 0 6px}.pf-consent p{margin:0 0 12px;color:var(--pf-c-muted)}'
        + '.pf-consent a{color:var(--pf-c-accent)}'
        + '.pf-consent-actions{display:flex;flex-wrap:wrap;gap:8px}'
        + '.pf-consent button{font:inherit;padding:8px 14px;border-radius:6px;cursor:pointer;'
        + 'border:1px solid var(--pf-c-border);background:var(--pf-c-bg);color:var(--pf-c-fg)}'
        + '.pf-consent button.pf-consent-primary{background:var(--pf-c-accent);border-color:var(--pf-c-accent);color:var(--pf-c-accent-fg)}'
        + '.pf-consent button:focus-visible{outline:2px solid var(--pf-c-accent);outline-offset:2px}'
        // margin:auto is what centres a modal <dialog>; Tailwind's preflight zeroes it.
        + '.pf-consent-dialog{margin:auto;background:var(--pf-c-bg);border:1px solid var(--pf-c-border);border-radius:8px;'
        + 'padding:20px;width:min(520px,calc(100vw - 32px));color:var(--pf-c-fg)}'
        + '.pf-consent-dialog::backdrop{background:rgba(0,0,0,.5)}'
        + '.pf-consent-row{display:flex;gap:10px;align-items:flex-start;padding:10px 0;border-top:1px solid var(--pf-c-border)}'
        + '.pf-consent-row input{margin-top:4px;width:18px;height:18px;flex:none}'
        + '.pf-consent-row strong{display:block}.pf-consent-row span{color:var(--pf-c-muted)}'
        + '.pf-consent-reopen{position:fixed;left:16px;bottom:16px;z-index:2147482999;font-size:12px!important;'
        + 'padding:6px 10px!important;opacity:.85}'
        + '@media (max-width:480px){.pf-consent-actions button{flex:1 1 100%}}';

    // ── The cookie ───────────────────────────────────────────────────────────

    function readCookie() {
        var match = document.cookie.match(/(?:^|;\s*)pf_consent=([^;]*)/);
        if (!match) {
            return null;
        }
        var value;
        try {
            value = decodeURIComponent(match[1]);
        } catch (e) {
            // A malformed cookie is no choice: ask again rather than stop here.
            return null;
        }
        var parts = {};
        value.split('&').forEach(function (pair) {
            var at = pair.indexOf('=');
            if (at > 0) {
                parts[pair.slice(0, at)] = pair.slice(at + 1);
            }
        });
        return parts;
    }

    function writeCookie(list) {
        var value = 'v=' + config.version + '&c=' + list.join('.') + '&t=' + Math.floor(Date.now() / 1000);
        document.cookie = COOKIE + '=' + encodeURIComponent(value)
            + '; Max-Age=' + (config.days * 86400)
            + '; Path=/; SameSite=Lax'
            + (location.protocol === 'https:' ? '; Secure' : '');
    }

    /** What the cookie allows, or null when there is no choice under this policy. */
    function storedChoice() {
        var parts = readCookie();
        if (!parts || String(parts.v) !== String(config.version)) {
            return null;
        }
        var known = optionalNames();
        return String(parts.c || '').split('.').filter(function (name) {
            return known.indexOf(name) !== -1;
        });
    }

    function optionalNames() {
        return config.categories.map(function (c) { return c.name; })
            .filter(function (name) { return name !== 'necessary'; });
    }

    // ── Google Consent Mode ──────────────────────────────────────────────────

    /** Which Consent Mode v2 signals each category covers. */
    var GOOGLE_SIGNALS = {
        preferences: ['functionality_storage', 'personalization_storage'],
        analytics: ['analytics_storage'],
        marketing: ['ad_storage', 'ad_user_data', 'ad_personalization'],
    };

    /**
     * Push a `consent` command the way gtag.js does — an `arguments` object, not an
     * array, because that is what GTM recognises as a command.
     */
    function gtag() {
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push(arguments);
    }

    function googleConsent(command, allDenied) {
        var state = { security_storage: 'granted' };
        Object.keys(GOOGLE_SIGNALS).forEach(function (category) {
            GOOGLE_SIGNALS[category].forEach(function (signal) {
                state[signal] = !allDenied && has(category) ? 'granted' : 'denied';
            });
        });
        gtag('consent', command, state);
        if (command === 'update') {
            window.dataLayer.push({ event: 'pramnos_consent', pramnosConsent: (granted || []).slice() });
        }
    }

    // ── Deciding ─────────────────────────────────────────────────────────────

    function has(category) {
        if (category === 'necessary') {
            return true;
        }
        return granted !== null && granted.indexOf(category) !== -1;
    }

    function choose(list) {
        var withdrawn = (granted || []).some(function (name) { return list.indexOf(name) === -1; });

        writeCookie(list);
        granted = list;
        hideBanner();
        release();
        googleConsent('update');
        document.dispatchEvent(new CustomEvent('pramnos:consent', { detail: { granted: list.slice() } }));
        report(list);

        // A script that already ran cannot be unloaded; a reload is the only way to
        // honour a withdrawal on this page.
        if (withdrawn) {
            location.reload();
        }
    }

    /** Run everything the visitor now allows: gated script elements and onGrant() callbacks. */
    function release() {
        document.querySelectorAll('script[type="text/plain"][data-consent]').forEach(function (placeholder) {
            if (!has(placeholder.getAttribute('data-consent'))) {
                return;
            }
            var script = document.createElement('script');
            for (var i = 0; i < placeholder.attributes.length; i++) {
                var attr = placeholder.attributes[i];
                if (attr.name !== 'type' && attr.name !== 'data-consent') {
                    script.setAttribute(attr.name, attr.value);
                }
            }
            placeholder.parentNode.replaceChild(script, placeholder);
        });

        Object.keys(waiting).forEach(function (category) {
            if (has(category)) {
                var queue = waiting[category];
                delete waiting[category];
                queue.forEach(function (fn) { fn(); });
            }
        });
    }

    function onGrant(category, fn) {
        if (has(category)) {
            fn();
            return;
        }
        (waiting[category] = waiting[category] || []).push(fn);
    }

    /**
     * Tell the server, for the consent trail. Only a signed-in page carries the
     * CSRF meta, and only a signed-in visitor has a trail to add to.
     */
    function report(list) {
        var csrf = document.querySelector('meta[name="csrf"]');
        if (!config.recordUrl || !csrf || typeof fetch !== 'function') {
            return;
        }
        fetch(config.recordUrl, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': csrf.getAttribute('content'),
            },
            body: JSON.stringify({ granted: list }),
        }).catch(function (error) {
            console.warn('pf-consent: the choice is saved in this browser but was not recorded', error);
        });
    }

    // ── Drawing ──────────────────────────────────────────────────────────────

    /**
     * The styles, without a `<style>` element.
     *
     * The framework's CSP nonces every inline `<style>` it renders, but one created
     * here after the fact has no nonce and would be refused. A constructed
     * stylesheet is not inline markup, so the policy does not govern it.
     */
    function applyStyles() {
        if (styled) {
            return;
        }
        styled = true;
        try {
            var sheet = new CSSStyleSheet();
            sheet.replaceSync(CSS);
            document.adoptedStyleSheets = document.adoptedStyleSheets.concat([sheet]);
        } catch (e) {
            // ponytail: pre-2023 browsers fall back to a <style> element, which a
            // strict CSP refuses — the banner still works, unstyled.
            var style = document.createElement('style');
            style.textContent = CSS;
            document.head.appendChild(style);
        }
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text) {
            node.textContent = text;
        }
        return node;
    }

    function button(text, primary, onClick) {
        var node = el('button', primary ? 'pf-consent-primary' : '', text);
        node.type = 'button';
        node.addEventListener('click', onClick);
        return node;
    }

    function policyLink() {
        if (!config.policyUrl) {
            return null;
        }
        var link = el('a', '', config.text.policy);
        link.href = config.policyUrl;
        return link;
    }

    function showBanner() {
        if (banner) {
            return;
        }
        banner = el('section', 'pf-consent pf-consent-banner');
        banner.setAttribute('role', 'region');
        banner.setAttribute('aria-label', config.text.title);

        var body = el('p', '', config.text.body + ' ');
        var link = policyLink();
        if (link) {
            body.appendChild(link);
        }

        var actions = el('div', 'pf-consent-actions');
        // "Reject" is as easy as "Accept": same size, same click count. Regulators
        // treat a harder refusal as consent that was not freely given.
        actions.appendChild(button(config.text.acceptAll, true, function () { choose(optionalNames()); }));
        actions.appendChild(button(config.text.rejectAll, true, function () { choose([]); }));
        actions.appendChild(button(config.text.customise, false, open));

        banner.appendChild(el('h2', '', config.text.title));
        banner.appendChild(body);
        banner.appendChild(actions);
        document.body.appendChild(banner);
    }

    function hideBanner() {
        if (banner) {
            banner.remove();
            banner = null;
        }
        reopenButton();
    }

    /**
     * Withdrawing has to be as easy as agreeing, so there is always a way back to the
     * dialog. A theme footer carries a `data-consent-open` link; a page without one — a
     * SPA that has not added its own — gets a small fixed button instead.
     */
    function reopenButton() {
        if (document.querySelector('[data-consent-open]')) {
            return;
        }
        applyStyles();
        var wrap = el('div', 'pf-consent');
        var node = button(config.text.settings, false, open);
        node.className = 'pf-consent-reopen';
        node.setAttribute('data-consent-open', '');
        wrap.appendChild(node);
        document.body.appendChild(wrap);
    }

    /** The preferences dialog. A native `<dialog>`: focus trap, Escape and backdrop for free. */
    function open() {
        if (!config) {
            return;
        }
        applyStyles();
        if (dialog) {
            dialog.remove();
        }
        dialog = el('dialog', 'pf-consent pf-consent-dialog');
        dialog.setAttribute('aria-label', config.text.settings);

        var form = el('form');
        form.method = 'dialog';
        form.appendChild(el('h2', '', config.text.settings));
        var intro = el('p', '', config.text.body + ' ');
        var link = policyLink();
        if (link) {
            intro.appendChild(link);
        }
        form.appendChild(intro);

        config.categories.forEach(function (category) {
            var row = el('label', 'pf-consent-row');
            var box = el('input');
            box.type = 'checkbox';
            box.name = category.name;
            box.checked = has(category.name);
            box.disabled = category.name === 'necessary';
            var words = el('div');
            words.appendChild(el('strong', '', category.label));
            words.appendChild(el('span', '', category.description));
            row.appendChild(box);
            row.appendChild(words);
            form.appendChild(row);
        });

        var actions = el('div', 'pf-consent-actions');
        actions.appendChild(button(config.text.save, true, function () {
            var list = optionalNames().filter(function (name) {
                return form.querySelector('input[name="' + name + '"]').checked;
            });
            dialog.close();
            choose(list);
        }));
        actions.appendChild(button(config.text.rejectAll, false, function () { dialog.close(); choose([]); }));
        actions.appendChild(button(config.text.acceptAll, false, function () { dialog.close(); choose(optionalNames()); }));
        form.appendChild(actions);

        dialog.appendChild(form);
        document.body.appendChild(dialog);
        dialog.showModal();
    }

    // ── Start ────────────────────────────────────────────────────────────────

    function start(loaded) {
        if (!loaded || loaded.enabled === false) {
            return;
        }
        config = Object.assign({}, DEFAULTS, loaded, { text: Object.assign({}, DEFAULTS.text, loaded.text || {}) });
        granted = storedChoice();
        if (granted !== null) {
            googleConsent('update');
        }

        document.addEventListener('click', function (event) {
            var trigger = event.target.closest && event.target.closest('[data-consent-open]');
            if (trigger) {
                event.preventDefault();
                open();
            }
        });

        release();
        if (granted === null) {
            applyStyles();
            showBanner();
        } else {
            reopenButton();
        }
    }

    window.PramnosConsent = { has: has, onGrant: onGrant, open: open };

    // The Consent Mode default, now: it is all-denied whatever the configuration says, so
    // it waits for nothing. In a SPA the configuration is fetched, and a Tag Manager loaded
    // after this script would otherwise run before the default existed — or for ever, if
    // the fetch failed.
    googleConsent('default', true);

    function boot() {
        var inline = self && self.getAttribute('data-config');
        var url = self && self.getAttribute('data-config-url');
        if (inline) {
            start(JSON.parse(inline));
        } else if (url) {
            fetch(url, { headers: { Accept: 'application/json' } })
                .then(function (response) { return response.json(); })
                .then(start)
                .catch(function (error) { console.warn('pf-consent: no configuration', error); });
        } else {
            start(DEFAULTS);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
