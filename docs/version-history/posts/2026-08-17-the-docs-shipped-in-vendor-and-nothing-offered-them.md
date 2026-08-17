---
date: 2026-08-17
categories: [Changelog]
---

# The docs shipped in `vendor/` and nothing ever offered them

`docs/` is not export-ignored. Every guide travels inside the composer package and sits in
`vendor/pramnos/framework/docs/` of every project, and the stated reason is explicit: the
documentation should be available to whoever is working there — an AI assistant included —
and the vendored docs always match the vendored code, so there is no version to negotiate.

The MCP server has shipped since v1.2 with five tools and three resources. The five tools
introspect the application: tables, schema, migrations, models, routes. The three resources
are the application's own `CLAUDE.md`, `README.md` and `app/app.php`.

None of them touches `docs/`. So the only route from an assistant to a guide was to guess
that it should look inside `vendor/` — which is the failure the documentation rules were
written after, not a hypothetical one. A feature was documented, present in the vendored
corpus, not found, and built a second time beside the working copy.

<!-- more -->

## Added

### `framework-docs`, a sixth MCP tool

The odd one out among the six: it takes no application and needs no database, because it
is the same answer in every project.

```jsonc
{}                                                    // the index — every guide, and the task each covers
{"query": "issue an API token for a signed-in browser"}
{"page": "Pramnos_Authentication_Guide"}              // read one in full
{"corpus": "changelog", "query": "session exchange"}  // when it changed, rather than how it works
```

Registered by `McpServiceProvider`, and by `mcp:serve`'s fallback server — there,
deliberately outside the `if ($app !== null)` guard the other five sit inside. There is
nothing for a missing application to make unanswerable, and a server booting without one is
exactly when somebody is asking how any of this is supposed to work.

**Ranking follows the corpus convention.** Every guide carries `use_cases:` frontmatter
phrased as *the task the reader has in hand* — "Adding a column to an existing table", not
"Schema builder reference". Those are the closest thing here to the question an assistant
arrives with, so a hit there outweighs a heading, which outweighs the body. Body matches
still count, because a question about a specific method name appears in no use case; they
are worth less.

**A page with no use cases is demoted, because it is not guidance.** This was measured
rather than assumed, and the measurement found the bug: the very first query ranked
`1.2-new-features` — a deliberately frozen v1.2 reference, and one of the two longest files
in the corpus — above every live guide, on body volume alone. Sending a reader to a page
that stopped describing current state on purpose is the one outcome this tool exists to
prevent. The rule is structural rather than a list of names, so a page cannot become
quietly exempt by being added later.

**The changelog is a separate corpus and is never merged with the guides.** There are far
more posts than guides, and each post repeats the vocabulary of the change it describes.
Merged, "how does this work" would be answered by three dated fragments of a feature's
history — precisely what the guide/changelog split exists to prevent, arriving as a ranking
accident instead of as a decision.

A page name is reduced with `basename()` before it is resolved. The name arrives from a
model, which is a caller that can be talked into asking for anything, and `app/app.php` —
database credentials and the authentication key — sits two directories above the guides in
exactly the layout the default path produces.

## Documentation

### A guide now owns the MCP server

MCP was documented **only** in `1.2-new-features.md`, which is frozen, and in a few dated
posts. That is the same shape as the failure above, one level up: the mechanism built to
make documentation findable was itself only findable by knowing where to look.

[MCP server](../../Pramnos_MCP_Guide.md) is now the page that owns the topic — enabling it,
all six tools, the resources, how to add your own, the protocol methods handled, and the
console-kernel trap that made `route-list` answer `{"error": "No router available"}` on the
only path that could reach it.

### The roadmap entry it closes, and the half it does not

`Roadmap.md` already asked for this, in three parts. Two are now built — the docs corpus
and the changelog kept separate from it. The third is not, and it is the one with the
evidence behind it: a `pramnos_check` tool that says **no** when a documented rule is
broken. Raw SQL where the query builder belongs, unqualified `authserver.*` tables, flash
messages passed as query params, view variables that collide with the View engine,
migrations prefixed `2020_01_01_*`, a hand-rolled debug panel beside the framework's own.

Every item on that list is something that happened *after* the guide describing it was
written. Being able to look a rule up and being told when you have broken it are different
mechanisms, and only the second has a track record. The entry has been narrowed to that.
