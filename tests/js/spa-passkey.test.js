/**
 * The passkey ceremony as a SPA project receives it.
 *
 * A server-rendered login page loads `pf-webauthn.js` in a `<script>` tag and the
 * ceremony posts with the session cookie. A SPA has neither: its code is modules,
 * and its API is authenticated with an API key and a bearer token. The temptation
 * — written down here because it is what actually happens — is to write a second,
 * small WebAuthn client for the SPA. It is never small, it is exact, and its
 * failures say `authentication_failed` and name no field.
 *
 * So the SPA gets the *same* source with ES exports appended
 * (`PasskeyAsset::spaModule()`), and the one thing that differs — the transport —
 * is injected. What these tests drive is precisely that: the shipped bytes,
 * through an injected transport, against a stubbed authenticator.
 *
 * Run:
 *   node --test tests/js/spa-passkey.test.js
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

/**
 * Ask PHP for the module a SPA project is given, exactly as the scaffolder
 * writes it. Reading the asset directly would test a different string than the
 * one that ships.
 */
function writeModule() {
    const php = 'require "vendor/autoload.php";'
        + ' echo Pramnos\\Auth\\Passkey\\PasskeyAsset::spaModule("TestApp");';
    const source = execFileSync('php', ['-r', php], { cwd: ROOT, encoding: 'utf8' });

    const dir  = fs.mkdtempSync(path.join(os.tmpdir(), 'pf-passkey-'));
    const file = path.join(dir, 'webauthn.mjs');
    fs.writeFileSync(file, source);

    return file;
}

// ─── A browser stub with just enough behaviour ──────────────────────────────

/** What the server hands out: base64url everywhere, as WebAuthn JSON requires. */
const SERVER_OPTIONS = {
    challenge: 'AQID',                                   // three bytes: 1, 2, 3
    rpId: 'example.test',
    allowCredentials: [{ id: 'BAUG', type: 'public-key' }],
    user: { id: 'BwgJ', name: 'ada' },
    excludeCredentials: [{ id: 'CgsM', type: 'public-key' }],
};

/** What an authenticator hands back: ArrayBuffers, which must go back as base64url. */
function makeCredential() {
    return {
        id: 'credential-id',
        type: 'public-key',
        rawId: new Uint8Array([1, 2, 3]).buffer,
        response: {
            clientDataJSON: new Uint8Array([4, 5]).buffer,
            authenticatorData: new Uint8Array([6, 7]).buffer,
            signature: new Uint8Array([8, 9]).buffer,
            attestationObject: new Uint8Array([10, 11]).buffer,
            userHandle: new Uint8Array([12]).buffer,
        },
        getClientExtensionResults: () => ({}),
    };
}

const calls = { get: [], create: [] };

function installBrowser() {
    const publicKeyCredential = function () {};
    publicKeyCredential.isConditionalMediationAvailable = () => Promise.resolve(true);

    globalThis.window = {
        PublicKeyCredential: publicKeyCredential,
        AbortController,
    };
    globalThis.navigator = {
        credentials: {
            get(request) {
                calls.get.push(request);
                return Promise.resolve(makeCredential());
            },
            create(request) {
                calls.create.push(request);
                return Promise.resolve(makeCredential());
            },
        },
    };
    globalThis.btoa = (s) => Buffer.from(s, 'binary').toString('base64');
    globalThis.atob = (s) => Buffer.from(s, 'base64').toString('binary');

    // Any use of fetch is a failure: the whole point of the transport seam is
    // that a SPA's calls do NOT go out as bare same-origin requests, which would
    // carry no API key and no bearer token.
    // Rejected rather than thrown, so a ceremony that fell back to it fails the
    // way a network error does — asynchronously — instead of in a shape no
    // caller of a promise-returning function would ever catch.
    globalThis.fetch = () => Promise.reject(
        new Error('the ceremony fell back to fetch instead of the injected transport')
    );
}

installBrowser();

/** Records what the ceremony posted, and answers like the framework's API does. */
function recordingTransport(posts, { failVerify = false } = {}) {
    return (url, body) => {
        posts.push({ url, body });

        if (url.endsWith('Options')) {
            return Promise.resolve({ ok: true, json: () => Promise.resolve({ options: SERVER_OPTIONS }) });
        }
        if (failVerify) {
            return Promise.resolve({ ok: false, json: () => Promise.resolve({ error: 'authentication_failed' }) });
        }

        return Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ status: 'success', access_token: 'tok', user: { id: 42 } }),
        });
    };
}

