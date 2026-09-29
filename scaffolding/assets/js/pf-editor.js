/**
 * pf-editor.js — a small WYSIWYG editor for the body of a message.
 *
 * Turns every `<textarea data-pf-editor="builtin">` into an editable area with a toolbar —
 * bold, italic, two heading levels, lists, a link, an image by address, clear formatting, and
 * the HTML itself — and keeps the textarea's value in step, so the form posts exactly what it
 * would without JavaScript. Without JavaScript the textarea is simply there.
 *
 * Deliberately small. A message body is mail, and mail wants plain markup: paragraphs, emphasis,
 * lists and links, which every client renders. Pasting inserts **text**, not the pasted page's
 * styles — a word processor's markup is the commonest reason a message looks broken in Outlook.
 * No dependency, no CDN, nothing for a Content-Security-Policy to refuse.
 *
 *   window.PfEditor.enhance(textarea)      // for a textarea added after the page loaded
 *   window.PfEditor.plain(textarea, true)  // the bare textarea, for a channel that is text only
 */
(function () {
    'use strict';

    var BUTTONS = [
        ['bold', 'B', 'Bold'],
        ['italic', 'I', 'Italic'],
        ['h2', 'H2', 'Heading'],
        ['h3', 'H3', 'Subheading'],
        ['ul', '• List', 'Bulleted list'],
        ['ol', '1. List', 'Numbered list'],
        ['link', 'Link', 'Link to an address'],
        ['image', 'Image', 'Image by its address'],
        ['clear', 'Clear', 'Remove formatting'],
        ['source', 'HTML', 'Edit the HTML']
    ];

    function exec(command, value) {
        document.execCommand(command, false, value);
    }

    var editors = new WeakMap();

    function enhance(textarea) {
        if (!textarea || textarea.getAttribute('data-pf-editor-ready') === '1') {
            return;
        }
        textarea.setAttribute('data-pf-editor-ready', '1');

        var wrap = document.createElement('div');
        var bar = document.createElement('div');
        var area = document.createElement('div');
        var source = false;
        var required = textarea.hasAttribute('required');
        var label = textarea.id ? document.querySelector('label[for="' + textarea.id + '"]') : null;

        wrap.className = 'pf-editor';
        bar.className = 'pf-editor-toolbar';
        bar.setAttribute('role', 'toolbar');
        bar.setAttribute('aria-label', 'Formatting');
        bar.style.cssText = 'display:flex;flex-wrap:wrap;gap:4px;margin-bottom:4px';

        area.className = 'pf-editor-area';
        area.contentEditable = 'true';
        area.setAttribute('role', 'textbox');
        area.setAttribute('aria-multiline', 'true');
        area.setAttribute('aria-label', label ? label.textContent.trim() : 'Message');
        area.style.cssText = 'min-height:' + (Number(textarea.getAttribute('rows') || 8) * 1.5) + 'em;'
            + 'border:1px solid #ccc;border-radius:4px;padding:8px;overflow:auto;background:#fff;color:#111';
        area.innerHTML = textarea.value;

        // A hidden `required` field cannot be focused, so the browser would refuse the form
        // with nowhere to point: the check moves to submit, where the area can take the focus.
        if (required) {
            textarea.removeAttribute('required');
        }

        function sync() {
            if (!source) {
                textarea.value = area.innerHTML === '<br>' ? '' : area.innerHTML;
            }
        }

        function run(command) {
            if (source && command !== 'source') {
                return;
            }
            if (command !== 'source') {
                area.focus();
            }
            var url;
            switch (command) {
                case 'h2':
                case 'h3':
                    // The bracketed form: Chrome takes a bare tag name, Firefox and Safari do not.
                    exec('formatBlock', '<' + command + '>');
                    break;
                case 'ul':
                    exec('insertUnorderedList');
                    break;
                case 'ol':
                    exec('insertOrderedList');
                    break;
                case 'link':
                    url = window.prompt('Link address', 'https://');
                    if (url && /^(https?:|mailto:)/i.test(url)) {
                        exec('createLink', url);
                    }
                    break;
                case 'image':
                    url = window.prompt('Image address', 'https://');
                    if (url && /^https:/i.test(url)) {
                        exec('insertImage', url);
                    }
                    break;
                case 'clear':
                    exec('removeFormat');
                    exec('formatBlock', '<p>');
                    break;
                case 'source':
                    if (!source) {
                        sync();
                    } else {
                        area.innerHTML = textarea.value;
                    }
                    source = !source;
                    area.hidden = source;
                    textarea.hidden = !source;
                    Array.prototype.forEach.call(bar.querySelectorAll('button'), function (b) {
                        b.disabled = source && b.getAttribute('data-command') !== 'source';
                    });
                    (source ? textarea : area).focus();
                    break;
                default:
                    exec(command);
            }
            sync();
        }

        BUTTONS.forEach(function (spec) {
            var button = document.createElement('button');
            button.type = 'button';
            button.textContent = spec[1];
            button.title = spec[2];
            button.setAttribute('aria-label', spec[2]);
            button.setAttribute('data-command', spec[0]);
            button.className = 'btn btn-sm btn-outline-secondary';
            button.addEventListener('mousedown', function (event) {
                event.preventDefault(); // keep the selection in the area
            });
            button.addEventListener('click', function () {
                run(spec[0]);
            });
            bar.appendChild(button);
        });

        area.addEventListener('input', sync);
        area.addEventListener('paste', function (event) {
            event.preventDefault();
            var data = event.clipboardData || window.clipboardData;
            exec('insertText', data ? data.getData('text/plain') : '');
        });

        if (textarea.form) {
            textarea.form.addEventListener('submit', function (event) {
                sync();
                if (required && textarea.value.replace(/<[^>]*>|&nbsp;|\s/g, '') === '') {
                    event.preventDefault();
                    (source ? textarea : area).focus();
                }
            });
        }

        textarea.parentNode.insertBefore(wrap, textarea);
        wrap.appendChild(bar);
        wrap.appendChild(area);
        wrap.appendChild(textarea);
        textarea.hidden = true;

        // Text only — push has no markup — shows the bare field and hides the toolbar; back
        // again, the area takes up whatever the field now holds.
        editors.set(textarea, function (on) {
            if (on === source && bar.hidden === on) {
                return;
            }
            if (on) {
                sync();
            } else {
                area.innerHTML = textarea.value;
            }
            source = on;
            bar.hidden = on;
            area.hidden = on;
            textarea.hidden = !on;
        });
    }

    function plain(textarea, on) {
        var toggle = editors.get(textarea);
        if (toggle) {
            toggle(Boolean(on));
        }
    }

    function init() {
        // Enter starts a paragraph rather than a <div>, which is what a mail client expects.
        try {
            exec('defaultParagraphSeparator', 'p');
        } catch (e) {
            // An engine without the command keeps its own separator.
        }
        Array.prototype.forEach.call(document.querySelectorAll('textarea[data-pf-editor="builtin"]'), enhance);
    }

    window.PfEditor = { enhance: enhance, plain: plain };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
