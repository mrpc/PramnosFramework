<?php

declare(strict_types=1);

namespace Pramnos\Auth\Passkey;

/**
 * The WebAuthn ceremony's JavaScript, and the two shapes it is delivered in.
 *
 * A passkey login is a conversation with the authenticator, not a form post:
 * base64url has to become an `ArrayBuffer` for `navigator.credentials`, and the
 * authenticator's answer has to become base64url again in the exact shape the
 * server's webauthn-lib adapter deserialises. Get one field wrong and the
 * ceremony fails with `authentication_failed`, which says nothing about which
 * field it was.
 *
 * That is worth writing once. The server-rendered themes and a SPA differ in how
 * they *load* code and in how they *authenticate* — not in the ceremony:
 *
 *   - `source()` — the IIFE that attaches `window.PramnosWebAuthn`, served as
 *     `assets/js/pf-webauthn.js` and used by the login and passkey-management
 *     views via a plain `<script>` tag, where an ESM `export` is a syntax error.
 *   - `spaModule()` — the same source with an ESM re-export appended, written
 *     into a SPA project as `lib/webauthn.js`, where `lib/api.js` imports it and
 *     hands it a transport that carries the API key and the bearer token.
 *
 * The transport is the whole difference, and it is a single injected function
 * ({@see \Pramnos\Auth\Controllers\ApiPasskey} for the server half).
 */
final class PasskeyAsset
{
    /** Where the single source lives, relative to this file. */
    private const SOURCE = __DIR__ . '/../../../../scaffolding/assets/js/pf-webauthn.js';

    /**
     * The ceremony, as a browser-ready classic script.
     *
     * @throws \RuntimeException When the asset is missing — an install that
     *                           cannot run the ceremony should say so rather
     *                           than write an empty file a browser silently
     *                           ignores until somebody presses the button.
     */
    public static function source(): string
    {
        $source = @file_get_contents(self::SOURCE);
        if ($source === false) {
            // the asset ships with the framework, so
            // @codeCoverageIgnoreStart
            // reaching this means a broken install, not a branch a test can set
            // up without deleting a file out of vendor/.
            throw new \RuntimeException(
                'The WebAuthn client asset is missing: ' . self::SOURCE
            );
            // @codeCoverageIgnoreEnd
        }

        return $source;
    }

