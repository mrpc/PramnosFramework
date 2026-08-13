---
use_cases:
  - Finding out what queries, timings or exceptions a request produced
  - Debugging the requests a page makes after it renders (XHR/fetch)
  - Debugging a single-page application, where the HTML toolbar cannot be injected
  - Opening the toolbar for one browser on a live server
  - Attaching debug data to your own JSON responses
  - Finding out why a request failed when its response carried nothing back
---

# Debugging: the toolbar, the requests after it, and live servers

The debug toolbar collects what a request did — its queries, timings, views,
logs, session and route — and shows it at the foot of the page. This guide
covers the two things that are not obvious: how to see the requests a page makes
*after* it has rendered, and how to open the toolbar on a server where it is off.

**One toolbar, two deliveries.** A server-rendered page and a single-page
application get the same toolbar, from one source
(`src/Pramnos/Debug/assets/debugbar.js`, owned by `Pramnos\Debug\DebugBarAsset`).
The difference is only how it arrives:

| | Server-rendered page | SPA |
|---|---|---|
| How it gets there | `DebugBar::render()` inlines the script before `</body>` | scaffolded as `lib/debug.js`, an ES module |
| DevPanel link | shown | absent — `/devpanel` is a server-rendered page behind MVC routing, and a SPA project's server has none |
| First request | seeded from a hidden `<div id="pramnos-debug-data">` | the first API call |
| Later requests | `fetch`/`XMLHttpRequest` are wrapped automatically | `lib/api.js` calls `record()` |

Everything below the delivery — the tabs, the request list, hiding the bar — is
the same code and behaves identically.

## Turning it on in development

Any of these is enough:

```
APP_DEBUG=1            # environment
development=true       # application setting
debug=true             # application setting
```

Or list `debug` in the application's `features`. The toolbar then appears on
every HTML page, and API responses carry their debug data as a `_debug` key.

## Getting it out of the way

Clicking a tab opens its panel; clicking the same tab again closes it. The `✕` at
the right-hand end hides the **whole bar**, leaving a small `⚙` handle in the
bottom-right corner to bring it back. The page's bottom padding is released with
the bar, so nothing leaves a gap behind.

The choice is remembered in `localStorage` under `pramnos.debugbar.hidden`, and
is shared by the server-rendered toolbar and the SPA panel — hiding it on one
page and having it return on the next is indistinguishable from a button that
does not work.

## Requests made after the page renders

Most pages are not finished when they render. A datatable pages and sorts, a
form saves, a widget polls, a single-page application does nothing *but* talk to
the server after the first load — and those requests were invisible.

The **requests** tab is the list of everything this page has done, the page's own
request included, newest first. It needs no setup on a server-rendered page: the
toolbar wraps `fetch` and `XMLHttpRequest` as it boots.

```
requests 12   SQL 9   Time   Route   Session   Logs        82ms server · 1.8MB
 13:42:07.918  GET   /api/meters?page=2   200   82ms   9
 13:42:03.114  POST  /orders/save         204   15ms   2
 13:41:58.002  GET   /dashboard (page)    200   61ms   7
```

Clicking a request **switches every other tab to it**, leaving the tab you have
open where it is. That is the part worth knowing: the tabs are not "the page" and
"the ajax calls" — they are whichever request is selected. Open SQL, then click
through the requests, and you are reading the same tab for each of them in turn.

Clicking the selected request again releases it, as does the `✕` on the chip
naming it in the info strip — either way the toolbar goes back to its default.

**Before you pick anything**, the toolbar shows:

- on a server-rendered page, **the page's own request** — not the newest one. A
  datatable fetches its rows the moment it renders, and following the newest
  request meant a page that had just rendered a template reported `Views 0`,
  which was true of a JSON call nobody had asked about;
- in a SPA, **the newest request**, because there is no page request to show;
- in **Logs** and **Exceptions**, everything across every request so far, with a
  column naming the request each line came from. Those two are streams — a log
  line happens at a moment, and which request produced it is a detail of it. An
  error logged by a background call would otherwise be invisible while some other
  request was in view.

