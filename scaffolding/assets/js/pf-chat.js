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
 *   data-question-class          — on the page: the classes of a question's bubble, and
 *   data-question-style            the inline style it falls back on
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

    var page = document.querySelector('[data-pf-chat]');

    if (page) {
        init(page);
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

            return base + (conversationId ? '?c=' + conversationId : '');
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
                    if (window.history && window.history.replaceState) {
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

        var waiting = root.querySelector('[data-pf-chat-waiting]');

        if (waiting && waiting.getAttribute('data-pf-chat-waiting')) {
            follow(waiting.getAttribute('data-pf-chat-waiting'));
        }

        if (!form) {
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
    }
}());
