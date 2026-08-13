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

Everything the toolbar was planned to do, it does: one renderer
(`src/Pramnos/Debug/assets/debugbar.js`, owned by `Pramnos\Debug\DebugBarAsset`)
serving both deliveries, and the same answers on a server-rendered page and in a
SPA — requests, time, SQL, route, auth, session, logs, views, the domain layer,
migrations, server exceptions, browser errors, the client's own state, and a
playground that calls the documented API for real.

Two things are deliberately *not* planned:

- **Component state** is the job of Svelte DevTools and the `$inspect` rune.
  Correlation is ours, state is theirs.
- **A container-resolved timing proxy for services**, which would make
  `Service::measure()` unnecessary, waits until services are actually resolved
  through the container. Until then the explicit call is the honest option: a
  proxy that only wraps *some* services would report a domain layer that is
  partly missing, which is worse than one that is visibly opt-in.

## A guide to *using* the debug toolbar, linked from the toolbar

Everything written about the toolbar so far documents how it works and what each tab
means to somebody reading the framework. There is no page that reads as "here is how
to use this while you are debugging something" — the tabs are described where they
were added, in the guide that owns the mechanism and in a dated post per change.

**Planned:**

- A task-shaped page: *the request came back wrong — where do I look*, *the page is
  slow*, *it worked and then stopped*, *the deep link 404s*. Each answers with the
  tab and the number to read, rather than describing the tab.
- A **button in the bar** that opens it, in a new tab: the toolbar is the one place
  somebody is standing when they need it, and the published site is where the page
  lives. The DevPanel link is already conditional on the delivery, so the pattern
  exists; this one is unconditional, because the docs are not per-installation.
- The link has to survive being vendored: the toolbar ships inside `vendor/`, so it
  points at the published GitHub Pages URL rather than at anything local.

*Done when:* a developer who has never opened the toolbar can go from "something is
wrong" to the right tab without reading the framework's source, and can get there
from the bar itself.

## Testing

### The suite takes 15 minutes, and that paces every change

**Measured, and it contradicted the standing hypothesis.** Full analysis with the
numbers: [Test suite performance](Pramnos_Test_Suite_Performance.md). From a
JUnit-logged run on 2026-08-13 (`1471dc9a`):

- `./dockertest` 17:02 with coverage, **14:58 without** — instrumentation is ~12%, real
  but not the lever;
- **`tests/bootstrap.php` touches no database at all**, so there is no fixed setup cost
  to remove. The suspicion that database setup dominates does not survive contact;
- **203 tests (2.2%) account for 46% of the run.** The other 7646 cost eleven seconds
  more than those 203 do, together — which is what makes this tractable;
- `tests/Unit` is 49% of the time at 60ms/test; `tests/Integration` is 43% at
  **303ms/test**; `tests/Feature` is declared and empty.

**Planned, in order of return:**

1. **Connect timeouts.** Seven tests wait exactly **8.00 s** each for a hostname that is
   *supposed* not to resolve — `BaseTestCaseTest` and `TestEnvironmentTest` assert which
   DSN was built, proven by the failure naming the host, then wait for TCP to give up.
   One line in four places, ≈49 s.
2. **`InitCommandUnitTest` scaffolds a whole project per test** — 61 tests × 1877 ms.
   Scaffold once per class for the read-only assertions, keep per-test only where the
   test changes the project. 80–130 s.
3. **Integration tests create their schema per test.** Schema per class in
   `setUpBeforeClass()`, data per test inside a transaction rolled back afterwards — the
   split that fits a database, since DDL is not transactional in MySQL. Needs one shared
   base class rather than fifty copies. Up to 150 s.
4. **Two specific classes**: `MediaObjectTest` builds real JPEGs 86 times;
   `TwoFactorAuthService*` hashes backup codes at default cost. 40–80 s.

Together ≈5–6 minutes, without removing a test, a database or the coverage report.

**Not to be done:** dropping a database from the matrix (the query-builder bugs this
framework has shipped were dialect-specific — a `?` only MySQL tolerated, a backtick only
MySQL accepts) or making coverage opt-in (12%, and `--no-coverage` already exists).
Parallelism is the *next* step and becomes cheaper once item 3 has moved schema creation
into one place, since each worker then needs its own schema.

*Done when:* the "≥ 1000 ms" row of the distribution has moved. A change that does not
move it has not moved the suite.

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