**What the page sent** is shown above whichever tab is open, collapsed, for any
request that had a body — it belongs to the request rather than to a collector,
and "what did I send" is the first question when a call comes back wrong. A
form-urlencoded body is decoded into the structure it encodes, so a datatables
request reads as `columns[0][data]: "userid"` rather than as
`columns%5B0%5D%5Bdata%5D=userid`.

The body never leaves the browser: it is what the page just sent, shown back to
whoever sent it, and nothing is added to the request to produce it. Secret-looking
values are masked anyway — this panel gets screenshotted, and a password in a bug
report is a password that has to be changed.

Two limits keep it from becoming the reason a page feels slow. Bodies are kept up
to **8KB** and say so when cut, because holding fifty file uploads is how a
debugging aid runs a tab out of memory. Above **2KB** a body is shown as it was
sent rather than laid out — pretty-printing means parsing, re-serialising and
walking every key to mask it, which is nothing for two kilobytes and real work
for eight. Masking applies at every size. The layout is computed once per request
and reused, so a polling page does not redo it on every render.

### Who this request was

The **Auth** tab answers the question behind "it worked and then it stopped":

- **who** — user id, username and type, or anonymous;
- **what** — `apiKey`, `accessToken`, the deprecated `userAuth` header, or a
  session cookie. An API call that quietly fell back to a cookie is a bug that
  only appears on somebody else's machine;
- **where from** — `accessToken header` or `Authorization: Bearer`, because "the
  token" means a different header to different people and only one is being
  sent;
- **until when** — a countdown to the token's expiry, and the tab turns red once
  it has passed, which explains every refusal above it in the list at once.

**The token itself never travels.** Only identity claims do — `sub`, `iss`,
`aud`, `iat`, `exp`, `nbf`, `jti`, `userid`, `username` — because this payload is
attached to responses and lands in a browser's network log, and a live
credential there would hand out the thing the panel exists to explain. The
signature is dropped and the claims are read without verifying it: this reports
what the client *sent*, and a token the server is about to reject is exactly the
one worth looking at. Whether it was accepted is the status of the request beside
it.

Auth is **state, not history**: it follows the most recent request rather than
the selected one, so signing in updates it without a page refresh. Pick a request
and it shows what was true for that one, saying so.

Both ends of a session are reported as the state they **leave behind**, not the
one they arrived with — otherwise both would read as failures:

- a **login** carries no credential (the token is issued *by* that call), so it
  would say "anonymous" at the exact moment somebody signed in. It reports the
  identity it created, and describes the token it just handed out, countdown
  included;
- a **logout** is authenticated (it has to be, to revoke anything), so it would
  say "signed in as admin" immediately after signing out. It reports that nobody
  is signed in any more.

The **Exceptions** tab turns red and carries a `⚠` as soon as any request has
raised something, so you do not have to open it to find out. A request whose
response could not carry the details — an error page is not a JSON object — still
contributes its count, and the row says the messages are in the application error
log rather than drawing an empty table.

Once you pick a request, all of it — the streams included — narrows to that
request and stays there. A polling widget will not pull the panel out from under
you. The other tabs are never aggregated: Route and Session describe one request,
and a combined SQL table would add up statements from three different calls and
lose which ran what.

Hovering a row reveals a small **id** button that copies the request's id — the
value to paste into a bug report or a log search, and what
`GET /devpanel/logs?request=<id>` takes. It is not a column: sixteen characters
of noise that somebody reads once should not have a permanent sixth of a narrow
table.

A response that carried no debug data still gets a row, with `—` where its
numbers would be. Seeing that a call happened at all is often the finding. A row
is **red across its whole width** when the request went wrong — a 4xx or 5xx, a
network failure with no status at all, or a 200 that quietly raised something,
which is the one nobody would go looking for.

### Calling the API from the toolbar

