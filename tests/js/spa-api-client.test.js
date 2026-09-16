/**
 * The API client as a scaffolded project receives it.
 *
 * `lib/api.js` is the single place the framework's API contract lives for a SPA —
 * the mandatory `apiKey` header, the `accessToken` header, cookies, the error
 * envelope — and until now the framework shipped it without ever running it. Every
 * test that touched it was in a *generated* project, against a mocked client, which
 * is exactly where a contract bug hides: the mock agrees with whatever the client
 * does.
 *
 * So this renders the stub the way `init` does, writes the two framework-owned
 * modules it imports beside it, and drives the real thing against a stubbed
 * `fetch`.
 *
 * Run:
 *   node --test tests/js/spa-api-client.test.js
 */
'use strict';

const { test, describe, beforeEach } = require('node:test');
const assert                         = require('node:assert/strict');
const fs                             = require('node:fs');
const os                             = require('node:os');
const path                           = require('node:path');
const { execFileSync }               = require('node:child_process');
const { pathToFileURL }              = require('node:url');

const ROOT = path.join(__dirname, '..', '..');

const API_PREFIX = '/api/1.0';

/**
 * Write the client and the two modules it imports into a temporary project.
 *
 * The framework-owned pair comes from PHP rather than from a copy here, for the
 * same reason they are generated at all: a second copy is a second thing to keep
 * in step.
 */
function buildClient() {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'pf-api-client-'));

    const stub = fs.readFileSync(
        path.join(ROOT, 'scaffolding/templates/spa-api-client.js.stub'),
        'utf8'
    );
    fs.writeFileSync(
        path.join(dir, 'api.mjs'),
        stub
            .replaceAll('{{ apiPrefix }}', API_PREFIX)
            .replaceAll('{{ appName }}', 'TestApp')
            .replaceAll('{{ tokenStorageKey }}', 'testapp_token')
            // The imports are rewritten, not the modules: node resolves a relative
            // specifier against this file, and `.js` next to an `.mjs` would be
            // read as CommonJS.
            .replace("from './debug.js'", "from './debug.mjs'")
            .replace("from './webauthn.js'", "from './webauthn.mjs'")
    );

    const php = (expr) => execFileSync('php', ['-r', `require "vendor/autoload.php"; echo ${expr};`], {
        cwd: ROOT,
        encoding: 'utf8',
    });

    fs.writeFileSync(path.join(dir, 'debug.mjs'), php('Pramnos\\Debug\\DebugBarAsset::spaModule("TestApp")'));
    fs.writeFileSync(path.join(dir, 'webauthn.mjs'), php('Pramnos\\Auth\\Passkey\\PasskeyAsset::spaModule("TestApp")'));

    return path.join(dir, 'api.mjs');
}

// ─── A browser stub with just enough behaviour ──────────────────────────────

const store = new Map();

globalThis.localStorage = {
    getItem: (k) => (store.has(k) ? store.get(k) : null),
    setItem: (k, v) => store.set(k, String(v)),
    removeItem: (k) => store.delete(k),
};
// Deliberately no `document`: importing lib/api.js — and therefore the debug panel
// and the WebAuthn ceremony it pulls in — has to work outside a browser, because
// that is what every test a scaffolded project ships for its client does.
globalThis.window = { __PRAMNOS__: { apiPrefix: API_PREFIX, apiKey: 'the-api-key' } };

/** Every request the client made, and the canned answers it was given. */
const calls = [];
let answers = [];

globalThis.fetch = async (url, options) => {
    calls.push({ url, options });
    const answer = answers.shift() ?? { status: 200, body: {} };

    return {
        ok: answer.status >= 200 && answer.status < 300,
        status: answer.status,
        json: async () => answer.body,
    };
};

