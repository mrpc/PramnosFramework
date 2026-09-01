/**
 * Proof-of-work human check — client side.
 *
 * Solves the challenge minted by \Pramnos\Security\HumanCheck: find a nonce
 * whose SHA-256 of "<payload>:<nonce>" begins with the required number of zero
 * bits.
 *
 * The work runs in a Web Worker, so the page stays responsive while the visitor
 * fills the form in. By the time they press submit it is normally already done.
 *
 * No dependencies and no network: a proof-of-work widget that loads a script
 * from a CDN has given back the property it existed to provide. The worker is
 * built from a blob of this file's own source, so there is no second file to
 * host or to keep in step.
 *
 * ## Four ways to do the same work
 *
 * In order of preference, and every one of them produces the same answer:
 *
 *   1. Worker + `crypto.subtle`   — the fast path, and what almost everybody gets.
 *   2. Worker + this file's SHA-256 — no Web Crypto, but Workers.
 *   3. Main thread + `crypto.subtle` — no Workers (some embedded webviews).
 *   4. Main thread + this file's SHA-256, in slices — everything else with JavaScript.
 *
 * The fallbacks are not politeness. `crypto.subtle` exists only in a **secure context**:
 * HTTPS, or localhost. A site reached over plain HTTP by hostname or LAN address — a staging
 * box, a colleague's machine, a tablet on the office network — has no `crypto.subtle` at all,
 * and with only path 1 the check could not be solved there. The form submitted an empty
 * solution, the server refused it, and the visitor read "your browser must support JavaScript"
 * while using a browser that supported it perfectly well. That is what happened, on the login
 * form, on a plain-HTTP host.
 *
 * Paths 2 and 4 hash in JavaScript, which is slower for the same difficulty. A slow sign-in
 * beats a sign-in that cannot happen.
 *
 * ## Proving it still works
 *
 * The client and the server have to agree byte for byte, and a disagreement is invisible from
 * either side: the browser produces a solution, the server refuses it, and the message the
 * visitor sees blames their browser. So the agreement is a test rather than a claim —
 * `HumanCheckClientAgreementTest` runs **this file** under Node, on every path, and verifies
 * each answer with the PHP that will judge it in production.
 *
 * Usage:
 *
 *   const check = new PfHumanCheck(challengeFromServer);
 *   check.solve().then(solution => {
 *       form.querySelector('[name=human_solution]').value = solution;
 *   });
 *
 * `challengeFromServer` is the object returned by HumanCheck::challenge():
 * { challenge, difficulty, expires, algorithm }.
 */
