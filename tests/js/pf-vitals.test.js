/**
 * `pf-vitals.js`: Core Web Vitals from real visitors, sampled, sent as the page is hidden.
 *
 * What has to hold: nothing is measured without the meta, the library, or a sample that picks
 * this view; a picked view sends every metric web-vitals reported, with what they depend on — the
 * path, the device, the navigation's delivery, the service worker's state and start, its
 * Server-Timing — once, with sendBeacon when the page is hidden, and with fetch(keepalive) where
 * sendBeacon refuses.
 *
 * Run:
 *   node --test tests/js/pf-vitals.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript, Event } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-vitals.js');

/**
 * A page with the meta (unless `meta` is false), a fake web-vitals that reports `metrics`, a
 * navigation entry, and every beacon and fetch recorded.
 */
function page({ sample = '1', meta = true, library = true, beacon = true, controlled = true, random = 0.5 } = {}) {
    const { window, document } = createDom({ url: 'https://site.test/el/jobs/42' });
    if (meta) {
        const tag = document.createElement('meta');
        tag.setAttribute('name', 'pf-vitals');
        tag.setAttribute('content', 'https://site.test/ClientVitals');
        tag.setAttribute('data-sample', sample);
        document.head.appendChild(tag);
    }
    const beacons = [];
    const fetched = [];
    window.navigator.sendBeacon = (url, blob) => { beacons.push({ url, blob }); return beacon; };
    window.navigator.serviceWorker = { controller: controlled ? {} : null };
    window.fetch = (url, init) => { fetched.push({ url, init }); return Promise.resolve({}); };
    window.Blob = class { constructor(parts, options) { this.text = parts.join(''); this.type = options.type; } };
    window.matchMedia = () => ({ matches: true });
    window.performance = { getEntriesByType: () => [{ deliveryType: 'navigational-prefetch', workerStart: 100, fetchStart: 112.4, serverTiming: [{ name: 'sw', description: 'preload' }] }] };
    window.Math = Object.create(Math, { random: { value: () => random } });
    Object.defineProperty(document, 'visibilityState', { value: 'visible', writable: true, configurable: true });
    if (library) {
        window.webVitals = {};
        for (const [name, value] of [['TTFB', 80.12345], ['LCP', 2100], ['CLS', 0.031]]) {
            window.webVitals['on' + name] = (report) => report({ name, value, rating: 'good', navigationType: 'navigate' });
        }
    }

    loadScript(SOURCE, window);

    const hide = () => {
        document.visibilityState = 'hidden';
        document._fire(new Event('visibilitychange'));
    };

    return { window, beacons, fetched, hide };
}

test('a picked view sends every metric with what it depends on, once, as the page is hidden', () => {
    // Arrange
    const { beacons, hide, window } = page();

    // Act — hidden, then left: the second has nothing new to send
    hide();
    window._fire(new Event('pagehide'));

    // Assert
    assert.equal(beacons.length, 1);
    assert.equal(beacons[0].url, 'https://site.test/ClientVitals');
    assert.equal(beacons[0].blob.type, 'application/json');
    const sent = JSON.parse(beacons[0].blob.text);
    assert.deepEqual(sent.metrics.map((m) => m.name), ['TTFB', 'LCP', 'CLS']);
    assert.equal(sent.metrics[0].value, 80.123, 'rounded to a thousandth');
    assert.equal(sent.metrics[0].navigation, 'navigate');
    assert.deepEqual(
        { path: sent.path, device: sent.device, delivery: sent.delivery, worker: sent.worker, workerMs: sent.workerMs, source: sent.source },
        { path: '/el/jobs/42', device: 'mobile', delivery: 'navigational-prefetch', worker: 'controlled', workerMs: 12, source: 'preload' }
    );
});

test('where sendBeacon refuses, fetch with keepalive carries it', () => {
    // Arrange
    const { beacons, fetched, hide } = page({ beacon: false, controlled: false });

    // Act
    hide();

    // Assert
    assert.equal(beacons.length, 1, 'tried first');
    assert.equal(fetched.length, 1);
    assert.equal(fetched[0].init.keepalive, true);
    assert.equal(JSON.parse(fetched[0].init.body).worker, 'supported');
});

test('nothing is measured without the meta, the library, or a sample that picks this view', () => {
    // Arrange
    const views = [page({ meta: false }), page({ library: false }), page({ sample: '0' }), page({ sample: '0.1', random: 0.5 })];

    // Act
    views.forEach((view) => view.hide());

    // Assert
    assert.deepEqual(views.map((view) => view.beacons.length + view.fetched.length), [0, 0, 0, 0]);
});
