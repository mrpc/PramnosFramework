/*!
 * pf-webauthn.js — minimal, dependency-free WebAuthn (passkey) browser glue for
 * PramnosFramework auth servers.
 *
 * Exposes window.PramnosWebAuthn with:
 *   supported()                        → boolean
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

    var PramnosWebAuthn = {
        supported: function () {
            return typeof window.PublicKeyCredential !== 'undefined'
                && typeof navigator.credentials !== 'undefined';
        },

        // Assertion ceremony (login / step-up). Resolves with the parsed JSON on
        // success ({status:'ok', redirect?}), rejects on any failure.
        authenticate: function (optionsUrl, verifyUrl, extra) {
            if (!this.supported()) { return Promise.reject(new Error('webauthn_unsupported')); }
            return postJson(optionsUrl, extra || {})
                .then(function (res) {
                    if (!res.ok) { throw new Error('options_failed'); }
                    return res.json();
                })
                .then(function (data) {
                    return navigator.credentials.get({ publicKey: prepareRequestOptions(data.options) });
                })
                .then(function (cred) {
                    return postJson(verifyUrl, serializeAssertion(cred));
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
            return postJson(optionsUrl, body || {})
                .then(function (res) {
                    if (!res.ok) { throw new Error('options_failed'); }
                    return res.json();
                })
                .then(function (data) {
                    return navigator.credentials.create({ publicKey: prepareCreationOptions(data.options) });
                })
                .then(function (cred) {
                    return postJson(registerUrl, serializeAttestation(cred));
                })
                .then(function (res) {
                    return res.json().then(function (b) {
                        if (!res.ok) { throw new Error(b.error || 'register_failed'); }
                        return b;
                    });
                });
        }
    };

    window.PramnosWebAuthn = PramnosWebAuthn;
})();