describe('the generated API client', () => {
    let api;

    beforeEach(async () => {
        if (!api) {
            api = await import(pathToFileURL(buildClient()).href);
        }
        calls.length = 0;
        answers      = [];
        store.clear();
        api.setToken(null);
    });

    /**
     * The API key is on every request, and the token only when there is one.
     *
     * The key is not optional: without it the API answers 403 "API key is
     * missing" before it looks at anything else, which reads as a broken
     * endpoint rather than a missing header.
     */
    test('it sends the apiKey header, and the token once there is one', async () => {
        answers = [{ status: 200, body: { ok: true } }, { status: 200, body: { ok: true } }];

        await api.request('/status');
        assert.equal(calls[0].url, '/api/1.0/status');
        assert.equal(calls[0].options.headers.apiKey, 'the-api-key');
        assert.equal(calls[0].options.headers.accessToken, undefined);

        api.setToken('a-token');
        await api.request('/me');
        assert.equal(calls[1].options.headers.accessToken, 'a-token');
    });

    /**
     * A token the API refuses is dropped **and** the call is answered anyway.
     *
     * This is the failure that reads as "the backend is down": the API refuses a
     * request carrying a bad token before it looks at the route, so one expired,
     * revoked or redeployed-away token makes `/status` — which needs no
     * authentication at all — fail alongside everything else, for the whole of
     * the first load. Clearing the token fixes the *next* request; retrying
     * fixes this one, which is the one the screen is waiting for.
     */
    test('a refused token is cleared and the call is retried anonymously', async () => {
        // Arrange — a dead token from a previous session.
        api.setToken('dead-token');
        answers = [
            { status: 403, body: { error: 'InvalidAccessToken', message: 'Invalid Access Token.' } },
            { status: 200, body: { status: 'ok' } },
        ];

        // Act
        const result = await api.request('/status');

        // Assert — two attempts: the second carried no token, and it succeeded.
        assert.equal(calls.length, 2);
        assert.equal(calls[0].options.headers.accessToken, 'dead-token');
        assert.equal(calls[1].options.headers.accessToken, undefined);
        assert.deepEqual(result, { status: 'ok' });

        // …and the dead token is gone from storage, so a reload starts clean.
        assert.equal(api.getToken(), null);
        assert.equal(store.get('testapp_token'), undefined);
    });

    /**
     * A protected endpoint still fails, and says so with its status.
     *
     * The retry is not a way in: it presents no credential, so anything that
     * needed one answers 401/403 again — and that refusal is what reaches the
     * screen, rather than the one about the token.
     */
    test('the retry does not turn a protected endpoint into a public one', async () => {
        // Arrange
        api.setToken('dead-token');
        answers = [
            { status: 403, body: { error: 'InvalidAccessToken' } },
            { status: 401, body: { error: 'Unauthorized', message: 'Sign in.' } },
        ];

        // Act & Assert
        await assert.rejects(
            () => api.request('/me'),
            (err) => err.name === 'ApiError' && err.status === 401
        );
        assert.equal(calls.length, 2);
    });

    /**
     * An anonymous call is not retried.
     *
     * It presented no token, so `InvalidAccessToken` cannot be about one — and a
     * retry identical to the attempt that just failed is an infinite loop, not a
     * recovery.
     */
    test('an anonymous call is answered, not retried', async () => {
        answers = [{ status: 403, body: { error: 'InvalidAccessToken' } }];

        await assert.rejects(
            () => api.request('/account/login', { method: 'POST', body: {}, anonymous: true }),
            (err) => err.status === 403
        );
        assert.equal(calls.length, 1, 'the anonymous call was retried');
    });

    /**
     * A two-factor account is reported as such, not as a wrong password.
     *
     * `login()` throws `TwoFactorRequired` rather than returning, which is the
     * contract both scaffolded sign-in screens are written against.
     */
    test('login reports a pending second factor', async () => {
        answers = [{
            status: 401,
            body: { error: 'two_factor_required', methods: ['totp', 'email'] },
        }];

        await assert.rejects(
            () => api.login('ada', 'secret'),
            (err) => err.name === 'TwoFactorRequired' && err.methods.includes('totp')
        );

        // Sent anonymously: signing in has to work when a stale token is still held.
        assert.equal(calls[0].options.headers.accessToken, undefined);
    });

    /**
     * A successful login stores the issued token.
     *
     * Everything after a sign-in depends on this one assignment; a client that
     * returned the user and forgot the token would look like it worked until the
     * next call.
     */
    test('login stores the issued token', async () => {
        answers = [{
            status: 200,
            body: { access_token: 'fresh', token_type: 'Bearer', user: { id: 7 } },
        }];

        const user = await api.login('ada', 'secret');

        assert.deepEqual(user, { id: 7 });
        assert.equal(api.getToken(), 'fresh');
    });

    /**
     * A 204 decodes to null rather than throwing.
     *
     * A save answers 204 and carries no body; `response.json()` on it rejects, so
     * a client that always decoded would turn every successful save into an error.
     */
    test('a 204 is a successful empty answer', async () => {
        answers = [{ status: 204, body: null }];

        assert.equal(await api.request('/things/1', { method: 'DELETE' }), null);
    });

    /**
     * Blank query parameters are dropped rather than sent empty.
     *
     * `?q=` is a different URL from no `q` at all — a second cache key and a WHERE
     * clause the server did not need to build — and `URLSearchParams` stringifies
     * `undefined` to the four characters "undefined".
     */
    test('empty query parameters are not sent', async () => {
        answers = [{ status: 200, body: {} }];

        await api.request('/things', { query: { page: 2, q: '', sort: undefined, tag: null } });

        assert.equal(calls[0].url, '/api/1.0/things?page=2');
    });
});