The **API** tab lists the endpoints in the project's own OpenAPI document, calls
one with the parameters you give it, and shows the answer. Nothing about it is
simulated: the request goes through the same server, the same middleware and the
same authentication as the application's own calls, and it is **recorded in the
requests list like any other** — so Time, SQL, Logs and Domain answer for the call
you just made. A playground that stubbed the request would answer a question
nobody asked.

The endpoint list is free of maintenance because it is not a list: it is the
OpenAPI document (`www/api/openapi.json`, from `npm run docs:build`). An endpoint
appears here because it is documented — and one that is missing from the tab is a
documentation gap the tab has just told you about.

- **Where it sends** — the API prefix the shell injected (`/api/1.0`). The
  document's own `servers` list is deliberately *not* preferred: a generated
  document names production URLs, and sending a development call there because a
  list happened to be ordered that way is the one mistake this tab must not make.
  An absolute URL in that list is ignored for the same reason.
- **Parameters** — the path is an editable field, pre-filled from the document.
  A path with `{braces}` says so; you replace them in place.
- **Bodies** — an operation with a request body gets a textarea, pre-filled from
  the document's `example` when it has one and otherwise from the schema's
  properties, one level deep. A skeleton of a deeply nested schema is harder to
  correct than an empty object is to fill.
- **Credentials** — the application's `apiKey` from the injected configuration,
  cookies (`same-origin`, so a signed-in browser session authenticates the call),
  and a bearer token if the page has stored one. The token is found by key name
  and the panel names **the key, never the value**; one click refuses it, which is
  how "is this endpoint actually public?" gets answered.
- **A request that never arrives** says so, rather than showing an empty result.

### What the browser thinks the world is

The **Client** tab is three sections answering one question three ways, and it is
the only tab that is present on every page — for every other tab, no data means no
tab, but here *absence is the finding*.

**Runtime configuration** — `window.__PRAMNOS__` exactly as the shell injected
it, with secrets masked by key name. This is the thing nobody can see by reading
the source. A page with none says so, and says what that means: on a
server-rendered page there is nothing to inject, while in a SPA it means the shell
did not run and the API client is falling back to its built-in defaults.

**Router** — the current URL, the router base, the route the application resolved
to, and its params. The base is printed next to the path because that pair *is*
the deep-link failure: an application served under `/app` whose router base is
empty resolves every deep link to its home screen, and nothing on screen says why.
When the path does not start with the base, the panel says so in as many words
rather than leaving two values side by side to be compared.

The route name is the application's to know, so the router reports it. A
scaffolded `lib/router.js` already does, on every navigation:

```js
import { reportRoute } from './debug.js';

reportRoute(name, { base: BASE });
```

Without that, the URL and the injected base are still shown — the route *name* is
the only part that goes missing. The shell publishes `routerBase` too, so the
comparison works in a project whose `router.js` predates this.

**Storage** — every key in `localStorage` and `sessionStorage`, with values masked
by key name (`token`, `secret`, `apiKey`, …) and long values truncated. A masked
value still reports its length, because "there is a token and it is 900 characters
long" is usually the whole finding: a stale token survives a deploy, the server
signs with a new key, and every call then fails in a way that looks like a server
problem. A storage area that refuses to be read — private mode, a blocked origin —
costs its own section and nothing else; one that is not there at all is reported
as absent rather than as broken, because those are different findings.

### What the browser threw

The **Exceptions** tab is the server's. Everything after the response arrives —
a screen that throws while rendering the data it just fetched, a promise nobody
caught, an `ApiError` a screen turned into a message — used to leave the panel
looking healthy next to a page that was visibly broken. The **Errors** tab is
that half, and it appears (red, with a `⚠`) only once something has been thrown.

Each row carries the failure's kind, its message, a folded stack, and **the
request it happened after** — the last recorded request at that moment. That is
a heuristic, and the column says `after GET /things` rather than claiming
causation, but it is right nearly every time: the code that threw was reacting to
the call that had just come back. An explicit request beats it whenever the
caller knows better, which the API client does.

