---
date: 2026-08-24
categories: [Changelog]
---

# The Svelte generator catches up with the MVC one

`create:crud` produced a front-end screen with a text box for every column. The
MVC half of the same command, on the same table, produced a checkbox for a
boolean and a searchable picker for a foreign key. Four faults nobody had filed
turned up while closing that gap, and one of them had been rendering every
boolean column in every generated form as a number input.

<!-- more -->

## The complaint

> The framework's scaffolding can make Svelte apps, but it is not a complete
> implementation. In the MVC version there are CLI commands to create every
> element you might need — I can design a migration from the command line and
> the system builds the model and a full CRUD with no intervention. The Svelte
> side does not have the same level of automation. It should.

## The finding

Not a missing capability. **One call site reaching for the weaker of two methods
that both already existed.**

`createSpaScreen()` called `editableColumns()`, which returns column *names*.
The MVC path called `introspectTableAsWizardColumns()` on the same table and got
the logical type, nullability, the `COLUMN COMMENT` and every foreign key — then
turned them into a checkbox, a `<textarea>`, a date input and a select.

That is not cosmetic. A text box over a `boolean` stores the string `"on"`, and
`"on"` is truthy for ever afterwards. A text box over a foreign key asks somebody
to type a numeric id they have no way to look up. A text box over a `timestamp`
accepts anything and the insert fails at the database. The generated screen was a
demo; the MVC screen was a feature.

## Fixed — the generated screen

`create:crud thing --table=things` in a `spa` project now produces a screen whose
controls match the columns:

| Column | Control |
|---|---|
| `boolean` / `tinyint(1)` | checkbox |
| `text` / `longtext` / `json` | textarea |
| `date` | `<input type="date">` |
| `datetime` / `timestamp` | `<input type="datetime-local">`, converted to the space form the database wants |
| `integer` / `decimal` / `float` | `<input type="number">`, `step="any"` where fractions are allowed |
| a foreign key | a searchable picker against the referenced resource's own list endpoint |

The `COLUMN COMMENT` becomes the label, `NOT NULL` becomes `required`, and the
generator's exclusion list still applies — a generated screen does not print a
password hash in an admin table and offer it for editing.

**A blank nullable field is saved as `null`, not `''`.** They compare, sort and
`COALESCE` differently, so a form that cannot express the difference converts
every unset optional column to `''` on the first save — a data change nobody
asked for, invisible until something depends on it.

The list sorts, searches and pages **on the server**, and its state lives in the
URL. A link to "page 3, sorted by listeners" is a link somebody can send, and a
background re-read leaves the reader where they were rather than jumping them to
page one.

### The picker degrades visibly

A foreign-key picker reads the referenced resource's own list endpoint — the one
`create:crud` generates for it — rather than a per-CRUD lookup action, which
would mean two lookup surfaces per foreign key with two sets of authorisation.

So it works when the referenced table has been generated too, and **says so when
it has not**: the field falls back to the raw id and names the endpoint it could
not read. A picker that silently renders an empty list is indistinguishable from
a table with no rows.

## Added — the components, and their tests

A screen that imports components a project does not have is a build error
arriving several minutes after the command that reported success. So the
components ship:

| File | What it is |
|---|---|
| `components/DataTable.svelte` | Table and card layouts from one column definition, over the framework's own `ApiListResponse::paginated()` envelope |
| `components/Pagination.svelte` | Numbered, windowed, every button keyboard-reachable with a real name |
| `components/ConfirmDialog.svelte` | Focus trap, Escape, optional typed-phrase mode — replacing `window.confirm()` |
| `components/Field.svelte` | The control-per-type renderer above |
| `lib/i18n.svelte.js` | `t()` / `tHtml()`, a client for the framework's own catalogue |

`DataTable` **reports and never performs**: `onsort`, `onpage` and `onsearch` say
what the user asked for and the screen decides. That is what lets one component
serve a server-paged list and a local one.

**Written once and never overwritten.** The whole value of shipping a `DataTable`
is that a project extends it, so a generator that refreshed it would undo that
work on the next `create:crud`. `project:resync --spa-components` takes a newer
version deliberately, and is not part of a plain `project:resync` for the same
reason.

**Each ships with its test**, into the project's `__tests__/`. The framework's own
JS runner is `node --test` with no Svelte compiler; a scaffolded project already
has Vitest and `@testing-library/svelte`. So they are tested where they run, in
every generated project, rather than nowhere.

## Added — the two doors

```bash
php pramnos create:screen Dashboard --blank      # a screen with no list
php pramnos create:screen Invoices --table=invoices
php pramnos create:component StatusBadge         # a component *and its test*
```

`createSpaScreen()` was reachable **only** through `create:crud`, so the
documented way to add a dashboard was to generate a CRUD for a table you did not
want and delete two thirds of it. `create:view` exists on the MVC side for
exactly that reason.

`create:component` writes the test beside the component, and that is the point of
it rather than a nicety: `create:service` writes a test stub, which is why
services in a scaffolded project have tests, and the front end had no such
command, which is why components did not. It is the same lever.

