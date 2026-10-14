---
use_cases:
  - Caching a site's CSS, JS and images in the browser so repeat visits are cheaper
  - Adding the handlers a push notification needs to reach a person
  - Adding a service worker to a project that was scaffolded without one
  - Working out why a stylesheet change is not showing up for some visitors
  - Removing a service worker from a site that already has one
  - Deciding whether to cache HTML in the browser as well as on the server
---

# Service Worker Guide

`pramnos init --service-worker=y` writes `<web-root>/sw.js` and the two lines that
register it. It caches **static assets in the browser** — stylesheets, scripts, fonts
and images — so a repeat visit pays for the HTML and nothing else.

It is **off by default**, and the rest of this page is mostly about the things it
deliberately will not do.

---

## Why it is opt-in

A service worker is the most persistent thing an application can install on somebody
else's machine. It keeps itself alive across reloads, so a mistake in one is not
corrected by the next deployment the way a mistake in a page is — the fixed page has
to get past the worker first. Shipping that to every scaffolded project by default
would be handing a loaded mechanism to people who did not ask for one.

What makes it safe to say yes to is the boundary below.

---

## What it caches, and what it refuses to

| | |
|---|---|
| Method | `GET` only |
| Origin | same-origin only |
| Paths | anything ending `.css .js .mjs .woff .woff2 .ttf .otf .eot .png .jpg .jpeg .gif .svg .webp .avif .ico` |
| Everything else | **not intercepted at all** — no `respondWith`, so the browser behaves as if there were no worker |

**HTML is never touched.** That is the whole design. Once a worker caches HTML it needs a
list of URLs never to cache — the signed-in pages, the checkout, the profile editor — and
that list is maintained by hand, one line at a time:

```js
if (request.url.includes('/admin')) { return; }
if (request.url.includes('/account/profile')) { return; }
// …one more line for every private page the application ever adds
```

Every page added to the application is a chance to forget a line, and the consequence
is one visitor's personal page stored in another's browser — where nothing on the
server can reach it. It is the same failure the [page
cache](Pramnos_Page_Cache_Guide.md) protects against with its bypass rules, except that
those rules cover a store the application owns and can purge.

A worker that never sees HTML cannot make that mistake, and needs no list.

---

## Two strategies, chosen by whether a URL can change meaning

**Immutable — cache-first, never revalidated.** A new version is a new path, so there
is nothing to check:

- `assets/vendor/<lib>/<version>/…` — the version is in the directory
- `assets/spa/…` — Vite content-hashes every filename

**Everything else — stale-while-revalidate.** The cached copy is served and a fresh one
is fetched behind it. The reader may see one stale paint; the next load is correct.

`assets/css/style.css` is the case that matters: its URL never changes, so cache-first
there would be stale **forever** — and if the stored copy were an error page, a
*Maintenance Mode* response say, the visitor would keep getting it through hard reloads.

Which is why **only a successful response is stored**: `response.ok` and not opaque, so a
redirect, a 404, a 500 and an unreadable cross-origin response are all refused.

!!! note "There is no `Set-Cookie` check, and there cannot be"
    `PageCache::store()` refuses a response carrying `Set-Cookie`, for good reasons. The
    same check here would be theatre: `Set-Cookie` is a forbidden response header, so
    `fetch()` never exposes it and the test would always pass. What makes it unnecessary
    is the path filter — a stylesheet is not answering a particular visitor.

---

## There is no cache version to bump

The usual pattern is a version prefix on the cache name, bumped by hand at deploy time.
This has none, and that is deliberate: a version prefix only purges the caches that carry
it. A worker with several caches, one of them unversioned, keeps the unversioned one
across every bump — the activation sweep deletes caches *not in the current list*, and
an unchanging name is always in it — so one bad response outlives every reload and every
version bump.

Nothing here needs a version. Immutable entries stay valid by definition and everything
else revalidates itself, so a bump would be repairing no state. What bounds the cache
instead is a cap on the number of entries, enforced when writing:

```js
const MAX_ENTRIES = 150;
```

Raise it if the application has more assets than that in play at once. It is FIFO, not
LRU — the oldest writes go first regardless of how often they are read, because a real
LRU needs an access log of its own and a static asset cache does not earn one.

!!! warning "A timer inside a service worker does not run"
    A browser terminates an idle service worker, so a cleanup on a `setInterval` of
    hours essentially never fires. Anything periodic has to happen on an event the
    worker is woken for — which, here, is a cache write.

---

## Where it lives, and why that is not cosmetic

The file is at the **web root**, not under `assets/`. A service worker's default scope
is the directory it is served from, so one at `assets/sw.js` could only ever intercept
requests for `assets/…`. Its own path decides what it is allowed to see.

The registration is built in PHP rather than shipped as a static file, for the same
reason:

```php
navigator.serviceWorker.register('<?php echo sURL; ?>sw.js')
```

An application served from a subdirectory registers at `/sub/sw.js`, and its scope
follows. A hard-coded `/sw.js` would 404 — or, if something answers it, register a
worker scoped above the application.

It is emitted in **two** places, because a project can have both kinds of page:

- the theme footer, for MVC pages — where `Html::render()` stamps the CSP nonce onto it
  like any other inline script;
