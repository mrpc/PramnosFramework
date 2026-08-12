---
date: 2026-08-12
categories:
  - Changelog
  - Added
  - Documentation
tags:
  - documentation
  - mkdocs
  - ai
---

# The docs now say when you would need them

`docs/` ships inside the composer package, so it already sits in every project's
`vendor/`. That makes it the documentation an assistant working in that project
reads from — and it is version-correct for free, because the vendored docs match
the vendored code. What was missing was any way to *choose* a page without
reading all of them.

<!-- more -->

## `use_cases:` on every indexable page

Every guide now opens with frontmatter describing the task a reader has in hand:

```yaml
---
use_cases:
  - Writing a controller that reads or writes the database
  - Converting existing raw SQL to query-builder calls
  - Diagnosing a query that returns nothing or the wrong rows
---
```

Phrased as the question, not as a description of the page: *"Adding a column to
an existing table"* is findable, *"Schema builder reference"* is not. 36 pages
carry it.

This is the field a retrieval tool selects on before fetching anything —
the same shape the Svelte MCP server's `list-sections` exposes as `use_cases`,
and for the same reason: the title of a page is a poor predictor of whether it
answers your question.

## The guide is no longer optional

Rule 1 required a dated changelog post with every change. It now requires
**both** the post and the guide page that owns the topic, brought to current
state.

The posts are a stream of deltas — 57 of them. Somebody asking how a feature
works has to land on one page describing it as it is, not reconstruct it from
three dated entries. That is not hypothetical: the SPA debug panel was
documented only in two changelog posts, and an assistant working in a real
project concluded from that the framework shipped no panel and wrote a second
one beside the working one. The guide section that would have prevented it was
written the same day this rule was.

Posts stay deltas. Guides describe current state. Neither substitutes for the
other.

## Both invariants are now tested

`tests/Unit/Docs/DocsRetrievabilityTest.php` asserts that every indexable page
declares at least one non-trivial use case, that every one is reachable from
`mkdocs.yml` nav, that every nav entry resolves to a file that exists, and that
each exemption still describes a page that is there.

The nav check earned itself immediately: **four pages were outside the nav** —
Application Styles, Queues, Redis and the frozen v1.2 reference. MkDocs reports
that as `INFO`, not a warning, so the build passed and nobody noticed. The first
three are now in the nav; the frozen reference is an enumerated exemption, along
with `releases.md` (a release index is history, not guidance).

Exemptions live in the test and are never inferred, so a new page cannot become
silently exempt — the failure mode this whole change exists to close.

## Scaffolded projects are told where the corpus is

A corpus nobody knows about is not a corpus. Every project scaffolded from now on
gets a section in its `CLAUDE.md` naming the directory
(`vendor/mrpc/pramnosframework/docs/`), showing how to read the `use_cases:`
headers to pick a page, and stating the two conclusions that matter: the guides
are current state while the posts are history, and **a capability documented in
those guides is not to be reimplemented in the project**.

`AI_INSTRUCTIONS.md` gains the same obligation for work on the framework itself,
as core directive 6.

## Also documented

`project:resync` was not in the Console guide at all — a command with three
scope flags, documented nowhere except the changelog. It now has a section
covering all three groups, the "only refresh what you have" default, and what
the merges preserve. Its SPA flag was also renamed from `--spa` to
`--debug-panel`: `--spa` read as "resync all the SPA sources" when it syncs one
framework-owned file.

## Also on the roadmap

Two items recorded rather than done: the MCP tools that would read this corpus
(`list_doc_sections` / `get_doc_section`, plus a `pramnos_check` for the rules
that prose does not prevent), and the fact that `index.md` and
`Getting_Started.md` are the same document under two nav labels — which matters
more now that two identical pages compete for the same question.
