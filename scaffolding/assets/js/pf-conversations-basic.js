/**
 * pf-conversations-basic.js — the conversations' reference page: the reader's conversations with
 * what is unread, one open with its messages (the server's HTML, escaped there), earlier ones on
 * demand, and a box to write in. Live through `pfRealtime` where the page has it; read again every
 * few seconds where it has not. No build step: an example of the JSON API as much as a page.
 *
 * Draws into `[data-pf-conversations-basic]`, whose `data-props` carries `base` (the API's address),
 * `me`, `userChannel` and `strings`; the markup inside is the view's.
 */
(function () {
    'use strict';

    /** The headers every call carries: the page's own through pf-utils.js, else the CSRF meta. */
    function headers(extra) {
        if (typeof window.pfApiHeaders === 'function') {
            return window.pfApiHeaders(extra);
        }
        var meta = document.querySelector('meta[name="csrf"]');
        var out = { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' };
        if (meta) {
            out['X-CSRF-Token'] = meta.getAttribute('content');
        }
        return Object.assign(out, extra || {});
    }

    /** A call to the API: the answer as JSON, or an Error with the server's reason. */
    function call(base, method, path, params, body) {
        var query = params ? Object.keys(params).filter(function (k) { return params[k] !== null && params[k] !== undefined; })
            .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&') : '';
        return fetch(base + path + (query ? '?' + query : ''), {
            method: method,
            credentials: 'same-origin',
            headers: headers(body ? { 'Content-Type': 'application/json' } : null),
            body: body ? JSON.stringify(body) : undefined,
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                if (!response.ok) {
                    throw new Error(data.error || response.statusText || 'Error');
                }
                return data;
            });
        });
    }

    /** Text as HTML text. */
    function escape(text) {
        return String(text).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
    }

    /** A time as hours and minutes — and the date, when it is not today. */
    function when(unix) {
        var d = new Date(unix * 1000);
        var time = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        return d.toDateString() === new Date().toDateString() ? time : d.toLocaleDateString() + ' ' + time;
    }

    /** One page: its state, and what draws it. */
    function start(root) {
        if (root.dataset.pfReady) {
            return;
        }
        root.dataset.pfReady = '1';
        var props = {};
        try {
            props = JSON.parse(root.dataset.props || '{}');
        } catch (e) {
            return;
        }
        var words = props.strings || {};
        var t = function (key) { return words[key] || key; };
        var api = function (method, path, params, body) { return call(props.base, method, path, params, body); };
        var list = root.querySelector('[data-pf-list]');
        var title = root.querySelector('[data-pf-title]');
        var messages = root.querySelector('[data-pf-messages]');
        var earlier = root.querySelector('[data-pf-earlier]');
        var form = root.querySelector('[data-pf-composer]');
        var box = form.querySelector('textarea');
        var error = root.querySelector('[data-pf-error]');
        var baseTitle = document.title;
        var state = { conversations: [], open: null, oldest: 0, newest: 0, seen: {} };
        var channel = null;
        var poll = null;

        /** What the server refused, for a few seconds. */
        function fail(err) {
            error.textContent = err.message || String(err);
            error.hidden = false;
            setTimeout(function () { error.hidden = true; }, 6000);
        }

        /** The list, read again: each conversation with its unread count; the total in the window's title. */
        function loadList() {
            return api('GET', 'conversations').then(function (answer) {
                state.conversations = answer.conversations || [];
                var unread = 0;
                list.innerHTML = state.conversations.map(function (c) {
                    var count = c.muted ? 0 : c.unread;
                    unread += count;
                    return '<li><button type="button" class="pf-btn pf-btn-ghost pf-conv-basic-item' + (c.id === state.open ? ' pf-conv-basic-current' : '')
                        + '" data-id="' + c.id + '"' + (c.id === state.open ? ' aria-current="true"' : '') + '><span>' + escape(c.title || '') + '</span>'
                        + (count > 0 ? ' <span class="pf-badge">' + count + '</span>' : '') + '</button></li>';
                }).join('') || '<li class="pf-conv-basic-empty">' + escape(t('No conversations yet.')) + '</li>';
                document.title = unread > 0 ? '(' + unread + ') ' + baseTitle : baseTitle;
            }).catch(fail);
        }

        /** A message as the page draws it. */
        function draw(m) {
            var body = m.deletedAt !== null ? '<p><em>' + escape(t('This message was deleted.')) + '</em></p>' : m.html;
            return '<article class="pf-conv-basic-msg" data-message="' + m.id + '"><header><strong>' + escape(m.author || (m.card && m.card.from) || t('System message'))
                + '</strong> <time>' + escape(when(m.at)) + '</time></header><div class="pf-conv-msg-body">' + body + '</div></article>';
        }

        /** Messages put in the page — at the foot, or before what is there — each once. */
        function add(items, before) {
            var fresh = items.filter(function (m) { return m.threadRootId === null && !state.seen[m.id]; });
            fresh.forEach(function (m) { state.seen[m.id] = true; });
            if (!fresh.length) {
                return;
            }
            var html = fresh.map(draw).join('');
            if (before) {
                var height = messages.scrollHeight;
                messages.insertAdjacentHTML('afterbegin', html);
                messages.scrollTop += messages.scrollHeight - height;
            } else {
                var atFoot = messages.scrollHeight - messages.scrollTop - messages.clientHeight < 80;
                messages.insertAdjacentHTML('beforeend', html);
                if (atFoot || !before) {
                    messages.scrollTop = messages.scrollHeight;
                }
            }
            fresh.forEach(function (m) {
                state.oldest = state.oldest ? Math.min(state.oldest, m.id) : m.id;
                state.newest = Math.max(state.newest, m.id);
            });
        }

        /** A message that changed — edited or deleted — drawn again where it is. */
        function change(m) {
            var there = messages.querySelector('[data-message="' + m.id + '"]');
            if (there) {
                there.outerHTML = draw(m);
            }
        }

        /** Tell the server the reader has seen up to the newest. */
        function read() {
            if (state.open !== null && state.newest > 0 && !document.hidden) {
                api('POST', 'read/' + state.open, null, { message: state.newest }).then(loadList).catch(function () {});
            }
        }

        /** Open a conversation: its newest messages, heard live or read again every few seconds. */
        function open(id) {
            stop();
            state.open = id;
            state.oldest = state.newest = 0;
            state.seen = {};
            messages.innerHTML = '';
            form.hidden = false;
            if (window.location.hash !== '#/c/' + id) {
                history.replaceState(null, '', '#/c/' + id);
            }
            return api('GET', 'messages/' + id).then(function (answer) {
                var c = answer.conversation;
                title.textContent = c.slug ? '#' + c.slug : (state.conversations.filter(function (x) { return x.id === id; })[0] || {}).title || c.name || '';
                earlier.hidden = answer.messages.length < 50;
                form.hidden = !c.member;
                add(answer.messages, false);
                read();
                listen(id);
                box.focus();
            }).catch(fail);
        }

        /** Earlier messages, before the oldest on the page. */
        function older() {
            api('GET', 'messages/' + state.open, { before: state.oldest }).then(function (answer) {
                earlier.hidden = answer.messages.length < 50;
                add(answer.messages, true);
            }).catch(fail);
        }

        /** What came after the newest on the page: polling, and after a reconnect. */
        function catchUp() {
            if (state.open === null) {
                return;
            }
            api('GET', 'messages/' + state.open, { after: state.newest }).then(function (answer) {
                add(answer.messages, false);
                read();
            }).catch(function () { /* offline: the next try */ });
        }

        /** Hear the open conversation live; without a realtime connection, ask every few seconds. */
        function listen(id) {
            var realtime = window.pfRealtime;
            if (!realtime) {
                poll = setInterval(function () { catchUp(); loadList(); }, 8000);
                return;
            }
            channel = realtime.channel('private-conversation.' + id);
            channel.listen('message.posted', posted).listen('message.edited', change).listen('message.deleted', change);
        }

        function posted(m) {
            if (m.conversationId === state.open) {
                add([m], false);
                read();
            }
        }

        function stop() {
            clearInterval(poll);
            poll = null;
            if (channel && channel.stopListening) {
                channel.stopListening('message.posted', posted);
                channel.stopListening('message.edited', change);
                channel.stopListening('message.deleted', change);
            }
            channel = null;
        }

        list.addEventListener('click', function (event) {
            var button = event.target.closest('[data-id]');
            if (button) {
                open(Number(button.getAttribute('data-id'))).then(loadList);
            }
        });
        earlier.addEventListener('click', older);
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var text = box.value.trim();
            if (text === '' || state.open === null) {
                return;
            }
            box.disabled = true;
            api('POST', 'post/' + state.open, null, { body: text }).then(function (answer) {
                box.value = '';
                add([answer.message], false);
                messages.scrollTop = messages.scrollHeight;
            }).catch(fail).then(function () {
                box.disabled = false;
                box.focus();
            });
        });
        // Enter sends; Shift+Enter is a new line.
        box.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                form.requestSubmit();
            }
        });
        document.addEventListener('visibilitychange', read);
        if (window.pfRealtime && props.userChannel) {
            window.pfRealtime.channel(props.userChannel).listen('conversation.activity', loadList);
        } else {
            setInterval(loadList, 20000);
        }

        var wanted = /^#\/c\/(\d+)/.exec(window.location.hash);
        loadList().then(function () {
            var first = wanted ? Number(wanted[1]) : (state.conversations[0] || {}).id;
            if (first) {
                open(first).then(loadList);
            } else {
                title.textContent = t('Choose a conversation');
            }
        });
    }

    function all() {
        document.querySelectorAll('[data-pf-conversations-basic]').forEach(start);
    }

    window.PfConversationsBasic = { start: start };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', all);
    } else {
        all();
    }
})();
