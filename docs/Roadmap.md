---
use_cases:
  - Checking whether something is already planned before proposing it
  - Finding known gaps and open items in the framework
---

# Roadmap & Open Items

A running list of planned work and known gaps in the framework. Shipped features
live in the [Changelog](version-history/index.md); this page tracks what is
**not yet done**.

> Items here are deliberately not started or intentionally deferred. Each notes
> *why* and what "done" requires. Anything touching live authentication is
> verified in a real application before it is merged.

## Authentication & Auth Server

### Retire the deprecated `auth` / `session` addons

The built-in login lifecycle, session tracking and activity logging now cover
what the legacy `auth` and `session` addons provided — **except** cookie-based
"remember me" re-authentication, which still relies on the `UserDatabase`
addon's `onAuthCheck()`.

**Planned:**

- A built-in remember-me `authCheck` (validate the `auth`/`username` cookies and
  re-establish the session) to replace `UserDatabase::onAuthCheck()`.
- Point `SessionTrackingMiddleware` at the built-in check.
- Drop the deprecated addons from new scaffolds, keeping backward compatibility
  for applications that still register them.

*Done when:* remember-me login persistence is verified across new requests /
browser restarts in a real application.

## Debug toolbar

The toolbar now has one renderer
(`src/Pramnos/Debug/assets/debugbar.js`, owned by `Pramnos\Debug\DebugBarAsset`)
and the SPA panel draws every collector the payload carries. Four things remain,
in this order — each later item is cheaper once the earlier one lands.

### 1. Finish the unification: the server-rendered half

`DebugBar::render()` still builds its own HTML, CSS and inline JS, so
server-rendered pages have neither the new tabs nor per-request logs in the ajax
list. Replace it with the data island plus the single script:

- emit `<div id="pramnos-debug-data" hidden>` holding `ApiDebugPayload::build()`
  plus `request_method` / `request_path` / `status_code`, and
  `<script{$nonce}>` with `DebugBarAsset::withAppName(DebugBarAsset::source(), …)`;
  keep returning `''` when no collectors are registered;
- **delete** `css()`, `js()`, `ajaxJs()`, `renderPanel()`, `formatTabLabel()`,
  `renderInfoStrip()` and the nine `render*()` methods — bypassed dead code is how
  two renderers happen again;
- `tests/js/debugbar-ajax.test.js` and `debugbar-hide.test.js` extract `ajaxJs()`
  and `js()`, which will be gone: point them at `DebugBarAsset::source()`, using
  the already-rewritten `spa-debug-panel.test.js` as the pattern;
- `DebugBarTest`'s six assertions on rendered HTML become assertions on the
  island's JSON, plus `#pdb-restore` being outside `#pramnos-debugbar`.

### 2. A Time tab that says something in a SPA

Today it shows one segment, because `routing` and `controller` are instrumented on
the MVC path and `Api` has no timers at all — and `boot` is misnamed: it measures
the DebugBar provider's own registration window, not application boot.

Free, in the renderer, from data already recorded:

- client-versus-server as one bar (`client 210ms = server 42ms + 168ms elsewhere`);
  both numbers are already kept, and nobody does the subtraction by hand;
- SQL as a share of server time, from the query collector's `total_ms`;
- **a waterfall across requests** in the `requests` tab, on a shared time axis.
  This is the SPA-specific insight no per-request tab can give: sequential calls
  that could have been parallel, and polling loops, become visible.

Then instrument the API path the way MVC is (`route`, `middleware`, `action`,
`serialize`) plus a real `bootstrap` segment around `Application::init()` — DB
connect, service providers and session start are the classic invisible cost. Those
segments also travel in `Server-Timing`, so they show in the browser's own network
panel with no toolbar involved.

### 3. A parent Service class, and a Domain tab

In a `spa` / Services project the domain logic lives in `src/Services/*Service.php`
— plain classes with nothing for the framework to hook, so the Models tab is empty
even though the request did work.

**Give services a base class, the way models have one.** `Model` is instrumented
automatically because it is a framework base with load/save hooks; a
`Pramnos\Application\Service` base can do the same for services, and carry the
other things every scaffolded service currently re-implements by hand. Then:

- a `ServicesCollector` fed by the base, so recording is automatic rather than
  opt-in;
- the tab becomes **Domain**, with a Models section and a Services section — the
  label follows the content instead of the content following the label;
- the payload keeps its `models` key, so anything already reading it is unaffected.

`create:crud` and the service stub then generate services extending the base. A
container-resolved timing proxy is the more elegant version and stays open as a
follow-up, but it should wait until services are actually resolved through the
container.

### 4. What a SPA developer still cannot see

- **Auth/session tab** — who is signed in, which credential is in use (apiKey,
  accessToken, cookie), where it came from, and a countdown to expiry decoded from
  the JWT. "It worked and then stopped" is almost always this.
