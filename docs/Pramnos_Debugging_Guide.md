# Debugging: the toolbar, the requests after it, and live servers

The debug toolbar collects what one request did — its queries, timings, views,
logs, session and route — and shows it at the foot of the page. This guide
covers the two things that are not obvious: how to see the requests a page makes
*after* it has rendered, and how to open the toolbar on a server where it is off.

## Turning it on in development

Any of these is enough:

```
APP_DEBUG=1            # environment
development=true       # application setting
debug=true             # application setting
```

Or list `debug` in the application's `features`. The toolbar then appears on
every HTML page, and API responses carry their debug data as a `_debug` key.

## Requests made after the page renders

Everything in the toolbar's other tabs describes a single request: the one that
built the page. But most pages are not finished when they render. A datatable
pages and sorts, a form saves, a widget polls, a single-page application does
nothing *but* talk to the server after the first load — and those requests were
invisible.

The **ajax** tab shows them. It needs no setup: the toolbar wraps `fetch` and
`XMLHttpRequest`, and every call the page makes appears as a row with its
method, URL, status, server time and query count. Click a row to see the
queries.

```
ajax 12
 ▸ GET   /api/meters?page=2   200   82ms   9
 ▾ POST  /orders/save         204   15ms   2
     client 21ms · server 15ms · 1.8MB · orders/save
     0.4ms   SELECT * FROM orders WHERE id = ?
     1.1ms   UPDATE orders SET status = ? WHERE id = ?
```

### How the data gets there

Two channels, because one is not enough:

- **`_debug` in the body.** Any JSON **object** response carries the full
  payload — timings, memory, and the queries with their statements. This is
  attached centrally, so it covers API endpoints, datatable endpoints and
  controllers that echo their own JSON alike.
- **`X-Pramnos-Debug` and `Server-Timing` headers.** A `204`, a redirect, an
  HTML fragment and a top-level JSON array have nowhere to put a `_debug` key.
  The headers carry a summary — time, memory, query *count*, route — for exactly
  those. `Server-Timing` also shows up in the browser's own network panel with
  no toolbar involved.

The headers never carry query text. A header is written to the web server's
access log and to every proxy in front of it, and statements there would put
customer data in files nobody treats as sensitive.

An annotated response also declares `Vary: Cookie`, and `Cache-Control:
no-store, private` whenever the grant came from a token. On a live server the
toolbar is open for one browser while every other visitor gets the same URLs,
and a shared cache in front of the application does not know the difference — a
cached JSON body with a `_debug` key would hand that browser's query log to
whoever asked for the same URL next.

The wrapper follows three rules, because it runs inside your application: the
original `fetch`/`XMLHttpRequest` is always called and its result returned
unchanged, response bodies are only read through `clone()` so your code still
gets to consume them, and every part of it is wrapped in `try`/`catch`. A
toolbar that breaks the page it is measuring is worse than no toolbar.

### From your own code

```php
use Pramnos\Debug\ApiDebugPayload;

if (ApiDebugPayload::isEnabled()) {
    $response['_debug'] = ApiDebugPayload::build();
}

$body = ApiDebugPayload::attachTo($body);   // JSON objects only; no-op otherwise
ApiDebugPayload::sendHeaders();             // Server-Timing + X-Pramnos-Debug
```

`isEnabled()` asks the toolbar whether it has collectors, rather than re-reading
`APP_DEBUG` — one definition of "development" instead of two that can drift.

## In a single-page application

A SPA does not get the HTML toolbar, and the reason is worth stating precisely,
because the usual guess is wrong. It is not that the numbers would freeze on the
shell: the `ajax` tab wraps `fetch`/`XMLHttpRequest` and keeps updating for as
long as the page lives. It is that **the SPA shell does not boot the framework**
— `www/spa.php` requires only the autoloader, so no middleware ever sees its
HTML and there is nothing to inject into.

**The framework ships the panel for that case too.** `php pramnos init` with a
SPA style writes `lib/debug.js` into the front-end sources (`frontend/lib/` for
the Vite stacks, `www/assets/js/lib/` for the build-less one) and wires it into
`lib/api.js`:

```js
import { record as recordDebug } from './debug.js';

recordDebug(method, path, response.status, payload && payload._debug, { ms, body });
```

It is the same toolbar: bar along the bottom, the same tabs and tables, copy
buttons, the last 50 requests with their statements, secret-looking values
masked. Nothing in it is application-specific, so **do not write your own** —
if a field is missing, add it there or report it upstream.

