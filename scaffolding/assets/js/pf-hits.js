/*
 * The site's own traffic: a page view when the page is shown, and the clicks out of it, sent to
 * `<meta name="pf-hits" content="…">` with sendBeacon. `\Pramnos\Visits\Visits::tag()` prints the
 * meta, this script and a <noscript> image for a page without JavaScript.
 *
 * Each hit: k (view, click), u (the page), r (its referrer), t (a click's link out), s (the
 * screen), p (it was prerendered). A prerendered page is counted when it is shown, not when it was
 * made. Clicks out are queued and sent as the page is left, or twenty at a time; nothing waits
 * on them.
 */
(function () {
    'use strict';

    var meta = document.querySelector('meta[name="pf-hits"]');

    if (!meta || !navigator.sendBeacon) {
        return;
    }

    var endpoint = meta.getAttribute('content');
    var queue = [];
    var screenSize = window.screen ? screen.width + 'x' + screen.height : '';

    function send(hits) {
        if (hits.length) {
            navigator.sendBeacon(endpoint, new Blob([JSON.stringify({ hits: hits })], { type: 'application/json' }));
        }
    }

    function flush() {
        send(queue.splice(0, queue.length));
    }

    function view(prerendered) {
        send([{ k: 'view', u: location.href, r: document.referrer, s: screenSize, p: prerendered ? 1 : 0 }]);
    }

    if (document.prerendering) {
        document.addEventListener('prerenderingchange', function () { view(true); }, { once: true });
    } else {
        view(false);
    }

    document.addEventListener('click', function (event) {
        var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
        var to;

        try {
            to = link ? new URL(link.getAttribute('href'), location.href) : null;
        } catch (e) {
            to = null;
        }
        if (!to || to.host === new URL(location.href).host || !/^https?:$/.test(to.protocol)) {
            return;
        }
        queue.push({ k: 'click', u: location.href, t: to.href });
        if (queue.length >= 20) {
            flush();
        }
    }, true);

    addEventListener('pagehide', flush);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            flush();
        }
    });
}());
