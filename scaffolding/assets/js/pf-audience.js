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

    var NO_VALUE = ['empty', 'not_empty', 'true', 'false', 'ever', 'never'];
    var EVENT_OPS = ['ever', 'never', 'at_least', 'at_most', 'last_within_days', 'not_within_days', 'first_within_days',
        'first_before', 'first_after', 'last_before', 'last_after'];
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
        if (condition.event !== undefined) {
            if (!condition.event || EVENT_OPS.indexOf(condition.op) === -1) { return false; }
            return NO_VALUE.indexOf(condition.op) !== -1 || (condition.value !== undefined && condition.value !== null && String(condition.value) !== '');
        }
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
                if (out.days === '' || out.days === null || out.days === 0) { delete out.days; }
                conditions.push(out);
            }
        });
        return { match: group.match === 'any' ? 'any' : 'all', conditions: conditions };
    }

    /** A word between fields, kept apart from its neighbours. */
    function word(value) {
        return el('span', { 'class': 'pf-audience-word' }, [value]);
    }

    /** An inline icon: plus, x, search, chevron. */
    var ICONS = {
        plus: '<path d="M10 4v12M4 10h12"/>',
        x: '<path d="M5 5l10 10M15 5L5 15"/>',
        search: '<circle cx="9" cy="9" r="5.5"/><path d="M13 13l3.5 3.5"/>',
    };
    function icon(name) {
        var span = el('span', { 'class': 'pf-icon-svg', 'aria-hidden': 'true' });
        span.innerHTML = '<svg viewBox="0 0 20 20" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">' + ICONS[name] + '</svg>';
        return span;
    }

    /**
     * A searchable choice under headings: a button that shows the current choice, and a popover
     * with a search field and the options. `groups` is `[{label, options: [{value, label, hint}]}]`;
     * `onChange(value)` is told on a pick. Arrow keys, Enter and Escape work in the search field.
     */
    function combobox(groups, value, onChange, opts) {
        opts = opts || {};
        var wrap = el('div', { 'class': 'pf-combo' + (opts.className ? ' ' + opts.className : '') });
        var all = [];
        groups.forEach(function (g) { g.options.forEach(function (o) { all.push(o); }); });
        var current = all.filter(function (o) { return String(o.value) === String(value); })[0] || null;
        var shown = [];
        var active = -1;
        var label = el('span', { 'class': current ? '' : 'pf-combo-placeholder' }, [current ? current.label : (opts.placeholder || '…')]);
        var button = el('button', Object.assign({ type: 'button', 'class': 'pf-combo-button', 'aria-haspopup': 'listbox', 'aria-expanded': 'false',
            'aria-label': opts.label || null, 'data-value': current ? current.value : '' }, opts.attrs || {}), [label]);
        var search = el('input', { type: 'search', 'class': 'pf-input pf-combo-search', placeholder: opts.searchLabel || 'Search…', 'aria-label': opts.searchLabel || 'Search', 'data-pf-combo-search': true });
        var list = el('ul', { 'class': 'pf-combo-list', role: 'listbox' });
        var pop = el('div', { 'class': 'pf-combo-pop' }, [search, list]);
        pop.hidden = true;
        var setActive = function (i) {
            shown.forEach(function (s, j) { s.node.classList.toggle('is-active', j === i); });
            active = i;
            if (i >= 0 && shown[i].node.scrollIntoView) { shown[i].node.scrollIntoView({ block: 'nearest' }); }
        };
        var draw = function () {
            list.textContent = '';
            shown = [];
            var q = String(search.value || '').trim().toLowerCase();
            groups.forEach(function (g) {
                var matching = g.options.filter(function (o) {
                    return !q || (o.label + ' ' + (o.hint || '') + ' ' + (g.label || '')).toLowerCase().indexOf(q) !== -1;
                });
                if (!matching.length) { return; }
                if (g.label) { list.appendChild(el('li', { 'class': 'pf-combo-group', role: 'presentation' }, [g.label])); }
                matching.forEach(function (o) {
                    var item = el('li', { 'class': 'pf-combo-option', role: 'option', 'data-value': o.value,
                        'aria-selected': current && String(current.value) === String(o.value) ? 'true' : 'false' }, [el('span', {}, [o.label]), o.hint ? el('small', {}, [o.hint]) : null]);
                    item.addEventListener('mousedown', function (event) { if (event.preventDefault) { event.preventDefault(); } pick(o); });
                    item.addEventListener('click', function () { pick(o); });
                    shown.push({ o: o, node: item });
                    list.appendChild(item);
                });
            });
            if (!shown.length) { list.appendChild(el('li', { 'class': 'pf-combo-empty' }, [opts.emptyLabel || 'Nothing found'])); }
            setActive(shown.length ? 0 : -1);
        };
        var outside = function (event) { if (!wrap.contains(event.target)) { close(); } };
        var open = function () {
            pop.hidden = false;
            button.setAttribute('aria-expanded', 'true');
            search.value = '';
            draw();
            search.focus();
            document.addEventListener('mousedown', outside, true);
        };
        var close = function () {
            pop.hidden = true;
            button.setAttribute('aria-expanded', 'false');
            document.removeEventListener('mousedown', outside, true);
        };
        var pick = function (o) {
            if (pop.hidden) { return; }
            current = o;
            label.textContent = o.label;
            label.className = '';
            button.setAttribute('data-value', o.value);
            close();
            onChange(o.value);
        };
        button.addEventListener('click', function () { if (pop.hidden) { open(); } else { close(); } });
        search.addEventListener('input', draw);
        search.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                if (event.preventDefault) { event.preventDefault(); }
                if (shown.length) { setActive((active + (event.key === 'ArrowDown' ? 1 : shown.length - 1)) % shown.length); }
            } else if (event.key === 'Enter') {
                if (event.preventDefault) { event.preventDefault(); }
                if (active >= 0) { pick(shown[active].o); }
            } else if (event.key === 'Escape') {
                close();
                button.focus();
            }
        });
        wrap.appendChild(button);
        wrap.appendChild(pop);
        return wrap;
    }

    /** "All" or "any", as two buttons, the current one pressed. */
    function matchControl(ctx, group, onChange) {
        var w = ctx.words;
        var box = el('span', { 'class': 'pf-segmented pf-audience-match', role: 'group', 'aria-label': w.matchLabel || 'All or any', 'data-pf-audience-match': true });
        [['all', w.all || 'all'], ['any', w.any || 'any']].forEach(function (pair) {
            var on = (group.match === 'any' ? 'any' : 'all') === pair[0];
            var b = el('button', { type: 'button', 'aria-pressed': on ? 'true' : 'false', 'data-value': pair[0] }, [pair[1]]);
            b.addEventListener('click', function () { if (!on) { group.match = pair[0]; onChange(true); } });
            box.appendChild(b);
        });
        return box;
    }

    /**
     * The condition rows of one group, and its own buttons. `onChange(structural)` redraws when
     * the shape changed, and only re-sends when a value did.
     */
    function drawGroup(ctx, group, depth, onChange) {
        var w = ctx.words;
        var box = el('div', { 'class': 'pf-audience-group', 'data-depth': depth });
        box.appendChild(el('div', { 'class': 'pf-audience-heading' }, [w.matchBefore || '', matchControl(ctx, group, onChange), w.matchAfter || '']));

        group.conditions.forEach(function (condition, index) {
            var remove = el('button', { type: 'button', 'class': 'pf-audience-remove ' + ctx.classes.icon, 'aria-label': w.remove || 'Remove', title: w.remove || 'Remove', 'data-pf-audience-remove': true }, [icon('x')]);
            remove.addEventListener('click', function () { group.conditions.splice(index, 1); onChange(true); });
            if (index > 0) {
                // The word between two rows is the switch: all ↔ any.
                var join = el('button', { type: 'button', 'class': 'pf-join', title: w.joinTitle || '', 'data-pf-audience-join': true },
                    [group.match === 'any' ? (w.or || 'or') : (w.and || 'and')]);
                join.addEventListener('click', function () { group.match = group.match === 'any' ? 'all' : 'any'; onChange(true); });
                box.appendChild(join);
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

        var add = el('button', { type: 'button', 'class': 'pf-audience-add ' + ctx.classes.button, 'data-pf-audience-add': true }, [icon('plus'), w.addCondition || 'Condition']);
        add.addEventListener('click', function () { group.conditions.push({ attribute: '', op: '' }); onChange(true); });
        var actions = el('div', { 'class': 'pf-audience-actions' }, [add]);
        if (depth < 2) {
            var addGroup = el('button', { type: 'button', 'class': 'pf-audience-add ' + ctx.classes.button, 'data-pf-audience-add-group': true }, [icon('plus'), w.addGroup || 'Group']);
            addGroup.addEventListener('click', function () {
                group.conditions.push({ match: group.match === 'any' ? 'all' : 'any', conditions: [{ attribute: '', op: '' }] });
                onChange(true);
            });
            actions.appendChild(addGroup);
        }
        box.appendChild(actions);
        return box;
    }

    /** What a condition can be about: the attributes under their headings, what people did, a segment. */
    function whatChoices(ctx) {
        var w = ctx.words;
        var groups = [];
        var byHeading = {};
        ctx.catalog.attributes.forEach(function (a) {
            var heading = a.group || w.attributes || 'Attributes';
            if (!byHeading[heading]) {
                byHeading[heading] = { label: heading, options: [] };
                groups.push(byHeading[heading]);
            }
            byHeading[heading].options.push({ value: a.key, label: a.label });
        });
        var events = {};
        ctx.catalog.events.forEach(function (e) {
            var heading = (w.events || 'What they did') + (e.group ? ' · ' + e.group : '');
            if (!events[heading]) {
                events[heading] = { label: heading, options: [] };
                groups.push(events[heading]);
            }
            events[heading].options.push({ value: '__event:' + e.key, label: e.label });
        });
        if (ctx.catalog.segments.length) {
            groups.push({ label: w.segments || 'Segments', options: [{ value: '__segment', label: w.segment || 'Segment' }] });
        }
        return groups;
    }

    /** One condition: what, how, and the value its type and operator take — on one line. */
    function drawCondition(ctx, condition, onChange) {
        var w = ctx.words;
        var row = el('div', { 'class': 'pf-audience-condition', 'data-pf-audience-condition': true });
        var isSegment = condition.segment !== undefined;
        var isEvent = condition.event !== undefined;
        var current = isSegment ? '__segment' : (isEvent ? '__event:' + condition.event : (condition.attribute || ''));

        row.appendChild(combobox(whatChoices(ctx), current, function (value) {
            Object.keys(condition).forEach(function (k) { delete condition[k]; });
            if (value === '__segment') {
                condition.segment = 0;
                condition.op = 'in';
            } else if (String(value).indexOf('__event:') === 0) {
                condition.event = String(value).slice(8);
                condition.op = 'ever';
            } else {
                var a = ctx.byKey[value];
                condition.attribute = value;
                condition.op = a ? a.operators[0] : '';
            }
            onChange(true);
        }, { placeholder: w.choose || 'Choose…', label: w.attribute || 'What', searchLabel: w.search, emptyLabel: w.nothingFound,
            className: 'pf-audience-attribute', attrs: { 'data-pf-audience-attribute': true } }));

        var attribute = isSegment || isEvent ? null : ctx.byKey[condition.attribute];
        if (!isSegment && !isEvent && !attribute) { return row; }

        // How: the operators the type has.
        var ops = isSegment ? ['in', 'not_in'] : (isEvent ? EVENT_OPS : attribute.operators);
        var how = el('select', { 'class': 'pf-audience-op ' + ctx.classes.select, 'aria-label': w.operator || 'operator', 'data-pf-audience-op': true },
            ops.map(function (op) { return el('option', { value: op, selected: condition.op === op }, [ctx.catalog.operators[op] || op]); }));
        how.value = condition.op;
        how.addEventListener('change', function () {
            var many = MANY_VALUES.indexOf(how.value) !== -1;
            var wasMany = MANY_VALUES.indexOf(condition.op) !== -1;
            // A value of another shape — one, a list, a pair, a count or a day — does not carry over.
            if (isEvent) {
                delete condition.value;
                delete condition.days;
            } else if (many !== wasMany || how.value === 'between' || condition.op === 'between') {
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
        row.appendChild(isEvent ? drawEventValue(ctx, condition, onChange) : drawValue(ctx, condition, attribute, onChange));
        return row;
    }

    /** An event's value: a count with an optional window, a number of days, or a day. */
    function drawEventValue(ctx, condition, onChange) {
        var w = ctx.words;
        var field = function (key, kind, label) {
            var input = el('input', { type: kind, min: kind === 'number' ? '0' : null, 'class': 'pf-audience-input ' + ctx.classes.input,
                'data-pf-audience-value': key, 'aria-label': label, value: condition[key] === undefined ? '' : condition[key] });
            input.value = condition[key] === undefined ? '' : String(condition[key]);
            var set = function () { condition[key] = kind === 'number' ? (input.value === '' ? '' : Number(input.value)) : input.value; onChange(false); };
            input.addEventListener('input', set);
            input.addEventListener('change', set);
            return input;
        };
        if (condition.op === 'at_least' || condition.op === 'at_most') {
            return el('span', { 'class': 'pf-audience-days' }, [
                field('value', 'number', w.times || 'times'), word(w.times || 'times'), word(w.inLast || 'in the last'),
                field('days', 'number', w.days || 'days'), word(w.days || 'days'),
            ]);
        }
        if (/_days$/.test(condition.op)) {
            return el('span', { 'class': 'pf-audience-days' }, [field('value', 'number', w.days || 'days'), word(w.days || 'days')]);
        }
        return field('value', 'date', w.day || 'day');
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
                input(condition.value, function (v) { condition.value = v === '' ? '' : Number(v); }, 'number'), word(w.days || 'days'),
            ]);
        }
        if (condition.op === 'between') {
            var pair = Array.isArray(condition.value) ? condition.value : ['', ''];
            condition.value = pair;
            return el('span', { 'class': 'pf-audience-between' }, [
                input(pair[0], function (v) { pair[0] = cast(v); }, kind), word(w.and || 'and'),
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

    /** A person's initials, for the avatar. */
    function initials(name) {
        var words = String(name || '?').trim().split(/\s+/).filter(Boolean);
        return ((words[0] || '?').charAt(0) + (words.length > 1 ? words[words.length - 1].charAt(0) : '')).toUpperCase();
    }

    /** The live panel: the count, a breakdown as bars, a sample of people, or what went wrong. */
    function drawLive(ctx, live, answer, picker, by) {
        var w = ctx.words;
        live.textContent = '';
        if (answer.error !== undefined) {
            live.appendChild(el('p', { 'class': 'pf-audience-error', role: 'alert' }, [answer.error || w.failed || 'That did not work.']));
            if (by) { live.appendChild(by); }
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
        if (by) { live.appendChild(by); }
        if (answer.breakdown && answer.breakdown.length) {
            var most = Math.max.apply(null, answer.breakdown.map(function (b) { return b.count; }).concat([1]));
            live.appendChild(el('ul', { 'class': 'pf-bars pf-audience-breakdown', 'data-pf-audience-breakdown': true }, answer.breakdown.map(function (b) {
                var bar = el('span', { style: 'width:' + Math.max(2, Math.round(100 * b.count / most)) + '%' });
                return el('li', {}, [el('span', {}, [b.label]), el('span', { 'class': 'pf-num' }, [number(b.count)]), el('span', { 'class': 'pf-bar' }, [bar])]);
            })));
        }
        if (answer.sample && answer.sample.length) {
            live.appendChild(el('p', { 'class': 'pf-label pf-audience-panel-label' }, [w.sample || 'For example']));
            live.appendChild(el('ul', { 'class': 'pf-people pf-audience-sample', 'data-pf-audience-sample': true }, answer.sample.map(function (p) {
                var hue = (Number(p.id) * 47) % 360;
                return el('li', {}, [el(p.url ? 'a' : 'span', { 'class': 'pf-person', href: p.url || null }, [
                    el('span', { 'class': 'pf-avatar', style: '--pf-hue: hsl(' + hue + ' 55% 42%)', 'aria-hidden': 'true' }, [initials(p.name)]),
                    el('span', {}, [el('span', { 'class': 'pf-person-name' }, [p.name]), p.line ? el('span', { 'class': 'pf-person-line' }, [p.line]) : null]),
                ])]);
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
        catalog.events = catalog.events || [];
        var ctx = {
            catalog: catalog,
            words: json(root.getAttribute('data-words'), {}),
            classes: Object.assign({ button: '', primary: '', input: '', select: '', icon: '' }, json(root.getAttribute('data-classes'), {})),
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
        var by = null;

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
                drawLive(ctx, live.querySelector('[data-pf-audience-answer]') || live, answer, mode === 'picker', by);
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
                by = el('select', { 'class': 'pf-audience-by ' + ctx.classes.select, 'aria-label': w.breakdownBy || 'Break down by', 'data-pf-audience-by': true },
                    [el('option', { value: '' }, [w.breakdownBy || 'Break down by…'])].concat(catalog.attributes.filter(function (a) {
                        return a.breakdown;
                    }).map(function (a) { return el('option', { value: a.key }, [a.label]); })));
                by.addEventListener('change', function () { breakdownKey = by.value; ask(); });
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

    /**
     * `data-pf-combobox`: a form's field as the searchable choice — the element holds a hidden input
     * the form posts, and `data-groups` the choices as `[{label, options: [{value, label, hint}]}]`.
     */
    function startCombobox(holder) {
        if (holder.getAttribute('data-pf-combobox-ready') !== null) { return; }
        holder.setAttribute('data-pf-combobox-ready', '');
        var field = holder.querySelector('input');
        if (!field) { return; }
        holder.appendChild(combobox(json(holder.getAttribute('data-groups'), []), field.value, function (value) {
            field.value = value;
            field.dispatchEvent(new window.Event('change', { bubbles: true }));
        }, { placeholder: holder.getAttribute('data-placeholder') || '…', label: holder.getAttribute('data-label') || null,
            searchLabel: holder.getAttribute('data-search') || undefined, emptyLabel: holder.getAttribute('data-empty') || undefined }));
    }

    function startAll() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-pf-audience]'), start);
        Array.prototype.forEach.call(document.querySelectorAll('[data-pf-combobox]'), startCombobox);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startAll);
    } else {
        startAll();
    }
    window.pfAudience = { start: start, clean: clean, combobox: combobox, icon: icon, startCombobox: startCombobox };
})();
