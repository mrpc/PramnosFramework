/**
 * A spotlight product tour: dims the page, cuts a glowing window around one element per step,
 * and shows a small card beside it — the step's own text, step-by-step navigation, and an
 * optional "🔊 Listen" button. Carries no content of its own: an application supplies its own
 * steps, so this is the mechanism only — a driver.js-style walkthrough, zero dependencies.
 *
 * ```js
 * window.PfTour.start([
 *     { title: 'Welcome', desc: 'This is your dashboard.', selector: 'main' },
 *     { title: 'Search', desc: 'Press Ctrl+K to look anything up.', selector: '.pf-ui-search' },
 * ], {
 *     onFinish: () => localStorage.setItem('toured-dashboard', '1'),
 *     voice: window.PfVoice ? window.PfVoice.createVoiceState() : null,   // optional read-aloud
 * });
 * ```
 *
 * A step's `avatarHtml` (optional, raw HTML — the caller's to escape) renders beside its text:
 * an application with a living avatar (`Assist\AvatarSets::render()`, or its own) passes that
 * markup in; without it, the step is text only.
 *
 * `window.PfTour.start(steps, options)` / `.stop()`. Escape, or the backdrop, closes it. Styled
 * in `components.css` under `.pf-tour-*`.
 */
(function () {
    'use strict';

    var state = null; // { steps, index, options, els: {...} } — one tour at a time

    function el(tag, className) {
        var e = document.createElement(tag);
        if (className) {
            e.className = className;
        }
        return e;
    }

    function currentStep() {
        return state.steps[state.index];
    }

    function updateSpotlight() {
        var step = currentStep();
        var target = step.selector ? document.querySelector(step.selector) : null;

        if (!target) {
            state.els.glow.style.display = 'none';
            state.els.mask.setAttribute('width', '0');
            state.els.mask.setAttribute('height', '0');
            positionCard(null);
            return;
        }

        if (typeof target.scrollIntoView === 'function') {
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        var r = target.getBoundingClientRect();
        var rect = { top: Math.max(8, r.top - 8), left: Math.max(8, r.left - 8), width: r.width + 16, height: r.height + 16 };

        state.els.mask.setAttribute('x', rect.left);
        state.els.mask.setAttribute('y', rect.top);
        state.els.mask.setAttribute('width', rect.width);
        state.els.mask.setAttribute('height', rect.height);

        state.els.glow.style.display = 'block';
        state.els.glow.style.top = rect.top + 'px';
        state.els.glow.style.left = rect.left + 'px';
        state.els.glow.style.width = rect.width + 'px';
        state.els.glow.style.height = rect.height + 'px';

        positionCard(rect);
    }

    /** The card sits below the spotlight, or centered when there is none to sit beside. */
    function positionCard(rect) {
        var card = state.els.card;
        if (!rect) {
            card.style.position = 'fixed';
            card.style.top = '50%';
            card.style.left = '50%';
            card.style.transform = 'translate(-50%, -50%)';
            return;
        }
        var viewportBottom = window.innerHeight || document.documentElement.clientHeight;
        var below = rect.top + rect.height + 16;
        var fitsBelow = below + 160 < viewportBottom;

        card.style.position = 'fixed';
        card.style.left = Math.max(8, Math.min(rect.left, (window.innerWidth || 0) - 360)) + 'px';
        card.style.top = (fitsBelow ? below : Math.max(8, rect.top - 176)) + 'px';
        card.style.transform = 'none';
    }

    function render() {
        var step = currentStep();
        var total = state.steps.length;

        state.els.badge.textContent = (state.options.stepLabel || 'Step %1 of %2')
            .replace('%1', String(state.index + 1)).replace('%2', String(total));
        state.els.avatar.innerHTML = step.avatarHtml || '';
        state.els.avatar.style.display = step.avatarHtml ? '' : 'none';
        state.els.title.textContent = step.title || '';
        state.els.desc.textContent = step.desc || '';
        state.els.back.style.display = state.index > 0 ? '' : 'none';
        state.els.next.textContent = state.index === total - 1
            ? (state.options.finishLabel || 'Finish')
            : (state.options.nextLabel || 'Next');

        while (state.els.dots.firstElementChild) {
            state.els.dots.removeChild(state.els.dots.firstElementChild);
        }
        state.steps.forEach(function (_, i) {
            var dot = el('button', 'pf-tour-dot' + (i === state.index ? ' pf-tour-dot-active' : ''));
            dot.setAttribute('type', 'button');
            dot.setAttribute('aria-label', (state.options.stepLabel || 'Step %1 of %2').replace('%1', String(i + 1)).replace('%2', String(total)));
            dot.addEventListener('click', function () { goTo(i); });
            state.els.dots.appendChild(dot);
        });

        state.els.speak.style.display = state.options.voice ? '' : 'none';
        setSpeaking(false);

        updateSpotlight();

        if (state.options.voice && state.options.autoRead) {
            speakStep();
        }
    }

    function speakStep() {
        if (!state.options.voice) {
            return;
        }
        var step = currentStep();
        state.options.voice.speak('pf-tour-step', ((step.title || '') + '. ' + (step.desc || '')), {
            onStart: function () { setSpeaking(true); },
            onEnd: function () { setSpeaking(false); },
            onError: function () { setSpeaking(false); },
        });
    }

    function setSpeaking(on) {
        state.els.speak.setAttribute('aria-pressed', on ? 'true' : 'false');
        state.els.speak.textContent = on
            ? (state.options.speakingLabel || '🔊 Speaking…')
            : (state.options.listenLabel || '🔊 Listen');
    }

    function goTo(index) {
        if (state.options.voice) {
            state.options.voice.stopSpeech();
        }
        state.index = Math.max(0, Math.min(index, state.steps.length - 1));
        render();
    }

    function next() {
        if (state.index < state.steps.length - 1) {
            goTo(state.index + 1);
        } else {
            finish();
        }
    }

    function back() {
        goTo(state.index - 1);
    }

    function finish() {
        if (!state) {
            return;
        }
        var options = state.options;
        teardown();
        if (options.onFinish) {
            options.onFinish();
        }
    }

    function teardown() {
        if (!state) {
            return;
        }
        if (state.options.voice) {
            state.options.voice.stopSpeech();
        }
        window.removeEventListener('resize', updateSpotlight);
        window.removeEventListener('scroll', updateSpotlight, true);
        document.removeEventListener('keydown', onKeydown);
        if (state.els.root.parentNode) {
            state.els.root.parentNode.removeChild(state.els.root);
        }
        state = null;
    }

    function onKeydown(event) {
        if (event.key === 'Escape') {
            finish();
        }
    }

    /**
     * Start a tour. Only one runs at a time — starting a second stops the first without calling
     * its onFinish, the same way opening a second modal would replace the first.
     *
     * @param {Array<{title?:string, desc?:string, selector?:string, avatarHtml?:string}>} steps
     * @param {{
     *     onFinish?: () => void,
     *     voice?: object,
     *     autoRead?: boolean,
     *     closeLabel?: string, nextLabel?: string, finishLabel?: string, backLabel?: string,
     *     stepLabel?: string, listenLabel?: string, speakingLabel?: string, closeTitle?: string
     * }} [options]
     */
    function start(steps, options) {
        if (!Array.isArray(steps) || steps.length === 0) {
            return;
        }
        if (state) {
            teardown();
        }

        var opts = options || {};
        var root = el('div', 'pf-tour');
        root.setAttribute('role', 'dialog');

        var backdropBtn = el('button', 'pf-tour-backdrop');
        backdropBtn.setAttribute('type', 'button');
        backdropBtn.setAttribute('aria-label', opts.closeTitle || 'Close tour');
        backdropBtn.addEventListener('click', finish);
        root.appendChild(backdropBtn);

        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'pf-tour-dim');
        svg.setAttribute('aria-hidden', 'true');
        var maskId = 'pf-tour-mask-' + Date.now();
        var defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
        var mask = document.createElementNS('http://www.w3.org/2000/svg', 'mask');
        mask.setAttribute('id', maskId);
        var full = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        full.setAttribute('x', '0');
        full.setAttribute('y', '0');
        full.setAttribute('width', '100%');
        full.setAttribute('height', '100%');
        full.setAttribute('fill', 'white');
        var hole = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        hole.setAttribute('rx', '16');
        hole.setAttribute('fill', 'black');
        mask.appendChild(full);
        mask.appendChild(hole);
        defs.appendChild(mask);
        var dim = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        dim.setAttribute('x', '0');
        dim.setAttribute('y', '0');
        dim.setAttribute('width', '100%');
        dim.setAttribute('height', '100%');
        dim.setAttribute('class', 'pf-tour-dim-fill');
        dim.setAttribute('mask', 'url(#' + maskId + ')');
        svg.appendChild(defs);
        svg.appendChild(dim);
        root.appendChild(svg);

        var glow = el('div', 'pf-tour-glow');
        glow.style.display = 'none';
        root.appendChild(glow);

        var card = el('div', 'pf-tour-card');

        var header = el('div', 'pf-tour-header');
        var badge = el('span', 'pf-tour-badge');
        header.appendChild(badge);
        var close = el('button', 'pf-tour-close');
        close.setAttribute('type', 'button');
        close.setAttribute('aria-label', opts.closeTitle || 'Close tour');
        close.textContent = '✕';
        close.addEventListener('click', finish);
        header.appendChild(close);
        card.appendChild(header);

        var body = el('div', 'pf-tour-body');
        var avatar = el('div', 'pf-tour-avatar');
        body.appendChild(avatar);
        var text = el('div', 'pf-tour-text');
        var title = el('h3', 'pf-tour-title');
        var desc = el('p', 'pf-tour-desc');
        text.appendChild(title);
        text.appendChild(desc);
        body.appendChild(text);
        card.appendChild(body);

        var dots = el('div', 'pf-tour-dots');
        card.appendChild(dots);

        var footer = el('div', 'pf-tour-footer');
        var speak = el('button', 'pf-tour-speak');
        speak.setAttribute('type', 'button');
        speak.setAttribute('aria-pressed', 'false');
        speak.addEventListener('click', function () {
            if (opts.voice && opts.voice.speaking) {
                opts.voice.stopSpeech();
                setSpeaking(false);
            } else {
                speakStep();
            }
        });
        footer.appendChild(speak);

        var nav = el('div', 'pf-tour-nav');
        var backBtn = el('button', 'pf-tour-back');
        backBtn.setAttribute('type', 'button');
        backBtn.textContent = opts.backLabel || 'Back';
        backBtn.addEventListener('click', back);
        nav.appendChild(backBtn);
        var nextBtn = el('button', 'pf-tour-next');
        nextBtn.setAttribute('type', 'button');
        nextBtn.addEventListener('click', next);
        nav.appendChild(nextBtn);
        footer.appendChild(nav);
        card.appendChild(footer);

        root.appendChild(card);
        document.body.appendChild(root);

        state = {
            steps: steps,
            index: 0,
            options: opts,
            els: { root: root, mask: hole, glow: glow, card: card, badge: badge, avatar: avatar, title: title, desc: desc, dots: dots, speak: speak, back: backBtn, next: nextBtn },
        };

        window.addEventListener('resize', updateSpotlight, { passive: true });
        window.addEventListener('scroll', updateSpotlight, { passive: true, capture: true });
        document.addEventListener('keydown', onKeydown);

        render();
    }

    /** End the running tour without calling onFinish — a page navigating away, say. */
    function stop() {
        teardown();
    }

    window.PfTour = { start: start, stop: stop };
}());
