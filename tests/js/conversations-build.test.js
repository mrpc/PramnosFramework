/**
 * The shipped `scaffolding/assets/js/pf-conversations.js` is built from the sources as they are, and
 * every word the components show is one the server sends translated.
 *
 * What has to hold: the file's first line records the hash of `resources/js/conversations` and the
 * Svelte it was built with, and both match now — a source changed without `npm run
 * build:conversations` fails here, so the shipped client cannot quietly differ from its source; and
 * each `t('…')` the components call is listed in `Client::strings()`, or the reader would see
 * it in English.
 *
 * Run:
 *   node --test tests/js/conversations-build.test.js
 */
'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const ROOT = path.join(__dirname, '..', '..');
const SOURCES = path.join(ROOT, 'resources', 'js', 'conversations');

test('the shipped file was built from these sources and this Svelte', async () => {
    // Arrange
    const { sourceStamp } = await import(pathToFileURL(path.join(SOURCES, 'build.mjs')).href);
    const shipped = fs.readFileSync(path.join(ROOT, 'scaffolding', 'assets', 'js', 'pf-conversations.js'), 'utf8');

    // Act
    const first = shipped.split('\n', 1)[0];

    // Assert
    assert.ok(first.includes(sourceStamp()), 'rebuild with `npm run build:conversations`: ' + first.slice(0, 120));
});

test('every word the components show is sent translated by Client::strings()', () => {
    // Arrange
    const php = fs.readFileSync(path.join(ROOT, 'src', 'Pramnos', 'Conversations', 'Client.php'), 'utf8');
    const listed = new Set([...php.matchAll(/^\s+'((?:[^'\\]|\\.)*)' => t\(/gm)].map((m) => m[1].replace(/\\'/g, "'")));
    const used = new Set();
    for (const name of fs.readdirSync(SOURCES).filter((n) => /\.(svelte|js)$/.test(n))) {
        const source = fs.readFileSync(path.join(SOURCES, name), 'utf8');
        for (const m of source.matchAll(/\bt\(\s*'((?:[^'\\]|\\.)*)'/g)) { used.add(m[1]); }
        for (const m of source.matchAll(/\btn\([^,]+,\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'/g)) { used.add(m[1]); used.add(m[2]); }
        for (const m of source.matchAll(/label: '((?:[^'\\]|\\.)*)'/g)) { used.add(m[1]); }
    }

    // Act
    const missing = [...used].filter((word) => !listed.has(word));

    // Assert
    assert.ok(used.size > 50, 'the words were found at all');
    assert.deepEqual(missing, [], 'add them to Client::strings() and resources/language/el.php');
});