- the SPA shell, which emits its own HTML and never sees the theme footer. That copy
  carries **no nonce** and cannot: the shell renders nothing through the document layer
  and boots no application, so neither the nonce injection nor the policy applies. It is
  in the same position as the `window.__PRAMNOS__` script already beside it. An
  application putting a nonce policy in front of its SPA has to account for both.

The same reason changes the base URL in the shell's copy:

```php
navigator.serviceWorker.register('<?php echo $siteUrl; ?>sw.js')
```

`sURL` is defined while the application boots, and the shell requires only the
autoloader — the constant does not exist there. `$siteUrl` is the shell's own `getUrl()`
call, made a few lines higher for the API key, and it is the same value, so the
subdirectory case is still handled. If you have customised the shell, keep the
assignment above the registration.

---

## CSP: `worker-src` has to allow it

`worker-src` governs `Worker`, `SharedWorker` **and the service-worker script**. A policy
of `worker-src 'none'` refuses every registration — the `register()` promise rejects and
nothing installs.

The framework's default is `worker-src 'self' blob:`, and it reads the `csp` block like
every directive around it:

```php
// app/app.php — only if the worker is served from somewhere else, which is unusual
'csp' => ['worker-src' => ['https://cdn.example']],
```

`'self'` is the tightest value that works for the service worker: a browser will not
accept a cross-origin service-worker script in the first place. `blob:` is there for
workers a page builds from a Blob — `pf-humancheck.js` builds its solver that way. Neither
gives up much over `'none'`: both need a script already running on your origin, at which
point `script-src 'self'` and the nonce have already been defeated.

**If the worker is not registering, read the console.** The registration reports a
rejected `register()` with `console.warn`, and a CSP refusal names the directive that
refused it. A refused registration is a misconfiguration somebody can fix, so it is
logged rather than swallowed.

Two other reasons registration silently does nothing, neither of them CSP:

- **`navigator.serviceWorker` is undefined outside a secure context.** `https://…` and
  `http://localhost` are secure; `http://192.168.…` and a plain `http://` hostname are
  not, so the guard short-circuits and nothing is logged at all.
- **The scope.** A worker at `/sub/sw.js` controls `/sub/…` and nothing above it, so a
  page at `/` is not controlled even though registration succeeded.

---

## Removing it, or clearing it

Because HTML is never cached, a bad deploy of `sw.js` cannot lock anyone out of the
site — the worst case is a stale stylesheet that fixes itself on the next load. The
recovery paths still exist, because a worker keeps itself alive and "deploy a fix" is
not a recovery mechanism for the worker itself.

From any page on the site:

```js
// Empty the cache, keep the worker
navigator.serviceWorker.controller.postMessage({command: 'purge'});

// Remove the worker and its cache
navigator.serviceWorker.controller.postMessage({command: 'unregister'});
```

Or, without the worker's cooperation:

```js
navigator.serviceWorker.getRegistrations().then(rs => rs.forEach(r => r.unregister()));
```

To stop shipping it, delete `<web-root>/sw.js` and the registration lines. Note that
deleting the file alone is **not** enough for visitors who already have it: a 404 on the
worker script causes the browser to unregister it, but only when it next checks — which
is on navigation, and up to 24 hours later. Sending an explicit `unregister` from the
page is what makes it immediate.

---

## Caching HTML as well

Not scaffolded. If you add it, do not start from a **deny-list** of private paths that
caches everything else. Start from an **allow-list** of paths that are genuinely public — the same shape as the page cache's
`onlyPaths` — so that a page nobody thought about is uncached rather than shared. And
remember what the server-side rules cannot do for you: `bypassCookies` protects the
store the application owns, not a copy sitting in somebody's browser, and
`PageCache::purgeUrl()` cannot reach one either.

---

## It also receives push notifications

The scaffolded worker carries the three handlers web push needs — `push`,
`notificationclick` and `pushsubscriptionchange` — because push is delivered *to a
service worker*, and a site without one cannot receive a notification at all.

They cost nothing on a site that never sends one: `push` fires only when something is
pushed. If you wrote your own worker, [the push guide](Pramnos_Push_Guide.md) is where
those handlers are, and `pushsubscriptionchange` is the one worth copying even if you
skip the others — without it, a browser that rotates its keys stops receiving
notifications permanently and nobody is told.

Two payload fields change what they do. `data.ack` is a URL the worker POSTs to as the push
arrives — a receipt, used by sign-in approvals so the waiting page can say "delivered". And
`data.open: true` makes a tap open `url` in a new window instead of focusing a tab that is
already open. The worker's own requests (`push/key`, `push/subscribe`, `push/unsubscribe`) are
resolved against its registration scope, so a site installed in a folder works.

An application's `sw.js` is its own file, and the framework never rewrites it. If yours
lacks these handlers or fields, copy the `Web push` block of
`scaffolding/templates/service-worker.js.stub` over yours.

---

## See also

- [Web Push Guide](Pramnos_Push_Guide.md) — notifications, and what the worker does with them
- [Page Cache Guide](Pramnos_Page_Cache_Guide.md) — the server-side half, including
  [what a hit tells the browser](Pramnos_Page_Cache_Guide.md#what-a-hit-tells-the-browser)
- [Console Commands](Pramnos_Console_Guide.md) — what `init` writes
- [Application Styles](Pramnos_Application_Styles_Guide.md) — the SPA shell
