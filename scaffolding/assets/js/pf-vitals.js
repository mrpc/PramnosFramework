/*
 * Core Web Vitals from real visitors: TTFB, FCP, LCP, INP and CLS, as the web-vitals library
 * measures them, sent once the page is hidden to `<meta name="pf-vitals" content="…">`.
 *
 * Only a share of page views is measured — `data-sample` on the meta, decided once per view — and
 * the page is not slowed for it: the library observes, and the report goes with sendBeacon as the
 * page is left. `\Pramnos\Logs\ClientVitals::tag()` prints the meta and both scripts.
 *
 * Each report says what its numbers depend on, so they can be read apart:
 *   path        the page — grouped on the server by `vitals.groups`
 *   device      mobile or desktop, by the viewport
 *   delivery    the navigation's deliveryType: from the cache, a prefetch, or the network
 *   worker      whether a service worker controls the page, could, or cannot
 *   workerMs    fetchStart − workerStart: the worker's start, when one ran
 *   source      the worker's own Server-Timing `sw` description: cache, network or preload
 * and each metric its navigationType: navigate, reload, back-forward-cache, prerender, restore.
 */
(function () {
    'use strict';

    var meta = document.querySelector('meta[name="pf-vitals"]');
    var vitals = window.webVitals;

    if (!meta || !vitals) {
        return;
    }

    var sample = parseFloat(meta.getAttribute('data-sample') || '0');

    if (!(Math.random() < sample)) {
        return;
    }

    var endpoint = meta.getAttribute('content');
    var nav = (window.performance && performance.getEntriesByType && performance.getEntriesByType('navigation')[0]) || {};
    var source = '';

    (nav.serverTiming || []).forEach(function (entry) {
        if (entry.name === 'sw') {
            source = entry.description || '';
        }
    });

    var shared = {
        path: window.location.pathname,
        device: window.matchMedia && window.matchMedia('(max-width: 767px)').matches ? 'mobile' : 'desktop',
        delivery: nav.deliveryType || '',
        worker: !('serviceWorker' in navigator) ? 'unsupported' : (navigator.serviceWorker.controller ? 'controlled' : 'supported'),
        workerMs: nav.workerStart > 0 ? Math.round(nav.fetchStart - nav.workerStart) : null,
        source: source
    };
    var queue = [];

    function add(metric) {
        queue.push({
            name: metric.name,
            value: Math.round(metric.value * 1000) / 1000,
            rating: metric.rating,
            navigation: metric.navigationType
        });
    }

    /*
     * Sent when the page is hidden, which is the last moment a browser promises: unload is not
     * fired on mobile, and INP and CLS are only final then. A view that comes back from the
     * background and is hidden again sends what it measured since.
     */
    function flush() {
        if (queue.length === 0) {
            return;
        }

        var body = JSON.stringify(Object.assign({ metrics: queue.splice(0) }, shared));

        if (navigator.sendBeacon && navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' }))) {
            return;
        }

        if (window.fetch) {
            window.fetch(endpoint, {
                method: 'POST',
                body: body,
                keepalive: true,
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' }
            }).catch(function () {});
        }
    }

    ['onTTFB', 'onFCP', 'onLCP', 'onINP', 'onCLS'].forEach(function (name) {
        if (typeof vitals[name] === 'function') {
            vitals[name](add);
        }
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            flush();
        }
    });
    window.addEventListener('pagehide', flush);
}());
