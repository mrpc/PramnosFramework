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

The toolbar has one renderer
(`src/Pramnos/Debug/assets/debugbar.js`, owned by `Pramnos\Debug\DebugBarAsset`),
and both deliveries use it: `DebugBar::render()` emits a data island plus that
script, and draws nothing itself. The Time tab, the cross-request waterfall, the
Auth tab, the Domain tab and the Errors tab have all landed; what is left is what
a SPA developer still cannot see from the browser's side.

Component *state* is deliberately not on this list: that is the job of Svelte
DevTools and the `$inspect` rune. Correlation is ours, state is theirs.

### Runtime config and router

`window.__PRAMNOS__`, the router base, the current route and params. This is the
"why does my deep link 404" class of question.

### Storage inspector

The keys the application owns, with secrets masked. A stale token in
`localStorage` is already documented as a trap in the generated
`FRONTEND_TESTING.md`.

### API playground

Endpoints from the OpenAPI document, called with parameters, response shown with
its own `_debug`. Cheap for us because the OpenAPI document already exists.

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
