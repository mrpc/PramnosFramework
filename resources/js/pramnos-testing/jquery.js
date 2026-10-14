/**
 * A jQuery-shaped stub, for testing legacy page code without jQuery itself.
 *
 * `$(selector)`, `$(element)`, `$(fn)` (run now), and on a set: `on`/`off`/`trigger`, `val`,
 * `text`, `html`, `attr`, `data`, `prop`, `addClass`/`removeClass`/`toggleClass`/`hasClass`,
 * `show`/`hide`, `find`, `closest`, `parent`, `each`, `first`, `length`. `$.ajax`, `$.get`,
 * `$.post` and `$.getJSON` go through the window's `fetch` (a `stubFetch()`), returning a
 * promise with `done`/`fail`/`always`. Not jQuery: no animations, no selector engine beyond the
 * DOM's, no deferred chains past the first.
 */
'use strict';

/**
 * Install `$` and `jQuery` on the window.
 *
 * @param {object} window From `createDom()`
 * @returns {Function} `$`
 */
function installJquery(window) {
    const document = window.document;

    function wrap(elements) {
        const set = Object.create(proto);
        elements.forEach((el, i) => { set[i] = el; });
        set.length = elements.length;
        set.elements = elements;
        return set;
    }

    const proto = {
        each(fn) { this.elements.forEach((el, i) => fn.call(el, i, el)); return this; },
        first() { return wrap(this.elements.slice(0, 1)); },
        get(i) { return this.elements[i]; },
        find(selector) { return wrap(this.elements.flatMap((el) => el.querySelectorAll(selector))); },
        closest(selector) { return wrap(this.elements.map((el) => el.closest(selector)).filter(Boolean)); },
        parent() { return wrap(this.elements.map((el) => el.parentElement).filter(Boolean)); },
        on(type, selectorOrHandler, maybeHandler) {
            const delegated = typeof selectorOrHandler === 'string';
            const handler = delegated ? maybeHandler : selectorOrHandler;
            this.elements.forEach((el) => {
                const listener = (event) => {
                    const target = delegated ? (event.target.closest ? event.target.closest(selectorOrHandler) : null) : el;
                    if (!target) {
                        return;
                    }
                    if (handler.call(target, event) === false) {
                        event.preventDefault();
                        event.stopPropagation();
                    }
                };
                (el._jq ||= []).push({ type, handler, listener });
                el.addEventListener(type, listener);
            });
            return this;
        },
        off(type) {
            this.elements.forEach((el) => {
                (el._jq || []).filter((one) => !type || one.type === type)
                    .forEach((one) => el.removeEventListener(one.type, one.listener));
                el._jq = (el._jq || []).filter((one) => type && one.type !== type);
            });
            return this;
        },
        trigger(type) {
            this.elements.forEach((el) => el.dispatchEvent(new window.Event(type, { bubbles: true })));
            return this;
        },
        click(handler) { return handler ? this.on('click', handler) : this.trigger('click'); },
        val(value) {
            if (value === undefined) { return this.elements[0] ? this.elements[0].value : undefined; }
            this.elements.forEach((el) => { el.value = String(value); });
            return this;
        },
        text(value) {
            if (value === undefined) { return this.elements.map((el) => el.textContent).join(''); }
            this.elements.forEach((el) => { el.textContent = value; });
            return this;
        },
        html(value) {
            if (value === undefined) { return this.elements[0] ? this.elements[0].innerHTML : undefined; }
            this.elements.forEach((el) => { el.innerHTML = String(value); });
            return this;
        },
        attr(name, value) {
            if (value === undefined) { return this.elements[0] ? this.elements[0].getAttribute(name) : undefined; }
            this.elements.forEach((el) => el.setAttribute(name, value));
            return this;
        },
        removeAttr(name) { this.elements.forEach((el) => el.removeAttribute(name)); return this; },
        prop(name, value) {
            if (value === undefined) { return this.elements[0] ? this.elements[0][name] : undefined; }
            this.elements.forEach((el) => { el[name] = value; });
            return this;
        },
        data(key, value) {
            if (value === undefined) { return this.elements[0] ? this.elements[0].dataset[key] : undefined; }
            this.elements.forEach((el) => { el.dataset[key] = value; });
            return this;
        },
        addClass(name) { this.elements.forEach((el) => el.classList.add(...name.split(/\s+/))); return this; },
        removeClass(name) { this.elements.forEach((el) => el.classList.remove(...name.split(/\s+/))); return this; },
        toggleClass(name, force) { this.elements.forEach((el) => el.classList.toggle(name, force)); return this; },
        hasClass(name) { return this.elements.some((el) => el.classList.contains(name)); },
        show() { this.elements.forEach((el) => { el.hidden = false; el.style.display = ''; }); return this; },
        hide() { this.elements.forEach((el) => { el.style.display = 'none'; }); return this; },
        is(selector) { return this.elements.some((el) => el.matches(selector)); },
    };

    function $(input) {
        if (typeof input === 'function') {
            input($);
            return wrap([]);
        }
        if (typeof input === 'string') {
            return wrap(document.querySelectorAll(input));
        }
        if (input && input.elements) {
            return input;
        }
        return wrap(input ? [input] : []);
    }

    function deferred(promise) {
        promise.done = (fn) => { promise.then(fn, () => {}); return promise; };
        promise.fail = (fn) => { promise.then(null, fn); return promise; };
        promise.always = (fn) => { promise.then(fn, fn); return promise; };
        return promise;
    }

    $.ajax = (options) => {
        const method = String(options.type || options.method || 'GET').toUpperCase();
        let url = options.url;
        let body;
        if (options.data !== undefined) {
            if (method === 'GET') {
                url += (url.includes('?') ? '&' : '?') + new URLSearchParams(options.data).toString();
            } else {
                body = typeof options.data === 'string' ? options.data : new URLSearchParams(options.data).toString();
            }
        }
        const promise = window.fetch(url, { method, headers: options.headers || {}, body })
            .then(async (res) => {
                const data = options.dataType === 'text' ? await res.text() : await res.json().catch(() => res.text());
                if (!res.ok) {
                    const error = Object.assign(new Error('HTTP ' + res.status), { status: res.status, responseJSON: data });
                    if (options.error) { options.error(error, 'error', String(res.status)); }
                    throw error;
                }
                if (options.success) { options.success(data, 'success', res); }
                return data;
            });
        promise.catch(() => {});
        return deferred(promise);
    };
    $.get = (url, data, success) => $.ajax({ url, data: typeof data === 'function' ? undefined : data, success: typeof data === 'function' ? data : success });
    $.getJSON = $.get;
    $.post = (url, data, success) => $.ajax({ url, type: 'POST', data, success });
    $.fn = proto;

    window.$ = $;
    window.jQuery = $;

    return $;
}

module.exports = { installJquery };
