---
date: 2026-08-27
categories: [Changelog]
---

# One search box over many entities

The legacy framework had a `search` class — a registry of providers, 66 lines. Bringing it
back as-is would have been a downgrade, because the part it did is now the part the
framework already does best. What was missing was the other half.

<!-- more -->

## Added

### `Search\Registry` — the aggregate, and only the aggregate

```php
// app/search.php
use Pramnos\Search\Registry;

Registry::register('Users', \Pramnos\User\User::class, [
    'display' => ['username', 'email'],
    'url'     => '/users/edit/:id',
]);
```

Documented in the new [Cross-Entity Search Guide](../../Pramnos_Search_Guide.md).

**Searching one entity was never the gap.** Every model implements `ApiListSource`, and
`ApiListQuery` turns a term into a query with paging, per-field search, dialect-correct
`ILIKE`/`LIKE` and honest totals — that is what a `Datatable` search field uses and what
`create:crud` generates. The legacy provider contract (`$object->search($query)`) did
strictly less than the interface every model already satisfies.

What no entity can implement for itself is one term across several entities. So that is
all this class is: a list of sources and a loop over the engine that already existed.

### Permissions, at two levels

The endpoint is guarded as a whole (`guard('search')`). Inside, per source and per row:

```php
Registry::register('Orders', \App\Models\Order::class, [
    'display'    => ['reference', 'customer_name'],
    'permission' => 'orders.list',                       // Auth\Gate, or fn($user): bool
    'filter'     => fn($user) => 'tenant_id = ' . (int) $user->tenantid,
]);
```

Three properties worth naming, because each is a decision that could have gone the other
way:

- **A hidden source leaves no trace** — not an empty group. An empty group headed
  "Invoices" tells somebody who may not see invoices that invoices exist, how they are
  labelled, and that a search found none. It is also never queried.
- **A filter callable must return a string.** A `null` from a missing tenant drops the
  source rather than degrading to "no filter" — degrading would return every row of the
  table to a viewer entitled to a subset.
- **Grouped, not ranked.** No score across sources: a relevance number comparing a
  username to an invoice line is invented. `1/12` beside a group is honest in a way a
  merged, re-sorted list is not.

### `Html\SearchBox` and `Omnibox.svelte`

Both scaffold styles get a working box, not the parts for one. Server-rendered themes get
`Html\SearchBox` in the header — all three UI systems — with behaviour from
`data-pf-omnibox` in `pf-utils.js`; SPA projects get `components/Omnibox.svelte`, wired
into the app shell. One endpoint, one result shape, two front ends.

Both debounce and both **abort** the previous request. Without the abort a slow answer to
`an` lands after a fast answer to `anna` and replaces newer results with older ones, which
on screen looks like the search returning wrong matches rather than like a race.

### `create:crud` offers to register the new entity

It asks. Registering every generated CRUD silently would decide what an omnibox may
surface, by default, in the direction that leaks — an audit table and a join table should
never appear in one.

## Fixed

### The SPA API client dropped `AbortSignal`

`api.get(path, query, options)` forwarded `method`, `body`, `query`, `headers` and
`anonymous` to `fetch`, but not `signal`. Any caller passing one got a request that could
not be cancelled and no indication of it — the abort silently did nothing. `signal` is now
forwarded, and an `AbortError` is no longer reported to the debug panel as a network
failure: a search box cancelling a request per keystroke would otherwise fill the request
tab with errors nothing went wrong for.

## Not brought over from the legacy

The provider contract required each source to return objects with a `render()` method,
which put HTML inside models — so the admin's markup could only be changed by editing the
model layer. Display is configuration here: which columns, which URL pattern.