Identical failures collapse into one row with a `×4` count. A render loop throws
the same error thousands of times, and fifty copies of it would push every other
finding off the panel.

**What arrives by itself:** `window.onerror` (including a cross-origin script
error, which reports a message and nothing else) and `unhandledrejection`. Both
listeners are passive — they never call `preventDefault()`, so the console still
shows the error and any other handler still runs.

**What you hand over.** The interesting failures are the ones somebody caught,
and a caught error reaches no global handler at all:

```js
import { reportError } from './lib/debug.js';

try {
    await api.post('/things', body);
} catch (error) {
    showMessage(error.message);
    reportError(error, { kind: 'ApiError' });   // then handle it however you like
    throw error;
}
```

A scaffolded project already does this in three places: `lib/api.js` reports
every `ApiError` with the request that produced it, and a network failure — which
has no response, no status and no `_debug`, so nothing else records it at all —
and `App.svelte` wraps each screen in a `<svelte:boundary>` whose `onerror` hands
the failure over while the shell stays on screen. An older project gets the panel
itself from `project:resync --debug-panel`, and these three call sites are a
manual edit, because `lib/api.js` and `App.svelte` are yours.

On a server-rendered page the same function is on the toolbar's own handle:
`window.__pramnosDebugBar.reportError(error)`.

Nothing is drawn until the bar exists. Errors are still collected before that —
in a SPA the first one is often the reason no request was ever made — but a
production page that throws gets no toolbar, because the bar is built only by a
response that carried debug data.

### What this request did to the domain layer

The **Domain** tab is one tab for both ways an application can hold its logic:
a **Models** section for `Pramnos\Application\Model` load/save/delete operations,
and a **Services** section for classes extending `Pramnos\Application\Service`.
The badge counts both, so it reflects what the panel contains.

It is one tab rather than two because a reader asking "what did this request do"
should not have to know which style this project uses — and because a project
built one way would otherwise stare at a permanently empty tab named after the
other. That is not hypothetical: the tab used to be called **Models**, and in a
Services + API + SPA project it was empty for a request that had done all of its
work in services.

The two sections record on different terms:

- **Models** are recorded automatically. `Model::_load()` and `Model::_save()`
  are framework code, so there is nothing to opt into.
- A **service** is recorded because it extends the base class — constructing it
  is enough — and one of its calls gets a *duration* when the method wraps its
  work in `measure()`:

  ```php
  public function overdue(int $days = 30): array
  {
      return $this->measure('overdue', fn(): array => $this->queryBuilder('invoices')
          ->where('paid', 0)
          ->getAll());
  }
  ```

  Both absences are named in the panel, because the fix differs: "no services
  recorded" means the class does not extend `Pramnos\Application\Service`, while
  "no call was timed" means it does, and no method has called `measure()` yet.

