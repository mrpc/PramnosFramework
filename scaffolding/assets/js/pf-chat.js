/**
 * The conversation page — /chat, and the administrators' Ask at /admin/Ai — asking in place.
 *
 * Without it the page works: the form posts, the question is queued, and the page refreshes
 * itself until the answer is there. With it the question shows at once with a thinking bubble,
 * files can be picked, dropped or pasted, the queued question is followed, and when it is
 * answered the page's own HTML is fetched and swapped in — the answer, the list, what it
 * remembers and the context bar, all drawn by the server, which also escapes the Markdown. One
 * renderer, so this page cannot drift from itself.
 *
 * Attributes handled:
 *   data-pf-chat                 — the page; data-page its address, data-asked where a question
 *                                  is followed; data-say-* and data-waiting-lines its words
 *   data-pf-chat-messages        — the conversation
 *   data-pf-chat-form            — the question form; data-pf-chat-files its file input,
 *                                  data-pf-chat-attach the 📎, data-pf-chat-chips the files held
 *   data-pf-chat-waiting="id"    — a question already waiting when the page was drawn
 *   data-pf-chat-suggestion="…"  — an example question: a click puts it in the box
 *   data-pf-chat-time            — a <time datetime="…Z">, shown in the reader's zone
 *   data-pf-chat-copy="…"        — copies the answer's Markdown, not its HTML; and over each
 *                                  table in an answer, a button copying it as tab-separated
 *                                  rows, which a spreadsheet pastes as cells
 *   data-pf-chart="{…}"          — a chart in an answer (Markdown::toHtml() from a ```chart
 *                                  block): drawn as SVG over the code it holds, which stays for a
 *                                  page without script. `window.PfChat.chart = function (el, spec)`
 *                                  draws with the application's own library instead, returning true
 *   data-question-class          — on the page: the classes of a question's bubble, and
 *   data-question-style            the inline style it falls back on
 *   data-pf-chat-embedded        — on the page: inside another page (the side panel), so the
 *                                  address bar is that page's and is left alone
 *
 * `window.PfChat.init(element)` runs it over a conversation placed later — the side panel's
 * `pf-assist.js` does — and each run ends with a `pf-chat:ready` event on the element.
 *
 * Enter sends, Shift+Enter is a new line. No inline handlers: the framework's CSP blocks them.
 */
