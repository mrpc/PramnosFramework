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

## Email

### Where a sent message's body is stored

Under design, written up separately in **[Mail body storage — design](Mail-Body-Storage-Design.md)**.

The short version: `mails.path` is where applications on this framework have kept gzipped bodies
for years, and this framework has never written to it or read from it. `BodyStore` was added
without looking at that, so there are now two conventions, `BodyStore::bodyOf()` returns nothing
for a row that uses the older one, and `mails.hash` means one thing in the schema comment and
another in `Email::send()`.

A first attempt to reconcile them was reverted whole (`03e944cc`) because it was written while the
design was still being argued and carried decisions nobody had agreed to.

**Done requires:** finishing the survey of what installations actually have; deciding whether the
archived body may stop being byte-identical to what was sent; and settling how deduplication and
orphan collection work at ten million rows. The design page has the measurements, the traps found
by prototyping, and four real bugs that are still open — including one where the garbage
collection deletes the body of a message that was just sent.

### hreflang, and a sitemap

Under design, written up separately in **[hreflang and Sitemaps — design](Hreflang-And-Sitemap-Design.md)**.

Neither exists. The framework has a full language system and no way for a page to say which URL is
its equivalent in another language; there is no sitemap generator, route or `<link rel="sitemap">`.
`robots` has no home either — it is done in four places with three mechanisms and no `Document`
property.

They are one piece of work rather than two, because the same declaration has to serve both: if a
sitemap and a page head disagree about which pages are translations of each other, Google discards
the declaration. And a controller cannot be the place it is declared, because no controller runs
when a sitemap is generated.

**Done requires:** deciding whether hreflang is delivered in the head, in the sitemap, or both
under the all-or-nothing rule for language groups; localized route groups, which make reciprocity
structural and are available because `Routing\Route` already compiles through Symfony; and a
sitemap generator that shards, since the protocol caps a file at 50,000 URLs and the sites this is
for have more pages than that.

The design page carries the requirements taken from an application that already does this in
production across five locales, what its implementation gets wrong, and the conflict between a
lean sitemap and sitemap-delivered hreflang.

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

## Migrations

### Moving the ledger replayed 91 already-applied migrations on a live database

**Not fixed. Parked, not forgotten** — and recorded here because the report lives in a
consuming application's repository (`docs/tasks/framework-prompt-ledger-change-replayed-91-migrations.md`
in the reference application), which a session working on the framework has no reason to
open.

An installation upgrading across the ledger change replayed its whole migration history.
Before it, migrations were recorded in `mysql.pramnos_schemaversion` keyed by **version
number** — 73 rows, the last `0.088`. After it, the runner records in
`public.schemaversion` keyed by **name**, `migration0091`. Nothing carried the history
across, so the new runner found none of `migration0011` … `migration0125` and did what it
is supposed to do with migrations that are not in the ledger:

```
07-09-2026 07:35:57  batch 10   91 migrations, from migration0011 onwards
07-09-2026 07:36:33  batch 10   migration0091 → add_compression_policy('alerthistory', '3 months')
```

Ninety-one already-applied migrations ran against a live production database in about fifty
seconds, and the ledger records every one as `result=1`.

**Most were harmless by accident**: re-running old DDL mostly fails on arrival — the ledger
is full of `23 of 80 statements failed (12 look like work already done)` — and the runner
counts the migration successful anyway. The dangerous ones are the *idempotent* ones,
because they succeed. `migration0091` re-added a compression policy that `migration0147`
had removed two hours earlier with measurements behind it, and the replay stopped at
`migration0125`, so 0147 was never reached to clean up. For a day the recorded state and
the real state disagreed with nothing to indicate it.

It then happened a second time on the same installation through the **legacy** entry point:
a single web request reached `Application::exec()` → `checkversion()` → `upgrade()`, which
keys the ledger by `$version` rather than by slug, found no `0.137` … `0.147` rows and
replayed eleven more. `migrate:status` reported "0 pending" throughout, because it reads
slugs. So the version-keyed and slug-keyed ledgers disagree at **both** entry points, and
fixing one would not have been enough.

**Three asks, in order of value:**

1. **Carry the history when the ledger moves.** The upgrade knew both schemas and was the
   only code that could ever have matched `0.088` to `migration0088`. A one-time backfill
   turns this from a replay into a no-op. Without it, every installation upgrading from the
   version-keyed ledger replays its entire history the first time anything calls `migrate`.
2. **Refuse to replay a whole history unasked.** A runner that finds *zero* of *ninety-one*
   migrations recorded is not looking at a fresh database — a fresh database has no tables
   either, and this one had 407 GB of them. Require an explicit flag when the ledger is
   empty but the application's tables exist. The cost of being wrong in that direction is a
   message; in this direction it is 91 migrations against live data.
