/**
 * pf-audience.js — the audience builder and picker, from the markup `Pramnos\Html\AudienceBuilder`
 * writes.
 *
 * `data-pf-audience="filter"`: conditions over people's attributes in groups of "all" / "any",
 * the live count, a breakdown and a sample beside them. `data-pf-audience="picker"`: a send's
 * audience — in any of these segments and groups, except these, a filter for this send alone, and
 * a consent list — with the numbers each step takes out.
 *
 * The state is kept in the hidden input as JSON, which is what the form posts; the server checks
 * it again, so nothing here is trusted. A condition still missing its value is left out of the
 * JSON until it has one, so the count never answers an error for a row being typed.
 *
 * Fires `pf:audience-change` on the element, with the state as detail, after every change.
 */
(function () {
    'use strict';

    var NO_VALUE = ['empty', 'not_empty', 'true', 'false'];
    var MANY_VALUES = ['one_of', 'not_one_of', 'any_of', 'all_of', 'none_of'];

    /** An element with attributes and children. */
    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (name) {
            if (attrs[name] !== null && attrs[name] !== undefined && attrs[name] !== false) {
                node.setAttribute(name, attrs[name] === true ? '' : String(attrs[name]));
            }
        });
        (children || []).forEach(function (child) {
            if (child === null || child === undefined) { return; }
            node.appendChild(typeof child === 'string' ? text(child) : child);
        });
        return node;
    }

    /** A text node, or a span where the document cannot make one. */
    function text(value) {
        if (document.createTextNode) { return document.createTextNode(String(value)); }
        var span = document.createElement('span');
        span.textContent = String(value);
        return span;
    }

    /** `%s` filled in order. */
    function say(words, key) {
        var args = Array.prototype.slice.call(arguments, 2);
        var i = 0;
        return String(words[key] || key).replace(/%s/g, function () { return String(args[i++]); });
    }

    function json(value, fallback) {
        try { return value ? JSON.parse(value) : fallback; } catch (e) { return fallback; }
    }

    /** A number in the page's language, with its grouping. */
    function number(n) {
        try { return Number(n).toLocaleString(document.documentElement && document.documentElement.lang || undefined); } catch (e) { return String(n); }
    }

    /** The session's token, for the POSTs. */
    function token() {
        var meta = document.querySelector('meta[name="csrf"]') || document.querySelector('meta[name="csrf-token"]');
        return meta ? (meta.content || meta.getAttribute && meta.getAttribute('content') || '') : '';
    }

    /** POST form fields to the API; resolves with the JSON, or `{error}`. */
    function post(url, fields) {
        var body = Object.keys(fields).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(fields[k]);
        }).join('&');
        return window.fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': token(), 'Accept': 'application/json' },
            body: body,
        }).then(function (response) {
            return response.json().then(function (data) {
                return response.ok ? data : { error: (data && data.error) || null };
            });
        }).catch(function () { return { error: null }; });
    }

    /** Whether a condition is complete enough to send. */
    function complete(condition, attribute) {
        if (condition.conditions) { return true; }
        if (condition.segment !== undefined) { return Number(condition.segment) > 0; }
        if (!attribute || !condition.op) { return false; }
        if (NO_VALUE.indexOf(condition.op) !== -1) { return true; }
        var v = condition.value;
        if (condition.op === 'between') { return Array.isArray(v) && v.length === 2 && v[0] !== '' && v[1] !== ''; }
        if (MANY_VALUES.indexOf(condition.op) !== -1) { return Array.isArray(v) && v.length > 0; }
        return v !== undefined && v !== null && String(v) !== '';
    }

    /** The tree as it is sent: incomplete conditions and empty groups left out. */
    function clean(group, byKey) {
        var conditions = [];
        group.conditions.forEach(function (c) {
            if (c.conditions) {
                var inner = clean(c, byKey);
                if (inner.conditions.length) { conditions.push(inner); }
            } else if (complete(c, byKey[c.attribute])) {
                var out = {};
                Object.keys(c).forEach(function (k) { out[k] = c[k]; });
                if (NO_VALUE.indexOf(c.op) !== -1) { delete out.value; }
                conditions.push(out);
            }
        });
        return { match: group.match === 'any' ? 'any' : 'all', conditions: conditions };
    }

    /**
     * The condition rows of one group, and its own buttons. `onChange(structural)` redraws when
     * the shape changed, and only re-sends when a value did.
     */
    function drawGroup(ctx, group, depth, onChange) {
        var w = ctx.words;
        var box = el('div', { 'class': 'pf-audience-group', 'data-depth': depth });
        var match = el('select', { 'class': 'pf-audience-match ' + ctx.classes.select, 'aria-label': w.matchLabel || 'match' }, [
            el('option', { value: 'all', selected: group.match !== 'any' }, [w.all || 'all']),
            el('option', { value: 'any', selected: group.match === 'any' }, [w.any || 'any']),
        ]);
        match.value = group.match === 'any' ? 'any' : 'all';
        match.addEventListener('change', function () { group.match = match.value; onChange(false); });
        box.appendChild(el('div', { 'class': 'pf-audience-heading' }, [w.matchBefore || '', match, w.matchAfter || '']));

        group.conditions.forEach(function (condition, index) {
            var remove = el('button', { type: 'button', 'class': 'pf-audience-remove ' + ctx.classes.button, 'aria-label': w.remove || 'Remove', 'data-pf-audience-remove': true }, ['×']);
            remove.addEventListener('click', function () { group.conditions.splice(index, 1); onChange(true); });
            if (index > 0) {
                box.appendChild(el('div', { 'class': 'pf-audience-join' }, [group.match === 'any' ? (w.or || 'or') : (w.and || 'and')]));
            }
            if (condition.conditions) {
                var nested = drawGroup(ctx, condition, depth + 1, onChange);
                nested.appendChild(remove);
                box.appendChild(nested);
            } else {
                var row = drawCondition(ctx, condition, onChange);
                row.appendChild(remove);
                box.appendChild(row);
            }
        });

        var add = el('button', { type: 'button', 'class': 'pf-audience-add ' + ctx.classes.button, 'data-pf-audience-add': true }, [w.addCondition || '+ condition']);
        add.addEventListener('click', function () { group.conditions.push({ attribute: '', op: '' }); onChange(true); });
        var actions = el('div', { 'class': 'pf-audience-actions' }, [add]);
        if (depth < 2) {
            var addGroup = el('button', { type: 'button', 'class': 'pf-audience-add ' + ctx.classes.button, 'data-pf-audience-add-group': true }, [w.addGroup || '+ group']);
            addGroup.addEventListener('click', function () {
                group.conditions.push({ match: group.match === 'any' ? 'all' : 'any', conditions: [{ attribute: '', op: '' }] });
                onChange(true);
            });
            actions.appendChild(addGroup);
        }
        box.appendChild(actions);
        return box;
    }

    /** One condition: what, how, and the value its type and operator take. */
    function drawCondition(ctx, condition, onChange) {
        var w = ctx.words;
        var row = el('div', { 'class': 'pf-audience-condition', 'data-pf-audience-condition': true });
        var isSegment = condition.segment !== undefined;

        // What: the attributes under their headings, and "segment" when there are segments.
        var what = el('select', { 'class': 'pf-audience-attribute ' + ctx.classes.select, 'aria-label': w.attribute || 'attribute', 'data-pf-audience-attribute': true },
            [el('option', { value: '' }, [w.choose || '…'])]);
        var headings = {};
        ctx.catalog.attributes.forEach(function (a) {
            var heading = a.group || '';
            if (!headings[heading]) {
                headings[heading] = heading ? el('optgroup', { label: heading }) : what;
                if (heading) { what.appendChild(headings[heading]); }
            }
            headings[heading].appendChild(el('option', { value: a.key, selected: !isSegment && condition.attribute === a.key }, [a.label]));
        });
        if (ctx.catalog.segments.length) {
            what.appendChild(el('option', { value: '__segment', selected: isSegment }, [w.segment || 'Segment']));
        }
        what.value = isSegment ? '__segment' : (condition.attribute || '');
        what.addEventListener('change', function () {
            Object.keys(condition).forEach(function (k) { delete condition[k]; });
            if (what.value === '__segment') {
                condition.segment = 0;
                condition.op = 'in';
            } else {
                var a = ctx.byKey[what.value];
                condition.attribute = what.value;
                condition.op = a ? a.operators[0] : '';
            }
            onChange(true);
        });
        row.appendChild(what);

        var attribute = isSegment ? null : ctx.byKey[condition.attribute];
        if (!isSegment && !attribute) { return row; }

        // How: the operators the type has.
        var ops = isSegment ? ['in', 'not_in'] : attribute.operators;
        var how = el('select', { 'class': 'pf-audience-op ' + ctx.classes.select, 'aria-label': w.operator || 'operator', 'data-pf-audience-op': true },
            ops.map(function (op) { return el('option', { value: op, selected: condition.op === op }, [ctx.catalog.operators[op] || op]); }));
        how.value = condition.op;
        how.addEventListener('change', function () {
            var many = MANY_VALUES.indexOf(how.value) !== -1;
            var wasMany = MANY_VALUES.indexOf(condition.op) !== -1;
            // A value of another shape — one, a list, a pair — does not carry over.
            if (many !== wasMany || how.value === 'between' || condition.op === 'between') {
                delete condition.value;
            }
            condition.op = how.value;
            onChange(true);
        });
        row.appendChild(how);

        if (isSegment) {
            var which = el('select', { 'class': 'pf-audience-value ' + ctx.classes.select, 'data-pf-audience-value': true },
                [el('option', { value: '' }, [w.choose || '…'])].concat(ctx.catalog.segments.map(function (s) {
                    return el('option', { value: s.id, selected: Number(condition.segment) === s.id }, [s.name]);
                })));
            which.value = condition.segment ? String(condition.segment) : '';
            which.addEventListener('change', function () { condition.segment = Number(which.value) || 0; onChange(false); });
            row.appendChild(which);
            return row;
        }
        if (NO_VALUE.indexOf(condition.op) !== -1) { return row; }
        row.appendChild(drawValue(ctx, condition, attribute, onChange));
        return row;
    }

    /** The value: one field, two, a number of days, or a choice of the attribute's values. */
    function drawValue(ctx, condition, attribute, onChange) {
        var w = ctx.words;
        var type = attribute.type;
        var cast = function (v) { return (type === 'number' || type === 'list' || type === 'many') && v !== '' && !isNaN(Number(v)) ? Number(v) : v; };
        var input = function (value, set, kind) {
            var field = el('input', { type: kind, 'class': 'pf-audience-input ' + ctx.classes.input, 'data-pf-audience-value': true, value: value === undefined ? '' : value });
            field.value = value === undefined ? '' : String(value);
            field.addEventListener('input', function () { set(field.value); onChange(false); });
            field.addEventListener('change', function () { set(field.value); onChange(false); });
            return field;
        };
        var kind = type === 'number' ? 'number' : (type === 'date' ? 'date' : 'text');

        if (condition.op === 'more_than_days_ago' || condition.op === 'less_than_days_ago') {
            return el('span', { 'class': 'pf-audience-days' }, [
                input(condition.value, function (v) { condition.value = v === '' ? '' : Number(v); }, 'number'), ' ', w.days || 'days',
            ]);
        }
        if (condition.op === 'between') {
            var pair = Array.isArray(condition.value) ? condition.value : ['', ''];
            condition.value = pair;
            return el('span', { 'class': 'pf-audience-between' }, [
                input(pair[0], function (v) { pair[0] = cast(v); }, kind), ' ', w.and || 'and', ' ',
                input(pair[1], function (v) { pair[1] = cast(v); }, kind),
            ]);
        }
        var values = attribute.values || [];
        if (MANY_VALUES.indexOf(condition.op) !== -1 && values.length) {
            var chosen = Array.isArray(condition.value) ? condition.value : [];
            condition.value = chosen;
            return el('span', { 'class': 'pf-audience-choices', role: 'group' }, values.map(function (v) {
                var box = el('input', { type: 'checkbox', value: v.value, checked: chosen.some(function (c) { return String(c) === String(v.value); }), 'data-pf-audience-choice': true });
                box.checked = chosen.some(function (c) { return String(c) === String(v.value); });
                box.addEventListener('change', function () {
                    var at = chosen.findIndex(function (c) { return String(c) === String(v.value); });
                    if (box.checked && at === -1) { chosen.push(cast(String(v.value))); }
                    if (!box.checked && at !== -1) { chosen.splice(at, 1); }
                    onChange(false);
                });
                return el('label', { 'class': 'pf-audience-choice' }, [box, ' ', v.label]);
            }));
        }
        if (MANY_VALUES.indexOf(condition.op) !== -1) {
            // No list of values: written, separated by commas.
            return input((condition.value || []).join(', '), function (v) {
                condition.value = v.split(',').map(function (s) { return s.trim(); }).filter(Boolean).map(cast);
            }, 'text');
        }
        if (values.length) {
            var pick = el('select', { 'class': 'pf-audience-value ' + ctx.classes.select, 'data-pf-audience-value': true },
                [el('option', { value: '' }, [w.choose || '…'])].concat(values.map(function (v) {
                    return el('option', { value: v.value, selected: String(condition.value) === String(v.value) }, [v.label]);
                })));
            pick.value = condition.value === undefined ? '' : String(condition.value);
            pick.addEventListener('change', function () { condition.value = cast(pick.value); onChange(false); });
            return pick;
        }
        return input(condition.value, function (v) { condition.value = cast(v); }, kind);
    }

    /** The live panel: the count, a breakdown and a sample, or what went wrong. */
    function drawLive(ctx, live, answer, picker) {
        var w = ctx.words;
        live.textContent = '';
        if (answer.error !== undefined) {
            live.appendChild(el('p', { 'class': 'pf-audience-error', role: 'alert' }, [answer.error || w.failed || 'That did not work.']));
            return;
        }
        var total = picker ? answer.final : answer.count;
        live.appendChild(el('div', { 'class': 'pf-audience-count', 'data-pf-audience-count': true }, [number(total)]));
        live.appendChild(el('div', { 'class': 'pf-audience-caption' }, [picker ? (w.reaches || 'will receive it') : (w.people || 'people match now')]));
        if (picker) {
            live.appendChild(el('p', { 'class': 'pf-audience-steps', 'data-pf-audience-steps': true }, [
                say(w, 'picked', number(answer.included)),
                answer.excluded ? ' · ' + say(w, 'excluded', number(answer.excluded)) : '',
                answer.consent ? ' · ' + say(w, 'withoutConsent', number(answer.consent)) : '',
            ]));
        }
        if (answer.breakdown) {
            live.appendChild(el('ul', { 'class': 'pf-audience-breakdown', 'data-pf-audience-breakdown': true }, answer.breakdown.map(function (b) {
                return el('li', {}, [b.label + ' ', el('span', { 'class': 'pf-audience-number' }, [number(b.count)])]);
            })));
        }
        if (answer.sample && answer.sample.length) {
            live.appendChild(el('p', { 'class': 'pf-audience-caption' }, [w.sample || 'For example']));
            live.appendChild(el('ul', { 'class': 'pf-audience-sample', 'data-pf-audience-sample': true }, answer.sample.map(function (p) {
                return el('li', {}, [el('a', { href: p.url }, [p.name])]);
            })));
        }
    }

    /** A filter builder, or the filter inside a picker. */
    function builder(root, ctx, tree, onTree) {
        var holder = el('div', { 'class': 'pf-audience-builder' });
        var draw = function () {
            holder.textContent = '';
            holder.appendChild(drawGroup(ctx, tree, 0, function (structural) {
                if (structural) { draw(); }
                onTree();
            }));
        };
        draw();
        return holder;
    }

    /** Wire one `data-pf-audience` element. */
    function start(root) {
        if (root.getAttribute('data-pf-audience-ready') !== null) { return; }
        root.setAttribute('data-pf-audience-ready', '');
        var mode = root.getAttribute('data-pf-audience') === 'picker' ? 'picker' : 'filter';
        var catalog = json(root.getAttribute('data-catalog'), {});
        catalog.attributes = catalog.attributes || [];
        catalog.segments = catalog.segments || [];
        catalog.groups = catalog.groups || [];
        catalog.consent = catalog.consent || [];
        catalog.operators = catalog.operators || {};
        var ctx = {
            catalog: catalog,
            words: json(root.getAttribute('data-words'), {}),
            classes: Object.assign({ button: '', primary: '', input: '', select: '' }, json(root.getAttribute('data-classes'), {})),
            byKey: {},
        };
        catalog.attributes.forEach(function (a) { ctx.byKey[a.key] = a; });
        var api = (root.getAttribute('data-api') || '').replace(/\/$/, '');
        var sample = root.getAttribute('data-sample') || '5';
        var hidden = root.querySelector('[data-pf-audience-state]');
        var live = root.querySelector('[data-pf-audience-live]');
        var w = ctx.words;
        var state = json(hidden && hidden.value, null);
        var timer = null;
        var asked = 0;
        var breakdownKey = '';

        if (mode === 'filter') {
            state = state && state.conditions ? state : { match: 'all', conditions: [] };
        } else {
            state = state || {};
            state.include = state.include || [];
            state.exclude = state.exclude || [];
            state.filter = state.filter && state.filter.conditions ? state.filter : { match: 'all', conditions: [] };
            state.consent = state.consent || null;
        }

        var sent = function () {
            if (mode === 'filter') { return clean(state, ctx.byKey); }
            return { include: state.include, exclude: state.exclude, filter: clean(state.filter, ctx.byKey), consent: state.consent };
        };

        var ask = function () {
            if (!live || !api) { return; }
            var mine = ++asked;
            var fields = { sample: sample };
            fields[mode] = JSON.stringify(sent());
            if (breakdownKey) { fields.breakdown = breakdownKey; }
            post(api + '/preview', fields).then(function (answer) {
                if (mine !== asked) { return; }   // a newer question is on its way
                drawLive(ctx, live.querySelector('[data-pf-audience-answer]') || live, answer, mode === 'picker');
            });
        };

        var changed = function () {
            if (hidden) { hidden.value = JSON.stringify(sent()); }
            root.dispatchEvent(new window.CustomEvent('pf:audience-change', { detail: sent(), bubbles: true }));
            if (timer) { window.clearTimeout(timer); }
            timer = window.setTimeout(ask, Number(root.getAttribute('data-delay') || 350));
        };

        var area = root.querySelector('[data-pf-audience-area]') || root;
        if (mode === 'filter') {
            area.appendChild(builder(root, ctx, state, changed));
            if (live && root.getAttribute('data-breakdown') !== null) {
                var by = el('select', { 'class': 'pf-audience-by ' + ctx.classes.select, 'aria-label': w.breakdownBy || 'Break down by', 'data-pf-audience-by': true },
                    [el('option', { value: '' }, [w.breakdownBy || 'Break down by…'])].concat(catalog.attributes.filter(function (a) {
                        return a.breakdown;
                    }).map(function (a) { return el('option', { value: a.key }, [a.label]); })));
                by.addEventListener('change', function () { breakdownKey = by.value; ask(); });
                live.appendChild(by);
                live.appendChild(el('div', { 'data-pf-audience-answer': true }));
            }
        } else {
            area.appendChild(drawPicker(ctx, state, changed));
        }
        if (hidden) { hidden.value = JSON.stringify(sent()); }
        ask();
    }

    /** The picker: in these, except these, a filter for this send, a consent list. */
    function drawPicker(ctx, state, changed) {
        var w = ctx.words;
        var box = el('div', { 'class': 'pf-audience-picker' });
        var name = function (pick) {
            var list = pick.segment ? ctx.catalog.segments : ctx.catalog.groups;
            var id = pick.segment || pick.group;
            var found = list.filter(function (x) { return x.id === id; })[0];
            return (found ? found.name : '#' + id);
        };
        var side = function (key, title) {
            var part = el('div', { 'class': 'pf-audience-side', 'data-pf-audience-side': key }, [el('h4', { 'class': 'pf-audience-title' }, [title])]);
            var chips = el('div', { 'class': 'pf-audience-chips' });
            var redraw = function () {
                chips.textContent = '';
                state[key].forEach(function (pick, i) {
                    var x = el('button', { type: 'button', 'class': 'pf-audience-chip-remove', 'aria-label': w.remove || 'Remove' }, ['×']);
                    x.addEventListener('click', function () { state[key].splice(i, 1); redraw(); changed(); });
                    chips.appendChild(el('span', { 'class': 'pf-audience-chip pf-audience-chip-' + (pick.segment ? 'segment' : 'group'), 'data-pf-audience-chip': true },
                        [name(pick), ' ', x]));
                });
            };
            var add = el('select', { 'class': 'pf-audience-pick ' + ctx.classes.select, 'aria-label': w.addPick || 'Add', 'data-pf-audience-pick': key },
                [el('option', { value: '' }, [w.addPick || '+ segment or group'])]);
            if (ctx.catalog.segments.length) {
                add.appendChild(el('optgroup', { label: w.segments || 'Segments' }, ctx.catalog.segments.map(function (s) {
                    return el('option', { value: 's' + s.id }, [s.name]);
                })));
            }
            if (ctx.catalog.groups.length) {
                add.appendChild(el('optgroup', { label: w.groups || 'Groups' }, ctx.catalog.groups.map(function (g) {
                    return el('option', { value: 'g' + g.id }, [g.name]);
                })));
            }
            add.addEventListener('change', function () {
                var v = add.value;
                if (!v) { return; }
                var pick = v.charAt(0) === 's' ? { segment: Number(v.slice(1)) } : { group: Number(v.slice(1)) };
                if (!state[key].some(function (p) { return p.segment === pick.segment && p.group === pick.group; })) {
                    state[key].push(pick);
                }
                add.value = '';
                redraw();
                changed();
            });
            redraw();
            part.appendChild(chips);
            part.appendChild(add);
            return part;
        };
        box.appendChild(side('include', w.include || 'To people in'));
        box.appendChild(side('exclude', w.exclude || 'Except people in'));

        var more = el('details', { 'class': 'pf-audience-more', open: state.filter.conditions.length > 0 },
            [el('summary', {}, [w.filterOnly || '+ a filter for this send only'])]);
        more.appendChild(builder(box, ctx, state.filter, changed));
        box.appendChild(more);

        if (ctx.catalog.consent.length) {
            var consent = el('select', { 'class': 'pf-audience-consent ' + ctx.classes.select, 'data-pf-audience-consent': true },
                [el('option', { value: '' }, [w.noConsent || 'No consent list'])].concat(ctx.catalog.consent.map(function (c) {
                    return el('option', { value: c.id, selected: state.consent === c.id }, [c.name]);
                })));
            consent.value = state.consent ? String(state.consent) : '';
            consent.addEventListener('change', function () { state.consent = Number(consent.value) || null; changed(); });
            box.appendChild(el('label', { 'class': 'pf-audience-consent-label' }, [(w.consentList || 'Consent list') + ' ', consent]));
        }
        return box;
    }

    function startAll() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-pf-audience]'), start);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startAll);
    } else {
        startAll();
    }
    window.pfAudience = { start: start, clean: clean };
})();
