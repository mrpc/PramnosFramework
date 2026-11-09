/**
 * `pf-hits.js`: the site's own page views and clicks out, sent with sendBeacon.
 *
 * What has to hold: nothing without the meta; a page view sent once when the page is shown — a
 * prerendered page only when it is shown, flagged; a click out queued and sent as the page is
 * left, a click inside the site or to a non-web address not at all.
 *
 * Run:
 *   node --test tests/js/pf-hits.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript, Event } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-hits.js');

/** A page with the meta (unless `meta` is false), links in and out, and every beacon recorded. */
function page({ meta = true, prerendering = false } = {}) {
    const { window, document } = createDom({ url: 'https://site.test/el/jobs/42?utm_source=x' });
    if (meta) {
        const tag = document.createElement('meta');
        tag.setAttribute('name', 'pf-hits');
        tag.setAttribute('content', 'https://site.test/Hits');
        document.head.appendChild(tag);
    }
    const links = {};
    for (const [name, href] of [['out', 'https://elsewhere.test/a?b=1'], ['in', '/el/about'], ['mail', 'mailto:a@b.test']]) {
        links[name] = document.createElement('a');
        links[name].setAttribute('href', href);
        document.body.appendChild(links[name]);
    }
    const beacons = [];
    window.navigator.sendBeacon = (url, blob) => { beacons.push({ url, hits: JSON.parse(blob.text).hits, type: blob.type }); return true; };
    window.Blob = class { constructor(parts, options) { this.text = parts.join(''); this.type = options.type; } };
    window.screen = { width: 390, height: 844 };
    document.prerendering = prerendering;
    Object.defineProperty(document, 'visibilityState', { value: 'visible', writable: true, configurable: true });

    loadScript(SOURCE, window);

    return { window, document, beacons, links };
}

test('a page view is sent once, as the page is shown, with the screen', () => {
    // Arrange / Act
    const { beacons } = page();

    // Assert
    assert.equal(beacons.length, 1);
    assert.equal(beacons[0].url, 'https://site.test/Hits');
    assert.equal(beacons[0].type, 'application/json');
    assert.deepEqual([beacons[0].hits[0].k, beacons[0].hits[0].u, beacons[0].hits[0].s, beacons[0].hits[0].p],
        ['view', 'https://site.test/el/jobs/42?utm_source=x', '390x844', 0]);
});

test('a prerendered page is counted when it is shown, flagged', () => {
    // Arrange
    const { beacons, document } = page({ prerendering: true });
    const before = beacons.length;

    // Act
    document._fire(new Event('prerenderingchange'));

    // Assert
    assert.equal(before, 0, 'not while it is only made');
    assert.equal(beacons.length, 1);
    assert.equal(beacons[0].hits[0].p, 1);
});

test('a click out is queued and sent as the page is left; one inside the site or to mail is not', () => {
    // Arrange
    const { beacons, links, window } = page();

    // Act
    links.in.click();
    links.mail.click();
    links.out.click();
    const queued = beacons.length;
    window._fire(new Event('pagehide'));

    // Assert
    assert.equal(queued, 1, 'only the view so far: the click waits');
    assert.equal(beacons.length, 2);
    assert.deepEqual(beacons[1].hits, [{ k: 'click', u: 'https://site.test/el/jobs/42?utm_source=x', t: 'https://elsewhere.test/a?b=1' }]);
});

test('nothing without the meta', () => {
    // Arrange / Act
    const { beacons, links, window } = page({ meta: false });
    links.out.click();
    window._fire(new Event('pagehide'));

    // Assert
    assert.equal(beacons.length, 0);
});
