/**
 * Runs the *shipped* client-side human check under Node and prints its answer.
 *
 * The point is that this file re-implements nothing. It loads
 * `scaffolding/assets/js/pf-humancheck.js` — the exact bytes a browser is served — into a scope
 * that pretends to be a browser, and asks it to solve a challenge the PHP side minted. The PHP
 * side then verifies the answer. A disagreement between the two is invisible in production:
 * the browser produces a solution, the server refuses it, and the message the visitor sees
 * blames their browser.
 *
 * The four modes are the four paths through `solve()`. Each one is produced by *withholding*
 * something from the scope, which is what an old browser or an insecure context does:
 *
 *   worker-subtle   a Worker and Web Crypto        — what almost everybody gets
 *   worker-purejs   a Worker, no Web Crypto        — plain HTTP, not localhost
 *   main-subtle     no Worker, Web Crypto          — some embedded webviews
 *   main-purejs     neither                        — everything else with JavaScript
 *   hash            the SHA-256 alone, against Node's own
 *
 * Usage: node humancheck-agreement.mjs <file> <payload> <bits> <mode>
 */

import { readFileSync } from 'node:fs';
import { webcrypto, createHash } from 'node:crypto';

const [file, payload, bitsRaw, mode] = process.argv.slice(2);
const bits = parseInt(bitsRaw, 10);

/**
 * Load the shipped file into a scope with only the capabilities this mode allows.
 */
function load(withSubtle, withWorker) {
    const scope = {};
    scope.self = scope;
    scope.TextEncoder = TextEncoder;
    scope.setTimeout = setTimeout;

    if (withSubtle) {
        scope.crypto = webcrypto;
    }

    if (withWorker) {
        // A Worker the way a browser builds one: the blob's source runs in its own scope.
        scope.Blob = class { constructor(parts) { this.source = parts.join(''); } };
        scope.URL = { createObjectURL: (blob) => blob, revokeObjectURL: () => {} };
        scope.Worker = class {
            constructor(blob) {
                const inner = { TextEncoder };
                inner.self = inner;
                if (withSubtle) { inner.crypto = webcrypto; }
                inner.postMessage = (data) => {
                    setTimeout(() => this.onmessage && this.onmessage({ data }), 0);
                };
                new Function('self', 'crypto', 'TextEncoder', blob.source)(
                    inner, inner.crypto, TextEncoder
                );
                this.inner = inner;
            }

            postMessage(data) { this.inner.onmessage({ data }); }
            terminate() {}
        };
    }

    new Function('self', 'window', 'TextEncoder', 'setTimeout', readFileSync(file, 'utf8'))(
        scope, scope, TextEncoder, setTimeout
    );

    return scope.PfHumanCheck;
}

if (mode === 'hash') {
    const sha256 = load(false, false).sha256;

    for (const input of ['', 'a', 'abc', 'hello:1z', 'a'.repeat(200), 'κάτι ελληνικό:4f', '😀:zz']) {
        const mine = Buffer.from(sha256(input)).toString('hex');
        const real = createHash('sha256').update(input, 'utf8').digest('hex');

        if (mine !== real) {
            console.error(`MISMATCH for ${JSON.stringify(input)}: ${mine} != ${real}`);
            process.exit(1);
        }
    }

    console.log('ok');
    process.exit(0);
}

const modes = {
    'worker-subtle': [true, true],
    'worker-purejs': [false, true],
    'main-subtle':   [true, false],
    'main-purejs':   [false, false],
};

if (!modes[mode]) {
    console.error(`unknown mode ${mode}`);
    process.exit(2);
}

const [subtle, worker] = modes[mode];
const PfHumanCheck = load(subtle, worker);

// The signature is part of the challenge string and not part of what is hashed; the client is
// responsible for stripping it, so it is handed the whole thing exactly as the server sends it.
const check = new PfHumanCheck({ challenge: payload + '.SIGNATURE', difficulty: bits });

console.log(await check.solve());