3. **`result=1` should not cover a migration whose statements mostly failed.** A distinct
   *partially applied* state would have made one `SELECT` show what had happened, which is
   what made the replay invisible in review afterwards.

**No installation is currently at risk**: the reference application's ledger is consistent
and has nothing pending, so the replay cannot recur there. The exposure is the *next*
installation to upgrade across the ledger change, and whether it gets away with it depends
entirely on which of its old migrations happen to be idempotent.

*Done when:* an installation whose history is only in the version-keyed table can run
`migrate` and have it report nothing pending.

## Testing

### The suite takes 15 minutes, and that paces every change

**Measured, and it contradicted the standing hypothesis.** Full analysis with the
numbers: [Test suite performance](Pramnos_Test_Suite_Performance.md). From a
JUnit-logged run on 2026-08-13 (`1471dc9a`):

- `./dockertest` **9:50 with coverage, 2:56 without** — instrumentation is **3.3×**, and it
  is the largest single item left. (The 2026-08-13 reading of ~12% compared a
  coverage-collecting run against one where Xdebug was still instrumenting; see
  [Test suite performance](Pramnos_Test_Suite_Performance.md).);
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
MySQL accepts). Coverage stays declared in `phpunit.xml`, but `--nocoverage` is the flag for
an ordinary run — it is 70% of the wall clock, not 12%.
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

### A verification tool over the documented rules — done

The retrieval half of this is **done**: `framework-docs` indexes, searches and
reads `docs/*.md` using each page's `use_cases`, keeps the changelog posts as a
separate corpus so "when did this change" cannot outrank "how does this work",
and demotes pages carrying no use cases because they are not guidance. See
[MCP server](Pramnos_MCP_Guide.md). Runtime scan, no build artifact to go stale.

The half that says **no** is now built too: `pramnos-check`, a seventh MCP tool. See
[MCP server](Pramnos_MCP_Guide.md#pramnos-check). It covers the rules this entry asked for:

- raw SQL where the query builder belongs (rule 12);
- unqualified `authserver.*` tables, which fail silently on PostgreSQL;
- `?message=` / `?error=` query params instead of flash messages, which re-show
  the message on reload;
- view variables named `sections`, `path`, `model` or `_layout`, which collide
  with the View engine and vanish without a word;
- migrations prefixed `2020_01_01_*`, which installations with a cutoff skip;
- a hand-rolled debug panel beside the framework's own.

Each is mechanically detectable, and prose in a guide demonstrably does not
prevent them — every item on that list is something that happened after the
guide describing it was written. Being *able* to find the rule and being *told*
when you have broken it are different mechanisms, and the second one is the one
with evidence behind it.

Each is matched as a **construction rather than a name**, with a negative test per rule and
a suppression mechanism whose reason is mandatory. The precision mattered more than the
coverage: the first run against the framework's own `src/` produced 29 raw-SQL findings of
which sixteen were noise, and a check that cries wolf is a check that gets muted.

**What this surfaced, and what is now open:** the framework does not pass its own check — 9
raw-SQL findings and 67 flash-query-parameter findings under `src/`. Every one was reviewed
and they are real. That is the entry's own argument turned on its author: the rules were
written down, and the framework drifted from them in seventy-six places. Rewriting them is a
priority decision, not a tidy-up, so it is recorded here rather than done quietly.

*Done:* an assistant now gets told "no" when it breaks a documented rule, rather than only
being able to look the rule up.

---

_Have a request or found a gap? Open an issue on
[GitHub](https://github.com/mrpc/PramnosFramework)._

### Test suite performance — closed

Both open items are answered in
[Test suite performance](Pramnos_Test_Suite_Performance.md), with measurements:

- **The remaining `DatabaseTestCase` conversions are declined.** The seven convertible
  classes are 10.26 s of 211.9 s of measured test time, and a perfect conversion of all
  seven saves about 8.5 s — at or below the suite's own run-to-run spread. Three of the
  seven are PostgreSQL, where the conversion has been measured making things *slower*.
  There is no experiment that could show the work succeeded.
- **`paratest` is now the only remaining lever, and the recommendation is to decide it on
  CI rather than on local wall clock.** Prize: 3:45 → roughly 50 s at 8 workers. Cost:
  per-worker databases on three engines, permanently. For a local loop that is a poor
  trade; for CI it is minutes × runs × people and plausibly worth it. Step 3 — routing
  every connection through one helper — is worth doing first either way.

*Open only if CI wall clock becomes the binding constraint.*
