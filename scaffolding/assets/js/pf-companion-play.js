/**
 * pf-companion-play.js — the companion leaves its dock and plays: a laser to chase, a ball of yarn,
 * zoomies across the page, and eyes that follow the pointer. Built on `pf-companion.js`'s dock, for
 * any avatar set; a set that draws gaits (`data-gait`), a facing (`data-facing`) and a look
 * (`--pf-look-x/y`) — Tesla's — moves with them, any other is carried as it is.
 *
 * ```js
 * PfCompanion.mount({ html: … });
 * PfCompanionPlay.follow(true);   // the eyes follow the pointer
 * PfCompanionPlay.laser();        // a red dot: click anywhere and she pounces; Esc, 30 s idle, or stop() ends it
 * PfCompanionPlay.yarn();         // a ball tossed on the page; she rolls on her back with it, then comes home
 * PfCompanionPlay.zoomies();      // a dash across the whole width
 * PfCompanionPlay.stop();         // whatever is playing ends; she trots home
 * ```
 *
 * While she is out, the avatar itself is moved into a fixed runner on the page and the dock is
 * left empty (`data-pf-companion-away` on it), so there is never a second copy. One game at a
 * time: starting one ends another. A question asked (`pf-assistant:state` listening, thinking,
 * talking) ends any game, so the assistant is home to answer. With `prefers-reduced-motion`, she
 * goes where she goes at once, without running.
 *
 * `pf-companion-play:start` and `pf-companion-play:stop` are dispatched on the document with
 * `{ game }`.
 *
 * **Without the framework's dock** — a component of the application's own (a Svelte SPA):
 *
 * ```js
 * PfCompanionPlay.attach(element);   // the element holding the avatar, instead of PfCompanion's dock
 * PfCompanionPlay.state('thinking'); // the avatar's state, from the component; a question ends any game
 * PfCompanionPlay.detach();          // on unmount: the game ends, the avatar is back in its place at
 *                                    // once — or removed, when its place is no longer on the page
 * ```
 */
