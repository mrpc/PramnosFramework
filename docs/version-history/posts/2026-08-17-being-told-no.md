---
date: 2026-08-17
categories: [Changelog]
---

# Being told no

`framework-docs` made the framework's rules findable. Findable is not the same as followed:
every rule in the list below is something that happened **after** the guide describing it was
written. Being able to look a rule up and being told when you have broken it are different
mechanisms, and only the second has a track record.

`pramnos-check` is the second one. A seventh MCP tool, no application or database required.

<!-- more -->

## Added

```jsonc
{}                                           // the whole project
{"path": "src/Models"}                       // one subtree, or a single file
{"rules": ["raw-sql", "flash-query-params"]} // a subset
```

Seven rules — six defects, and one that polices the escape hatch. Each is chosen for the same
property: **it fails silently.** A table name that matches nothing, a message that reappears on
reload, a view variable that is simply absent in the template, a migration an installation
skips.

The `authserver` table list is read from the framework's own migrations at runtime, so it
cannot drift out of step with the schema the framework creates.

Suppression requires a reason:

```php
// pramnos-check: ignore raw-sql — recursive CTE the builder cannot express
```

A bare `ignore raw-sql` suppresses nothing and is reported as its own finding. The value of
rule 12's "leave a one-line comment saying why" is that the next reader can tell a considered
exception from an oversight, and a check tool that lets you delete findings silently is worse
than no check tool.

## Precision was the hard part, and it was measured

A check that cries wolf gets muted, and then the real finding it makes next month is muted with
it. So every rule matches a **construction, not a name** — the lesson from a check in this
framework's own history that flagged `var rows` in six unrelated functions because it matched
an identifier rather than a redeclaration, and was deleted for it.

The first run against the framework's own `src/` reported **29 raw-SQL findings, and sixteen
were noise**: `SELECT version()`, `SELECT NOW()`, `select @@global.long_query_time`, TimescaleDB
catalogs — and one example inside a docblock, because the first version did not strip comments.
A tool that reports the guide teaching the right thing has already lost.

Tightened to nine defensible findings, and each exclusion has a negative test:

- rule 12 exempts introspection and driver-specific features in its own text;
- a statement with no table to address cannot be expressed by a builder at all;
- migrations must emit exact SQL and fixtures are clearer as literals;
- *reading* `?message=` is legitimate — an application does not control every link pointing
  at it;
- `authserver.user_activity_log` contains the unqualified form as a substring;
- `$config->path = …` is not a view variable;
- `_debug` in a project with no shipped `lib/debug.js` has nothing to duplicate.

The second-largest source of noise was self-inflicted and caught by its own tests: blanking
comments before looking for suppression comments silences nothing, because a suppression *is* a
comment. The tool now reads code and comments as two views of the same file.

## The framework does not pass its own check

Against `src/`: **9 raw-SQL findings and 67 flash-query-parameter findings.** All reviewed, all
real.

That is this tool's argument turned on its author. The rules were written down, and the
framework drifted from them in seventy-six places — a controller redirecting to
`?error=not_found` instead of using the flash API it ships, a `SELECT COUNT(*) FROM ' . $table`
where `->table($table)->count()` exists, and three raw statements the scaffolder writes into
every new project.

Recorded rather than quietly fixed. Rewriting seventy-six call sites is a decision about
priorities, and presenting it as a tidy-up in the same change that introduced the tool would
hide how much there is.
