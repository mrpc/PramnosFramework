---
use_cases:
  - Adding a card of your own to the administration dashboard
  - Letting each administrator arrange, resize, hide and add dashboard cards
  - Hiding a dashboard card from people who may not see its figures
  - Replacing or removing the framework's own dashboard cards
---

# Dashboard Guide

The administration dashboard (`DashboardController`, at `/admin/Dashboard`) is a grid of cards,
called widgets. Each person arranges it for themselves: which cards, in what order, how wide.
The framework brings four widgets; an application adds its own.

## A widget

```php
namespace App\Widgets;

use Pramnos\Dashboard\Widget;
use Pramnos\Html\StatTile;

final class OpenOrders extends Widget
{
    public function id(): string    { return 'orders.open'; }
    public function title(): string { return 'Open orders'; }
    public function link(): ?string { return adminUrl('Orders'); }

    public function render(): string
    {
        return StatTile::grid([
            StatTile::render('Open', (string) Order::openCount(), link: $this->link()),
            StatTile::render('Late', (string) Order::lateCount(), state: StatTile::WARNING),
        ]);
    }

    public function allowed(?object $user): bool
    {
        return \Pramnos\Auth\AdminAccess::allows($user, 'admin.orders');
    }
}
```

| Method | What it is | Default |
|---|---|---|
| `id()` | A unique name, kept in the layouts people save: do not rename it | — |
| `title()` | The card's heading | — |
| `render()` | The card's inside, as HTML; `''` is "nothing to show today" | — |
| `allowed($user)` | Whether this person may have it | usertype ≥ 80, the dashboard's level |
| `size()` | `Widget::HALF` or `Widget::FULL` | half |
| `byDefault()` | Whether a new dashboard has it | yes |
| `link()` | Where the heading leads | none |

`render()` returns the card's inside, and the dashboard draws the card around it in the theme's
classes. A widget that needs a model has the controller drawing it:
`$this->controller?->getModel('Orders')`. The controller is null outside a dashboard, and an
application's own dashboard controller hands it over with `$widget->setController($this)`. Draw figures with `Html\StatTile`, which every theme styles, or with the theme's own
classes. A widget that throws becomes a short note on its card and a line in the
`dashboard` log, and the rest of the dashboard still draws.

## Registering widgets

```php
// app/app.php
'dashboard' => [
    'widgets'   => [\App\Widgets\OpenOrders::class],
    'framework' => false,   // leave out the framework's four
],
```

Or in code, for example from a service provider:
`\Pramnos\Dashboard\WidgetRegistry::register(new OpenOrders())`. The order is the framework's
widgets, then app.php's, then the registered ones. A widget under an id that is already taken
replaces the earlier one. So an application can redraw a framework card under its id, and the
layouts people saved keep it.

The framework's widgets read their figures through `Dashboard\Widgets\Figures`, once per
request:

| Id | Card |
|---|---|
| `users.active` | Signed in now, in the last day and week; opens the sessions list |
| `api.performance` | Requests, the share that failed, average and p95 latency over 24 hours |
| `database` | Size, connections, cache hit ratio; opens the database page |
| `health` | Every health check, with its message when not ok — full width |

## Each person's layout

"Customise" shows each card's tools:

- move up or move down;
- wide or narrow;
- hide;
- a list of the cards this person may add.

In the same mode a card can be dragged into place. The tools are buttons, so a keyboard can do
all of it, and each change is announced in a live region. Each change is saved at once. Adding
a card saves and reloads the page, because its contents are drawn on the server. "Back to the
default" forgets the layout.

`pf-utils.js` does the editing over the `data-pf-dashboard*` attributes the bundled views draw.
It posts the layout, as JSON with the page's CSRF token, to `savelayout`. `resetlayout` forgets
it. Both are POST-only actions of the controller.

**A widget's `allowed()` is the only gate.** A layout is cleaned whenever it is read or written:

- a widget nobody registers any more is dropped;
- so is a widget this person may not have, and a second copy of one;
- an unknown width becomes the widget's own.

So a saved layout never shows what its owner's permissions do not allow, and a permission taken
away takes the card with it.

Layouts live in `user_dashboards` (`userid`, `area`, `layout` as JSON), from a framework
migration. `Dashboard\Layouts` reads and writes them:

```php
$layouts = new \Pramnos\Dashboard\Layouts();
$layouts->for($user);                // the saved layout, cleaned, or the default
$layouts->save($user, $layout);      // [{id, size}, …]
$layouts->save($user, null);         // back to the default
```

An area with a dashboard of its own sets `protected string $dashboardArea = 'partners';` on its
subclass of `DashboardController`, and its layouts are kept apart. The controller's links and
redirects follow its own class name. Until `migrate` creates the table, everybody sees the
default.

**Do not cache the dashboard page.** It carries the session's CSRF token, which `savelayout`
checks, and a cached copy hands one session's token to every other session, so their saves are
refused with 403. Each card can cache its own figures inside `render()` instead.

## A view of the older shape

A copy of `admin_dashboard` published before widgets existed still receives `$this->activeUsers`,
`$this->dbStats`, `$this->apiStats` and `$this->healthResults`, worked out once alongside the
widgets. Take the new copy with `project:publish-views --group=dashboard --force` to get the
arrangeable dashboard.