In production nothing attaches `_debug`, so `record()` never has anything to
show: no data, no DOM, no panel. That is why the file ships unconditionally
rather than being imported behind a development flag.

### An older project without the panel

Projects scaffolded before the panel existed have no `lib/debug.js`. Copy it in
from the framework rather than by hand:

```bash
./pramnos project:resync --spa --all     # add it
./pramnos project:resync --spa           # refresh an existing one
./pramnos project:resync --spa --dry-run # preview
```

The command reads `app_style`/`spa_stack` from `app/app.php`, so the file lands
in the directory this project's SPA actually loads from. If `lib/api.js` never
calls `recordDebug`, it says so and prints the two lines to add — that file is
yours and is not rewritten, because a panel nothing feeds is silent in exactly
the way a missing panel is.

## Opening the toolbar on a live server

The toolbar is off in production, and it should be. But the bugs that deserve a
toolbar are mostly the ones that only happen there, on live data and live
traffic, and reproducing those elsewhere is the hard part of fixing them.

`debug:token` issues a grant for **one browser**, with an expiry:

```
$ php pramnos debug:token --ttl=2h

  https://example.com/?_debug=1786237200.9f86d081898637d1…

  Valid until 2026-08-12 16:40:00 (2h)
  Open it once; the toolbar then follows that browser, including its XHR calls.
  End it early with ?_debug=off

  The toolbar exposes queries, logs and session keys. Treat this link as a credential.
```

Open the link. A cookie is set, the toolbar appears, and it keeps appearing on
every page — and on every request those pages make — until the token expires.
Nobody else's requests are affected.

`--ttl` accepts `90` (seconds), `30m`, `2h`, `1d`. Twelve hours is the ceiling: a
debug token that lasts a month is a backdoor with a friendly name.

### What the token is

`<expiry>.<hmac>` — the expiry as a unix timestamp, and an HMAC-SHA256 of it
under the application key. That means:

- **No storage.** Nothing to create, expire or clean up.
- **It cannot be extended.** The expiry is what is signed, so editing it breaks
  the signature.
- **Rotating `APP_KEY` revokes every outstanding token**, immediately.
- **Comparison is `hash_equals()`**, so a wrong token cannot be found one byte
  at a time.

Set `debug_token_secret` to sign with something other than `APP_KEY` — for an
installation that wants to hand out debug access without sharing the key
everything else uses.

### With no application key, nothing is granted

There is nothing to sign with, so `debug:token` refuses and every check returns
false. There is deliberately no fallback secret: a predictable one here would
hand the query log of a live server to anyone who read the source. Run
`php pramnos key:generate` first.

### Why a cookie rather than the session

Service providers boot before `Application::init()` starts the session, so at the
moment the toolbar has to decide whether to exist there is no session to ask.
`$_COOKIE` is already populated.

It has a second benefit that matters more: the grant travels with every later
request on its own, including the XHR and fetch calls a page makes after it has
rendered. That is what makes the ajax tab work on a live server at all.

The cookie is `HttpOnly` (no script needs to read it), `Secure` whenever the
request arrived over HTTPS — including via `X-Forwarded-Proto`, since a live
installation usually terminates TLS at a load balancer — and `SameSite=Lax`.

### What a grant does not do

It opens the **toolbar**. It does not turn the server into a development one:
`isDebugMode()` still says false, so error display, error pages and every other
production behaviour are unchanged. That separation is deliberate — one person
gets to watch, and nobody gets a stack trace on a public page.

### The token appears in your access logs

Stated plainly, because it is the one weakness of putting a credential in a URL:
the redeeming request has the token in its query string, so it is written to the
web server's access log, and to the log of every proxy in front of it. Anyone
who can read those logs can reuse the token until it expires.

There is no way around that for a link you can paste into a browser — the
mitigation is the expiry, which is why the ceiling is twelve hours and why the
default is one. Use the shortest `--ttl` that does the job, and `?_debug=off`
when you are finished rather than leaving it to run out.

The cookie set from it is `HttpOnly`, so the token is not readable by scripts
after that first request.

## Security checklist before using this in production

- `APP_KEY` is set and is not shared with anything public.
- The link is treated as a credential — not pasted into a ticket, a chat channel
  or a screenshot.
- The shortest `--ttl` that does the job.
- `?_debug=off` when finished, rather than waiting for the expiry.
- Rotate `APP_KEY` if a link is ever exposed; it revokes every outstanding token.
- Remember the redeeming URL is in your access logs, with the token in it.
