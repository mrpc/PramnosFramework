---
use_cases:
  - Adding a search box that covers several entities at once
  - Making a new model findable from the admin search box
  - Restricting which entities a user can find, or which rows they see
  - Diagnosing a search box that renders but finds nothing
---

# Cross-Entity Search Guide

One term, several entities, grouped results — the search box in an admin header that
finds a user, an order and an invoice without you choosing a screen first.

## What was already there, and what this adds

Searching **one** entity has never needed this page. Every model implements
`Application\ApiList\ApiListSource`, and `ApiListQuery` turns a term into a query with
paging, per-field search, dialect-correct `ILIKE`/`LIKE` and honest totals. That is what
a `Datatable`'s search box uses, what a generated CRUD list uses, and what
`GET /admin/users?search=…` uses.

What no entity can implement for itself is the **aggregate**. `Search\Registry` is that,
and nothing else: a list of sources, and a loop over the engine that already exists.

| | Per-entity search | Cross-entity search |
|---|---|---|
| What answers it | `ApiListSource` on the model | `Search\Registry` |
| Where it appears | a list screen's own search field | one box in the header |
| Configuration needed | none | `app/search.php` |

## Registering a source

Sources are declared in `app/search.php`, a plain PHP file loaded by
`Registry::loadDefinitions()` — the same convention as `app/schedule.php`.

```php
use Pramnos\Search\Registry;

Registry::register('Users', \Pramnos\User\User::class, [
    'display' => ['username', 'email'],
    'url'     => '/users/edit/:id',
]);
```

A scaffolded project gets this file with `User` already registered. `create:crud` offers
to add a line for each new entity — it asks rather than doing it, because an omnibox is
a decision about what may surface and some tables should never appear in one.

### Options

| Option | |
|---|---|
| `display` | Columns to show. **The first is the result title**, the rest become the subtitle. Defaults to the source's own default fields, which is a guess — set it. |
| `url` | Link pattern; `:id` is replaced with the primary key, URL-encoded. Omit for a result that is not a link. |
| `limit` | Cap for this source. Defaults to the query's per-source cap (5). |
| `fields` | Columns to select. Defaults to the primary key plus `display`. |
| `order` | Order spec, passed through to `ApiListQuery`. |
| `permission` | Who may see this entity at all — see below. |
| `filter` | A `WHERE` body applied before the term. As a callable, which rows this viewer may see. |

The source itself may be an `ApiListSource` instance, the **class name** of one, or a
callable returning one. Prefer the class name: it is not constructed until something
actually searches, so a registry of six models costs nothing on the requests that never
use the box.

## Permissions

The endpoint guards the box as a whole — `ApiAdmin::search()` runs `guard('search')`, so
it is authenticated and permission-checked like every other admin action. Inside the
registry there are two further levels, and both are opt-in.

### Per source — who may see this entity

```php
Registry::register('Invoices', \App\Models\Invoice::class, [
    'display'    => ['reference', 'customer_name'],
    'permission' => 'invoices.list',        // checked with Auth\Gate
]);

Registry::register('Payroll', \App\Models\Payslip::class, [
    'display'    => ['employee', 'period'],
    'permission' => static fn($user) => $user?->usertype === 'hr',
]);
```

A string goes through [`Auth\Gate::allows()`](Pramnos_Authorization_Guide.md); a callable
receives the current user (or `null`) and returns a bool.

!!! warning "A hidden source leaves no trace in the response"
    Not an empty group. An empty group headed "Invoices" tells somebody who may not see
    invoices that invoices exist, how they are labelled, and that a search found none.
    The group is absent entirely, and the source is never queried.

`Gate` is **fail-closed**: an ability with no rule, policy or stored permission behind it
decides `false`. That is the right default and the one surprising one — a `permission`
naming an ability nothing defines hides the source from everybody, administrators
included. If a registered entity never appears, check that its ability is actually
defined before checking anything else.

No `permission` means visible: the source inherits the endpoint's own grant rather than
being open to the world.

### Per row — which records this viewer may see

`filter` as a callable is how a tenant scope is written:

```php
Registry::register('Orders', \App\Models\Order::class, [
    'display' => ['reference', 'customer_name'],
    'filter'  => static fn($user) => 'deleted = 0 AND tenant_id = ' . (int) $user->tenantid,
]);
```

!!! danger "A filter callable must return a string"
    Anything else — a `null` from a missing tenant, an early return somebody added —
    drops the source instead of degrading to "no filter". Degrading would return **every
    row of the table** to a viewer entitled to a subset. This is the one place where the
    safe direction is not the convenient one, so it is enforced rather than documented.

## What comes back

```json
{
  "query": "ann",
  "total": 13,
  "groups": [
    {
      "label": "Users",
      "total": 12,
      "results": [
        { "id": 1, "title": "annak", "subtitle": "anna@example.com", "url": "/users/edit/1" }
      ]
    }
  ]
}
```

**Grouped, not ranked.** There is no score across sources, deliberately: a relevance
number comparing a username to an invoice line is invented. `1/12` next to a group is
honest in a way that a merged, re-sorted list is not.

A source that fails — a table a migration has not created, a query that errors — is
logged to the `search` log and dropped. One undeployed model must not take the search box
down for every other entity.

## The user interface

Both scaffold styles ship a box; both call the same endpoint and render the same shape.

**Server-rendered themes** get [`Html\SearchBox`](Pramnos_Html_Components_Guide.md#searchbox)
in the header, with behaviour from `data-pf-omnibox` in `assets/js/pf-utils.js` and styles
from `assets/css/style.css`. All three UI systems (Bootstrap, Tailwind, plain) get it.

**SPA projects** get `components/Omnibox.svelte`, wired into the app shell.

Both render only for a signed-in user who can reach the admin section, and only when
something is registered — a box that always answers 403, or always finds nothing, is
worse than no box.

Both debounce (250 ms) and both abort the previous request. The abort is not an
optimisation: without it a slow answer to `an` can land after a fast answer to `anna` and
replace newer results with older ones, which on screen looks like the search returning
wrong matches rather than like a race.

### Rendering it somewhere else

```php
$box = new \Pramnos\Html\SearchBox();   // endpoint from the app's api_prefix
$box->placeholder = 'Find anything…';
$box->id = 'sidebar-search';            // needed only for a second box on one page
echo $box->render();
```

The component contributes **no script and no stylesheet** — both are already on the page.
That is the same rule `Html\Input` follows: an element that pushes assets into the
document while rendering itself makes echoing a search box change the page's asset list.

## Existing projects

A project scaffolded before this existed has no `app/search.php`, and
`loadDefinitions()` returning false means "no search box" rather than an error. To adopt
it, create the file, register at least one source, and:

- **server-rendered** — add `echo (new \Pramnos\Html\SearchBox())->render();` to your
  header and re-take `pf-utils.js` and the omnibox styles;
- **SPA** — `pramnos project:resync --spa-components` writes `components/Omnibox.svelte`.

The route is `GET <api_prefix>/admin/search`, handled by
`\Pramnos\Auth\Controllers\ApiAdmin::search()`.

## See also

- [HTML Components](Pramnos_Html_Components_Guide.md) — `SearchBox`, `Input`, `Select`, `Pagination`
- [Query Builder](Pramnos_QueryBuilder_Guide.md) — the query half
- [Authorization](Pramnos_Authorization_Guide.md) — `Gate`, abilities and policies