- **Runtime config and router** — `window.__PRAMNOS__`, the router base, the
  current route and params. This is the "why does my deep link 404" class.
- **Storage inspector** — the keys the application owns, with secrets masked. A
  stale token in `localStorage` is already documented as a trap in the generated
  `FRONTEND_TESTING.md`.
- **Errors tab** — `window.onerror`, `unhandledrejection` and every `ApiError`,
  tied to the request that produced them, plus component failures caught through
  `<svelte:boundary>`. Component *state* stays the job of Svelte DevTools and the
  `$inspect` rune; correlation is ours.
- **API playground** — endpoints from the OpenAPI document, called with parameters,
  response shown with its own `_debug`. Cheap for us because the OpenAPI document
  already exists.

*Done when:* the same toolbar, from the same source, answers "what did this request
do" identically on a server-rendered page and in a SPA — including its logs, its
domain-layer calls and where its time went.

## Testing

### The suite takes 17½ minutes, and that paces every change

Measured on 2026-08-12, full suite: **17:36 for 9167 tests** (≈115ms/test average).
Every development cycle waits on it.

**Coverage is not the bottleneck.** `phpunit.xml` declares an always-on
`<coverage>` block, so the default run instruments every line and writes
`coverage/clover.xml` even when nobody asked — but measured against the same
filter (243 tests) it costs only ~8%:

| Run | Wall clock |
|---|---|
| `./dockertest --filter Init` | 2:44 |
| `./dockertest --no-coverage --filter Init` | 2:31 |

Still worth making opt-in (the container runs `xdebug.mode=coverage`, and the
clover write is pure waste on a filtered run), but it does not explain the time.

**What to measure next.** 243 tests in 150s is ~620ms each, which is far too much
for work that is mostly in-process. Two candidates, both unverified:

- **Per-test database setup.** If schema import or drop/create runs per test — or
  per test *class* — that is the cost. Options in increasing order of work: wrap
  each test in a transaction and roll back; import the schema once per run and
  reset with `TRUNCATE`; on PostgreSQL, create each run's database from a template
  (`CREATE DATABASE … TEMPLATE …`), which is close to free. **Consecutive runs
  should not rebuild what has not changed** — the databases only need rebuilding
  when a migration changed.
- **Tests that scaffold whole projects on disk.** The `Init*` tests write a full
  project per test method. That may be what this particular filter measured, in
  which case the average is not representative and the real distribution needs a
  `--log-junit` pass to find the slowest classes.

*Done when:* a full run is under 5 minutes, and a filtered run of one class is
under 10 seconds. Start by ranking the slowest tests (`--log-junit` plus a sort)
rather than optimising the first suspect.

### Deduplicate the two Getting Started pages

`docs/index.md` and `docs/Getting_Started.md` are the same document — identical
headings and prose, differing only in tab-versus-space indentation inside the
code fences — yet the nav presents them as "Quickstart" and "Full Setup Guide".

This matters more now that `docs/` is a retrieval corpus (rule 13): two pages
with the same content compete for the same question and neither is more
authoritative than the other.

- Decide which one survives, or split them for real — a short quickstart that
  stops at "it runs", and a full guide that continues into Docker, structure and
  scaffolding.
- Update the `nav:` labels so they describe what each page actually is.
- Keep whichever URL is already linked from the README and the scaffolded
  projects, or add a redirect.

*Done when:* the two pages answer different questions, and the `use_cases:` of
each say which.

### An MCP tool over the docs corpus

The retrieval preconditions now hold (`use_cases:` frontmatter on every
indexable page, nav coverage, both enforced by
`tests/Unit/Docs/DocsRetrievabilityTest.php`) but nothing reads them yet. The
scaffolded `mcp:serve` exposes application introspection only — tables, schema,
migrations, models, routes.

- `list_doc_sections` / `get_doc_section` over `docs/*.md`, returning each page's
  `use_cases` so the agent can choose before fetching. Runtime scan of ~90 files,
  no build artifact to go stale.
- The changelog posts as a **separate** corpus, so "when did this change" cannot
  outrank "how does this work".
- A verification tool (`pramnos_check`) for the rules assistants break in
  practice: raw SQL where the query builder belongs (rule 12), unqualified
  `authserver.*` tables, `?message=`/`?error=` query params instead of flash
  messages, view variables named `sections`/`path`/`model`/`_layout`, migrations
  prefixed `2020_01_01_*`, a hand-rolled debug panel beside `lib/debug.js`. Each
  is mechanically detectable, and prose in a guide demonstrably does not prevent
  them.

*Done when:* an assistant working in a project can find the right guide section
without reading every file, and gets told "no" when it breaks a documented rule.

---

_Have a request or found a gap? Open an issue on
[GitHub](https://github.com/mrpc/PramnosFramework)._
