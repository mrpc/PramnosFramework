---
date: 2026-08-24
categories: [Changelog]
---

# A flush that cleared nothing, and said it had

A cache category with an underscore in its name could not be cleared. The file
adapter wrote its entries into one directory and `clear()` looked in another, so
the flush deleted nothing and returned as though it had worked. Found while
fixing something else.

<!-- more -->

## What was wrong

`FileAdapter::getFilePath()` decided which directory an entry belonged in by
splitting its key on the first underscore. Cache keys are built as
`{category}_{id}.{ext}`, so:

| Category | Key | Directory chosen |
|---|---|---|
| `userlist` | `userlist_<id>.sql` | `userlist` ✔ |
| `schema_columns_things` | `schema_columns_things_<id>.sql` | `schema` ✘ |

`clear($category)` builds its path from the category it is handed. So for the
second row it looked for a directory called `schema_columns_things`, found
nothing, deleted nothing, and returned success. **Every category with an
underscore in its name was permanently unclearable, silently**, and its entries
went on being served until they expired.

## Why it stayed hidden

Every category the framework itself uses is a single word — `permissions`,
`userlist`, `usertokens`, `media`, `settings`, `applications` — so they all land
on the ✔ row. That was checked rather than assumed: **those flushes all work**,
and always did.

What does not is `getColumns()`'s `schema_columns_<table>`, added while bringing
the SPA generator up to the MVC one. And **this guide's own example** recommended
`$cache->category = 'user_' . $userId;` — the broken shape — a few sections below
the page that explains what a category is for.

Somebody had already met the same parsing from the other side:
`Cache::_generateCacheName()` strips underscores out of the *prefix* before
building the key, for exactly this reason. The category never got the same
treatment.

## The fix

**The adapter is told its category rather than recovering it from the key.**
`AbstractAdapter` gained a `category`, `Cache` sets it (with the prefix) before
every adapter call, and `FileAdapter` uses it for both the write path and the
flush — one value, both sides, no string parsing.

Setting it per call matters: an adapter instance is shared while the category is
chosen per call — `Database::cacheRead()` assigns it right before reading — so an
adapter that kept its constructor's category would file everything under
whichever one happened to be first.

**A flush also sweeps the place the old layout misfiled entries.** Without that,
upgrading leaves every misfiled entry on disk, still being served until it
expires — so the bug would outlive its own fix by exactly the staleness window
that made it worth fixing. The sweep matches on the file name, anchored with the
same `_` the key is built with, so `schema_columns_things` cannot take
`schema_columns_widgets`'s entries out of the `schema/` directory they both
landed in.

**The other adapters were checked, not assumed.** Redis, Memcached and the array
store all match the category against the key with a separator anchor, so none of
them had this. It was the file adapter's directory derivation alone.

## And the invalidation it was blocking

`Database::getColumns()` caches an introspection for an hour, on the stated
grounds that schemas rarely change. True — and the moment one *does* change is
exactly the moment somebody asks again, and nothing was invalidating the entry.

The framework's own documented order of work makes that routine rather than a
corner: `create:migration`, migrate, `create:crud`. Anything reading columns
afterwards — a model hydrating its field list, a form builder, an inspector — was
answered with the table as it had been an hour earlier. The store is shared, so
the staleness outlived the process: re-running the command did not clear it.

Every DDL method on `SchemaBuilder` now flushes the table it touched:
`createTable()`, `alterTable()`, `dropTable()`, `dropTableIfExists()`,
`renameTable()`. A raw `$db->query('ALTER TABLE …')` still does not — call
`$db->forgetColumns($table)` yourself if you do that.

Two mechanisms, and they cover different callers:

- **`forgetColumns()`** keeps every ordinary caller correct after a migration.
- **`getColumns($table, $schema, false, true)`** — the `$fresh` flag — keeps a
  code *generator* correct even after a change nobody announced, because a
  generator runs minutes after the schema moved and a stale answer there produces
  a model for columns that no longer exist.

Removing either one leaves a real hole, which is why both are tested.

## What was measured

Reverting the adapter's category handling reddens the directory-naming test and
**not** the `clear()` tests — because the legacy sweep catches the misfiled entry
either way. That is the two halves working as intended rather than a gap in the
tests, and it is written into the test file: a reader reversing one half and
seeing greens would otherwise conclude the tests were checking nothing.

Removing the `SchemaBuilder` calls reddens three of the five schema-invalidation
tests.

## Documentation

- **[Cache Guide](../../Pramnos_Cache_Guide.md#categories-and-organization)** —
  that a category may contain underscores, what went wrong, and the corrected
  `user_<id>` example with the flush that now works.
- **[Database API Guide](../../Pramnos_Database_API_Guide.md#reading-a-tables-schema-getcolumns)**
  — `getColumns()` was undocumented. It now has a section: the fields it returns,
  the cache, what invalidates it, and when to pass `$fresh`.