(function () {
    'use strict';

    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    /** Pixels a second, by gait. */
    var SPEED = { walk: 120, trot: 260, sprint: 650, zoomies: 1100 };
    var LASER_IDLE_MS = 30000;
    var SWAT_MS = 1200;
    var PLAY_MS = 3200;
    var MAX_LOOK = 1.35;
    var BUSY = ['listening', 'thinking', 'talking'];

    var out = null;       // { runner, placeholder, home: {x, y}, pos: {x, y} } while she is out of the dock
    var game = null;      // { name, timers: [], undo: [] }
    var following = null; // the pointer listener, while the eyes follow
    var loose = [];       // timers outside a game — the way home — which a new game cancels
    var attached = null;  // an element of the application's own, in place of PfCompanion's dock

    function dock() {
        if (attached) {
            return attached;
        }

        return window.PfCompanion && typeof window.PfCompanion.dock === 'function' ? window.PfCompanion.dock() : null;
    }

    function avatar() {
        if (out) {
            return out.runner.firstElementChild;
        }
        var d = dock();

        return d ? d.querySelector('.pf-assistant-avatar') || d.firstElementChild : null;
    }

    function reduced() {
        return typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function later(fn, ms) {
        var timer = window.setTimeout(fn, ms);
        (game ? game.timers : loose).push(timer);

        return timer;
    }

    function wait(ms) {
        return new Promise(function (done) { later(done, ms); });
    }

    function rect(el) {
        var r = el.getBoundingClientRect();

        return { x: r.left, y: r.top, w: r.width || 64, h: r.height || 64 };
    }

    /** Out of the dock: the avatar into a fixed runner where it stood; the dock keeps its size, empty. */
    function leave() {
        if (out) {
            return out;
        }
        var a = avatar();
        var d = dock();
        if (!a || !d) {
            return null;
        }
        var r = rect(a);
        var runner = document.createElement('div');
        runner.className = 'pf-companion-runner';
        runner.style.cssText = 'position:fixed;left:0;top:0;z-index:2147483000;pointer-events:none;'
            + 'width:' + r.w + 'px;height:' + r.h + 'px;transform:translate(' + r.x + 'px,' + r.y + 'px)';
        // Where she goes back to: a hidden mark in her place, so the dock's own markup stays in order.
        var placeholder = document.createElement('span');
        placeholder.hidden = true;
        placeholder.className = 'pf-companion-place';
        d.style.minWidth = (d.offsetWidth || r.w) + 'px';
        d.style.minHeight = (d.offsetHeight || r.h) + 'px';
        a.parentNode.insertBefore(placeholder, a);
        runner.appendChild(a);
        document.body.appendChild(runner);
        d.setAttribute('data-pf-companion-away', '');
        out = { runner: runner, placeholder: placeholder, home: { x: r.x, y: r.y }, pos: { x: r.x, y: r.y }, size: { w: r.w, h: r.h } };

        return out;
    }

    /** Back in the dock, as she was. */
    function arrive() {
        if (!out) {
            return;
        }
        var a = out.runner.firstElementChild;
        if (a && out.placeholder.parentNode) {
            out.placeholder.parentNode.insertBefore(a, out.placeholder);
        }
        if (out.placeholder.parentNode) {
            out.placeholder.parentNode.removeChild(out.placeholder);
        }
        if (out.runner.parentNode) {
            out.runner.parentNode.removeChild(out.runner);
        }
        if (a) {
            a.removeAttribute('data-gait');
            a.removeAttribute('data-facing');
        }
        var d = dock();
        if (d) {
            d.removeAttribute('data-pf-companion-away');
            d.style.minWidth = '';
            d.style.minHeight = '';
        }
        out = null;
    }

    /**
     * Run to a point of the page (her top-left corner there), at a gait, facing where she goes.
     *
     * @returns {Promise<boolean>} false when there is nothing to move
     */
    function moveTo(x, y, gait) {
        var o = leave();
        if (!o) {
            return Promise.resolve(false);
        }
        var a = o.runner.firstElementChild;
        var dx = x - o.pos.x;
        var dy = y - o.pos.y;
        var ms = reduced() ? 0 : Math.round(Math.sqrt(dx * dx + dy * dy) / (SPEED[gait] || SPEED.trot) * 1000);
        if (Math.abs(dx) > 1) {
            if (dx > 0) {
                a.setAttribute('data-facing', 'right');
            } else {
                a.removeAttribute('data-facing');
            }
        }
        if (ms > 0) {
            a.setAttribute('data-gait', gait);
        }
        o.runner.style.transition = ms > 0 ? 'transform ' + ms + 'ms linear' : 'none';
        o.runner.style.transform = 'translate(' + x + 'px,' + y + 'px)';
        o.pos = { x: x, y: y };

        return wait(ms).then(function () {
            a.removeAttribute('data-gait');

            return true;
        });
    }

    /** Home at a trot, and into the dock. */
    function goHome() {
        if (!out) {
            return Promise.resolve();
        }

        return moveTo(out.home.x, out.home.y, 'trot').then(arrive);
    }

    /** A pose and a state for a while, then back to what she had. */
    function act(poseName, stateName, ms) {
        var a = avatar();
        if (!a) {
            return Promise.resolve();
        }
        var before = a.getAttribute('data-pose');
        if (poseName) {
            a.setAttribute('data-pose', poseName);
        }
        if (stateName && window.pfAssistant && out) {
            window.pfAssistant.state(stateName, out.runner);
        }

        return wait(ms).then(function () {
            if (poseName) {
                if (before === null) {
                    a.removeAttribute('data-pose');
                } else {
                    a.setAttribute('data-pose', before);
                }
            }
            if (stateName && window.pfAssistant && out) {
                window.pfAssistant.state('idle', out.runner);
            }
        });
    }

    /** A game begins: any other ends where it is, without going home first. */
    function begin(name) {
        end();
        // On her way home: she stops there and plays from where she is.
        loose.forEach(function (t) { window.clearTimeout(t); });
        loose = [];
        game = { name: name, timers: [], undo: [] };
        document.dispatchEvent(new CustomEvent('pf-companion-play:start', { bubbles: true, detail: { game: name } }));

        return game;
    }

    /** Whatever is playing stops: its timers, its listeners, what it put on the page. */
    function end() {
        if (!game) {
            return null;
        }
        var ended = game;
        game = null;
        ended.timers.forEach(function (t) { window.clearTimeout(t); });
        ended.undo.forEach(function (fn) { fn(); });
        var a = avatar();
        if (a) {
            a.removeAttribute('data-gait');
        }
        document.dispatchEvent(new CustomEvent('pf-companion-play:stop', { bubbles: true, detail: { game: ended.name } }));

        return ended;
    }

    /** Stop the game, and she trots home. */
    function stop() {
        end();

        return goHome();
    }

    function listen(target, type, fn, options) {
        target.addEventListener(type, fn, options);
        game.undo.push(function () { target.removeEventListener(type, fn, options); });
    }

    function put(el) {
        document.body.appendChild(el);
        game.undo.push(function () {
            if (el.parentNode) {
                el.parentNode.removeChild(el);
            }
        });

        return el;
    }

    /** Where she stands so as to reach a point with her front paws: her centre on it, on the floor of it. */
    function standAt(px, py) {
        var size = out ? out.size : { w: 64, h: 64 };

        return { x: Math.max(0, Math.min(window.innerWidth - size.w, px - size.w / 2)), y: Math.max(0, Math.min(window.innerHeight - size.h, py - size.h * 0.8)) };
    }

    /**
     * The laser: a red dot on the pointer and a crosshair; a click sends her after it — a sprint, a
     * crouch and a swat — and is not a click on the page. Esc, 30 seconds without a click, or
     * stop() ends it.
     */
    function laser() {
        var g = begin('laser');
        var dot = document.createElement('div');
        dot.className = 'pf-companion-laser';
        dot.style.cssText = 'position:fixed;left:0;top:0;width:10px;height:10px;margin:-5px 0 0 -5px;border-radius:50%;'
            + 'background:#ef4444;box-shadow:0 0 8px 3px rgba(239,68,68,.6);pointer-events:none;z-index:2147483001';
        put(dot);
        var root = document.documentElement;
        var cursor = root.style.cursor;
        root.style.cursor = 'crosshair';
        g.undo.push(function () { root.style.cursor = cursor || ''; });

        var idle = null;
        function resetIdle() {
            if (idle !== null) {
                window.clearTimeout(idle);
            }
            idle = later(function () {
                if (game === g) {
                    stop();
                }
            }, LASER_IDLE_MS);
        }
        resetIdle();

        listen(document, 'mousemove', function (e) {
            dot.style.transform = 'translate(' + e.clientX + 'px,' + e.clientY + 'px)';
        }, { passive: true });
        listen(document, 'click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            resetIdle();
            var to = standAt(e.clientX, e.clientY);
            moveTo(to.x, to.y, 'sprint').then(function (moved) {
                if (moved && game === g) {
                    return act('stalking', 'excited', SWAT_MS);
                }
            });
        }, true);
        listen(document, 'keydown', function (e) {
            if (e.key === 'Escape') {
                stop();
            }
        });

        return g;
    }

    /** A ball of yarn tossed on the page: she runs to it, rolls on her back kicking it, then comes home. */
    function yarn() {
        var g = begin('yarn');
        var size = 28;
        var x = Math.round(window.innerWidth * (0.15 + Math.random() * 0.6));
        var y = Math.max(0, window.innerHeight - size - 24);
        var ball = document.createElement('div');
        ball.className = 'pf-companion-yarn';
        ball.setAttribute('aria-hidden', 'true');
        ball.textContent = '🧶';
        ball.style.cssText = 'position:fixed;left:0;top:0;font-size:' + size + 'px;line-height:1;pointer-events:none;z-index:2147483001;'
            + 'transition:transform .6s cubic-bezier(.2,.8,.3,1);transform:translate(' + x + 'px,' + y + 'px)';
        put(ball);
        var to = standAt(x + size / 2, y + size);

        return moveTo(to.x, to.y, 'sprint').then(function (moved) {
            return moved && game === g ? act('belly-up', 'playing', PLAY_MS) : null;
        }).then(function () {
            if (game === g) {
                stop();
            }
        });
    }

    /** A dash across the whole width of the page, and home. */
    function zoomies() {
        var g = begin('zoomies');
        if (!leave()) {
            end();

            return Promise.resolve();
        }
        var y = out.pos.y;
        var right = window.innerWidth - out.size.w;
        // The far side first, then all the way back: a dash, whichever corner she starts from.
        var first = out.pos.x < right / 2 ? right : 0;

        return moveTo(first, y, 'zoomies').then(function () {
            return game === g ? moveTo(right - first, y, 'zoomies') : null;
        }).then(function () {
            if (game === g) {
                stop();
            }
        });
    }

    /** The eyes follow the pointer: `--pf-look-x/y` on the avatar, from -1.35 to 1.35. */
    function follow(on) {
        if (following) {
            document.removeEventListener('mousemove', following);
            following = null;
        }
        if (!on) {
            var a = avatar();
            if (a) {
                a.style.removeProperty('--pf-look-x');
                a.style.removeProperty('--pf-look-y');
            }

            return;
        }
        following = function (e) {
            var a = avatar();
            if (!a) {
                return;
            }
            var r = rect(a);
            var dx = e.clientX - (r.x + r.w / 2);
            var dy = e.clientY - (r.y + r.h / 2);
            var dist = Math.sqrt(dx * dx + dy * dy) || 1;
            var reach = Math.min(1, dist / 200) * MAX_LOOK;
            a.style.setProperty('--pf-look-x', (dx / dist * reach).toFixed(2));
            a.style.setProperty('--pf-look-y', (dy / dist * reach).toFixed(2));
        };
        document.addEventListener('mousemove', following, { passive: true });
    }

    // A question asked: the assistant comes home to answer it.
    document.addEventListener('pf-assistant:state', function (e) {
        if (game && e.detail && BUSY.indexOf(e.detail.state) !== -1) {
            stop();
        }
    });

    /** Play with the avatar inside an element of the application's own, instead of PfCompanion's dock. */
    function attach(element) {
        detach();
        attached = element || null;

        return window.PfCompanionPlay;
    }

    /**
     * Stop at once, with nothing left behind: the game's listeners and what it put on the page
     * gone, the eyes no longer following, and the avatar back in its place — or removed with its
     * runner, when that place is no longer on the page (a component unmounted while she played).
     */
    function detach() {
        end();
        loose.forEach(function (t) { window.clearTimeout(t); });
        loose = [];
        follow(false);
        if (out) {
            if (out.placeholder.parentNode && document.documentElement.contains(out.placeholder)) {
                arrive();
            } else {
                if (out.runner.parentNode) {
                    out.runner.parentNode.removeChild(out.runner);
                }
                out = null;
            }
        }
        attached = null;
    }

    /**
     * The avatar's state, from a component that does not use pfAssistant: through pfAssistant
     * where it is on the page, else on the avatar itself. A question asked ends any game, as it
     * does through pfAssistant.
     */
    function state(name) {
        if (game && BUSY.indexOf(name) !== -1) {
            stop();
        }
        var a = avatar();
        if (!a) {
            return;
        }
        if (window.pfAssistant && typeof window.pfAssistant.state === 'function') {
            window.pfAssistant.state(name, out ? out.runner : (a.parentNode || undefined));

            return;
        }
        var previous = a.getAttribute('data-state');
        if (previous) {
            a.classList.remove('pf-assistant--' + previous);
        }
        a.classList.add('pf-assistant--' + name);
        a.setAttribute('data-state', name);
    }

    window.PfCompanionPlay = {
        attach: attach,
        detach: detach,
        state: state,
        laser: laser,
        yarn: yarn,
        zoomies: zoomies,
        follow: follow,
        stop: stop,
        playing: function () { return game ? game.name : null; },
        isOut: function () { return out !== null; },
    };
}());
