/*!
 * pf-webauthn.js — minimal, dependency-free WebAuthn (passkey) browser glue for
 * PramnosFramework auth servers.
 *
 * Exposes window.PramnosWebAuthn with:
 *   supported()                        → boolean
 *   setTransport(fn)                   → send the ceremony's POSTs through fn
 *   authenticate(optionsUrl, verifyUrl, extra?) → Promise<result>
 *   register(optionsUrl, registerUrl, body?)    → Promise<result>
 *
 * It fetches server-issued options (base64url), converts the binary fields to
 * ArrayBuffers for navigator.credentials, then serialises the authenticator's
 * response back to the standard base64url WebAuthn JSON the framework's
 * webauthn-lib adapter deserialises. Same-origin fetch with credentials so the
 * session cookie (and the server-side pending state / challenge) travels along.
 */
(function () {
    'use strict';

    // `window` in a browser, `globalThis` under Node. The SPA ships this file as an ES
    // module (lib/webauthn.js), and `node --test` imports it along with lib/api.js —
    // where a bare `window` reference is a ReferenceError at import time, before any
    // test has run.
    var root = typeof window !== 'undefined' ? window : globalThis;

    /** Decode a base64url string into an ArrayBuffer (for navigator.credentials). */
    function b64urlToBuf(value) {
        var s = String(value).replace(/-/g, '+').replace(/_/g, '/');
        var pad = s.length % 4;
        if (pad) { s += '===='.slice(pad); }
        var bin = atob(s);
        var buf = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) { buf[i] = bin.charCodeAt(i); }
        return buf.buffer;
    }

    /** Encode an ArrayBuffer as a base64url string (for posting back to the server). */
    function bufToB64url(buf) {
        var bytes = new Uint8Array(buf);
        var bin = '';
        for (var i = 0; i < bytes.length; i++) { bin += String.fromCharCode(bytes[i]); }
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    // Convert a server request-options object (base64url) for credentials.get().
    function prepareRequestOptions(options) {
        var o = Object.assign({}, options);
        o.challenge = b64urlToBuf(o.challenge);
        if (Array.isArray(o.allowCredentials)) {
            o.allowCredentials = o.allowCredentials.map(function (c) {
                return Object.assign({}, c, { id: b64urlToBuf(c.id) });
            });
        }
        return o;
    }

    // Convert a server creation-options object (base64url) for credentials.create().
    function prepareCreationOptions(options) {
        var o = Object.assign({}, options);
        o.challenge = b64urlToBuf(o.challenge);
        if (o.user && o.user.id) { o.user = Object.assign({}, o.user, { id: b64urlToBuf(o.user.id) }); }
        if (Array.isArray(o.excludeCredentials)) {
            o.excludeCredentials = o.excludeCredentials.map(function (c) {
                return Object.assign({}, c, { id: b64urlToBuf(c.id) });
            });
        }
        return o;
    }

    /** Serialise an assertion PublicKeyCredential to the base64url WebAuthn JSON. */
    function serializeAssertion(cred) {
        var r = cred.response;
        return {
            id: cred.id,
            type: cred.type,
            rawId: bufToB64url(cred.rawId),
            response: {
                clientDataJSON: bufToB64url(r.clientDataJSON),
                authenticatorData: bufToB64url(r.authenticatorData),
                signature: bufToB64url(r.signature),
                userHandle: r.userHandle ? bufToB64url(r.userHandle) : null
            },
            clientExtensionResults: cred.getClientExtensionResults ? cred.getClientExtensionResults() : {}
        };
    }

    /** Serialise an attestation PublicKeyCredential to the base64url WebAuthn JSON. */
    function serializeAttestation(cred) {
        var r = cred.response;
        return {
            id: cred.id,
            type: cred.type,
            rawId: bufToB64url(cred.rawId),
            response: {
                clientDataJSON: bufToB64url(r.clientDataJSON),
                attestationObject: bufToB64url(r.attestationObject)
            },
            clientExtensionResults: cred.getClientExtensionResults ? cred.getClientExtensionResults() : {}
        };
    }

    /** POST a JSON body same-origin (session cookie included) and return the fetch promise. */
    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: body === undefined ? '{}' : JSON.stringify(body)
        });
    }

    /**
     * How the ceremony talks to the server.
     *
     * The ceremony itself is the same everywhere — the conversions, the options, the
     * serialisation — and the *only* thing a token-authenticated SPA needs differently is
     * this one function: its calls carry an API key and a bearer token through lib/api.js
     * rather than a session cookie. Replacing it is what lets the SPA use this file
     * instead of a second copy of the ceremony that then has to be fixed twice.
     *
     * Anything put here must answer like `fetch`: a promise for an object with `ok` and
     * `json()`.
     */
    var transport = postJson;

    // The one pending conditional ceremony, so an explicit sign-in can cancel it.
    //
    // The browser allows a single outstanding `credentials.get()`. A conditional request sits
    // waiting for the whole life of the page, so starting *any* other ceremony while it is pending
    // is refused — which would mean the «Sign in with a passkey» button silently stopped working the
    // moment conditional UI was switched on. Cancelling first is what keeps both paths available.
    var pendingConditional = null;

    function cancelConditional() {
        if (pendingConditional !== null) {
            try {
                pendingConditional.abort();
            } catch (e) {
                // An already-settled controller does not need aborting.
            }
            pendingConditional = null;
        }
    }

    var PramnosWebAuthn = {
        /**
         * Send the ceremony's requests through `fn` instead of a same-origin fetch.
         *
         * Called once, at import time, by a SPA's lib/api.js. Passing anything that is not
         * a function restores the default, so a bad argument degrades to the server-rendered
         * behaviour rather than to a TypeError in the middle of a login.
         */
        setTransport: function (fn) {
            transport = typeof fn === 'function' ? fn : postJson;
        },

        supported: function () {
            return typeof root.PublicKeyCredential !== 'undefined'
                && typeof navigator.credentials !== 'undefined';
        },

        // Whether this browser can offer a passkey inside the username autofill.
        //
        // Feature-detected rather than assumed: the method is absent in older browsers, and calling
        // it there is a TypeError on the sign-in page.
        conditionalSupported: function () {
            return this.supported()
                && typeof root.PublicKeyCredential.isConditionalMediationAvailable === 'function'
                && typeof root.AbortController !== 'undefined';
        },

        // Offer the passkey inside the username field's autofill, instead of behind a button.
        //
        // The promise stays pending until somebody picks a passkey from the autofill list — which
        // may be never, and that is the normal case. So it resolves with the verified body on
        // success and with `null` when the browser cannot do this at all; a rejection means the
        // ceremony was started and then failed, which the caller may want to show.
        conditional: function (optionsUrl, verifyUrl, extra) {
            var self = this;

            if (!this.conditionalSupported()) {
                return Promise.resolve(null);
            }

            return root.PublicKeyCredential.isConditionalMediationAvailable()
                .then(function (available) {
                    if (!available) {
                        return null;
                    }

                    return transport(optionsUrl, extra || {})
                        .then(function (res) {
                            if (!res.ok) { throw new Error('options_failed'); }
                            return res.json();
                        })
                        .then(function (data) {
                            cancelConditional();
                            pendingConditional = new root.AbortController();

                            return navigator.credentials.get({
                                publicKey: prepareRequestOptions(data.options),
                                mediation: 'conditional',
                                signal: pendingConditional.signal
                            });
                        })
                        .then(function (cred) {
                            pendingConditional = null;

                            // An aborted ceremony resolves with nothing in some browsers rather
                            // than rejecting; there is no assertion to send in that case.
                            if (!cred) {
                                return null;
                            }

                            return transport(verifyUrl, serializeAssertion(cred))
                                .then(function (res) {
                                    return res.json().then(function (body) {
                                        if (!res.ok) {
                                            throw new Error(body.error || 'verify_failed');
                                        }
                                        return body;
                                    });
                                });
                        });
                })
                .catch(function (error) {
                    pendingConditional = null;

                    // Cancelling is not a failure: the person used the password form instead, or
                    // pressed the button, which is exactly what the cancel exists for.
                    if (error && (error.name === 'AbortError' || error.name === 'NotAllowedError')) {
                        return null;
                    }

                    throw error;
                });
        },

        // Stop waiting for a passkey from the autofill list.
        cancelConditional: function () {
            cancelConditional();
        },

        // Assertion ceremony (login / step-up). Resolves with the parsed JSON on
        // success ({status:'ok', redirect?}), rejects on any failure.
        authenticate: function (optionsUrl, verifyUrl, extra) {
            if (!this.supported()) { return Promise.reject(new Error('webauthn_unsupported')); }

            // A conditional ceremony waiting on the username field holds the browser's single
            // outstanding `credentials.get()`. Without this the button below it would be refused.
            cancelConditional();

            return transport(optionsUrl, extra || {})
                .then(function (res) {
                    if (!res.ok) { throw new Error('options_failed'); }
                    return res.json();
                })
                .then(function (data) {
                    return navigator.credentials.get({ publicKey: prepareRequestOptions(data.options) });
                })
                .then(function (cred) {
                    return transport(verifyUrl, serializeAssertion(cred));
                })
                .then(function (res) {
                    return res.json().then(function (body) {
                        if (!res.ok) { throw new Error(body.error || 'verify_failed'); }
                        return body;
                    });
                });
        },

        // Registration ceremony (dashboard). $body is passed to the options call.
        register: function (optionsUrl, registerUrl, body) {
            if (!this.supported()) { return Promise.reject(new Error('webauthn_unsupported')); }
            return transport(optionsUrl, body || {})
                .then(function (res) {
                    if (!res.ok) { throw new Error('options_failed'); }
                    return res.json();
                })
                .then(function (data) {
                    return navigator.credentials.create({ publicKey: prepareCreationOptions(data.options) });
                })
                .then(function (cred) {
                    return transport(registerUrl, serializeAttestation(cred));
                })
                .then(function (res) {
                    return res.json().then(function (b) {
                        if (!res.ok) { throw new Error(b.error || 'register_failed'); }
                        return b;
                    });
                });
        }
    };

    root.PramnosWebAuthn = PramnosWebAuthn;
})();
