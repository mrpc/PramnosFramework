/**
 * The conversations' JSON (`/admin/Conversations/…`), with the page's session and CSRF token.
 * Each call resolves to the answer, or rejects with an Error whose message is the server's reason.
 */

/** The headers every call carries: the page's own, through pf-utils.js where it is loaded. */
function headers(extra) {
    if (typeof window !== 'undefined' && typeof window.pfApiHeaders === 'function') {
        return window.pfApiHeaders(extra);
    }
    const meta = typeof document !== 'undefined' ? document.querySelector('meta[name="csrf"]') : null;

    return Object.assign({ 'X-Requested-With': 'XMLHttpRequest' }, meta ? { 'X-CSRF-Token': meta.getAttribute('content') } : {}, extra);
}

/** The answer as JSON, or the server's reason as the error. */
async function answer(response) {
    let body = null;
    try {
        body = await response.json();
    } catch {
        body = null;
    }
    if (!response.ok) {
        throw new Error((body && body.error) || response.statusText || 'Error');
    }

    return body;
}

/**
 * A client for one base address.
 *
 * @param {string} base `…/admin/Conversations/`
 * @param {typeof fetch} [fetcher]
 */
export function createApi(base, fetcher) {
    const raw = fetcher || ((...args) => window.fetch(...args));
    // Whether the server answers: three failures in a row — no answer, or the 5xx a proxy gives
    // for a server behind it that is gone — and it is down; the next answer, and it is back.
    let failures = 0;
    const listeners = [];
    const tell = (state) => listeners.forEach((fn) => fn(state));
    const doFetch = (...args) => raw(...args).then((response) => {
        if (response.status >= 500 || response.status === 0) {
            if (++failures === 3) {
                tell('down');
            }
        } else {
            if (failures >= 3) {
                tell('up');
            }
            failures = 0;
        }
        return response;
    }, (error) => {
        if (++failures === 3) {
            tell('down');
        }
        throw error;
    });
    const url = (path, params) => {
        const query = params ? Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== '')
            .map(([k, v]) => encodeURIComponent(k) + '=' + encodeURIComponent(v)).join('&') : '';

        return base + path + (query ? '?' + query : '');
    };

    return {
        /** GET `path` with these query values. */
        get: (path, params) => doFetch(url(path, params), { credentials: 'same-origin', headers: headers({ Accept: 'application/json' }) }).then(answer),
        /** POST `path` with this body as JSON. */
        post: (path, body) => doFetch(url(path), {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers({ Accept: 'application/json', 'Content-Type': 'application/json' }),
            body: JSON.stringify(body || {}),
        }).then(answer),
        /** POST a file as `file`, multipart. */
        upload: (path, file) => {
            const form = new FormData();
            form.append('file', file);

            return doFetch(url(path), { method: 'POST', credentials: 'same-origin', headers: headers({ Accept: 'application/json' }), body: form }).then(answer);
        },
        /** Be told when the server stops answering (`down`) and answers again (`up`). */
        onHealth: (fn) => listeners.push(fn),
        /** Where a file is read. */
        fileUrl: (id, thumbnail) => url('file/' + id, thumbnail ? { thumbnail: 1 } : null),
    };
}
