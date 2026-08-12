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

## Documentation

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
