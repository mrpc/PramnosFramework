/**
 * Browser errors reported by `pf-utils.js` to the address in `<meta name="pf-client-errors">`.
 *
 * What has to hold: an uncaught error and an unhandled rejection are each sent once, as JSON,
 * with where they happened and the page; the same error twice on one page is sent once, and no
 * more than ten are sent in all; a cross-origin "Script error." carries nothing and is not sent;
 * `window.pfReportError()` reports one the page caught; and without the meta tag nothing is sent.
 *
 * Run:
 *   node --test tests/js/pf-client-errors.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript, Event } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-utils.js');

/**
 * A page with pf-utils.js, and every beacon it sends.
 *
 * @param {boolean} withMeta Whether the head names the address
 */
function page(withMeta = true) {
    const { window, document } = createDom({ url: 'https://app.test/orders?id=4' });
    if (withMeta) {
        const meta = document.createElement('meta');
        meta.setAttribute('name', 'pf-client-errors');
        meta.setAttribute('content', '/ClientErrors');
        meta.setAttribute('data-release', 'v1.2.3');
        document.head.appendChild(meta);
    }
    const beacons = [];
    window.Blob = class {
        /** Keep the parts, so the test can read the JSON back. */
        constructor(parts, options) { this.text = parts.join(''); this.type = options.type; }
    };
    window.navigator.sendBeacon = (url, blob) => { beacons.push({ url, type: blob.type, body: JSON.parse(blob.text) }); return true; };
    loadScript(SOURCE, window);

    /** An uncaught error, as the browser dispatches it on window. */
    const error = (message, extra = {}) => {
        const event = new Event('error');
        Object.assign(event, { message, filename: 'https://app.test/assets/js/app.js', lineno: 12, colno: 3, error: { message, stack: 'at f (app.js:12:3)' } }, extra);
        window.dispatchEvent(event);
    };

    return { window, beacons, error };
}

/** An uncaught error is sent as JSON to the address, with where it happened, the page and the release. */
test('an uncaught error is reported', () => {
    // Arrange
    const { beacons, error } = page();

    // Act
    error('x is undefined');

    // Assert
    assert.equal(beacons.length, 1);
    assert.equal(beacons[0].url, '/ClientErrors');
    assert.equal(beacons[0].type, 'application/json');
    assert.deepEqual(beacons[0].body, {
        kind: 'error',
        message: 'x is undefined',
        stack: 'at f (app.js:12:3)',
        source: 'https://app.test/assets/js/app.js',
        line: 12,
        column: 3,
        page: 'https://app.test/orders',
        release: 'v1.2.3',
    });
});

/** A rejection nobody handled is sent too, as kind `rejection`, from its reason. */
test('an unhandled rejection is reported', () => {
    // Arrange
    const { window, beacons } = page();
    const event = new Event('unhandledrejection');
    event.reason = { message: 'fetch failed', stack: 'at load' };

    // Act
    window.dispatchEvent(event);

    // Assert
    assert.equal(beacons[0].body.kind, 'rejection');
    assert.equal(beacons[0].body.message, 'fetch failed');
});

/**
 * The same error twice on one page is one report; ten distinct ones are the most a page sends;
 * a cross-origin "Script error." is never sent.
 */
test('a page sends each error once, ten at most, and no empty ones', () => {
    // Arrange
    const { beacons, error } = page();

    // Act
    error('same');
    error('same');
    error('Script error.');
    for (let i = 0; i < 20; i++) { error('loop ' + i); }

    // Assert
    assert.equal(beacons.filter((b) => b.body.message === 'same').length, 1);
    assert.equal(beacons.filter((b) => b.body.message === 'Script error.').length, 0);
    assert.equal(beacons.length, 10);
});

/** An error the page caught itself is reported with window.pfReportError(). */
test('pfReportError reports a caught error', () => {
    // Arrange
    const { window, beacons } = page();

    // Act
    window.pfReportError({ message: 'caught it', stack: 'at g' });

    // Assert
    assert.equal(beacons[0].body.message, 'caught it');
    assert.equal(beacons[0].body.kind, 'error');
});

/** Without a beacon the report goes by fetch, kept alive past the page. */
test('without sendBeacon the report goes by fetch', () => {
    // Arrange
    const { window, error } = page();
    const fetched = [];
    window.navigator.sendBeacon = () => false;
    window.fetch = (url, init) => { fetched.push({ url, init }); return Promise.resolve({}); };

    // Act
    error('no beacon');

    // Assert
    assert.equal(fetched.length, 1);
    assert.equal(fetched[0].url, '/ClientErrors');
    assert.equal(fetched[0].init.keepalive, true);
    assert.equal(JSON.parse(fetched[0].init.body).message, 'no beacon');
});

/** A page whose head does not name the address sends nothing. */
test('without the meta tag nothing is sent', () => {
    // Arrange
    const { window, beacons, error } = page(false);

    // Act
    error('ignored');

    // Assert
    assert.equal(beacons.length, 0);
    assert.equal(window.pfReportError, undefined);
});
