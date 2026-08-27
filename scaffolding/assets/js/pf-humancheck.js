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
     * The worker body. Written as a string so it can be turned into a blob URL
     * — this keeps the whole thing in one file the application can serve from
     * its own origin.
     */
    var WORKER_SOURCE = [
        'self.onmessage = async function (event) {',
        '    var payload = event.data.payload;',
        '    var bits = event.data.bits;',
        '    var encoder = new TextEncoder();',
        '    var nonce = 0;',
        '',
        '    // A bare counter is fine: the challenge already carries 128 bits of',
        '    // server randomness, so two clients never search the same space.',
        '    for (;;) {',
        '        var candidate = nonce.toString(36);',
        '        var digest = new Uint8Array(await crypto.subtle.digest(',
        '            "SHA-256", encoder.encode(payload + ":" + candidate)',
        '        ));',
        '',
        '        if (hasLeadingZeroBits(digest, bits)) {',
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
        '};',
        '',
        'function hasLeadingZeroBits(bytes, bits) {',
        '    var whole = Math.floor(bits / 8);',
        '    for (var i = 0; i < whole; i++) {',
        '        if (bytes[i] !== 0) { return false; }',
        '    }',
        '    var remaining = bits % 8;',
        '    if (remaining === 0) { return true; }',
        '    return (bytes[whole] >> (8 - remaining)) === 0;',
        '}'
    ].join('\n');

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
            if (typeof Worker === 'undefined' || !global.crypto || !global.crypto.subtle) {
                // Web Crypto needs a secure context. Rejecting rather than
                // falling back to a weak hash keeps the failure visible: a
                // check that silently degrades is the thing this exists to
                // avoid.
                reject(new Error('Web Workers and Web Crypto are required'));
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

            self.worker.onerror = function (error) {
                URL.revokeObjectURL(url);
                reject(error);
            };

            self.worker.postMessage({ payload: self.payload, bits: self.difficulty });
        });
    };

    /**
     * Stop work in progress — for a form the visitor navigated away from.
     */
    PfHumanCheck.prototype.cancel = function () {
        if (this.worker) {
            this.worker.terminate();
            this.worker = null;
        }
    };

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
     * A browser with no Web Worker or no crypto.subtle submits with an empty solution and
     * is refused by the server. That is deliberate: silently letting it through would make
     * the check bypassable by advertising an old user agent.
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

                    // Held, not blocked: the work is nearly always finished by now, and a
                    // disabled button would leave somebody staring at a form that looks
                    // broken while it finishes.
                    event.preventDefault();
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
