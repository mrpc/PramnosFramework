/**
 * The translation client as a scaffolded project receives it.
 *
 * `lib/i18n.svelte.js` is the SPA's half of the framework's own catalogue — the same map
 * `Language::_()` reads on the server — and the framework shipped it without ever running
 * it. That is how it came to send a request the API refuses before routing: there is no
 * `apiKey` header, `ApiAuthMiddleware` answers 403, and the module's own `!response.ok`
 * turns that into "no translation available". A product that has never rendered a
 * translated string then looks exactly like one nobody has translated yet — which is why
 * it went unnoticed in every environment from the day it was written.
 *
 * The module is authored for Svelte, so `$state()` is a compiler rune rather than a
 * function. It is unwrapped here into the plain object it compiles to; everything else —
 * the fetch, the headers, the fallbacks — is the shipped code.
 *
 * Run:
 *   node --test tests/js/spa-i18n.test.js
 */
'use strict';

const { test, describe, beforeEach } = require('node:test');
const assert                         = require('node:assert/strict');
const fs                             = require('node:fs');
const os                             = require('node:os');
const path                           = require('node:path');
const { pathToFileURL }              = require('node:url');

const ROOT       = path.join(__dirname, '..', '..');
const API_PREFIX = '/1.0';

/** The stub, rendered and with the rune unwrapped, as an importable module. */
function buildModule() {
    const dir  = fs.mkdtempSync(path.join(os.tmpdir(), 'pf-i18n-'));
    const stub = fs.readFileSync(
        path.join(ROOT, 'scaffolding/templates/spa-i18n.js.stub'),
        'utf8'
    );

    fs.writeFileSync(
        path.join(dir, 'i18n.mjs'),
        stub
            .replaceAll('{{ apiPrefix }}', API_PREFIX)
            .replaceAll('{{ appName }}', 'TestApp')
            // The locale map `init` computes from the project's language files.
            .replaceAll('{{ localeMapJson }}', JSON.stringify({ el: 'el-GR', en: 'en-GB' }))
            // `$state({...})` compiles to a plain reactive object; outside Svelte the
            // object on its own behaves identically for everything asserted here.
            .replace(/\$state\(/g, '(')
    );

    return path.join(dir, 'i18n.mjs');
}

// ─── A browser stub with just enough behaviour ──────────────────────────────

const calls = [];
let answers = [];

globalThis.window = { __PRAMNOS__: { apiKey: 'the-api-key', apiPrefix: API_PREFIX } };

globalThis.fetch = async (url, options = {}) => {
    calls.push({ url, options });

    const next = answers.shift() || { status: 200, body: { success: true, lang: 'el', strings: {}, available: ['el'] } };

    return {
        ok: next.status >= 200 && next.status < 300,
        status: next.status,
        text: async () => JSON.stringify(next.body),
    };
};

describe('the generated translation client', () => {
    let i18n;

    beforeEach(async () => {
        if (!i18n) {
            i18n = await import(pathToFileURL(buildModule()).href);
        }
        calls.length = 0;
        answers      = [];
    });

    /**
     * The request carries the application key.
     *
     * **The assertion this file exists for.** Without it `ApiAuthMiddleware` refuses
     * before the router is reached, so the endpoint's own correctness is irrelevant — and
     * the module reports the 403 as "no translation available", which is the same thing it
     * reports for an installation that ships no translations. One of those is a bug and
     * the other is a Tuesday, and they were indistinguishable.
     */
    test('it sends the apiKey header', async () => {
        answers = [{ status: 200, body: { success: true, lang: 'el', strings: { Save: 'Αποθήκευση' }, available: ['el'] } }];

        assert.equal(await i18n.loadLanguage(), true);
        assert.equal(calls[0].options.headers.apiKey, 'the-api-key');
    });

    /**
     * A named language is requested by name and skips the token.
     *
     * That is the sign-in screen: it renders before anybody is signed in, so there is no
     * preference to look up and no token to present — but it still needs its labels, and
     * therefore still needs the key.
     */
    test('a named language is sent as a parameter, without a token', async () => {
        answers = [{ status: 200, body: { success: true, lang: 'el', strings: {}, available: ['el'] } }];

        await i18n.loadLanguage('el', 'a-token');

        assert.equal(calls[0].url, '/1.0/language?lang=el');
        assert.equal(calls[0].options.headers.Authorization, undefined);
        assert.equal(calls[0].options.headers.apiKey, 'the-api-key');
    });

    /**
     * With no language named, the token goes so the server can read the preference.
     *
     * The endpoint answers the *user's* language rather than the request's, which is the
     * reason it is authenticated at all — a front end that had to name a language would
     * need the preference before it could ask for it.
     */
    test('the token is sent when the server is being asked to choose', async () => {
        answers = [{ status: 200, body: { success: true, lang: 'el', strings: {}, available: ['el'] } }];

        await i18n.loadLanguage(null, 'a-token');

        assert.equal(calls[0].url, '/1.0/language');
        assert.equal(calls[0].options.headers.Authorization, 'Bearer a-token');
    });

    /**
     * A refused request leaves the English fallback in place and says it failed.
     *
     * The behaviour is right and is not being changed — a translation fetch must never
     * break the application. What was wrong is that it was the *only* thing that ever
     * happened. Pinned so the silence stays deliberate.
     */
    test('a refused request falls back to English rather than throwing', async () => {
        answers = [{ status: 403, body: { success: false } }];

        assert.equal(await i18n.loadLanguage(), false);
        assert.equal(i18n.t('Save'), 'Save');
    });

    /**
     * The language it reports is the one the server says it served.
     *
     * It used to default to the literal `english`, which is half of a two-vocabulary bug:
     * `Language::getLanguages()` answers **file names**, so a project shipping `en.php`
     * has `['en']` and nothing anywhere is called `english`. The two agreed only where the
     * file happened to be named that way.
     */
    test('it reports the language the server served, not a literal', async () => {
        answers = [{ status: 200, body: { success: true, lang: 'en', strings: {}, available: ['en'] } }];

        await i18n.loadLanguage();

        assert.equal(i18n.currentLanguage(), 'en');
    });

    /**
     * An installation shipping no language files is answered, not treated as an error.
     *
     * The server sends an empty catalogue and an empty name; the source strings are
     * English and `t()`'s fallback is the key itself, so there is nothing to fail about.
     */
    test('an empty catalogue is a successful answer', async () => {
        answers = [{ status: 200, body: { success: true, lang: '', strings: {}, available: [] } }];

        assert.equal(await i18n.loadLanguage(), true);
        assert.equal(i18n.t('Save'), 'Save');
    });
});
