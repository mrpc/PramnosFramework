/**
 * The administration's side panel: Ask about the page you are on, and that page's help.
 *
 * The server draws everything — the buttons (`data-pf-assist-open`, every one of them; `="help"`
 * or `="ask"` opens on that tab), the panel inside
 * `<template data-pf-assist>`, the help already rendered — and this script only opens it beside
 * the page. Ask is the analyst's conversation page itself: fetched, its conversation taken out of
 * it, and run by `pf-chat.js`, with the page this panel was opened on sent with every question.
 * One renderer for the conversation, so the panel cannot drift from Ask.
 *
 * Kept per tab (sessionStorage): whether the panel is open, its tab, and the conversation — so it
 * stays open, on the same conversation, from one administration page to the next.
 *
 * Attributes on the panel: data-page (the page, as JSON), data-ask (Ask's address),
 * data-chat-script (pf-chat.js), data-say-* (its words). Esc closes it. No inline handlers: the
 * framework's CSP blocks them.
 */
(function () {
    'use strict';

    var KEY = 'pf-assist';

    // Every opener: a theme may draw several — Ask, and a "?" for the page's help — each opening
    // the one panel, on its own tab when it names one (`data-pf-assist-open="help"`).
    var openers = Array.prototype.slice.call(document.querySelectorAll('[data-pf-assist-open]'));
    var template = document.querySelector('template[data-pf-assist]');

    if (openers.length === 0 || !template) {
        return;
    }

    var panel = null;
    // The opener used last, where focus goes back when the panel closes.
    var opener = openers[0];

    /**
     * Whether the panel draws itself: inline styles from the server, unless the theme styles it
     * (`'assist' => ['styles' => 'theme']`), when it carries only its classes and is shown and
     * hidden with `hidden`.
     */
    function inline() {
        return panel.hasAttribute('style');
    }

    function isOpen() {
        return panel !== null && (inline() ? panel.style.display !== 'none' : !panel.hidden);
    }

    function setOpen(on) {
        if (inline()) {
            panel.style.display = on ? 'flex' : 'none';
        } else {
            panel.hidden = !on;
        }
        openers.forEach(function (one) { one.setAttribute('aria-expanded', on ? 'true' : 'false'); });
    }

    /** What this tab remembers; nothing, where storage is refused. */
    function remembered() {
        try {
            return JSON.parse(window.sessionStorage.getItem(KEY) || '{}') || {};
        } catch (e) {
            return {};
        }
    }

    function remember(changes) {
        var state = remembered();

        Object.keys(changes).forEach(function (key) { state[key] = changes[key]; });
        try {
            window.sessionStorage.setItem(KEY, JSON.stringify(state));
        } catch (e) {
            // Private mode: the panel simply starts closed on the next page.
        }
    }

    function words(name, fallback) {
        return (panel && panel.getAttribute('data-say-' + name)) || fallback;
    }

    function pane(name) {
        return panel.querySelector('[data-pf-assist-pane="' + name + '"]');
    }

    /** Show one tab's pane, and load Ask the first time it is shown. */
    function show(name) {
        if (!pane(name)) {
            name = pane('ask') ? 'ask' : 'help';
        }
        Array.prototype.forEach.call(panel.querySelectorAll('[data-pf-assist-pane]'), function (one) {
            one.hidden = one.getAttribute('data-pf-assist-pane') !== name;
        });
        Array.prototype.forEach.call(panel.querySelectorAll('[data-pf-assist-tab]'), function (tab) {
            var selected = tab.getAttribute('data-pf-assist-tab') === name;
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            // A themed panel styles `[aria-selected="true"]` itself.
            if (inline()) {
                tab.style.fontWeight = selected ? '600' : '400';
                tab.style.background = selected ? 'rgba(128,128,128,.18)' : 'none';
            }
        });
        remember({ tab: name });
        if (name === 'ask' && !pane('ask').getAttribute('data-loaded')) {
            load(remembered().c || 0);
        }
    }

    /** pf-chat.js, once: the panel's conversation runs on it. */
    function withChat(then) {
        if (window.PfChat) {
            then();

            return;
        }
        var script = document.createElement('script');
        script.src = panel.getAttribute('data-chat-script');
        script.onload = then;
        document.head.appendChild(script);
    }

    /** The conversation, from Ask's own page, into the Ask pane. */
    function load(conversationId) {
        var ask = pane('ask');
        var url = panel.getAttribute('data-ask') + (conversationId ? '?c=' + conversationId : '');

        ask.setAttribute('data-loaded', '1');
        ask.textContent = words('loading', 'Loading…');

        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var chat = new window.DOMParser().parseFromString(html, 'text/html').querySelector('[data-pf-chat]');

                if (!chat) {
                    throw new Error('no conversation on the page');
                }
                chat.setAttribute('data-pf-chat-embedded', '');
                ask.textContent = '';
                ask.appendChild(document.importNode(chat, true));
                withChat(function () { window.PfChat.init(ask.querySelector('[data-pf-chat]')); });
            })
            .catch(function () {
                ask.removeAttribute('data-loaded');
                ask.textContent = words('failed', 'Ask could not be opened here.');
            });
    }

    /**
     * Each time the conversation is drawn: only the conversation and the question box — the list
     * and the settings are Ask's page's — and the page sent with the question.
     */
    function fit(chat) {
        var page = {};

        Array.prototype.forEach.call(chat.querySelectorAll('nav, aside, h1, a[href$="/settings"]'), function (part) {
            if (part.closest('[data-pf-chat]') === chat && !part.closest('[data-pf-chat-messages]')) {
                // A link is hidden with the line it is on: Ask's way to its settings. By its own
                // display: a theme's inline `display` would win over `hidden`.
                (part.tagName === 'A' ? part.parentNode : part).style.display = 'none';
            }
        });

        try {
            page = JSON.parse(panel.getAttribute('data-page') || '{}');
        } catch (e) {
            page = {};
        }

        var form = chat.querySelector('[data-pf-chat-form]');
        if (form) {
            Object.keys(page).forEach(function (key) {
                var field = form.querySelector('input[name="page[' + key + ']"]') || document.createElement('input');
                field.setAttribute('type', 'hidden');
                field.setAttribute('name', 'page[' + key + ']');
                field.value = page[key];
                form.appendChild(field);
            });
            var current = form.querySelector('input[name="conversation_id"]');
            remember({ c: current ? Number(current.value) || 0 : 0 });
        }
    }

    /** Open the panel, on `tab` when given, else on the one it was on. */
    function open(tab) {
        // The analyst greets the reader the first time the panel opens in this session.
        try {
            if (window.pfAssistant && !window.sessionStorage.getItem('pf-assistant-greeted')) {
                window.sessionStorage.setItem('pf-assistant-greeted', '1');
                window.setTimeout(function () { window.pfAssistant.state('greeting'); }, 0);
            }
        } catch (e) { /* no session storage: no greeting */ }
        if (!panel) {
            panel = template.content.firstElementChild.cloneNode(true);
            document.body.appendChild(panel);

            panel.addEventListener('pf-chat:ready', function (event) { fit(event.target); });
            panel.querySelector('[data-pf-assist-close]').addEventListener('click', close);
            Array.prototype.forEach.call(panel.querySelectorAll('[data-pf-assist-tab]'), function (tab) {
                tab.addEventListener('click', function () { show(tab.getAttribute('data-pf-assist-tab')); });
            });
            var fresh = panel.querySelector('[data-pf-assist-new]');
            if (fresh) {
                fresh.addEventListener('click', function () {
                    remember({ c: 0 });
                    show('ask');
                    load(0);
                });
            }
        }
        setOpen(true);
        remember({ open: true });
        show(tab || remembered().tab || 'ask');
    }

    function close() {
        if (panel) {
            setOpen(false);
        }
        remember({ open: false });
        opener.focus();
    }

    /** The tab an opener names, or '' for "where it was". */
    function tabOf(one) {
        return one.getAttribute('data-pf-assist-open') || '';
    }

    openers.forEach(function (one) {
        one.addEventListener('click', function (event) {
            event.preventDefault();
            var tab = tabOf(one);
            var current = remembered().tab;
            opener = one;

            // The same button again closes it; another tab's button turns to that tab.
            if (isOpen() && (tab === '' || tab === current || !pane(tab))) {
                close();
            } else if (isOpen()) {
                show(tab);
            } else {
                open(tab);
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && isOpen()) {
            close();
        }
    });

    if (remembered().open) {
        open();
    }
}());