The payload key is still `models`, and `services` is its own key beside it —
anything already reading the payload is unaffected. See the
[Application Styles Guide](Pramnos_Application_Styles_Guide.md#the-service-base-class)
for the base class itself.

### Where the time went

The **Time** tab does two subtractions nobody does by hand, from numbers already
collected:

- **client versus server**, as one bar and one sentence:
  `client 210ms = server 42ms + 168ms elsewhere`. The browser measured the call,
  the server reported its own share, and the difference is network, queueing and
  the browser's own work. A call that spends 40ms in PHP and 210ms in the air is
  not a slow endpoint, and optimising the endpoint is the wrong afternoon.
- **SQL as a share of server time**, from the query collector's `total_ms` —
  "24ms of 40ms was the database" is the difference between an indexing problem
  and an application one. It turns red above half.

Either is absent rather than zero when the number is missing: a bar claiming 0ms
of network for a response that only carried a header would be inventing.

The **segments** below them are what the server did, and they now include the
part that happens before any application code runs:

| segment | what it covers |
|---|---|
| `bootstrap` | the whole of `Application::init()` |
| `db-connect`, `providers`, `session` | its phases, individually |
| `routing`, `controller` | the MVC path |
| `middleware`, `action` | the API path — a SPA showed one segment because these did not exist |
| `debugbar` | the toolbar registering its own collectors, named for what it is. It used to be called `boot`, which read as application startup and was not |

Bootstrap cannot be timed the usual way — the collector that would record it is
registered *by* one of those phases — so each phase is measured as it happens and
reported at the end with the times it actually ran at, rather than all of them
stacked at the moment they were handed over.

These also travel in `Server-Timing`, so the browser's own network panel draws
them with no toolbar involved. Only the framework's own phases do: an application
can name a timer anything, and that header is written to every access log between
here and the client.

The **requests** tab draws every request on **one time axis**, oldest first, the
way a network panel does. This is the insight no per-request tab can give: three
calls of 200ms each are a 200ms page if they overlap and a 600ms page if they do
not, and a tab showing each of them separately cannot tell you which you have. A
polling loop looks like a comb; a staircase is a chain of calls waiting on each
other. Failed requests are red there too, and clicking a bar picks that request.

### How the data gets there

Two channels, because one is not enough:

- **`_debug` in the body.** Any JSON **object** response carries the full
  payload — timings, memory, and the queries with their statements. This is
  attached centrally, so it covers API endpoints, datatable endpoints and
  controllers that echo their own JSON alike.
- **`X-Pramnos-Debug` and `Server-Timing` headers.** A `204`, a redirect, an
  HTML fragment and a top-level JSON array have nowhere to put a `_debug` key —
  and neither does an error page, which is what an uncaught exception produces.
  The headers carry a summary — time, memory, query *count*, route, and how many
  errors were raised — for exactly those. `Server-Timing` also shows up in the
  browser's own network panel with no toolbar involved.

A request that **died** is the case where this matters most, and the honest
limit is worth stating: the summary can say *that* something was raised, never
*what*. Messages in a header would be written to every access log between here
and the browser. The count is what sends you to the
application error log — or to the button that fetches it, described below.

The headers never carry query text. A header is written to the web server's
access log and to every proxy in front of it, and statements there would put
customer data in files nobody treats as sensitive.

#### The page outranks the toolbar

Injection is wrapped so that **nothing the toolbar does can cost you the
response**. Anything thrown while rendering gives the body back exactly as it
arrived, and a decorated body shorter than the original is discarded. Both the
middleware and the output-buffer path enforce this.

The rule is written down because of the failure that produced it. With the
toolbar booted, a plain HTML response reached the browser as **200 with
`Content-Length: 0`**: PHP discards an output buffer when its callback throws, so
a 37KB page was produced and then dropped. The response headers said the request
had succeeded — `Server-Timing`, `X-Pramnos-Debug`, a request id — nothing was
logged, because the callback runs at shutdown, and every uptime check passed. To
a person it looked like a broken front-end build. One development environment
lost a day and switched `APP_DEBUG` off.

An empty 200 is the worst shape a failure can take. A missing toolbar is a bug
report; a missing page is a phone call. If the toolbar ever disappears from a page
that is otherwise fine, that is this guard doing its job, and the reason will be
in the application error log rather than on screen.

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

### What a rendered page actually carries

`DebugBar::render()` emits two things and no markup of its own:

```html
<div id="pramnos-debug-data" hidden>{"time":61.2,"queries":{…},"request_method":"GET",…}</div>
<script nonce="…">/* the framework's one toolbar source */</script>
```

The script reads the island as its first request, then wraps the transports. A
`<div hidden>` rather than a `<script type="application/json">` because a data
island inside a script element is a grey area under a strict
Content-Security-Policy, and this has to work on every install.

Under a strict CSP, pass the request's nonce — `DebugBarServiceProvider` already
does, from `Application::$cspNonce`:

```php
echo $bar->render($nonce);
```

One nonce covers both: the script copies it onto the `<style>` element it
injects, so `style-src` is satisfied without a second value to thread through.

### Asking the server for what the response could not carry

Everything above travels *with* the response. A request that **died** has no
response to put anything in: an error page is not a JSON object, so there is no
`_debug` key, and the header that still gets through has room for a count but
never for a message.

So every request gets a name while the toolbar is running:

- `RequestId` issues a 16-character id, and the response announces it in
  `X-Request-Id` and inside the debug summary;
- `Logger` writes that id on every line it logs during the request;
- `GET /devpanel/logs?request=<id>` hands those lines back, as JSON.

In the **Logs** and **Exceptions** tabs, a request that has an id gets a button —
*Ask the server for this request's log lines* — and what comes back is shown
under "From the server's log". The toolbar asks through the unwrapped `fetch`,
so looking never adds another row to the list you are looking at.

**By id, never by time.** "Everything logged between the request and its
response" would be the obvious implementation and it is the wrong one: on a live
server the toolbar is open for one browser, by grant, while every other visitor
writes into the same seconds. Their lines are not yours to read. A line qualifies
only by carrying the id.

**The toolbar works out the URL itself.** The route is a framework constant, the
same path in every installation, so nothing about it travels in a debug payload —
a response should carry what only it knows, and this is not that. The base comes
from what the page already knows about itself: `window.__PRAMNOS__.base` in a
SPA, whose API need not live where the page does, and the document's own base URL
otherwise, which is right for a server-rendered page including one served from a
subdirectory.

Whether the route *answers* is settled by the answer. An application with the
DevPanel switched off replies `404`; the toolbar says so once on the button and
stops offering. Feature detection by use, rather than by advertisement.

The endpoint requires the DevPanel feature, and accepts either an admin user or
the same signed `debug:token` grant that opened the toolbar — the developer
holding a token on a live server is usually not an admin user. It replies
`no-store`, and reads only the application's own log directory. Ids are issued
only while the toolbar is active, so a production installation issues none and
its log format is untouched.

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

A SPA does not get the toolbar injected into its HTML, and the reason is worth
stating precisely, because the usual guess is wrong. It is not that the numbers
would freeze on the shell: the toolbar keeps recording for as long as the page
lives. It is that **the SPA shell does not boot the framework** — `www/spa.php`
requires only the autoloader, so no middleware ever sees its HTML and there is
nothing to inject into.

**The framework ships the panel for that case too.** `php pramnos init` with a
SPA style writes `lib/debug.js` into the front-end sources (`frontend/lib/` for
the Vite stacks, `www/assets/js/lib/` for the build-less one) and wires it into
`lib/api.js`:

```js
import { record as recordDebug } from './debug.js';

recordDebug(method, path, response.status, payload && payload._debug, { ms, body });
```

It is the same toolbar — literally. Both are generated from one source
(`Pramnos\Debug\DebugBarAsset`): the server-rendered page gets it inlined, a SPA
project gets it as an ES module with `record()` exported. There is no second
renderer to drift, which is what produced a `✕` that had to be fixed twice.

So both deliveries draw **every collector the payload carries** — SQL, Time,
Route, Auth, Session, Logs, Views, Domain, Migrations, Exceptions — plus **Errors**,
**Client** and **API**, which come from the browser rather than from any payload. A
collector the payload does not carry gets no tab, rather than an empty one that
reads as "nothing happened".

Nothing in the panel is application-specific, so **do not write your own** — if a
field is missing, add it to the framework's source or report it upstream. A
second panel beside the working one has already happened once, because the SPA
panel was documented only in changelog posts.

In production nothing attaches `_debug`, so `record()` never has anything to
show: no data, no DOM, no panel. That is why the file ships unconditionally
rather than being imported behind a development flag.

### An older project without the panel

Projects scaffolded before the panel existed have no `lib/debug.js`. Copy it in
from the framework rather than by hand:

```bash
./pramnos project:resync --debug-panel --all     # add it
./pramnos project:resync --debug-panel           # refresh an existing one
./pramnos project:resync --debug-panel --dry-run # preview
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
