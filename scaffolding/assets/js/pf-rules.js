/**
 * pf-rules.js — a rule's editor, from the markup `Pramnos\Html\RuleEditor` writes.
 *
 * Four numbered cards — when, who, what, until — each folding to a one-line summary. When: the
 * trigger as a sentence, an event picked from a searchable list grouped as the application declared
 * them, and whether a person may come in more than once. What: the steps as cards joined by a line,
 * each with its icon; a dashed tile adds one from a menu of kinds. Until: a goal event within days,
 * a consent list, sending hours on the 24-hour clock, and limits per channel. Who and the exit are
 * the audience builder's (`pf-audience.js`), as is a branch's own conditions.
 *
 * Every change writes the state back to the hidden `definition` field as JSON; the server checks it
 * again when the form is saved.
 */
(function () {
    'use strict';

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (name) {
            var v = attrs[name];
            if (v !== null && v !== undefined && v !== false) { node.setAttribute(name, v === true ? '' : String(v)); }
        });
        (children || []).forEach(function (child) {
            if (child === null || child === undefined) { return; }
            node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
        });
        return node;
    }

    function json(value, fallback) {
        try { return value ? JSON.parse(value) : fallback; } catch (e) { return fallback; }
    }

    /** `%s` filled in order. */
    function say(text) {
        var args = Array.prototype.slice.call(arguments, 1);
        var i = 0;
        return String(text).replace(/%s/g, function () { return String(args[i++]); });
    }

    /** The theme's classes for the controls, and the icons, from the editor's markup. */
    var classes = { button: '', input: '', select: '', icon: '' };
    var icons = {};

    /** An icon by name, as the editor's markup gave it. */
    function icon(name) {
        var span = el('span', { 'class': 'pf-icon-svg', 'aria-hidden': 'true' });
        span.innerHTML = icons[name] || '';
        return span;
    }

    /** A select over `[value, label]` pairs, the current value chosen, `onChange(value)` told. */
    function select(options, current, onChange, attrs) {
        var node = el('select', Object.assign({ 'class': 'pf-rule-input ' + classes.select }, attrs || {}), options.map(function (o) {
            return el('option', { value: o[0], selected: String(o[0]) === String(current) }, [o[1]]);
        }));
        node.value = current === null || current === undefined ? '' : String(current);
        node.addEventListener('change', function () { onChange(node.value); });
        return node;
    }

    /** A text, number or time field. */
    function input(type, current, onChange, attrs) {
        var node = el('input', Object.assign({ type: type, 'class': 'pf-rule-input ' + classes.input, value: current === undefined || current === null ? '' : current }, attrs || {}));
        node.value = current === undefined || current === null ? '' : String(current);
        var tell = function () { onChange(type === 'number' ? (node.value === '' ? '' : Number(node.value)) : node.value); };
        node.addEventListener('input', tell);
        node.addEventListener('change', tell);
        return node;
    }

    /** A time of day on the 24-hour clock, whatever the browser's locale: "09:00". */
    function clock(current, onChange, attrs) {
        var node = input('text', current, function (v) {
            onChange(/^([01]\d|2[0-3]):[0-5]\d$/.test(v) ? v : (v === '' ? '' : null));
        }, Object.assign({ inputmode: 'numeric', pattern: '([01][0-9]|2[0-3]):[0-5][0-9]', maxlength: '5', placeholder: '09:00', size: '5' }, attrs || {}));
        node.className = 'pf-rule-input pf-input-time ' + classes.input;
        return node;
    }

    /** A sentence: words and fields in a row. */
    function sentence(parts, attrs) {
        return el('div', Object.assign({ 'class': 'pf-rule-sentence' }, attrs || {}), parts.map(function (p) {
            return typeof p === 'string' ? el('span', { 'class': 'pf-rule-word' }, [p]) : p;
        }));
    }

    /** Seconds as an amount and a unit, both edited. */
    function duration(w, seconds, onChange) {
        var unit = seconds && seconds % 86400 === 0 ? 86400 : (seconds && seconds % 3600 === 0 ? 3600 : 60);
        var amount = seconds ? seconds / unit : '';
        var amountField = input('number', amount, function (v) { amount = v; onChange(amount === '' ? 0 : amount * unit); }, { min: '1', 'data-pf-rule-amount': true });
        amountField.className += ' pf-input-short';
        var unitField = select([[60, w.minutes || 'minutes'], [3600, w.hours || 'hours'], [86400, w.days || 'days']], unit, function (v) {
            unit = Number(v); onChange(amount === '' ? 0 : amount * unit);
        }, { 'data-pf-rule-unit': true });
        return [amountField, unitField];
    }

    function start(root) {
        if (root.getAttribute('data-pf-rule-ready') !== null) { return; }
        root.setAttribute('data-pf-rule-ready', '');
        var catalog = json(root.getAttribute('data-catalog'), {});
        classes = Object.assign({ button: '', input: '', select: '', icon: '' }, json(root.getAttribute('data-classes'), {}));
        icons = json(root.getAttribute('data-icons'), {});
        var w = json(root.getAttribute('data-words'), {});
        var hidden = root.querySelector('[data-pf-rule-state]');
        var state = json(hidden && hidden.value, {}) || {};
        state.trigger = state.trigger || { type: 'event', event: '' };
        state.entry = state.entry || 'first';
        state.steps = state.steps && !Array.isArray(state.steps) ? state.steps : {};
        var actions = {};
        (catalog.actions || []).forEach(function (a) { actions[a.key] = a; });
        var events = {};
        (catalog.events || []).forEach(function (e) { events[e.key] = e; });
        var whoCount = null;

        /** The events, grouped as declared, for a combobox. */
        var eventGroups = function () {
            var groups = [];
            var byLabel = {};
            (catalog.events || []).forEach(function (e) {
                var label = e.group || w.otherEvents || 'Other';
                if (!byLabel[label]) { byLabel[label] = { label: label, options: [] }; groups.push(byLabel[label]); }
                byLabel[label].options.push({ value: e.key, label: e.label, hint: e.key });
            });
            return groups;
        };
        /** An event field: the searchable list where pf-audience.js is there, a typed name otherwise. */
        var eventField = function (current, onChange, attrs) {
            if (window.pfAudience && window.pfAudience.combobox) {
                return window.pfAudience.combobox(eventGroups(), current || '', onChange, {
                    placeholder: w.chooseEvent || 'Choose an event…', label: w.eventName || 'Event', searchLabel: w.search, emptyLabel: w.nothingFound,
                    attrs: attrs,
                });
            }
            return input('text', current, onChange, Object.assign({ placeholder: w.eventName || 'Event' }, attrs || {}));
        };
        var eventLabel = function (key) { return key ? ((events[key] || {}).label || key) : ''; };
        var kindOf = function (step) {
            if (step.type === 'action') { return (actions[step.action] || {}).channel ? 'message' : 'action'; }
            return step.type;
        };

        var save = function () {
            if (hidden) { hidden.value = JSON.stringify(state); }
            summarize();
            root.dispatchEvent(new window.CustomEvent('pf:rule-change', { detail: state, bubbles: true }));
        };
        var ids = function () { return Object.keys(state.steps); };
        var title = function (id) {
            var step = state.steps[id];
            if (!step) { return id; }
            var kind = kindOf(step);
            return id + ' · ' + (kind === 'message' || kind === 'action' ? ((actions[step.action] || {}).label || step.action) : (w[step.type] || step.type));
        };
        var target = function (current, onChange, attrs) {
            return select([['', w.end || 'End']].concat(ids().map(function (id) { return [id, title(id)]; })), current || '', function (v) {
                onChange(v === '' ? null : v);
            }, attrs);
        };

        // ── The one-line summaries of the folded cards ──
        var summary = function (key, text) {
            var node = root.querySelector('[data-pf-rule-summary="' + key + '"]');
            if (node) { node.textContent = text; }
        };
        var summarize = function () {
            var t = state.trigger;
            var when = t.type === 'event' ? (t.event ? say(w.whenEvent || 'When: %s', eventLabel(t.event)) : (w.noEvent || 'No event chosen yet'))
                : (w[t.type] || t.type) + (t.segment ? ' · #' + t.segment : '') + (t.cron ? ' · ' + t.cron : '');
            summary('when', when + ' · ' + (state.entry === 'every' ? (w.every || 'every time') : (w.first || 'the first time only')));
            summary('who', whoCount === null ? '' : (whoCount === 0 ? (w.everybody || 'Everybody') : say(w.conditionsCount || '%s conditions', whoCount)));
            var n = ids().length;
            summary('what', n === 0 ? (w.noSteps || 'No steps yet') : say(w.stepsCount || '%s steps', n) + ' · ' + ids().map(title).slice(0, 3).join(' → ') + (n > 3 ? ' …' : ''));
            var parts = [];
            if (state.goal && state.goal.event) { parts.push(say(w.goalSummary || 'Goal: %s within %s days', eventLabel(state.goal.event), state.goal.days)); }
            if (state.hours) { parts.push(state.hours.from + '–' + state.hours.to); }
            var caps = state.caps && !Array.isArray(state.caps) ? Object.keys(state.caps) : [];
            if (caps.length) { parts.push(say(w.limitsCount || '%s limits', caps.length)); }
            summary('until', parts.join(' · ') || (w.noLimits || 'No goal, no limits'));
        };
        var whoBox = root.querySelector('[data-pf-rule-card="who"]');
        if (whoBox) {
            whoBox.addEventListener('pf:audience-change', function (event) {
                whoCount = event.detail && event.detail.conditions ? event.detail.conditions.length : 0;
                summarize();
            });
        }

        // ── When ──
        var whenBox = root.querySelector('[data-pf-rule-when]');
        var drawWhen = function () {
            whenBox.textContent = '';
            var t = state.trigger;
            var parts = [w.whenWord || 'When', select(['event', 'segment_entered', 'segment_left', 'schedule', 'once'].map(function (k) { return [k, w[k] || k]; }), t.type, function (v) {
                state.trigger = { type: v };
                if (v === 'event') { state.trigger.event = ''; }
                drawWhen(); save();
            }, { 'data-pf-rule-trigger': true })];
            if (t.type === 'event') {
                parts.push(eventField(t.event, function (v) { t.event = v; save(); }, { 'data-pf-rule-event': true }));
            } else if (t.type === 'segment_entered' || t.type === 'segment_left') {
                parts.push(select([['', w.choose || '…']].concat((catalog.segments || []).map(function (s) { return [s.id, s.name]; })), t.segment || '', function (v) {
                    t.segment = Number(v) || null; save();
                }, { 'data-pf-rule-segment': true }));
            } else if (t.type === 'schedule') {
                parts.push(input('text', t.cron || '', function (v) { t.cron = v; save(); }, { placeholder: '0 10 * * 1', 'data-pf-rule-cron': true, 'aria-label': w.cron || 'Cron expression' }));
            }
            whenBox.appendChild(sentence(parts));
            whenBox.appendChild(sentence([w.entry || 'Takes a person in',
                select([['first', w.first || 'the first time only'], ['every', w.every || 'every time']], state.entry, function (v) { state.entry = v; save(); }, { 'data-pf-rule-entry': true })]));
        };

        // ── What: the steps ──
        var stepsBox = root.querySelector('[data-pf-rule-steps]');
        var KINDS = [
            { kind: 'message', icon: 'send', label: w.message || 'Message', hint: w.messageHint || '' },
            { kind: 'action', icon: 'action', label: w.action || 'Action', hint: w.actionHint || '' },
            { kind: 'wait', icon: 'clock', label: w.wait || 'Wait', hint: w.waitHint || '' },
            { kind: 'wait_event', icon: 'rule', label: w.wait_event || 'Wait for an event', hint: w.waitEventHint || '' },
            { kind: 'branch', icon: 'branch', label: w.branch || 'If', hint: w.branchHint || '' },
            { kind: 'split', icon: 'split', label: w.split || 'A/B split', hint: w.splitHint || '' },
        ];
        var firstAction = function (channelled) {
            return (catalog.actions || []).filter(function (a) { return Boolean(a.channel) === channelled; })[0] || (catalog.actions || [])[0] || null;
        };
        var fresh = function (kind, next) {
            if (kind === 'message' || kind === 'action') {
                var a = firstAction(kind === 'message');
                return { type: 'action', action: a ? a.key : '', params: {}, next: next };
            }
            return kind === 'wait' ? { type: kind, seconds: 86400, next: next }
                : kind === 'wait_event' ? { type: kind, event: '', seconds: 86400, next: next, timeout: null }
                : kind === 'branch' ? { type: kind, filter: { match: 'all', conditions: [] }, yes: next, no: null }
                : { type: kind, variants: [{ key: 'A', share: 50, next: next }, { key: 'B', share: 50, next: null }] };
        };
        var addStep = function (kind) {
            var n = ids().length + 1;
            while (state.steps['s' + n]) { n++; }
            var id = 's' + n;
            // The new step follows the last one that ends.
            var last = ids().filter(function (other) { var s = state.steps[other]; return s.type !== 'branch' && s.type !== 'split' && s.next === null; }).pop();
            state.steps[id] = fresh(kind, null);
            if (last && last !== id) { state.steps[last].next = id; }
            if (!state.start) { state.start = id; }
            drawSteps(); save();
        };
        var addTile = function () {
            var wrap = el('div', { 'class': 'pf-menu' });
            var tile = el('button', { type: 'button', 'class': 'pf-rule-add-tile', 'aria-haspopup': 'menu', 'aria-expanded': 'false', 'data-pf-rule-add': true },
                [icon('plus'), w.addStep || 'Add a step']);
            var pop = el('div', { 'class': 'pf-menu-pop', role: 'menu' });
            pop.hidden = true;
            var close = function () { pop.hidden = true; tile.setAttribute('aria-expanded', 'false'); document.removeEventListener('mousedown', outside, true); };
            var outside = function (event) { if (!wrap.contains(event.target)) { close(); } };
            KINDS.forEach(function (k) {
                if ((k.kind === 'message' || k.kind === 'action') && !(catalog.actions || []).length) { return; }
                var item = el('button', { type: 'button', 'class': 'pf-menu-item', role: 'menuitem', 'data-pf-rule-kind': k.kind },
                    [el('span', { 'class': 'pf-rule-icon pf-rule-step-' + k.kind }, [icon(k.icon)]), el('span', {}, [el('b', {}, [k.label]), k.hint ? el('small', {}, [k.hint]) : null])]);
                item.addEventListener('click', function () { close(); addStep(k.kind); });
                pop.appendChild(item);
            });
            tile.addEventListener('click', function () {
                if (pop.hidden) {
                    pop.hidden = false;
                    tile.setAttribute('aria-expanded', 'true');
                    document.addEventListener('mousedown', outside, true);
                } else { close(); }
            });
            wrap.appendChild(tile);
            wrap.appendChild(pop);
            return wrap;
        };
        var drawSteps = function () {
            stepsBox.textContent = '';
            var flow = el('div', { 'class': 'pf-rule-flow' });
            if (ids().length > 1) {
                stepsBox.appendChild(sentence([w.start || 'Starts at',
                    select(ids().map(function (id) { return [id, title(id)]; }), state.start || ids()[0], function (v) { state.start = v; save(); }, { 'data-pf-rule-start': true })]));
            }
            ids().forEach(function (id, i) {
                if (i > 0) { flow.appendChild(el('div', { 'class': 'pf-rule-link' })); }
                flow.appendChild(drawStep(id));
            });
            if (ids().length) { flow.appendChild(el('div', { 'class': 'pf-rule-link' })); }
            flow.appendChild(addTile());
            stepsBox.appendChild(flow);
        };

        var drawStep = function (id) {
            var step = state.steps[id];
            var kind = kindOf(step);
            var meta = KINDS.filter(function (k) { return k.kind === kind; })[0] || KINDS[0];
            var box = el('div', { 'class': 'pf-rule-step pf-rule-step-' + kind, 'data-pf-rule-step': id });
            var remove = el('button', { type: 'button', 'class': 'pf-rule-remove', 'aria-label': w.remove || 'Remove', title: w.remove || 'Remove', 'data-pf-rule-remove': true }, [icon('close')]);
            remove.addEventListener('click', function () {
                delete state.steps[id];
                // Whatever led here now ends.
                Object.keys(state.steps).forEach(function (other) {
                    var s = state.steps[other];
                    ['next', 'timeout', 'yes', 'no'].forEach(function (k) { if (s[k] === id) { s[k] = null; } });
                    (s.variants || []).forEach(function (v) { if (v.next === id) { v.next = null; } });
                });
                if (state.start === id) { state.start = ids()[0] || null; }
                drawSteps(); save();
            });
            box.appendChild(el('div', { 'class': 'pf-rule-step-head', 'data-pf-rule-type': step.type }, [
                el('span', { 'class': 'pf-rule-icon' }, [icon(meta.icon)]),
                el('span', { 'class': 'pf-rule-step-title' }, [meta.label]),
                el('span', { 'class': 'pf-rule-step-id' }, [id]),
                remove,
            ]));
            box.appendChild(drawBody(id, step, kind));
            return box;
        };

        var drawBody = function (id, step, kind) {
            var body = el('div', { 'class': 'pf-rule-body' });
            var then = function (label, key, attrs) {
                body.appendChild(sentence([label, target(step[key], function (v) { step[key] = v; save(); }, attrs)]));
            };
            if (step.type === 'action') {
                var offered = (catalog.actions || []).filter(function (a) { return Boolean(a.channel) === (kind === 'message'); });
                if (!offered.some(function (a) { return a.key === step.action; })) { offered = catalog.actions || []; }
                body.appendChild(sentence([kind === 'message' ? (w.send || 'Send') : (w.doThis || 'Do'),
                    select(offered.map(function (a) { return [a.key, a.label]; }), step.action, function (v) {
                        step.action = v; step.params = {}; drawSteps(); save();
                    }, { 'data-pf-rule-action': true })]));
                var params = el('div', { 'class': 'pf-grid-2' });
                body.appendChild(params);
                ((actions[step.action] || {}).params || []).forEach(function (p) {
                    var set = function (v) { step.params[p.name] = v; save(); };
                    var current = step.params[p.name];
                    var field = p.type === 'textarea'
                        ? (function () { var t = el('textarea', { 'class': 'pf-rule-input pf-textarea', rows: '3', 'data-pf-rule-param': p.name }); t.value = current || ''; t.addEventListener('input', function () { set(t.value); }); return t; })()
                        : p.type === 'select' ? select([['', w.choose || '…']].concat(p.options.map(function (o) { return [o.value, o.label]; })), current || '', set, { 'data-pf-rule-param': p.name })
                        : p.type === 'group' ? select([['', w.choose || '…']].concat((catalog.groups || []).map(function (g) { return [g.id, g.name]; })), current || '', function (v) { set(Number(v) || ''); }, { 'data-pf-rule-param': p.name })
                        : p.type === 'attribute' ? select([['', w.choose || '…']].concat((catalog.attributes || []).map(function (a) { return [a.key, a.label]; })), current || '', set, { 'data-pf-rule-param': p.name })
                        : input(p.type === 'number' ? 'number' : 'text', current, set, { 'data-pf-rule-param': p.name });
                    params.appendChild(el('label', { 'class': 'pf-field' }, [el('span', { 'class': 'pf-label' }, [p.label]), field]));
                });
                then(w.next || 'Then', 'next', { 'data-pf-rule-next': 'next' });
            } else if (step.type === 'wait') {
                body.appendChild(sentence([w.waitFor || 'Wait for'].concat(duration(w, step.seconds, function (s) { step.seconds = s; save(); }))));
                then(w.next || 'Then', 'next', { 'data-pf-rule-next': 'next' });
            } else if (step.type === 'wait_event') {
                body.appendChild(sentence([w.waitUntil || 'Wait until', eventField(step.event, function (v) { step.event = v; save(); }, { 'data-pf-rule-param': 'event' }),
                    w.forUpTo || 'for up to'].concat(duration(w, step.seconds, function (s) { step.seconds = s; save(); }))));
                then(w.happened || 'It happened', 'next', { 'data-pf-rule-next': 'next' });
                then(w.timeout || 'Time ran out', 'timeout', { 'data-pf-rule-next': 'timeout' });
            } else if (step.type === 'branch') {
                var mode = step.message ? 'message' : 'filter';
                body.appendChild(sentence([w.branch || 'If', select([['filter', w.onFilter || 'on conditions'], ['message', w.onMessage || 'on a message']], mode, function (v) {
                    if (v === 'message') { delete step.filter; step.message = { step: '', status: 'opened' }; } else { delete step.message; step.filter = { match: 'all', conditions: [] }; }
                    drawSteps(); save();
                }, { 'data-pf-rule-mode': true })]));
                if (step.message) {
                    var actionIds = ids().filter(function (other) { return state.steps[other].type === 'action'; });
                    body.appendChild(sentence([w.theMessageOf || 'the message of',
                        select([['', w.choose || '…']].concat(actionIds.map(function (a) { return [a, title(a)]; })), step.message.step, function (v) { step.message.step = v; save(); }, { 'data-pf-rule-message': true }),
                        select(['opened', 'clicked', 'delivered'].map(function (k) { return [k, w[k] || k]; }), step.message.status, function (v) { step.message.status = v; save(); }, { 'data-pf-rule-status': true })]));
                } else {
                    body.appendChild(drawFilter(step));
                }
                then(w.yes || 'Yes', 'yes', { 'data-pf-rule-next': 'yes' });
                then(w.no || 'No', 'no', { 'data-pf-rule-next': 'no' });
            } else {
                step.variants.forEach(function (variant, i) {
                    var key = input('text', variant.key, function (v) { variant.key = v; save(); }, { 'aria-label': w.variant || 'Variant', size: '4' });
                    key.className += ' pf-input-short';
                    var share = input('number', variant.share, function (v) { variant.share = v; save(); }, { 'aria-label': w.share || 'Share %', min: '1', max: '99' });
                    share.className += ' pf-input-short';
                    body.appendChild(el('div', { 'class': 'pf-rule-variant', 'data-pf-rule-variant': i }, [
                        el('span', { 'class': 'pf-rule-word' }, [w.variant || 'Variant']), key, share, el('span', { 'class': 'pf-rule-word' }, ['% →']),
                        target(variant.next, function (v) { variant.next = v; save(); }, { 'data-pf-rule-next': 'variant' }),
                    ]));
                });
                var more = el('button', { type: 'button', 'class': 'pf-rule-add ' + classes.button, 'data-pf-rule-add-variant': true }, [icon('plus'), w.addVariant || 'Variant']);
                more.addEventListener('click', function () {
                    step.variants.push({ key: String.fromCharCode(65 + step.variants.length), share: 0, next: null });
                    drawSteps(); save();
                });
                body.appendChild(el('div', {}, [more]));
            }
            return body;
        };

        /** A branch's conditions: an audience builder of its own, started here. */
        var drawFilter = function (step) {
            var holder = el('div', {
                'class': 'pf-audience pf-audience-filter', 'data-pf-audience': 'filter', 'data-delay': '400',
                'data-catalog': JSON.stringify({ attributes: catalog.attributes || [], operators: catalog.operators || {}, segments: catalog.segments || [], events: catalog.events || [] }),
                'data-words': root.getAttribute('data-audience-words') || '{}',
                'data-classes': root.getAttribute('data-classes') || '{}',
            });
            var field = el('input', { type: 'hidden', 'data-pf-audience-state': true });
            field.value = JSON.stringify(step.filter || { match: 'all', conditions: [] });
            holder.appendChild(field);
            holder.appendChild(el('div', { 'data-pf-audience-area': true }));
            holder.addEventListener('pf:audience-change', function (event) {
                if (event.stopPropagation) { event.stopPropagation(); }
                step.filter = event.detail; save();
            });
            if (window.pfAudience) { window.pfAudience.start(holder); }
            return holder;
        };

        // ── Until ──
        var untilBox = root.querySelector('[data-pf-rule-until]');
        var drawUntil = function () {
            untilBox.textContent = '';
            var goal = state.goal || null;
            var days = input('number', goal ? goal.days : 7, function (v) { if (state.goal) { state.goal.days = v; save(); } }, { min: '1', 'data-pf-rule-goal-days': true, 'aria-label': w.days || 'days' });
            days.className += ' pf-input-short';
            untilBox.appendChild(sentence([el('span', { 'class': 'pf-label' }, [w.goal || 'Goal']),
                eventField(goal ? goal.event : '', function (v) { state.goal = v === '' ? null : { event: v, days: (state.goal || {}).days || 7 }; save(); }, { 'data-pf-rule-goal': true }),
                w.within || 'within', days, w.days || 'days']));
            untilBox.appendChild(sentence([el('span', { 'class': 'pf-label' }, [w.consent || 'Consent list']),
                select([['', w.none || '—']].concat((catalog.consent || []).map(function (c) { return [c.id, c.name]; })), state.consent || '', function (v) {
                    state.consent = Number(v) || null; save();
                }, { 'data-pf-rule-consent': true })]));
            var hours = state.hours || { from: '', to: '' };
            var setHours = function () { state.hours = hours.from && hours.to ? { from: hours.from, to: hours.to } : null; save(); };
            untilBox.appendChild(sentence([el('span', { 'class': 'pf-label' }, [w.sendHours || 'Sending hours']), w.from || 'from',
                clock(hours.from, function (v) { if (v !== null) { hours.from = v; setHours(); } }, { 'data-pf-rule-from': true, 'aria-label': w.from || 'from' }),
                w.to || 'to',
                clock(hours.to, function (v) { if (v !== null) { hours.to = v; setHours(); } }, { 'data-pf-rule-to': true, 'aria-label': w.to || 'to' })]));

            var caps = state.caps && !Array.isArray(state.caps) ? state.caps : {};
            state.caps = caps;
            var channels = [];
            (catalog.actions || []).forEach(function (a) { if (a.channel && channels.indexOf(a.channel) === -1) { channels.push(a.channel); } });
            Object.keys(caps).forEach(function (channel) {
                var cap = caps[channel];
                var max = input('number', cap.max, function (v) { cap.max = v; save(); }, { min: '1', 'aria-label': w.caps || 'At most' });
                max.className += ' pf-input-short';
                var per = input('number', cap.days, function (v) { cap.days = v; save(); }, { min: '1', 'aria-label': w.days || 'days' });
                per.className += ' pf-input-short';
                var which = select(channels.filter(function (c) { return c === channel || !caps[c]; }).map(function (c) { return [c, c]; }), channel, function (v) {
                    delete caps[channel]; caps[v] = cap; drawUntil(); save();
                }, { 'aria-label': w.channel || 'Channel' });
                var x = el('button', { type: 'button', 'class': 'pf-rule-remove', 'aria-label': w.remove || 'Remove', title: w.remove || 'Remove' }, [icon('close')]);
                x.addEventListener('click', function () { delete caps[channel]; drawUntil(); save(); });
                untilBox.appendChild(sentence([el('span', { 'class': 'pf-label' }, [w.limit || 'Limit']), w.caps || 'At most', max, which, w.per || 'per', per, w.days || 'days', x],
                    { 'data-pf-rule-cap': channel }));
            });
            var free = channels.filter(function (c) { return !caps[c]; });
            if (free.length) {
                var add = el('button', { type: 'button', 'class': 'pf-rule-add ' + classes.button, 'data-pf-rule-add-cap': true }, [icon('plus'), w.addCap || 'Limit']);
                add.addEventListener('click', function () { caps[free[0]] = { max: 1, days: 1 }; drawUntil(); save(); });
                untilBox.appendChild(el('div', {}, [add]));
            }
        };

        drawWhen();
        drawSteps();
        drawUntil();
        save();
    }

    function startAll() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-pf-rule-editor]'), start);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startAll);
    } else {
        startAll();
    }
    window.pfRules = { start: start };
})();