(function (global) {
    'use strict';

    /**
     * SHA-256 in about sixty lines, for the browsers that will not lend us one.
     *
     * Declared as an ordinary function and turned into worker source with `String()`, so there
     * is one implementation rather than two — and no `new Function`, which the framework's own
     * Content-Security-Policy forbids (`script-src` carries no `'unsafe-eval'`, deliberately).
     *
     * Byte-for-byte identical to PHP's `hash('sha256', $s, true)`, including multi-byte UTF-8
     * and surrogate pairs. Asserted, not assumed.
     *
     * @param {string} text
     * @returns {Uint8Array} 32 bytes
     */
    function pfSha256Bytes(text) {
        var K = [
            0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
            0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
            0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
            0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
            0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
            0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
            0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
            0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
        ];

        // UTF-8 by hand: TextEncoder is not present everywhere this has to run, and the
        // payload comes from the server, so multi-byte input is encoded properly rather than
        // assumed away.
        var bytes = [];
        for (var i = 0; i < text.length; i++) {
            var code = text.charCodeAt(i);
            if (code < 0x80) {
                bytes.push(code);
            } else if (code < 0x800) {
                bytes.push(0xc0 | (code >> 6), 0x80 | (code & 0x3f));
            } else if (code < 0xd800 || code >= 0xe000) {
                bytes.push(0xe0 | (code >> 12), 0x80 | ((code >> 6) & 0x3f), 0x80 | (code & 0x3f));
            } else {
                i++;
                var point = 0x10000 + (((code & 0x3ff) << 10) | (text.charCodeAt(i) & 0x3ff));
                bytes.push(
                    0xf0 | (point >> 18), 0x80 | ((point >> 12) & 0x3f),
                    0x80 | ((point >> 6) & 0x3f), 0x80 | (point & 0x3f)
                );
            }
        }

        var bitLength = bytes.length * 8;
        bytes.push(0x80);
        while (bytes.length % 64 !== 56) { bytes.push(0); }
        // 64-bit big-endian length. The high word is always zero for anything a browser will
        // hash here — that would take half a gigabyte of input.
        bytes.push(0, 0, 0, 0);
        bytes.push(
            (bitLength >>> 24) & 0xff, (bitLength >>> 16) & 0xff,
            (bitLength >>> 8) & 0xff, bitLength & 0xff
        );

        var h = [0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19];
        var w = new Array(64);

        for (var offset = 0; offset < bytes.length; offset += 64) {
            for (var t = 0; t < 16; t++) {
                w[t] = (bytes[offset + t * 4] << 24) | (bytes[offset + t * 4 + 1] << 16)
                     | (bytes[offset + t * 4 + 2] << 8) | bytes[offset + t * 4 + 3];
            }
            for (t = 16; t < 64; t++) {
                var s0 = ((w[t - 15] >>> 7) | (w[t - 15] << 25)) ^ ((w[t - 15] >>> 18) | (w[t - 15] << 14)) ^ (w[t - 15] >>> 3);
                var s1 = ((w[t - 2] >>> 17) | (w[t - 2] << 15)) ^ ((w[t - 2] >>> 19) | (w[t - 2] << 13)) ^ (w[t - 2] >>> 10);
                w[t] = (w[t - 16] + s0 + w[t - 7] + s1) | 0;
            }

            var a = h[0], b = h[1], c = h[2], d = h[3], e = h[4], f = h[5], g = h[6], hh = h[7];

            for (t = 0; t < 64; t++) {
                var S1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
                var ch = (e & f) ^ (~e & g);
                var temp1 = (hh + S1 + ch + K[t] + w[t]) | 0;
                var S0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
                var maj = (a & b) ^ (a & c) ^ (b & c);
                var temp2 = (S0 + maj) | 0;
                hh = g; g = f; f = e; e = (d + temp1) | 0;
                d = c; c = b; b = a; a = (temp1 + temp2) | 0;
            }

            h[0] = (h[0] + a) | 0; h[1] = (h[1] + b) | 0; h[2] = (h[2] + c) | 0; h[3] = (h[3] + d) | 0;
            h[4] = (h[4] + e) | 0; h[5] = (h[5] + f) | 0; h[6] = (h[6] + g) | 0; h[7] = (h[7] + hh) | 0;
        }

        var out = new Uint8Array(32);
        for (var j = 0; j < 8; j++) {
            out[j * 4] = (h[j] >>> 24) & 0xff;
            out[j * 4 + 1] = (h[j] >>> 16) & 0xff;
            out[j * 4 + 2] = (h[j] >>> 8) & 0xff;
            out[j * 4 + 3] = h[j] & 0xff;
        }

        return out;
    }

    /**
     * Does a digest begin with this many zero bits?
     *
     * Shared by every path, and by the worker through `String()`. Bits rather than characters,
     * matching `HumanCheck::meetsDifficulty()`: a leading-zero *character* count moves in steps
     * of 16x and gives no way to ask for "a bit more than 300ms".
     *
     * @param {Uint8Array} bytes
     * @param {number} bits
     */
    function pfHasLeadingZeroBits(bytes, bits) {
        var whole = Math.floor(bits / 8);
        for (var i = 0; i < whole; i++) {
            if (bytes[i] !== 0) { return false; }
        }
        var remaining = bits % 8;
        if (remaining === 0) { return true; }
        return (bytes[whole] >> (8 - remaining)) === 0;
    }

    /**
     * The worker body. Written as a string so it can be turned into a blob URL
     * — this keeps the whole thing in one file the application can serve from
     * its own origin.
     */
    var WORKER_SOURCE = [
        String(pfSha256Bytes),
        String(pfHasLeadingZeroBits),
        'var hasSubtle = !!(self.crypto && self.crypto.subtle);',
        'var encoder = typeof TextEncoder !== "undefined" ? new TextEncoder() : null;',
        '',
        '// Web Crypto when this context has it, this file\'s own SHA-256 when it does not.',
        '// Both are asked for the digest of the same string, so the answer is the same.',
        'async function digest(text) {',
        '    if (hasSubtle && encoder) {',
        '        return new Uint8Array(await self.crypto.subtle.digest("SHA-256", encoder.encode(text)));',
        '    }',
        '    return pfSha256Bytes(text);',
        '}',
        '',
        'self.onmessage = async function (event) {',
        '    var payload = event.data.payload;',
        '    var bits = event.data.bits;',
        '    var nonce = 0;',
        '',
        '    // A bare counter is fine: the challenge already carries 128 bits of',
        '    // server randomness, so two clients never search the same space.',
        '    for (;;) {',
        '        var candidate = nonce.toString(36);',
        '',
        '        if (pfHasLeadingZeroBits(await digest(payload + ":" + candidate), bits)) {',
        '            self.postMessage({ solution: candidate, attempts: nonce + 1 });',
        '            return;',
        '        }',
        '',
        '        nonce++;',
        '',
        '        // Report progress occasionally so a caller can show something',
        '        // on the slow devices where this actually takes a moment.',
        '        if (nonce % 20000 === 0) {',
        '            self.postMessage({ progress: nonce });',
        '        }',
        '    }',
        '};'
    ].join('\n');

    /**
     * How many candidates the main-thread fallback tries before yielding.
     *
     * Only reached when there is no Worker, so the loop is on the thread that draws the page:
     * without a yield the tab freezes for the whole search, and a frozen tab is what somebody
     * force-quits. 2,000 is a few milliseconds of hashing even in the slow path.
     */
    var MAIN_THREAD_SLICE = 2000;

    /**
     * @param {{challenge: string, difficulty: number}} challenge
     *        The object returned by HumanCheck::challenge().
     */
    function PfHumanCheck(challenge) {
        this.challenge = challenge.challenge;
        this.difficulty = challenge.difficulty;
        // The signature is not part of what gets hashed — the server hashes the
        // payload only, so the client must strip the last dot-separated field.
        this.payload = this.challenge.split('.').slice(0, 3).join('.');
        this.worker = null;
    }

    /**
     * Solve the challenge.
     *
     * @param {function(number)=} onProgress Called with the attempt count every
     *        20,000 hashes, for a progress indicator on slow devices.
     * @returns {Promise<string>} The solution nonce, to submit alongside the
     *          challenge.
     */
    PfHumanCheck.prototype.solve = function (onProgress) {
        var self = this;

        return new Promise(function (resolve, reject) {
            /*
             * No Worker: solve here instead of giving up.
             *
             * `crypto.subtle` is not checked at this level any more. It is missing on any
             * plain-HTTP host that is not localhost, and refusing there meant nobody on a
             * staging box or a LAN address could sign in — the failure the visitor saw blamed
             * their browser. The worker, and the loop below, each use Web Crypto if the context
             * has it and this file's SHA-256 if it does not.
             */
            var canWork = typeof Worker !== 'undefined'
                && typeof Blob !== 'undefined'
                && typeof URL !== 'undefined'
                && typeof URL.createObjectURL === 'function';

            if (!canWork) {
                self.solveHere(onProgress).then(resolve, reject);
                return;
            }

            var blob = new Blob([WORKER_SOURCE], { type: 'text/javascript' });
            var url = URL.createObjectURL(blob);
            self.worker = new Worker(url);

            self.worker.onmessage = function (event) {
                if (event.data.progress !== undefined) {
                    if (onProgress) { onProgress(event.data.progress); }
                    return;
                }
                URL.revokeObjectURL(url);
                self.worker.terminate();
                self.worker = null;
                resolve(event.data.solution);
            };

            self.worker.onerror = function () {
                /*
                 * A worker that would not start is not a dead end.
                 *
                 * It happens: a Content-Security-Policy without `worker-src blob:`, an
                 * extension blocking blob workers, a webview that reports the constructor and
                 * refuses the URL. Rejecting here left the form submitting an empty solution,
                 * which the server refuses — so the visitor was told their browser was too old
                 * because of a policy header.
                 */
                URL.revokeObjectURL(url);
                if (self.worker) {
                    self.worker.terminate();
                    self.worker = null;
                }
                self.solveHere(onProgress).then(resolve, reject);
            };

            self.worker.postMessage({ payload: self.payload, bits: self.difficulty });
        });
    };

    /**
     * Solve on this thread, in slices, when there is no Worker to do it in.
     *
     * `setTimeout` between slices rather than one loop: this runs on the thread that draws the
     * page, and a search that holds it for a second or two is a tab that stops responding.
     * Sliced, the visitor keeps typing and never learns that anything was happening.
     *
     * Web Crypto when the context has it — its `digest()` is a promise, which is why this is
     * written as a chain rather than a `for`.
     *
     * @param {function(number)=} onProgress
     * @returns {Promise<string>}
     */
    PfHumanCheck.prototype.solveHere = function (onProgress) {
        var payload = this.payload;
        var bits = this.difficulty;
        var hasSubtle = !!(global.crypto && global.crypto.subtle && typeof TextEncoder !== 'undefined');
        var encoder = hasSubtle ? new TextEncoder() : null;
        var cancelled = function () { return false; };
        var owner = this;

        owner.cancelled = false;
        cancelled = function () { return owner.cancelled; };

        function digest(text) {
            if (hasSubtle) {
                return global.crypto.subtle.digest('SHA-256', encoder.encode(text))
                    .then(function (buffer) { return new Uint8Array(buffer); });
            }

            return Promise.resolve(pfSha256Bytes(text));
        }

        return new Promise(function (resolve, reject) {
            var nonce = 0;

            function slice() {
                if (cancelled()) {
                    reject(new Error('cancelled'));
                    return;
                }

                var end = nonce + MAIN_THREAD_SLICE;

                function step() {
                    if (nonce >= end) {
                        if (onProgress) { onProgress(nonce); }
                        // Yield to the browser, then carry on.
                        setTimeout(slice, 0);
                        return;
                    }

                    var candidate = nonce.toString(36);

                    digest(payload + ':' + candidate).then(function (bytes) {
                        if (pfHasLeadingZeroBits(bytes, bits)) {
                            resolve(candidate);
                            return;
                        }

                        nonce++;
                        step();
                    }, reject);
                }

                step();
            }

            slice();
        });
    };

    /**
     * Stop work in progress — for a form the visitor navigated away from.
     */
    PfHumanCheck.prototype.cancel = function () {
        // The flag stops the main-thread path between slices; the worker path is terminated.
        this.cancelled = true;

        if (this.worker) {
            this.worker.terminate();
            this.worker = null;
        }
    };

    /*
     * Exposed for the agreement test, which runs this file under Node and checks every path
     * against the PHP that will judge the answer in production. The alternative was a test that
     * re-implemented the hash, which would have agreed with itself and proved nothing.
     */
    PfHumanCheck.sha256 = pfSha256Bytes;
    PfHumanCheck.hasLeadingZeroBits = pfHasLeadingZeroBits;
    PfHumanCheck.workerSource = WORKER_SOURCE;

    global.PfHumanCheck = PfHumanCheck;

    /* ── Auto-wiring (data-pf-humancheck) ───────────────────────────────────────
     *
     * The class above solved a challenge and left every form to wire itself up, which
     * meant each one repeated the same twelve lines — and any form that got them subtly
     * wrong failed *open*, submitting with an empty solution and being refused by the
     * server with no way for the visitor to know why.
     *
     *   <form data-pf-humancheck='{"challenge":"…","difficulty":12,"expires":…}'>
     *       <input type="hidden" name="human_challenge" value="…">
     *       <input type="hidden" name="human_solution" value="">
     *
     * Work starts as soon as the page loads — while the visitor is still typing — so by
     * the time they submit it is normally already done. If it is not, submission waits
     * for it rather than being blocked: the button is left alone and the form is held for
     * the moment or two the worker still needs.
     *
     * A browser with no Web Worker, or no Web Crypto, solves it on the main thread with this
     * file's own SHA-256 instead — see `solve()`. What is left over is a browser with
     * JavaScript switched off, which submits an empty solution and is refused. That one is
     * deliberate and cannot be otherwise: the check *is* the JavaScript, and letting a request
     * through because it claimed to have none would make it bypassable by saying so.
     */
    if (typeof document !== 'undefined') {
        document.addEventListener('DOMContentLoaded', function () {
            var forms = document.querySelectorAll('[data-pf-humancheck]');

            Array.prototype.forEach.call(forms, function (form) {
                var raw = form.getAttribute('data-pf-humancheck');
                var challenge;

                try {
                    challenge = JSON.parse(raw);
                } catch (error) {
                    return;
                }

                var field = form.querySelector('[name="human_solution"]');
                if (!field || !challenge || !challenge.challenge) { return; }

                var solved = false;
                var pending = null;

                try {
                    pending = new PfHumanCheck(challenge).solve().then(function (solution) {
                        field.value = solution;
                        solved = true;
                    });
                } catch (error) {
                    // No worker, no crypto: the server refuses, which is the safe end.
                    return;
                }

                form.addEventListener('submit', function (event) {
                    if (solved || !pending) { return; }

                    // Held, not blocked: the work is nearly always finished by now.
                    event.preventDefault();

                    // …but the hold is exactly when somebody presses submit a second time,
                    // because nothing on the page changed. `pf-auth.js` cannot do this for us:
                    // it skips a submit whose default was prevented, which is how it avoids
                    // disabling a form that failed validation and has to be fixed.
                    if (window.PramnosAuth && window.PramnosAuth.markSubmitBusy) {
                        window.PramnosAuth.markSubmitBusy(form);
                    }

                    pending.then(function () {
                        form.submit();
                    }).catch(function () {
                        form.submit();
                    });
                });
            });
        });
    }
}(typeof self !== 'undefined' ? self : this));
