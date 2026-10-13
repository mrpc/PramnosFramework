/**
 * The conversation page (/chat), waiting for an answer in place.
 *
 * Without it the page works: the form posts, the question is queued, and the page refreshes
 * itself until the answer is there. With it the form is sent with fetch, "Thinking…" shows at
 * once, the queued question is followed, and the conversation opens when it is answered.
 *
 * Attributes handled:
 *   data-pf-chat                 — the page; data-asked is the address a question is followed at,
 *                                  data-page the page's own (the conversation opens there)
 *   data-pf-chat-form            — the question form
 *   data-pf-chat-waiting="id"    — a question already waiting when the page was drawn
 *
 * Enter sends, Shift+Enter is a new line. No inline handlers: the framework's CSP blocks them.
 */
(function () {
    'use strict';

    var page = document.querySelector('[data-pf-chat]');

    if (!page) {
        return;
    }

    var asked = page.getAttribute('data-asked') || '';
    var form = page.querySelector('[data-pf-chat-form]');

    /** Open the conversation an answer went into. */
    function open(conversationId) {
        var base = page.getAttribute('data-page') || asked.replace(/chat\/asked\/?$/, 'chat');
        window.location.href = base + (conversationId ? '?c=' + conversationId : '');
    }

    /** Say something in place of the waiting line. */
    function say(text, isError) {
        var line = page.querySelector('[data-pf-chat-waiting]') || document.createElement('p');
        line.textContent = text;
        line.setAttribute('role', isError ? 'alert' : 'status');
        line.className = isError ? 'pf-bad text-error' : 'pf-muted';

        if (!line.parentNode) {
            page.querySelector('.pf-chat-messages').appendChild(line);
        }

        return line;
    }

    /** Follow a queued question until it is answered or fails. */
    function follow(askId) {
        fetch(asked + askId, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (body) {
                var data = body && body.data;

                if (!data) {
                    say((body && body.error) || 'The AI could not answer.', true);
                } else if (data.status === 'answered') {
                    open(data.conversation_id);
                } else if (data.status === 'failed') {
                    say(data.says || 'The AI could not answer.', true);
                } else {
                    window.setTimeout(function () { follow(askId); }, 2000);
                }
            })
            .catch(function () { window.setTimeout(function () { follow(askId); }, 5000); });
    }

    var waiting = page.querySelector('[data-pf-chat-waiting]');

    if (waiting) {
        follow(waiting.getAttribute('data-pf-chat-waiting'));
    }

    if (!form) {
        return;
    }

    var box = form.querySelector('textarea');

    if (box) {
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

        if (button) {
            button.disabled = true;
        }

        var line = say('Thinking…', false);
        line.setAttribute('data-pf-chat-waiting', '');

        if (question && line.parentNode) {
            var mine = document.createElement('div');
            mine.style.whiteSpace = 'pre-wrap';
            mine.textContent = question;
            line.parentNode.insertBefore(mine, line);
        }

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { Accept: 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (body) {
                if (button) {
                    button.disabled = false;
                }

                if (body && body.data && body.data.ask_id) {
                    if (box) {
                        box.value = '';
                    }
                    follow(body.data.ask_id);
                } else if (body && body.data && body.data.conversation_id) {
                    open(body.data.conversation_id);
                } else {
                    say((body && body.error) || 'The AI could not answer.', true);
                }
            })
            .catch(function () {
                // Sent the ordinary way instead: the page then follows the question itself.
                form.submit();
            });
    });
}());