## Added — a translation endpoint for the front end

A SPA cannot call `_()`. Without an endpoint a front end either ships no
translation or grows a **second** catalogue — and a second catalogue means a
string that moves between a component and a controller loses its translation,
silently, in whichever direction it moved.

`scaffold:spa` now writes a controller answering `GET {apiPrefix}/language`,
serving the installation's own map, and `lib/i18n.svelte.js` is a client for it:
same key, same fallback, same `%s` rule as `_()`.

`tHtml()` keeps the translation's own markup live and escapes everything
substituted into it. A translator writing `<strong>` is trusted; a value arriving
at run time from an API or another user is script.

## Changed — the router carries state

`parse()` returns `{name, path, segments, query}` and the shell passes the route
to every screen as a prop. `segments` is how a detail view knows its record;
`query` is how a list knows its page. `go()` and `href()` replace `pathFor()` +
`router.link` — clicks are intercepted once on the window, so an anchor is just
an anchor, and modified clicks, `target` and `download` still behave like real
links.

`api.get(path, query)` takes the query as an object and drops blanks, nulls and
undefineds. A URL carrying an empty parameter is a *different* URL from one
carrying none — two cache keys for one view — and dropping them centrally is why
no screen has to remember an `if` per parameter and forget one.

> **If your application calls `api.get(path, options)` with two arguments**, the
> second is now read as a query. Find them with:
> ```bash
> grep -rn "api\.get([^)]*," --include=*.js --include=*.svelte .
> ```
> `create:api-client`'s generated module composes its own query and calls
> `api.get` with one argument, so it is unaffected and needs no regenerating.

## Fixed — four faults nobody had filed

All four are older than this work, and all four were found by running the tests
for it.

### Every boolean column on MySQL was a number input

`Database::getColumns()` selected `DATA_TYPE`, which is `tinyint`.
`mapSqlTypeToLogical()` checks for `tinyint(1)` to recognise MySQL's boolean
convention — and the width lives only in `COLUMN_TYPE`. So the check could never
match, while the code and its comment both said it did.

**This affected the MVC generator too**, and had for as long as both existed.
PostgreSQL reports `boolean` either way, which is why nobody noticed on that
side. `COLUMN_TYPE` is now selected alongside; `Type` is unchanged for the
callers that read it.

### Column order was whatever the server felt like

Neither introspection query had an `ORDER BY`, and `INFORMATION_SCHEMA` has no
inherent order — so a generated form's fields came back roughly alphabetical
rather than in the order the table declares them, which buries the column the
record is actually identified by somewhere in the middle. Both queries now order
by ordinal position.

### `getColumns()` cached for an hour and nothing invalidated it

The framework's own documented order of work is `create:migration`, migrate,
`create:crud`. The generator therefore runs minutes after the schema changed, and
read a cached answer describing the table as it was **before** the migration —
then wrote a model and a form for the old columns and reported success. The cache
store is shared, so the staleness outlived the process and re-running the command
did not clear it either.

Generators now read fresh, through an additive `$fresh` parameter. Request-time
callers keep the cache.

### `spa_source_dir` was honoured by one command and ignored by another

`project:resync` read it; the CRUD generator hard-coded `frontend/`. A project
that had moved its front end got its generated screens written where nothing
builds, and a resync that reported them missing. There is one rule now —
`Init::spaSourceDirFor()` — for the three callers that each carried a copy of it.
The copy this change was about to add would have answered `frontend/` for a
build-less project, which is a fourth wrong answer.

Separately, `Language::getLanguages()` scanned `ROOT/language` while `load()`
reads `app/language` first — the layout `init` generates. So it threw *"Languages
directory does not exist"* on a project whose translations were working, and a
language picker had nothing to put in it.

## Documentation

- **[Console Guide](../../Pramnos_Console_Guide.md#front-end-generation-spa-projects)**
  — `create:screen`, `create:component`, the control-per-type table, and the
  shared components' contracts.
- **[Application Styles Guide](../../Pramnos_Application_Styles_Guide.md)** — what
  the SPA target of `create:crud` now actually produces.
- **[Internationalization Guide](../../Pramnos_Internationalization_Guide.md)** —
  translating a front end from the same catalogue, and `getLanguages()`.

## Two faults in the handed-over code, fixed before it shipped

The filing came with its own implementation attached, generalised from a working
admin panel. Two things did not survive review, and both are worth naming
because neither would have failed loudly:

- `Field.svelte`'s foreign-key resolver was an `$effect` that read the state it
  wrote. A reference the endpoint cannot resolve leaves the list empty, the
  assignment is a fresh array so it counts as a change, and the effect fires
  again — one unresolvable id was an infinite request loop.
- Both new commands named their file with `getProperClassName($name, false)`,
  which pluralises and flattens the rest of the name to lower case. So
  `create:component StatusBadge` would have written `Statusbadges.svelte` — a
  component nothing imports, under a name nobody asked for. A screen is not a
  database table.
