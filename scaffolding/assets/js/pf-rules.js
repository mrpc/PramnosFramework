/**
 * pf-rules.js — a rule's editor, from the markup `Pramnos\Html\RuleEditor` writes.
 *
 * Edits when (the trigger and whether a person may come in more than once), the steps — each a
 * box with what it does and where it leads — and until when (goal, consent list, sending hours,
 * frequency caps). Who and the exit are the audience builder's, beside it. Every change writes
 * the state back to the hidden `definition` field as JSON; the server checks it again when saved.
 *
 * A branch on conditions holds its own audience builder (`pf-audience.js`), started here.
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

    /** The theme's classes for the controls, from the editor's markup. */
    var classes = { button: '', input: '', select: '' };

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

    /** Seconds as an amount and a unit, both edited. */
    function duration(w, seconds, onChange) {
        var unit = seconds && seconds % 86400 === 0 ? 86400 : (seconds && seconds % 3600 === 0 ? 3600 : 60);
        var amount = seconds ? seconds / unit : '';
        var amountField = input('number', amount, function (v) { amount = v; onChange(amount === '' ? 0 : amount * unit); }, { min: '1', 'data-pf-rule-amount': true });
        var unitField = select([[60, w.minutes || 'minutes'], [3600, w.hours || 'hours'], [86400, w.days || 'days']], unit, function (v) {
            unit = Number(v); onChange(amount === '' ? 0 : amount * unit);
        }, { 'data-pf-rule-unit': true });
        return el('span', { 'class': 'pf-rule-inline' }, [amountField, ' ', unitField]);
    }

    function start(root) {
        if (root.getAttribute('data-pf-rule-ready') !== null) { return; }
        root.setAttribute('data-pf-rule-ready', '');
        var catalog = json(root.getAttribute('data-catalog'), {});
        classes = Object.assign({ button: '', input: '', select: '' }, json(root.getAttribute('data-classes'), {}));
        var w = json(root.getAttribute('data-words'), {});
        var hidden = root.querySelector('[data-pf-rule-state]');
        var state = json(hidden && hidden.value, {}) || {};
        state.trigger = state.trigger || { type: 'event', event: '' };
        state.entry = state.entry || 'first';
        state.steps = state.steps && !Array.isArray(state.steps) ? state.steps : {};
        var actions = {};
        (catalog.actions || []).forEach(function (a) { actions[a.key] = a; });

        var save = function () {
            if (hidden) { hidden.value = JSON.stringify(state); }
            root.dispatchEvent(new window.CustomEvent('pf:rule-change', { detail: state, bubbles: true }));
        };
        var ids = function () { return Object.keys(state.steps); };
        var target = function (current, onChange, attrs) {
            return select([['', w.end || 'End']].concat(ids().map(function (id) { return [id, id]; })), current || '', function (v) {
                onChange(v === '' ? null : v);
            }, attrs);
        };

        // ── When ──
        var whenBox = root.querySelector('[data-pf-rule-when]');
        var drawWhen = function () {
            whenBox.textContent = '';
            var t = state.trigger;
            whenBox.appendChild(select(['event', 'segment_entered', 'segment_left', 'schedule', 'once'].map(function (k) { return [k, w[k] || k]; }), t.type, function (v) {
                state.trigger = { type: v };
                if (v === 'event') { state.trigger.event = ''; }
                drawWhen(); save();
            }, { 'data-pf-rule-trigger': true }));
            if (t.type === 'event') {
                var list = el('datalist', { id: 'pf-rule-events' }, (catalog.events || []).map(function (e) { return el('option', { value: e.key }, [e.label]); }));
                whenBox.appendChild(list);
                whenBox.appendChild(input('text', t.event, function (v) { t.event = v; save(); }, { list: 'pf-rule-events', placeholder: w.eventName || 'Event', 'data-pf-rule-event': true }));
            } else if (t.type === 'segment_entered' || t.type === 'segment_left') {
                whenBox.appendChild(select([['', w.choose || '…']].concat((catalog.segments || []).map(function (s) { return [s.id, s.name]; })), t.segment || '', function (v) {
                    t.segment = Number(v) || null; save();
                }, { 'data-pf-rule-segment': true }));
            } else if (t.type === 'schedule') {
                whenBox.appendChild(input('text', t.cron || '', function (v) { t.cron = v; save(); }, { placeholder: '0 10 * * 1', 'data-pf-rule-cron': true }));
            }
            whenBox.appendChild(el('label', { 'class': 'pf-rule-inline' }, [(w.entry || 'Takes a person in') + ' ',
                select([['first', w.first || 'first'], ['every', w.every || 'every']], state.entry, function (v) { state.entry = v; save(); }, { 'data-pf-rule-entry': true })]));
        };

        // ── Steps ──
        var stepsBox = root.querySelector('[data-pf-rule-steps]');
        var drawSteps = function () {
            stepsBox.textContent = '';
            if (ids().length) {
                stepsBox.appendChild(el('label', { 'class': 'pf-rule-inline' }, [(w.start || 'Starts at') + ' ',
                    select(ids().map(function (id) { return [id, id]; }), state.start || ids()[0], function (v) { state.start = v; save(); }, { 'data-pf-rule-start': true })]));
            }
            ids().forEach(function (id) { stepsBox.appendChild(drawStep(id)); });
            var add = el('button', { type: 'button', 'class': 'pf-rule-add ' + classes.button, 'data-pf-rule-add': true }, [w.addStep || '+ step']);
            add.addEventListener('click', function () {
                var n = ids().length + 1;
                while (state.steps['s' + n]) { n++; }
                var id = 's' + n;
                var first = (catalog.actions || [])[0];
                state.steps[id] = { type: 'action', action: first ? first.key : '', params: {}, next: null };
                if (!state.start) { state.start = id; }
                drawSteps(); save();
            });
            stepsBox.appendChild(add);
        };

        var drawStep = function (id) {
            var step = state.steps[id];
            var box = el('div', { 'class': 'pf-rule-box pf-rule-' + step.type, 'data-pf-rule-step': id });
            var remove = el('button', { type: 'button', 'class': 'pf-rule-remove', 'aria-label': w.remove || 'Remove', 'data-pf-rule-remove': true }, ['×']);
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
            box.appendChild(el('div', { 'class': 'pf-rule-head' }, [el('b', {}, [id]), ' ',
                select(['action', 'wait', 'wait_event', 'branch', 'split'].map(function (k) { return [k, w[k] || k]; }), step.type, function (v) {
                    var next = step.next || null;
                    state.steps[id] = v === 'action' ? { type: v, action: ((catalog.actions || [])[0] || {}).key || '', params: {}, next: next }
                        : v === 'wait' ? { type: v, seconds: 86400, next: next }
                        : v === 'wait_event' ? { type: v, event: '', seconds: 86400, next: next, timeout: null }
                        : v === 'branch' ? { type: v, filter: { match: 'all', conditions: [] }, yes: next, no: null }
                        : { type: v, variants: [{ key: 'A', share: 50, next: next }, { key: 'B', share: 50, next: null }] };
                    drawSteps(); save();
                }, { 'data-pf-rule-type': true }), remove]));
            box.appendChild(drawBody(id, step));
            return box;
        };

        var drawBody = function (id, step) {
            var body = el('div', { 'class': 'pf-rule-body' });
            var row = function (label, field) { body.appendChild(el('label', { 'class': 'pf-rule-row' }, [el('span', { 'class': 'pf-rule-label' }, [label]), ' ', field])); };
            if (step.type === 'action') {
                row(w.action || 'Do', select((catalog.actions || []).map(function (a) { return [a.key, a.label]; }), step.action, function (v) {
                    step.action = v; step.params = {}; drawSteps(); save();
                }, { 'data-pf-rule-action': true }));
                ((actions[step.action] || {}).params || []).forEach(function (p) {
                    var set = function (v) { step.params[p.name] = v; save(); };
                    var current = step.params[p.name];
                    var field = p.type === 'textarea'
                        ? (function () { var t = el('textarea', { 'class': 'pf-rule-input ' + classes.input, rows: '3', 'data-pf-rule-param': p.name }); t.value = current || ''; t.addEventListener('input', function () { set(t.value); }); return t; })()
                        : p.type === 'select' ? select([['', w.choose || '…']].concat(p.options.map(function (o) { return [o.value, o.label]; })), current || '', set, { 'data-pf-rule-param': p.name })
                        : p.type === 'group' ? select([['', w.choose || '…']].concat((catalog.groups || []).map(function (g) { return [g.id, g.name]; })), current || '', function (v) { set(Number(v) || ''); }, { 'data-pf-rule-param': p.name })
                        : p.type === 'attribute' ? select([['', w.choose || '…']].concat((catalog.attributes || []).map(function (a) { return [a.key, a.label]; })), current || '', set, { 'data-pf-rule-param': p.name })
                        : input(p.type === 'number' ? 'number' : 'text', current, set, { 'data-pf-rule-param': p.name });
                    row(p.label, field);
                });
                row(w.next || 'Then', target(step.next, function (v) { step.next = v; save(); }, { 'data-pf-rule-next': 'next' }));
            } else if (step.type === 'wait') {
                row(w.wait || 'Wait', duration(w, step.seconds, function (s) { step.seconds = s; save(); }));
                row(w.next || 'Then', target(step.next, function (v) { step.next = v; save(); }, { 'data-pf-rule-next': 'next' }));
            } else if (step.type === 'wait_event') {
                row(w.eventName || 'Event', input('text', step.event, function (v) { step.event = v; save(); }, { list: 'pf-rule-events', 'data-pf-rule-param': 'event' }));
                row(w.wait || 'Wait', duration(w, step.seconds, function (s) { step.seconds = s; save(); }));
                row(w.happened || 'It happened', target(step.next, function (v) { step.next = v; save(); }, { 'data-pf-rule-next': 'next' }));
                row(w.timeout || 'Time ran out', target(step.timeout, function (v) { step.timeout = v; save(); }, { 'data-pf-rule-next': 'timeout' }));
            } else if (step.type === 'branch') {
                var mode = step.message ? 'message' : 'filter';
                row(w.branch || 'If', select([['filter', w.onFilter || 'on conditions'], ['message', w.onMessage || 'on a message']], mode, function (v) {
                    if (v === 'message') { delete step.filter; step.message = { step: '', status: 'opened' }; } else { delete step.message; step.filter = { match: 'all', conditions: [] }; }
                    drawSteps(); save();
                }, { 'data-pf-rule-mode': true }));
                if (step.message) {
                    var actionIds = ids().filter(function (other) { return state.steps[other].type === 'action'; });
                    row(w.action || 'Message', select([['', w.choose || '…']].concat(actionIds.map(function (a) { return [a, a]; })), step.message.step, function (v) { step.message.step = v; save(); }, { 'data-pf-rule-message': true }));
                    row('', select(['opened', 'clicked', 'delivered'].map(function (k) { return [k, w[k] || k]; }), step.message.status, function (v) { step.message.status = v; save(); }, { 'data-pf-rule-status': true }));
                } else {
                    body.appendChild(drawFilter(step));
                }
                row(w.yes || 'Yes', target(step.yes, function (v) { step.yes = v; save(); }, { 'data-pf-rule-next': 'yes' }));
                row(w.no || 'No', target(step.no, function (v) { step.no = v; save(); }, { 'data-pf-rule-next': 'no' }));
            } else {
                step.variants.forEach(function (variant, i) {
                    var line = el('div', { 'class': 'pf-rule-variant', 'data-pf-rule-variant': i }, [
                        input('text', variant.key, function (v) { variant.key = v; save(); }, { 'aria-label': w.variant || 'Variant', size: '4' }), ' ',
                        input('number', variant.share, function (v) { variant.share = v; save(); }, { 'aria-label': w.share || 'Share %', min: '1', max: '99' }), '% → ',
                        target(variant.next, function (v) { variant.next = v; save(); }, { 'data-pf-rule-next': 'variant' }),
                    ]);
                    body.appendChild(line);
                });
                var more = el('button', { type: 'button', 'class': 'pf-rule-add ' + classes.button, 'data-pf-rule-add-variant': true }, [w.addVariant || '+ variant']);
                more.addEventListener('click', function () {
                    step.variants.push({ key: String.fromCharCode(65 + step.variants.length), share: 0, next: null });
                    drawSteps(); save();
                });
                body.appendChild(more);
            }
            return body;
        };

        /** A branch's conditions: an audience builder of its own, started here. */
        var drawFilter = function (step) {
            var holder = el('div', {
                'class': 'pf-audience pf-audience-filter', 'data-pf-audience': 'filter', 'data-delay': '400',
                'data-catalog': JSON.stringify({ attributes: catalog.attributes || [], operators: catalog.operators || {}, segments: catalog.segments || [], events: catalog.events || [] }),
                'data-words': root.getAttribute('data-audience-words') || '{}',
            });
            var field = el('input', { type: 'hidden', 'data-pf-audience-state': true });
            field.value = JSON.stringify(step.filter || { match: 'all', conditions: [] });
            holder.appendChild(field);
            holder.appendChild(el('div', { 'data-pf-audience-area': true }));
            holder.addEventListener('pf:audience-change', function (event) { step.filter = event.detail; save(); });
            if (window.pfAudience) { window.pfAudience.start(holder); }
            return holder;
        };

        // ── Until ──
        var untilBox = root.querySelector('[data-pf-rule-until]');
        var drawUntil = function () {
            untilBox.textContent = '';
            var goal = state.goal || null;
            untilBox.appendChild(el('label', { 'class': 'pf-rule-row' }, [el('span', { 'class': 'pf-rule-label' }, [w.goal || 'Goal']), ' ',
                input('text', goal ? goal.event : '', function (v) {
                    state.goal = v === '' ? null : { event: v, days: (state.goal || {}).days || 7 }; save();
                }, { list: 'pf-rule-events', 'data-pf-rule-goal': true }), ' ',
                input('number', goal ? goal.days : '', function (v) { if (state.goal) { state.goal.days = v; save(); } }, { min: '1', 'data-pf-rule-goal-days': true })]));
            untilBox.appendChild(el('label', { 'class': 'pf-rule-row' }, [el('span', { 'class': 'pf-rule-label' }, [w.consent || 'Consent list']), ' ',
                select([['', w.none || '—']].concat((catalog.consent || []).map(function (c) { return [c.id, c.name]; })), state.consent || '', function (v) {
                    state.consent = Number(v) || null; save();
                }, { 'data-pf-rule-consent': true })]));
            var hours = state.hours || { from: '', to: '' };
            var setHours = function () { state.hours = hours.from && hours.to ? { from: hours.from, to: hours.to } : null; save(); };
            untilBox.appendChild(el('label', { 'class': 'pf-rule-row' }, [el('span', { 'class': 'pf-rule-label' }, [w.sendHours || 'Sending hours']), ' ',
                input('time', hours.from, function (v) { hours.from = v; setHours(); }, { 'data-pf-rule-from': true }), ' ' + (w.to || 'to') + ' ',
                input('time', hours.to, function (v) { hours.to = v; setHours(); }, { 'data-pf-rule-to': true })]));
            var caps = state.caps && !Array.isArray(state.caps) ? state.caps : {};
            state.caps = caps;
            Object.keys(caps).forEach(function (channel) {
                var cap = caps[channel];
                var line = el('div', { 'class': 'pf-rule-row', 'data-pf-rule-cap': channel }, [(w.caps || 'At most') + ' ',
                    input('number', cap.max, function (v) { cap.max = v; save(); }, { min: '1' }), ' ' + channel + ' ' + (w.messages || 'messages in') + ' ',
                    input('number', cap.days, function (v) { cap.days = v; save(); }, { min: '1' }), ' ' + (w.days || 'days')]);
                var x = el('button', { type: 'button', 'class': 'pf-rule-remove', 'aria-label': w.remove || 'Remove' }, ['×']);
                x.addEventListener('click', function () { delete caps[channel]; drawUntil(); save(); });
                line.appendChild(x);
                untilBox.appendChild(line);
            });
            var channels = [];
            (catalog.actions || []).forEach(function (a) { if (a.channel && channels.indexOf(a.channel) === -1 && !caps[a.channel]) { channels.push(a.channel); } });
            if (channels.length) {
                untilBox.appendChild(select([['', w.addCap || '+ limit']].concat(channels.map(function (c) { return [c, c]; })), '', function (v) {
                    if (v) { caps[v] = { max: 1, days: 1 }; drawUntil(); save(); }
                }, { 'data-pf-rule-add-cap': true }));
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