    /**
     * The ceremony as an ES module, for a SPA project's `lib/webauthn.js`.
     *
     * The header is addressed to whoever opens the file expecting to edit it: it
     * is framework code that happens to live in the project, and a local edit is
     * lost the next time the project is resynced.
     *
     * @param string $appName Named in the header so the file says which
     *                        application it was written into.
     */
    public static function spaModule(string $appName = ''): string
    {
        $name = $appName === '' ? 'Pramnos' : $appName;

        $header = "/**\n"
            . " * Passkey (WebAuthn) ceremony for {$name} — FRAMEWORK-OWNED, do not edit.\n"
            . " *\n"
            . " * Generated from the framework's single WebAuthn source, the same bytes the\n"
            . " * server-rendered login page is served. Add missing behaviour there rather\n"
            . " * than here: an edit made in this file is lost on the next resync, and a\n"
            . " * second copy of a ceremony this exact means every fix has to be made twice.\n"
            . " *\n"
            . " * lib/api.js imports this, gives it a transport that carries the API key and\n"
            . " * the bearer token, and exposes loginWithPasskey()/registerPasskey(). Screens\n"
            . " * call those, not this file.\n"
            . " */\n";

        // The IIFE sets window.PramnosWebAuthn; the exports forward to it, so the
        // module has one implementation rather than a copy of it.
        $export = "\n"
            . "/** The ceremony object the IIFE above attached, or null outside a browser. */\n"
            . "function client() {\n"
            . "    const root = typeof window !== 'undefined' ? window : globalThis;\n"
            . "\n"
            . "    return root.PramnosWebAuthn || null;\n"
            . "}\n"
            . "\n"
            . "/**\n"
            . " * Send the ceremony's requests through `fn` instead of a same-origin fetch.\n"
            . " *\n"
            . " * `fn(url, body)` must answer like fetch: a promise for `{ ok, json() }`.\n"
            . " * lib/api.js calls this once, at import time.\n"
            . " *\n"
            . " * @param {function(string, object): Promise<{ok: boolean, json: function(): Promise<object>}>} fn\n"
            . " */\n"
            . "export function setTransport(fn) {\n"
            . "    const webauthn = client();\n"
            . "    if (webauthn) {\n"
            . "        webauthn.setTransport(fn);\n"
            . "    }\n"
            . "}\n"
            . "\n"
            . "/**\n"
            . " * Whether this browser can do WebAuthn at all.\n"
            . " *\n"
            . " * False on http:// (except localhost) and in older browsers, which is why the\n"
            . " * passkey button is rendered conditionally rather than failing when pressed.\n"
            . " *\n"
            . " * @returns {boolean}\n"
            . " */\n"
            . "export function supported() {\n"
            . "    const webauthn = client();\n"
            . "\n"
            . "    return webauthn ? webauthn.supported() : false;\n"
            . "}\n"
            . "\n"
            . "/**\n"
            . " * Whether a passkey can be offered inside the username autofill.\n"
            . " *\n"
            . " * @returns {Promise<boolean>}\n"
            . " */\n"
            . "export function conditionalSupported() {\n"
            . "    const webauthn = client();\n"
            . "\n"
            . "    return webauthn ? webauthn.conditionalSupported() : Promise.resolve(false);\n"
            . "}\n"
            . "\n"
            . "/**\n"
            . " * Run the assertion ceremony: options → authenticator → verify.\n"
            . " *\n"
            . " * @param {string} optionsUrl\n"
            . " * @param {string} verifyUrl\n"
            . " * @param {object} [extra]  Posted with the options request (e.g. { username })\n"
            . " * @returns {Promise<object>} The verify endpoint's parsed body\n"
            . " */\n"
            . "export function authenticate(optionsUrl, verifyUrl, extra = {}) {\n"
            . "    const webauthn = client();\n"
            . "    if (!webauthn) {\n"
            . "        return Promise.reject(new Error('webauthn_unsupported'));\n"
            . "    }\n"
            . "\n"
            . "    return webauthn.authenticate(optionsUrl, verifyUrl, extra);\n"
            . "}\n"
            . "\n"
            . "/**\n"
            . " * Run the attestation ceremony: options → authenticator → register.\n"
            . " *\n"
            . " * @param {string} optionsUrl\n"
            . " * @param {string} registerUrl\n"
            . " * @param {object} [body]   Posted with the options request (e.g. { label })\n"
            . " * @returns {Promise<object>} The register endpoint's parsed body\n"
            . " */\n"
            . "export function register(optionsUrl, registerUrl, body = {}) {\n"
            . "    const webauthn = client();\n"
            . "    if (!webauthn) {\n"
            . "        return Promise.reject(new Error('webauthn_unsupported'));\n"
            . "    }\n"
            . "\n"
            . "    return webauthn.register(optionsUrl, registerUrl, body);\n"
            . "}\n"
            . "\n"
            . "/**\n"
            . " * Wait for a passkey chosen from the username autofill.\n"
            . " *\n"
            . " * Resolves when somebody picks one, with null when the wait was cancelled —\n"
            . " * which is what happens when they use the password form instead.\n"
            . " *\n"
            . " * @param {string} optionsUrl\n"
            . " * @param {string} verifyUrl\n"
            . " * @param {object} [extra]\n"
            . " * @returns {Promise<object|null>}\n"
            . " */\n"
            . "export function conditional(optionsUrl, verifyUrl, extra = {}) {\n"
            . "    const webauthn = client();\n"
            . "    if (!webauthn) {\n"
            . "        return Promise.resolve(null);\n"
            . "    }\n"
            . "\n"
            . "    return webauthn.conditional(optionsUrl, verifyUrl, extra);\n"
            . "}\n"
            . "\n"
            . "/** Stop waiting for a passkey from the autofill list. */\n"
            . "export function cancelConditional() {\n"
            . "    const webauthn = client();\n"
            . "    if (webauthn) {\n"
            . "        webauthn.cancelConditional();\n"
            . "    }\n"
            . "}\n";

        return $header . self::source() . $export;
    }
}