describe('the SPA passkey module', () => {
    let webauthn;
    let posts;

    beforeEach(async () => {
        if (!webauthn) {
            webauthn = await import(pathToFileURL(writeModule()).href);
        }
        posts = [];
        calls.get.length = 0;
        calls.create.length = 0;
        webauthn.setTransport(recordingTransport(posts));
    });

    /**
     * The module publishes the ceremony rather than carrying a copy of it.
     *
     * If this fails, somebody has written a second WebAuthn client — which is the
     * one outcome the generated file exists to prevent.
     */
    test('it is the framework ceremony, exported', () => {
        assert.equal(typeof globalThis.window.PramnosWebAuthn, 'object');
        assert.equal(typeof webauthn.authenticate, 'function');
        assert.equal(webauthn.supported(), true);
    });

    /**
     * Sign-in: options are fetched, converted, and the assertion posted back.
     *
     * The conversion is the part that cannot be eyeballed. `challenge: 'AQID'` has
     * to reach `credentials.get()` as the three bytes 1, 2, 3 in an ArrayBuffer —
     * a string there is a `TypeError` in some browsers and a silent verification
     * failure in others.
     */
    test('it runs the sign-in ceremony over the injected transport', async () => {
        const result = await webauthn.authenticate('/passkey/loginOptions', '/passkey/login', { username: 'ada' });

        // Two calls, in order, through the transport — never fetch.
        assert.deepEqual(posts.map((p) => p.url), ['/passkey/loginOptions', '/passkey/login']);
        assert.deepEqual(posts[0].body, { username: 'ada' });

        // The challenge arrived as bytes, not as the base64url string.
        const request = calls.get[0].publicKey;
        assert.deepEqual([...new Uint8Array(request.challenge)], [1, 2, 3]);
        assert.deepEqual([...new Uint8Array(request.allowCredentials[0].id)], [4, 5, 6]);

        // …and the authenticator's ArrayBuffers went back as base64url.
        assert.equal(posts[1].body.response.signature, 'CAk');
        assert.equal(posts[1].body.rawId, 'AQID');

        // The token envelope reaches the caller, which is what lib/api.js stores.
        assert.equal(result.access_token, 'tok');
    });

    /**
     * Enrolment converts the two fields sign-in does not have.
     *
     * `user.id` and `excludeCredentials` exist only in the creation ceremony, and
     * a missed `user.id` fails at the authenticator with no server-side trace at
     * all.
     */
    test('it runs the enrolment ceremony over the injected transport', async () => {
        await webauthn.register('/passkey/registerOptions', '/passkey/register', { label: 'laptop' });

        assert.deepEqual(posts.map((p) => p.url), ['/passkey/registerOptions', '/passkey/register']);
        assert.deepEqual(posts[0].body, { label: 'laptop' });

        const request = calls.create[0].publicKey;
        assert.deepEqual([...new Uint8Array(request.user.id)], [7, 8, 9]);
        assert.deepEqual([...new Uint8Array(request.excludeCredentials[0].id)], [10, 11, 12]);

        // Attestation, not assertion: a different field, and the server rejects the wrong one.
        assert.equal(posts[1].body.response.attestationObject, 'Cgs');
    });

    /**
     * The server's own error code reaches the caller.
     *
     * A screen can only say something useful — "that passkey is not registered
     * here" — if the code survives. A generic rejection would make every failure
     * look the same to the person who has to act on it.
     */
    test('it rejects with the error the server named', async () => {
        webauthn.setTransport(recordingTransport(posts, { failVerify: true }));

        await assert.rejects(
            () => webauthn.authenticate('/passkey/loginOptions', '/passkey/login', {}),
            /authentication_failed/
        );
    });

    /**
     * Outside a browser it degrades rather than throwing at import time.
     *
     * A project's own `node --test` suite imports lib/api.js, which imports this.
     * Every one of those tests would fail on a bare `window` reference — for a
     * feature none of them use.
     */
    test('it is inert where there is no authenticator', async () => {
        const saved = globalThis.window.PublicKeyCredential;
        delete globalThis.window.PublicKeyCredential;
        try {
            assert.equal(webauthn.supported(), false);
            await assert.rejects(
                () => webauthn.authenticate('/passkey/loginOptions', '/passkey/login', {}),
                /webauthn_unsupported/
            );
        } finally {
            globalThis.window.PublicKeyCredential = saved;
        }
    });

    /**
     * A bad transport falls back to the default rather than breaking mid-login.
     *
     * The setter is called at module-import time by generated code; a wrong
     * argument there must not turn every later ceremony into a TypeError thrown
     * from inside the framework.
     */
    test('a non-function transport restores the default', async () => {
        webauthn.setTransport(null);

        // The default posts with fetch, which this stub refuses — proving the
        // default was restored rather than the null being called.
        await assert.rejects(
            () => webauthn.authenticate('/passkey/loginOptions', '/passkey/login', {}),
            /fell back to fetch/
        );

        webauthn.setTransport(recordingTransport(posts));
    });
});
