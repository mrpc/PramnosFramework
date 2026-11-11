/**
 * A generic preview panel for whatever `pf-companion.js` has mounted — the "debug window" for
 * auditioning a companion, built to know nothing about any particular one. It reads the
 * currently mounted dock from the DOM rather than from any list of poses or states the
 * framework keeps, because it keeps none.
 *
 * ```js
 * window.PfCompanionDebug.mount();   // a small floating panel, bottom-left by default
 * window.PfCompanionDebug.unmount();
 * ```
 *
 * Shows, for whatever is currently mounted (`document.querySelector('.pf-companion-dock')`):
 * - A larger live copy of its avatar.
 * - One button per `window.pfAssistant` state (the 9 chat-lifecycle ones — unchanged, reused).
 * - A free-text box + Apply, calling `window.PfCompanion.setPose()` with whatever was typed —
 *   works for any name, because this file does not know any.
 * - Quick-pick buttons, **only** if the avatar element happens to carry a `data-poses="a,b,c"`
 *   attribute — an entirely optional convention a set's own markup may declare for exactly this;
 *   absent, the free-text box is all there is.
 * - A toggle previewing `pf-companion:activity` active/away without waiting out the real
 *   idle timer.
 *
 * Nothing here is a route or wired into any authentication — mount it on whatever `/debug` page
 * an application already has, the way it would mount anything else.
 */
(function () {
    'use strict';

    var panel = null;

    function el(tag, className) {
        var e = document.createElement(tag);
        if (className) {
            e.className = className;
        }
        return e;
    }

    function dock() {
        return document.querySelector('.pf-companion-dock');
    }

    function avatarOf(dockEl) {
        if (!dockEl) {
            return null;
        }
        return dockEl.querySelector('.pf-assistant-avatar') || dockEl.firstElementChild;
    }

    /**
     * Mount the panel. Calling it again replaces the previous one. With `into`, it is drawn inside
     * that element, in the page's flow; without, fixed in the bottom-left corner.
     *
     * @param {{ into?: HTMLElement }} [options]
     * @returns {HTMLElement}
     */
    function mount(options) {
        var into = options && options.into ? options.into : null;
        unmount();

        var root = el('div', 'pf-companion-debug-panel' + (into ? ' pf-companion-debug-panel--inline' : ''));

        var preview = el('div', 'pf-companion-debug-preview');
        var currentDock = dock();
        if (currentDock) {
            preview.innerHTML = currentDock.innerHTML;
        }
        root.appendChild(preview);

        var states = el('div', 'pf-companion-debug-states');
        (window.pfAssistant ? window.pfAssistant.states : []).forEach(function (name) {
            var button = el('button', 'pf-companion-debug-state');
            button.setAttribute('type', 'button');
            button.textContent = name;
            button.addEventListener('click', function () {
                if (window.pfAssistant) {
                    window.pfAssistant.state(name);
                }
            });
            states.appendChild(button);
        });
        root.appendChild(states);

        var avatar = avatarOf(currentDock);
        var declaredPoses = avatar ? (avatar.getAttribute('data-poses') || '') : '';
        if (declaredPoses) {
            var poses = el('div', 'pf-companion-debug-poses');
            declaredPoses.split(',').map(function (s) { return s.trim(); }).filter(Boolean).forEach(function (name) {
                var button = el('button', 'pf-companion-debug-pose');
                button.setAttribute('type', 'button');
                button.textContent = name;
                button.addEventListener('click', function () {
                    if (window.PfCompanion) {
                        window.PfCompanion.setPose(name);
                    }
                });
                poses.appendChild(button);
            });
            root.appendChild(poses);
        }

        var input = el('input', 'pf-companion-debug-input');
        input.setAttribute('type', 'text');
        input.setAttribute('placeholder', 'Any pose name…');
        root.appendChild(input);

        var apply = el('button', 'pf-companion-debug-apply');
        apply.setAttribute('type', 'button');
        apply.textContent = 'Apply';
        apply.addEventListener('click', function () {
            if (input.value && window.PfCompanion) {
                window.PfCompanion.setPose(input.value);
            }
        });
        root.appendChild(apply);

        var activityToggle = el('button', 'pf-companion-debug-activity');
        activityToggle.setAttribute('type', 'button');
        activityToggle.textContent = 'Toggle active/away';
        activityToggle.addEventListener('click', function () {
            var target = dock();
            var next = target && target.getAttribute('data-pf-companion-activity') === 'active' ? false : true;
            if (target) {
                target.setAttribute('data-pf-companion-activity', next ? 'active' : 'away');
            }
            document.dispatchEvent(new CustomEvent('pf-companion:activity', { bubbles: true, detail: { active: next } }));
        });
        root.appendChild(activityToggle);

        (into || document.body).appendChild(root);
        panel = root;

        return root;
    }

    function unmount() {
        if (panel && panel.parentNode) {
            panel.parentNode.removeChild(panel);
        }
        panel = null;
    }

    window.PfCompanionDebug = { mount: mount, unmount: unmount };
}());
