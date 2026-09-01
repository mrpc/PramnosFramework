/**
 * Runs the shipped `pf-webauthn.js` under Node against a stubbed browser.
 *
 * The point is to exercise the real bytes a browser is served rather than a re-implementation: a
 * test that described what the script *should* do would agree with itself and prove nothing. The
 * WebAuthn calls cannot happen here, so `navigator.credentials` and `PublicKeyCredential` are stubs
 * that record what they were asked for — which is exactly what the assertions are about.
 *
 * Usage: node webauthn-conditional.mjs <script path> <scenario>
 * Prints one JSON object describing what happened.
 */
import { readFileSync } from 'node:fs';

const [scriptPath, scenario] = process.argv.slice(2);

const log = { calls: [], posts: [], aborted: 0, result: undefined, error: undefined };

// ── the stubbed browser ──────────────────────────────────────────────────────

class AbortControllerStub {
    constructor() {
        this.signal = { aborted: false, id: log.calls.length };
        log.aborted += 0;
    }

    abort() {
        this.signal.aborted = true;
        log.aborted += 1;
    }
}

const options = {
    challenge: 'AQID',                      // base64url, three bytes
    allowCredentials: [{ id: 'AQID', type: 'public-key' }],
    rpId: 'example.test',
};

const assertion = {
    id: 'credential-id',
    rawId: new Uint8Array([1, 2, 3]),
    type: 'public-key',
    response: {
        clientDataJSON: new Uint8Array([4, 5]),
        authenticatorData: new Uint8Array([6, 7]),
        signature: new Uint8Array([8, 9]),
        userHandle: new Uint8Array([10]),
    },
};

let resolveGet;
const pendingGet = new Promise((resolve) => { resolveGet = resolve; });

const navigatorStub = {
    credentials: {
        get(request) {
            log.calls.push({
                mediation: request.mediation ?? null,
                hasSignal: request.signal !== undefined,
                signalAborted: request.signal ? request.signal.aborted : null,
            });

            if (scenario === 'conditional-waits') {
                // The realistic case: nobody has picked a passkey yet.
                return pendingGet;
            }

            if (scenario === 'button-cancels-conditional') {
                // The *first* call is the conditional one and must stay pending, so that pressing
                // the button has something real to cancel. Anything after it is the button's own.
                if (log.calls.length === 1) {
                    return pendingGet;
                }

                return Promise.resolve(assertion);
            }

            if (scenario === 'conditional-aborted') {
                const error = new Error('aborted');
                error.name = 'AbortError';
                return Promise.reject(error);
            }

            return Promise.resolve(assertion);
        },
        create() {
            return Promise.resolve(assertion);
        },
    },
};

const publicKeyCredentialStub = function () {};
publicKeyCredentialStub.isConditionalMediationAvailable = () =>
    Promise.resolve(scenario !== 'unavailable');

if (scenario === 'no-method') {
    delete publicKeyCredentialStub.isConditionalMediationAvailable;
}

const windowStub = {
    PublicKeyCredential: publicKeyCredentialStub,
    AbortController: AbortControllerStub,
};

globalThis.window = windowStub;
globalThis.navigator = navigatorStub;
globalThis.AbortController = AbortControllerStub;
globalThis.btoa = (s) => Buffer.from(s, 'binary').toString('base64');
globalThis.atob = (s) => Buffer.from(s, 'base64').toString('binary');

globalThis.fetch = (url, init) => {
    log.posts.push({ url, body: init && init.body ? JSON.parse(init.body) : null });

    if (url.includes('options')) {
        return Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ options }),
        });
    }

    return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ status: 'ok', redirect: '/account' }),
    });
};

// ── run the shipped script ───────────────────────────────────────────────────

const source = readFileSync(scriptPath, 'utf8');
// eslint-disable-next-line no-new-func
new Function(source)();

const api = windowStub.PramnosWebAuthn;

if (!api) {
    console.log(JSON.stringify({ error: 'the script did not publish PramnosWebAuthn' }));
    process.exit(1);
}

function finish() {
    console.log(JSON.stringify({
        ...log,
        conditionalSupported: api.conditionalSupported(),
    }));
}

async function main() {
    if (scenario === 'conditional-waits') {
        // The normal case: the ceremony is started and nobody has picked a passkey. Awaiting it
        // would hang for ever — which is the behaviour under test, so it is started and left.
        api.conditional('/passkey/options', '/passkey/verify').catch((e) => { log.error = e.message; });
        await new Promise((r) => setTimeout(r, 10));
        log.result = 'still-waiting';

        return;
    }

    if (scenario === 'button-cancels-conditional') {
        // Start the conditional ceremony and leave it pending, then press the button.
        api.conditional('/passkey/options', '/passkey/verify').catch(() => {});
        await new Promise((r) => setTimeout(r, 10));

        log.result = await api.authenticate('/passkey/options', '/passkey/verify')
            .then((body) => body.status)
            .catch((e) => 'error:' + e.message);

        return;
    }

    try {
        const body = await api.conditional('/passkey/options', '/passkey/verify');
        log.result = body === null ? null : body.status;
    } catch (error) {
        log.error = error.message;
    }
}

main().then(finish).catch((e) => {
    log.error = String(e && e.message);
    finish();
});