(function () {
    'use strict';

    /** What the server reads: documents and pictures, ten at most, 5 MB together. */
    var TYPES = ['.docx', '.odt', '.txt', '.md', '.eml', '.jpg', '.jpeg', '.png', '.gif', '.webp'];
    var FILES = 10;
    var BYTES = 5000000;
    /** Nothing for the first seconds, which most answers take; then a line every few. */
    var QUIET_MS = 8000;
    var PHRASE_MS = 4000;
    var LONG_MS = 60000;

    /** Series colours, in order: told apart, and readable on light and dark. */
    var COLOURS = ['#4e79a7', '#f28e2b', '#e15759', '#59a14f', '#76b7b2', '#edc948', '#b07aa1', '#ff9da7', '#9c755f', '#bab0ac'];
    var SVG = 'http://www.w3.org/2000/svg';
    var W = 640;
    var H = 320;

    function node(tag, attributes, parent) {
        var el = document.createElementNS(SVG, tag);

        Object.keys(attributes).forEach(function (name) { el.setAttribute(name, String(attributes[name])); });
        if (parent) {
            parent.appendChild(el);
        }

        return el;
    }

    /** A shape's tooltip: the label, the series and the value. */
    function tip(shape, text) {
        node('title', {}, shape).textContent = text;
    }

    function figure(value) {
        return typeof value === 'number' && isFinite(value) ? value.toLocaleString() : '';
    }

    /** A round top for an axis: 1, 2 or 5 times a power of ten. */
    function nice(value) {
        if (!(value > 0)) {
            return 1;
        }
        var power = Math.pow(10, Math.floor(Math.log(value) / Math.LN10));
        var n = value / power;

        return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * power;
    }

    function small(attributes) {
        attributes['font-size'] = 11;
        attributes.fill = 'currentColor';

        return attributes;
    }

    /** Bars, horizontal bars, lines and areas: on axes. */
    function axes(svg, spec) {
        var labels = spec.labels;
        var series = spec.series;
        var horizontal = spec.type === 'hbar';
        var lines = spec.type === 'line' || spec.type === 'area';
        var stacked = !!spec.stacked && spec.type !== 'line';
        var values = [0];
        labels.forEach(function (label, at) {
            var sum = 0;
            series.forEach(function (one) {
                var v = Number(one.data[at]) || 0;
                sum += v;
                values.push(stacked ? sum : v);
            });
        });
        var low = typeof spec.min === 'number' ? spec.min : Math.min.apply(null, values);
        // Four steps of a round size, so the figures on the axis are round too.
        var high = low + 4 * nice((Math.max.apply(null, values.concat([low + 1])) - low) / 4);
        var left = horizontal ? 120 : 56;
        var top = spec.y ? 28 : 12;
        var width = W - left - 12;
        var height = H - top - 44;
        var scale = function (v) { return (Math.min(high, Math.max(low, v)) - low) / (high - low); };
        var along = function (at) { return left + (labels.length === 1 ? width / 2 : width * at / (labels.length - 1)); };
        var step = Math.max(1, Math.ceil(labels.length / 12));
        var band = (horizontal ? height : width) / labels.length;

        // The value axis: five lines, and their figures.
        for (var k = 0; k <= 4; k++) {
            var tick = horizontal ? left + width * k / 4 : top + height * (1 - k / 4);
            node('line', horizontal ? { x1: tick, x2: tick, y1: top, y2: top + height } : { x1: left, x2: left + width, y1: tick, y2: tick }, svg)
                .setAttribute('stroke', 'rgba(128,128,128,.3)');
            node('text', small(horizontal
                ? { x: tick, y: top + height + 16, 'text-anchor': 'middle' }
                : { x: left - 6, y: tick + 4, 'text-anchor': 'end' }), svg).textContent = figure(low + (high - low) * k / 4);
        }

        // The categories: at most twelve of their names, shortened.
        labels.forEach(function (label, at) {
            if (at % step !== 0) {
                return;
            }
            var middle = (horizontal ? top : left) + band * (at + 0.5);
            var text = String(label).length > 18 ? String(label).slice(0, 17) + '…' : String(label);
            node('text', small(horizontal
                ? { x: left - 6, y: middle + 4, 'text-anchor': 'end' }
                : { x: lines ? along(at) : middle, y: top + height + 16, 'text-anchor': 'middle' }), svg).textContent = text;
        });
        if (spec.y) {
            node('text', small({ x: 4, y: 12, opacity: 0.7 }), svg).textContent = String(spec.y);
        }
        if (spec.x) {
            node('text', small({ x: left + width, y: H - 6, 'text-anchor': 'end', opacity: 0.7 }), svg).textContent = String(spec.x);
        }

        var below = labels.map(function () { return 0; });

        if (lines) {
            series.forEach(function (one, index) {
                var colour = COLOURS[index % COLOURS.length];
                var points = labels.map(function (label, at) {
                    return [along(at), top + height * (1 - scale((Number(one.data[at]) || 0) + (stacked ? below[at] : 0)))];
                });
                if (spec.type === 'area') {
                    var floor = labels.map(function (label, at) {
                        return [along(at), top + height * (1 - scale(stacked ? below[at] : Math.max(low, 0)))];
                    }).reverse();
                    node('polygon', { points: points.concat(floor).map(function (p) { return p.join(','); }).join(' '), fill: colour, 'fill-opacity': 0.35 }, svg);
                }
                node('polyline', { points: points.map(function (p) { return p.join(','); }).join(' '), fill: 'none', stroke: colour, 'stroke-width': 2 }, svg);
                points.forEach(function (p, at) {
                    tip(node('circle', { cx: p[0], cy: p[1], r: 3.5, fill: colour }, svg), labels[at] + ' · ' + (one.name || '') + ': ' + figure(one.data[at]));
                    below[at] += stacked ? Number(one.data[at]) || 0 : 0;
                });
            });

            return;
        }

        // Bars: side by side, or one on another.
        var thick = stacked ? band * 0.6 : band * 0.8 / series.length;
        labels.forEach(function (label, at) {
            series.forEach(function (one, index) {
                var v = Number(one.data[at]) || 0;
                var from = scale(stacked ? below[at] : Math.max(low, 0));
                var to = scale(stacked ? below[at] + v : v);
                var offset = band * at + (stacked ? band * 0.2 : band * 0.1 + thick * index);
                var bar = horizontal
                    ? { x: left + width * Math.min(from, to), y: top + offset, width: Math.abs(to - from) * width, height: thick }
                    : { x: left + offset, y: top + height * (1 - Math.max(from, to)), width: thick, height: Math.abs(to - from) * height };
                bar.fill = COLOURS[index % COLOURS.length];
                tip(node('rect', bar, svg), label + ' · ' + (one.name || '') + ': ' + figure(v));
                below[at] += v;
            });
        });
    }

    /** Pies and doughnuts: one series, parts of a whole. */
    function pie(svg, spec) {
        var data = spec.series[0].data.map(function (v) { return Math.max(0, Number(v) || 0); });
        var total = data.reduce(function (a, b) { return a + b; }, 0) || 1;
        var cx = W / 2;
        var cy = H / 2;
        var r = H / 2 - 12;
        var inner = spec.type === 'doughnut' ? r * 0.55 : 0;
        var angle = -Math.PI / 2;
        var point = function (radius, a) { return (cx + radius * Math.cos(a)) + ' ' + (cy + radius * Math.sin(a)); };

        data.forEach(function (v, at) {
            var part = v / total;
            var colour = COLOURS[at % COLOURS.length];
            var label = spec.labels[at] + ': ' + figure(v) + ' (' + Math.round(part * 100) + '%)';

            if (part >= 0.9999) {
                // A whole circle: an arc from a point to itself draws nothing.
                tip(node('circle', { cx: cx, cy: cy, r: inner ? (r + inner) / 2 : r / 2, fill: 'none', stroke: colour, 'stroke-width': inner ? r - inner : r }, svg), label);
            } else if (part > 0) {
                var end = angle + part * 2 * Math.PI;
                var large = part > 0.5 ? 1 : 0;
                var d = 'M ' + point(r, angle) + ' A ' + r + ' ' + r + ' 0 ' + large + ' 1 ' + point(r, end)
                    + (inner ? ' L ' + point(inner, end) + ' A ' + inner + ' ' + inner + ' 0 ' + large + ' 0 ' + point(inner, angle) : ' L ' + cx + ' ' + cy) + ' Z';
                tip(node('path', { d: d, fill: colour, stroke: 'Canvas', 'stroke-width': 1 }, svg), label);
                angle = end;
            }
        });
    }

    /** Draw one chart over the code it holds — the application's way, or the built-in SVG. */
    function chart(holder) {
        var spec;

        try {
            spec = JSON.parse(holder.getAttribute('data-pf-chart') || '');
        } catch (e) {
            return;
        }
        if (!spec || !Array.isArray(spec.labels) || !Array.isArray(spec.series) || !spec.series.length) {
            return;
        }
        if (typeof window.PfChat.chart === 'function' && window.PfChat.chart(holder, spec) === true) {
            return;
        }

        holder.textContent = '';
        holder.style.margin = '12px 0 16px';
        [[spec.title, '600', '1'], [spec.subtitle, '400', '0.7']].forEach(function (line) {
            if (line[0]) {
                var div = document.createElement('div');
                div.style.fontWeight = line[1];
                div.style.opacity = line[2];
                div.textContent = String(line[0]);
                holder.appendChild(div);
            }
        });

        var round = spec.type === 'pie' || spec.type === 'doughnut';
        var svg = node('svg', { viewBox: '0 0 ' + W + ' ' + H, width: '100%', role: 'img', 'aria-label': spec.title || '' }, holder);
        svg.style.maxWidth = W + 'px';
        svg.style.height = 'auto';
        (round ? pie : axes)(svg, spec);

        // The key: each series, or each slice of a pie.
        var names = round ? spec.labels : spec.series.map(function (one) { return one.name || ''; });
        var key = document.createElement('div');
        key.style.display = 'flex';
        key.style.flexWrap = 'wrap';
        key.style.gap = '4px 12px';
        key.style.fontSize = '0.85em';
        names.forEach(function (name, at) {
            if (name !== '') {
                var item = document.createElement('span');
                item.textContent = '■ ' + name;
                item.style.color = COLOURS[at % COLOURS.length];
                key.appendChild(item);
            }
        });
        holder.appendChild(key);
    }

    /** Say a conversation is drawn and running, for a page that holds it. */
    function ready(root) {
        if (typeof window.CustomEvent === 'function' && typeof root.dispatchEvent === 'function') {
            root.dispatchEvent(new window.CustomEvent('pf-chat:ready', { bubbles: true }));
        }
    }

    function init(root) {
        var asked = root.getAttribute('data-asked') || '';
        var form = root.querySelector('[data-pf-chat-form]');
        var messages = root.querySelector('[data-pf-chat-messages]');
        var held = [];
        var clock = null;

        function words(name, fallback) {
            return root.getAttribute('data-say-' + name) || fallback;
        }

        function address(conversationId) {
            var base = root.getAttribute('data-page') || asked.replace(/chat\/asked\/?$/, 'chat');

            return base + (conversationId ? (base.indexOf('?') === -1 ? '?' : '&') + 'c=' + conversationId : '');
        }

        /**
         * Show the conversation an answer went into: its page, fetched and swapped in. A page
         * that cannot be fetched or read is opened the ordinary way.
         */
        function open(conversationId) {
            var url = address(conversationId);

            if (typeof window.DOMParser !== 'function') {
                window.location.href = url;

                return;
            }

            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    var fresh = new window.DOMParser().parseFromString(html, 'text/html').querySelector('[data-pf-chat]');

                    if (!fresh) {
                        throw new Error('no conversation in the page');
                    }

                    root.innerHTML = fresh.innerHTML;
                    if (window.history && window.history.replaceState && root.getAttribute('data-pf-chat-embedded') === null) {
                        window.history.replaceState(null, '', url);
                    }
                    init(root);
                })
                .catch(function () { window.location.href = url; });
        }

        /** The line in the thinking bubble, or the reason it stopped. */
        function say(text, isError) {
            stop();
            var line = root.querySelector('[data-pf-chat-waiting]') || document.createElement('p');
            line.textContent = text;
            line.setAttribute('role', isError ? 'alert' : 'status');
            line.className = isError ? 'pf-bad text-error' : 'pf-muted';

            if (!line.parentNode && messages) {
                messages.appendChild(line);
            }

            return line;
        }

        /** Thinking: at once; past a few seconds a changing line; past a minute, a promise. */
        function thinking() {
            var line = say(words('thinking', 'Thinking') + '…', false);
            var lines = [];
            var started = Date.now();
            line.setAttribute('data-pf-chat-waiting', '');
            line.className = 'pf-chat-thinking pf-muted';

            try {
                lines = JSON.parse(root.getAttribute('data-waiting-lines') || '[]');
            } catch (e) {
                lines = [];
            }
            // A fresh order each wait, so the same line does not always open.
            lines = lines.slice().sort(function () { return Math.random() - 0.5; });

            clock = window.setInterval(function () {
                var waited = Date.now() - started;

                if (waited >= LONG_MS) {
                    line.textContent = words('long', 'Still working on a long answer — it will appear here.');
                } else if (waited >= QUIET_MS && lines.length) {
                    line.textContent = lines[Math.floor((waited - QUIET_MS) / PHRASE_MS) % lines.length];
                }
            }, 1000);
        }

        function stop() {
            if (clock !== null) {
                window.clearInterval(clock);
                clock = null;
            }
        }

        /** Follow a queued question until it is answered or fails. */
        function follow(askId) {
            fetch(asked + askId, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (body) {
                    var data = body && body.data;

                    if (!data) {
                        say((body && body.error) || words('failed', 'The AI could not answer.'), true);
                    } else if (data.status === 'answered') {
                        stop();
                        open(data.conversation_id);
                    } else if (data.status === 'failed') {
                        say(data.says || words('failed', 'The AI could not answer.'), true);
                    } else {
                        window.setTimeout(function () { follow(askId); }, 2000);
                    }
                })
                .catch(function () { window.setTimeout(function () { follow(askId); }, 5000); });
        }

        /** "today 22:50", or "13 Oct 2026 22:50", in the reader's zone. */
        function when(at) {
            var time = at.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

            return at.toDateString() === new Date(Date.now()).toDateString()
                ? words('today', 'today') + ' ' + time
                : at.toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' }) + ' ' + time;
        }

        Array.prototype.forEach.call(root.querySelectorAll('[data-pf-chat-time]'), function (stamp) {
            var at = new Date(stamp.getAttribute('datetime') || '');

            if (!isNaN(at.getTime())) {
                stamp.textContent = when(at);
            }
        });

        /** Put text on the clipboard, and say so on the button for a moment. */
        function copy(button, text) {
            var clipboard = window.navigator && window.navigator.clipboard;

            if (!clipboard) {
                return;
            }
            clipboard.writeText(text).then(function () {
                var was = button.textContent;
                button.textContent = '✓ ' + words('copied', 'Copied');
                window.setTimeout(function () { button.textContent = was; }, 2000);
            });
        }

        Array.prototype.forEach.call(root.querySelectorAll('[data-pf-chat-copy]'), function (button) {
            button.addEventListener('click', function () { copy(button, button.getAttribute('data-pf-chat-copy') || ''); });
        });

        // Over each table in an answer: its cells, tab-separated, which a spreadsheet pastes as cells.
        Array.prototype.forEach.call(root.querySelectorAll('.pf-chat-answer table'), function (table) {
            var button = document.createElement('button');
            var holder = table.parentNode;
            button.setAttribute('type', 'button');
            button.className = 'pf-chat-copy-table';
            button.textContent = '⧉ ' + words('copy-table', 'Copy table');
            button.addEventListener('click', function () {
                copy(button, Array.prototype.map.call(table.querySelectorAll('tr'), function (row) {
                    return Array.prototype.map.call(row.querySelectorAll('th, td'), function (cell) {
                        return String(cell.textContent).replace(/[\t\r\n]+/g, ' ').trim();
                    }).join('\t');
                }).join('\n'));
            });
            holder.parentNode.insertBefore(button, holder);
        });

        Array.prototype.forEach.call(root.querySelectorAll('[data-pf-chart]'), chart);

        var waiting = root.querySelector('[data-pf-chat-waiting]');

        if (waiting && waiting.getAttribute('data-pf-chat-waiting')) {
            follow(waiting.getAttribute('data-pf-chat-waiting'));
        }

        if (!form) {
            ready(root);

            return;
        }

        var box = form.querySelector('textarea');

        // An example question fills the box, ready to send or to change first.
        Array.prototype.forEach.call(root.querySelectorAll('[data-pf-chat-suggestion]'), function (example) {
            example.addEventListener('click', function () {
                if (box) {
                    box.value = example.getAttribute('data-pf-chat-suggestion') || '';
                    box.focus();
                }
            });
        });
        var picker = form.querySelector('[data-pf-chat-files]');
        var attach = form.querySelector('[data-pf-chat-attach]');
        var chips = form.querySelector('[data-pf-chat-chips]');

        /** The files held, as chips with ✕. */
        function draw() {
            if (!chips) {
                return;
            }
            chips.textContent = '';
            held.forEach(function (file, at) {
                var chip = document.createElement('span');
                var remove = document.createElement('button');
                chip.className = 'pf-chat-chip';
                chip.textContent = file.name + ' ';
                remove.setAttribute('type', 'button');
                remove.setAttribute('aria-label', words('remove', 'Remove') + ' ' + file.name);
                remove.textContent = '✕';
                remove.addEventListener('click', function () {
                    held.splice(at, 1);
                    draw();
                });
                chip.appendChild(remove);
                chips.appendChild(chip);
            });
        }

        /** The same file under another name: two pasted pictures are both "image.png". */
        function rename(file, name) {
            return typeof File === 'function' ? new File([file], name, { type: file.type }) : { name: name, size: file.size, type: file.type, file: file };
        }

        /** Hold a file, if the server can read it and it fits. */
        function take(file) {
            var name = String(file.name || '');
            var lower = name.toLowerCase();

            if (!TYPES.some(function (type) { return lower.slice(-type.length) === type; })) {
                say(words('unsupported', 'Attach a picture, or a Word (.docx), OpenDocument (.odt), text, Markdown or saved email (.eml) file.'), true);

                return;
            }
            if (held.length >= FILES) {
                say(words('too-many', 'Up to 10 files with one question.'), true);

                return;
            }
            if (held.reduce(function (sum, one) { return sum + (one.size || 0); }, 0) + (file.size || 0) > BYTES) {
                say(words('too-large', 'That is too large — up to 5 MB, all the files together.'), true);

                return;
            }

            var taken = held.filter(function (one) { return one.name === name; }).length;
            held.push(taken === 0 ? file : rename(file, name.replace(/(\.[^.]+)?$/, function (ext) { return '-' + (taken + 1) + ext; })));
            draw();
        }

        if (attach && picker) {
            attach.addEventListener('click', function () { picker.click(); });
        }
        if (picker) {
            picker.addEventListener('change', function () {
                Array.prototype.forEach.call(picker.files || [], take);
                picker.value = '';
            });
        }

        form.addEventListener('dragover', function (event) { event.preventDefault(); });
        form.addEventListener('drop', function (event) {
            event.preventDefault();
            Array.prototype.forEach.call((event.dataTransfer && event.dataTransfer.files) || [], take);
        });

        if (box) {
            box.addEventListener('paste', function (event) {
                var pictures = Array.prototype.filter.call((event.clipboardData && event.clipboardData.files) || [], function (one) {
                    return String(one.type || '').indexOf('image/') === 0;
                });

                if (!pictures.length) {
                    return;
                }
                event.preventDefault();
                pictures.forEach(function (one) {
                    take(/\.(jpe?g|png|gif|webp)$/i.test(one.name || '') ? one : rename(one, 'pasted.' + ((one.type || '').split('/')[1] || 'png')));
                });
            });

            box.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                    event.preventDefault();
                    form.requestSubmit ? form.requestSubmit() : form.submit();
                }
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var button = form.querySelector('button[type="submit"]');
            var question = box ? box.value : '';
            var sent = held.slice();

            if (question.trim() === '' && sent.length === 0) {
                return;
            }
            if (button) {
                button.disabled = true;
            }

            var body = new FormData(form);
            if (typeof body.delete === 'function') {
                body.delete('files[]');
            }
            sent.forEach(function (one) {
                body.append('files[]', one.file || one, one.name);
            });

            // The question shows at once, as the page draws one: "You", now, and the bubble.
            var mine = document.createElement('article');
            var head = document.createElement('header');
            var name = document.createElement('strong');
            var stamp = document.createElement('time');
            var bubble = document.createElement('div');
            mine.className = 'pf-chat-message pf-chat-question';
            mine.setAttribute('data-pf-chat-role', 'user');
            mine.style.display = 'flex';
            mine.style.flexDirection = 'column';
            mine.style.alignItems = 'flex-end';
            mine.style.marginBottom = '16px';
            name.textContent = words('you', 'You');
            stamp.setAttribute('datetime', new Date(Date.now()).toISOString());
            stamp.textContent = ' · ' + when(new Date(Date.now()));
            head.appendChild(name);
            head.appendChild(stamp);
            bubble.className = root.getAttribute('data-question-class') || '';
            // A theme whose classes may not exist (Bootstrap 3 under Bootstrap 5 names) gives a fallback.
            (root.getAttribute('data-question-style') || '').split(';').forEach(function (rule) {
                var at = rule.indexOf(':');

                if (at > 0 && typeof bubble.style.setProperty === 'function') {
                    bubble.style.setProperty(rule.slice(0, at).trim(), rule.slice(at + 1).trim());
                }
            });
            bubble.style.whiteSpace = 'pre-wrap';
            bubble.style.padding = '10px 14px';
            bubble.style.maxWidth = '80%';
            bubble.textContent = question + (sent.length ? '\n📎 ' + sent.map(function (one) { return one.name; }).join(', ') : '');
            mine.appendChild(head);
            mine.appendChild(bubble);
            if (messages) {
                messages.appendChild(mine);
            }
            thinking();

            // The examples were for an empty conversation: gone the moment it is not. Their own
            // display, not `hidden`: the theme lays their row out with an inline `display:flex`,
            // which `[hidden]` loses to.
            var examples = Array.prototype.map.call(root.querySelectorAll('[data-pf-chat-suggestion]'), function (one) { return one.parentNode; });
            examples.forEach(function (holder) { holder.style.display = 'none'; });

            if (box) {
                box.value = '';
            }
            held = [];
            draw();

            /** Back in the box, so a retry is one press. */
            function giveBack(reason) {
                if (mine.parentNode) {
                    mine.parentNode.removeChild(mine);
                }
                if (box) {
                    box.value = question;
                }
                held = sent;
                draw();
                if (button) {
                    button.disabled = false;
                }
                examples.forEach(function (holder) { holder.style.display = ''; });
                say(reason, true);
            }

            fetch(form.action, {
                method: 'POST',
                body: body,
                headers: { Accept: 'application/json' },
                credentials: 'same-origin'
            })
                .then(function (r) { return r.json(); })
                .then(function (answer) {
                    if (answer && answer.data && answer.data.ask_id) {
                        if (button) {
                            button.disabled = false;
                        }
                        follow(answer.data.ask_id);
                    } else if (answer && answer.data && answer.data.conversation_id) {
                        stop();
                        open(answer.data.conversation_id);
                    } else {
                        giveBack((answer && answer.error) || words('failed', 'The AI could not answer.'));
                    }
                })
                .catch(function () {
                    // Sent the ordinary way instead: the page then follows the question itself.
                    stop();
                    form.submit();
                });
        });

        ready(root);
    }

    // Last, once everything above is defined: a `var` is hoisted without its value.
    var page = document.querySelector('[data-pf-chat]');

    window.PfChat = window.PfChat || {};
    window.PfChat.init = init;

    if (page) {
        init(page);
    }
}());
