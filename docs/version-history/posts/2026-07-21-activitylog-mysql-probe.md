---
date: 2026-07-21
categories:
  - Changelog
  - Authserver
tags:
  - authserver
  - activity-log
  - mysql
  - schema
  - bugfix
  - routing
  - seo
  - http
  - scaffolding
  - datatables
  - css
---

# Activity log now writes on MySQL (schema-aware table probe)

`Pramnos\Auth\ActivityLog` silently recorded nothing on MySQL: its
table-existence probe used an unqualified name that never matched the
schema-prefixed physical table. The probe is now driver-aware, so the audit
trail is written on MySQL exactly as it already was on PostgreSQL.

<!-- more -->

## Fixed

- **`ActivityLog::record()` was a silent no-op on MySQL.** The internal probe
  called `Database::tableExists('user_activity_log')`, an exact-name lookup.
  On MySQL the `authserver.` schema is emulated as a table prefix, so the
  physical table is `authserver_user_activity_log` and the probe never matched
  — every `record()` short-circuited and no row was written. On PostgreSQL the
  real `authserver` schema meant `table_name = 'user_activity_log'` matched, so
  the bug was invisible there (and in the PostgreSQL reference suite).

  The probe now goes through the schema builder with the fully-qualified name,
  `Database::schema()->hasTable('authserver.user_activity_log')` — the same
  call the creating migration uses — which resolves correctly on both the real
  PostgreSQL schema and MySQL's table-prefix emulation.

## Why it matters

The authserver dashboard / security pages read back this table. On MySQL
installs the login/logout/passkey audit trail was simply empty; it now
populates as designed. The change is confined to the table probe — the insert
path, the feature gate, the missing-table no-op and the swallow-on-failure
guarantees are all unchanged.

---

## SEO-friendly 404 for unknown controllers

A request that resolved to a controller which does not exist returned a
plain-text `There is no controller to run...` body with an HTTP **200** status —
useless to visitors and misleading to search engines. The front controller now
answers with a proper not-found response.

### Changed

- **`Application::notFound()` (new, public) emits a real HTTP 404** — a minimal
  styled page with a `noindex` robots directive and a link home; any
  caller-supplied message is HTML-escaped.
- **`Api::notFound()` overrides it with a JSON 404 envelope**
  (`{"status":404,"error":"NotFound"}`) so API clients get a machine-readable
  not-found instead of the old string.
- The three `close('There is no controller to run...')` call sites (two in
  `Application::exec()`, one in `Api::exec()`) now delegate to `notFound()`.

### Why a 404 and not a redirect

A genuine 404 (not a 301 to the home page) is the correct SEO signal: blanket
redirecting unknown URLs to `/` reads as a soft-404 and hurts indexing. The
method is public so app controllers can trigger it for their own missing
resources.

---

## `Document::getInstance()` no longer lets a stray `?format=` hijack the response

The `format` query parameter doubles as a document-type selector, but callers
also use it for their own purposes (e.g. the DataTables adapter sends
`format=datatables`). An unknown value fell through to a fresh HTML document at
`render()` time — discarding a JSON/raw `Response` a controller had already
prepared. Unknown `format` values now fall back to the current default document
type; known types and the historical HTML default are unchanged.

---

## View no longer triggers a PHP 8.5 "null array offset" deprecation

`View::addModel()` / `getModel()` keyed the models array on `$model->name`. On an
unsaved record (e.g. an `edit/0` create form) that name is null, and PHP 8.5
deprecates null array offsets — emitting warnings on every such page. The key is
now coerced to a string (`''` for null); lookup semantics are unchanged.

---

## Scaffolded DataTables CRUD now works end-to-end

Generating a CRUD controller (`create:migration` wizard → controller) on a
project with DataTables installed produced a list page that fatally errored,
returned HTML instead of JSON, skipped auth on writes, and rendered unstyled.
Fixed across the generators and the plain-css theme.

### Fixed

- **`pramnos-adapters` is auto-included with DataTables** and its bundled files
  register under per-file handles (`pramnos-datatable`, `pramnos-gridjs`) instead
  of colliding on one — fixing the `Cannot find script: pramnos-adapters` fatal.
  The controller enqueues `pramnos-datatable`, guarded by `isScriptRegistered()`.
- **`getApiList()` returns JSON, not the HTML theme.** It reads the adapter's
  query params (`page`/`perpage`/`search`/`order`/`fields`, `format`) from the
  request and returns `\Pramnos\Http\Response::json(...)`.
- **All actions are registered** so `Controller::exec()` dispatches them instead
  of falling back to `display()`: `show`/`getApiList` are public, while the
  create/edit form and `save`/`delete` require login (previously create/edit were
  reachable without authentication).
- **The DataTables stylesheet is enqueued** (guarded) so the list controls are
  styled.
- **Breadcrumbs render** on the generated CRUD views — the controller populated
  them but nothing displayed them.
- **Forms use the theme's semantic classes on plain-css too** (`form-control`,
  `btn btn-primary/secondary`, `card`) instead of empty `class=""`; the plain-css
  `.btn` gained `line-height`/`vertical-align` so `<a>` and `<button>` buttons
  match in height.

---

## Controller scaffolding renders from a stub template

The wizard-generated CRUD controller was built from a large PHP heredoc embedded
in `MakeCommandBase`. It is now rendered from
`scaffolding/templates/crud-controller.stub` via the existing `renderStub()`
mechanism — matching how the middleware / event / migration generators already
work — so the generated controller can be customised by editing the stub.
Generated output is byte-for-byte unchanged.

---

## DebugBar "Views" panel now lists inserted partials

`View::insert()` does a plain include and never went through `getTpl()`, so the
DebugBar Views panel listed only the top-level template — not the partials a page
actually rendered (breadcrumb, sidebar, …), which are often exactly what a
developer needs to edit. `insert()` now records each partial in the
`ViewsCollector`, so every rendered template file shows up when debugging.
