/**
 * A floating companion dock: mounts one avatar (any markup — typically
 * `Assist\AvatarSets::render()`'s output) fixed on the screen, tracks whether the reader is
 * active or has gone idle, and passes a pose name through to the avatar's own `data-pose`
 * attribute without knowing or caring what it means.
 *
 * Deliberately not here: a pose vocabulary, mini-games, character content. This is the dock,
 * the idle signal, and an event bus — what a companion *looks like* and *does* is the
 * application's, built on top with its own CSS reacting to `[data-pose="…"]` and its own
 * listeners on the two events below. See docs/Pramnos_LLM_Analyst_Guide.md "## The floating
 * companion".
 *
 * ```js
 * window.PfCompanion.mount({ html: '<span class="pf-assistant-avatar ...">…</span>' });
 * // or, with no `html`: reads <template data-pf-companion> — the same declarative convention
 * // pf-assist.js uses for its own panel.
 *
 * document.addEventListener('pf-companion:click', () => { ... });      // what a click means: yours
 * document.addEventListener('pf-companion:activity', (e) => { ... });  // e.detail.active: boolean
 *
 * window.PfCompanion.setPose('sitting');       // free-form; the framework enforces nothing
 * window.PfCompanion.setPosition('top-left');  // a convenience default; override the CSS class
 *                                               // yourself for anything else
 * window.PfCompanion.unmount();
 * ```
 */
(function () {
    'use strict';

    var CORNERS = ['top-left', 'top-right', 'bottom-left', 'bottom-right'];
    var ACTIVITY_EVENTS = ['mousemove', 'keydown', 'scroll', 'click', 'touchstart'];
    var THROTTLE_MS = 500;

    var current = null; // { dock, idleMs, active, idleTimer, lastActivityAt, listeners }

    function avatarRoot(dock) {
        return dock.querySelector('.pf-assistant-avatar') || dock.firstElementChild;
    }

    function setActive(active) {
        if (!current || current.active === active) {
            return;
        }
        current.active = active;
        current.dock.setAttribute('data-pf-companion-activity', active ? 'active' : 'away');
        document.dispatchEvent(new CustomEvent('pf-companion:activity', { bubbles: true, detail: { active: active } }));
    }

    function scheduleIdle() {
        if (!current) {
            return;
        }
        if (current.idleTimer) {
            window.clearTimeout(current.idleTimer);
        }
        current.idleTimer = window.setTimeout(function () {
            setActive(false);
        }, current.idleMs);
    }

    function onActivity() {
        if (!current) {
            return;
        }
        var now = Date.now();
        if (now - current.lastActivityAt < THROTTLE_MS) {
            return;
        }
        current.lastActivityAt = now;
        setActive(true);
        scheduleIdle();
    }

    /**
     * Mount the companion. Calling it again replaces whatever was mounted before, without
     * firing any event for the one being replaced — the same as opening a second modal.
     *
     * @param {{
     *     html?: string,            // defaults to <template data-pf-companion>'s content
     *     position?: 'top-left'|'top-right'|'bottom-left'|'bottom-right',
     *     idleMs?: number,          // default 60000; 0 disables idle detection entirely
     *     container?: HTMLElement,  // default document.body
     *     into?: HTMLElement,       // drawn inside it, in the page's flow, with no fixed corner
     * }} [options]
     * @returns {HTMLElement|null} The dock element, or null when there was no html to mount.
     */
    function mount(options) {
        var opts = options || {};
        unmount();

        var html = opts.html;
        if (html === undefined) {
            var template = document.querySelector('template[data-pf-companion]');
            if (template) {
                html = template.innerHTML;
                if (opts.position === undefined && template.getAttribute('data-position')) {
                    opts.position = template.getAttribute('data-position');
                }
            }
        }
        if (!html) {
            return null;
        }

        var dock = document.createElement('div');
        dock.className = 'pf-companion-dock ' + (opts.into ? 'pf-companion-dock--inline'
            : 'pf-companion-dock--' + (CORNERS.indexOf(opts.position) !== -1 ? opts.position : 'bottom-right'));
        dock.innerHTML = html;
        dock.setAttribute('data-pf-companion-activity', 'active');
        dock.addEventListener('click', function () {
            document.dispatchEvent(new CustomEvent('pf-companion:click', { bubbles: true }));
        });

        (opts.into || opts.container || document.body).appendChild(dock);

        var idleMs = typeof opts.idleMs === 'number' ? opts.idleMs : 60000;
        current = { dock: dock, idleMs: idleMs, active: true, idleTimer: null, lastActivityAt: Date.now(), listeners: [] };

        if (idleMs > 0) {
            ACTIVITY_EVENTS.forEach(function (type) {
                document.addEventListener(type, onActivity, { passive: true });
                current.listeners.push(type);
            });
            scheduleIdle();
        }

        return dock;
    }

    /** Remove the mounted companion and detach every listener it installed. */
    function unmount() {
        if (!current) {
            return;
        }
        if (current.idleTimer) {
            window.clearTimeout(current.idleTimer);
        }
        current.listeners.forEach(function (type) {
            document.removeEventListener(type, onActivity);
        });
        if (current.dock.parentNode) {
            current.dock.parentNode.removeChild(current.dock);
        }
        current = null;
    }

    /**
     * Pass a pose name through to the mounted avatar's `data-pose` attribute. Free-form: not
     * validated, not enumerated — the framework has no list of poses to check against.
     *
     * @param {string} name
     */
    function setPose(name) {
        if (!current) {
            return;
        }
        var avatar = avatarRoot(current.dock);
        if (avatar) {
            avatar.setAttribute('data-pose', name);
        }
    }

    /**
     * Move the dock to one of the four named corners — a convenience default, not the only
     * way to position it: `.pf-companion-dock` is plain CSS, override it for anything else.
     *
     * @param {string} corner
     */
    function setPosition(corner) {
        if (!current || CORNERS.indexOf(corner) === -1) {
            return;
        }
        CORNERS.forEach(function (c) { current.dock.classList.remove('pf-companion-dock--' + c); });
        current.dock.classList.add('pf-companion-dock--' + corner);
    }

    /** Whether a companion is currently mounted. */
    function isMounted() {
        return current !== null;
    }

    /** The mounted dock element, or null — for what builds on it (pf-companion-play.js). */
    function dockElement() {
        return current ? current.dock : null;
    }

    window.PfCompanion = {
        mount: mount,
        unmount: unmount,
        setPose: setPose,
        setPosition: setPosition,
        isMounted: isMounted,
        dock: dockElement,
    };
}());
