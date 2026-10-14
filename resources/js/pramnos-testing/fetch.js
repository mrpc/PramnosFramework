/**
 * A `fetch` that answers from a table, and records what it was asked.
 */
'use strict';

/**
 * A response, as much of one as page scripts read.
 *
 * @param {*}      body   An object (JSON) or a string
 * @param {number} status HTTP status
 */
function response(body, status = 200) {
    const text = typeof body === 'string' ? body : JSON.stringify(body);
    return {
        status,
        ok: status >= 200 && status < 300,
        headers: { get: (name) => (String(name).toLowerCase() === 'content-type' ? (typeof body === 'string' ? 'text/html' : 'application/json') : null) },
        json: async () => JSON.parse(text),
        text: async () => text,
    };
}

/**
 * A fetch stub.
 *
 * Routes are `'GET /api/things'` or `'/api/things'` (any method), answered by a body, by
 * `{ status, body }`, or by `(request) => body | { status, body }`. A request no route answers
 * gets a 404. `fetch.calls` lists every request: `{ method, url, headers, body }`.
 *
 * @param {object} routes
 * @returns {Function} The stub
 */
function stubFetch(routes = {}) {
    const calls = [];
    const fetch = async (input, init = {}) => {
        const url = String(input && input.url ? input.url : input);
        const method = String(init.method || 'GET').toUpperCase();
        const path = url.replace(/^https?:\/\/[^/]+/, '');
        const request = { method, url, path, headers: init.headers || {}, body: init.body };
        calls.push(request);

        const route = [`${method} ${path}`, `${method} ${path.split('?')[0]}`, path, path.split('?')[0]]
            .find((key) => Object.prototype.hasOwnProperty.call(routes, key));
        if (route === undefined) {
            return response({ status: 404, error: 'No route in the stub for ' + method + ' ' + path }, 404);
        }

        let answer = routes[route];
        if (typeof answer === 'function') {
            answer = await answer(request);
        }
        if (answer && typeof answer === 'object' && 'status' in answer && 'body' in answer) {
            return response(answer.body, answer.status);
        }
        return response(answer);
    };
    fetch.calls = calls;

    return fetch;
}

/**
 * `window.pfApiHeaders`, as `pf-utils.js` defines it: the CSRF token from the page's
 * `<meta name="csrf">`, added to the headers it is given.
 *
 * @param {object} window From `createDom({ meta: { csrf: 'token' } })`
 */
function installApiHeaders(window) {
    window.pfApiHeaders = (extra) => {
        const headers = extra || {};
        const meta = window.document.querySelector('meta[name="csrf"]');
        if (meta && meta.getAttribute('content')) {
            headers['X-CSRF-Token'] = meta.getAttribute('content');
        }
        return headers;
    };

    return window.pfApiHeaders;
}

module.exports = { stubFetch, response, installApiHeaders };
