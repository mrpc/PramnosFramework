---
use_cases:
  - Adding a card of your own to the administration dashboard
  - Letting each administrator arrange, resize, hide and add dashboard cards
  - Hiding a dashboard card from people who may not see its figures
  - Replacing or removing the framework's own dashboard cards
  - Letting each person set a card's period, market or chart type
  - Adding or redrawing a dashboard card without reloading the page
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
| `allowed($user)` | Whether this person may have it | the `manager` role |
| `size()` | The width it starts at: `Widget::SMALL`, `MEDIUM`, `LARGE` or `FULL` | medium |
| `options()` | What a person may set on it — see [Settings](#settings) | nothing |
| `description()` | A line on what it shows, for the list cards are chosen from | `''` |
| `byDefault()` | Whether a new dashboard has it | yes |
| `link()` | Where the heading leads | none |

**Widths.** The grid has four columns. `s` spans one, `m` two, `l` three and `full` all four. On a
tablet the grid has two columns, and anything wider than `s` spans both. On a phone it has one. A
layout saved with the older `half` reads as `m`, and `Widget::HALF` remains an alias for it.

`render()` returns the card's inside, and the dashboard draws the card around it in the theme's
classes. A widget that needs a model has the controller drawing it:
`$this->controller?->getModel('Orders')`. The controller is null outside a dashboard, and an
application's own dashboard controller hands it over with `$widget->setController($this)`. Draw figures with `Html\StatTile`, which every theme styles, or with the theme's own
classes. A widget that throws becomes a short note on its card and a line in the
`dashboard` log, and the rest of the dashboard still draws.

## Settings

A card that people tune declares what may be set, by name, and reads the choice with `setting()`:

```php
public function options(): array
{
    return [
        'period' => ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'],
        'market' => ['all', 'gr', 'cy'],
        'title'  => static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, 80) : null,
    ];
}

public function render(): string
{
    return Sales::chart((int) $this->setting('period'), (string) $this->setting('market'));
}
```

- **A list** is the values allowed. It can be `[value => label]` or a plain list, and its first
  value is the default. The card's form shows it as a select.
- **A function** answers the value as it is kept, or null to refuse it. The form shows a text
  field, and a refused or empty value is simply absent.
- **A field** says how it is asked:

  ```php
  'from' => ['label' => 'From', 'input' => 'date', 'when' => ['period' => 'custom'],
      'clean' => static fn (mixed $v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null],
  'chart' => ['label' => 'Chart', 'values' => ['bar' => 'Bars', 'line' => 'Lines']],
  ```

    - `input` is `text`, `date` or `number`, cleaned by `clean`; or `values` makes it a list, as
      above.
    - `label` is the field's words, translated by the view. Without one, the option's name is
      used.
    - `when` names the settings it is shown for. A custom period's dates appear only once
      `custom` is chosen, and a field hidden that way is not sent.

Each person's choices are kept in their layout, cleaned against `options()` whenever it is read.
A setting the widget does not declare is dropped, and a value outside a list becomes the list's
first. The dashboard hands a widget its settings with `configure()` before `render()`.
`Widget::cleanSettings($widget, $given)` is that cleaning, for code of its own.

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
- its width, s, m, l and full in turn;
- its settings (a gear, for a card that declares options);
- hide;
- a catalogue of the cards this person may have, in a dialog: each card with its
  `description()`, searched by a field, *Add* for a card not on the board and *On the board* for
  one that is. A card hidden is offered again at once, without a reload.

In the same mode a card is dragged by its grip, or by its title row (`data-pf-widget-head`), and
resized by its corner, with pointer events, so a finger does it as a mouse does. The title row
starts a drag only once the pointer has moved a few pixels, so a click stays a click, and never
from a link, button or field in it.

What makes a lifted card follow the pointer (`position: fixed`, its layer, no pointer events),
the handles' `touch-action: none` and the page's text selection are set inline by the script
and put back when let go. A theme's utilities layer, such as daisyUI's `.card { position:
relative }` or a `.btn`'s `touch-action: manipulation`, beats any stylesheet rule regardless of
specificity, so the stylesheet carries only the looks. While a card is being dragged:

- it follows the pointer, and a dashed placeholder of its size, at most a third of the window
  tall, shows where it will land;
- the placeholder moves to the side of the card under the pointer: above or below a card that
  fills its row, left or right of any other. It moves only after the pointer has moved a few
  pixels, so it does not flicker;
- the window scrolls near its top and bottom.

The corner resizes by whole columns, with the width written on the card as it is reached. The
arithmetic, `sizeForSpan`, `dropBefore`, `placeAt` and `autoScrollStep`, is on
`window.pfDashboard`. The tools are buttons, so a keyboard can do
all of it, and each change is announced in a live region. Each change is saved at once. A card
added, or one whose settings changed, is drawn by the server alone, through `card?id=…`, and put
in place without a reload. Its charts are drawn as it arrives. "Back to the default" forgets the
layout.

**One card alone.** `GET …/Dashboard/card?id=…` (`DashboardController::card()`) answers a card's
HTML, frame and all, with this person's saved width and settings, or the widget's own for a card
not yet placed. A card they may not have is 404. The bundled views draw every card through
`dashboard/card.html.php`, the same file the action renders, so the dashboard and the action
cannot draw a card differently. A view published before this has no `data-card` on the board,
and adding a card reloads the page as before.

`pf-utils.js` does the editing over the `data-pf-dashboard*` attributes the bundled views draw.
A catalogue is any `[data-pf-dashboard-add="id"]` button, in a `<dialog
data-pf-dashboard-catalogue-dialog>` opened by `[data-pf-dashboard-catalogue]`, with
`[data-pf-dashboard-catalogue-search]` to search and `[data-pf-dashboard-option]` and
`[data-pf-dashboard-present]` to show what is placed. A `<select data-pf-dashboard-add>` still
works, its placed options hidden rather than removed. Everything the script says, such as
*hidden* or the width names, comes from the board's `data-pf-dashboard-say`, JSON in the page's
language.
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
$layouts->save($user, $layout);      // [{id, size, settings}, …]
$layouts->save($user, null);         // back to the default
```

An area with a dashboard of its own sets `protected string $dashboardArea = 'partners';` on its
subclass of `DashboardController`, and its layouts are kept apart. A dashboard that is the area's
home page answers its address in `dashboardHome()`, which "Back to the default" returns to with
a message. The controller's links and
redirects follow its own class name. Until `migrate` creates the table, everybody sees the
default.

**Do not cache the dashboard page.** It carries the session's CSRF token, which `savelayout`
checks, and a cached copy hands one session's token to every other session, so their saves are
refused with 403. Each card can cache its own figures inside `render()` instead.

## A view of the older shape

A copy of `admin_dashboard` published before widgets existed still receives `$this->activeUsers`,
`$this->dbStats`, `$this->apiStats` and `$this->healthResults`. Each is a
`Dashboard\Widgets\LazyFigures`, read as an array, by index, loop or count, and worked out only
when read. So a dashboard that does not read them never runs the health checks behind them.
`->toArray()` gives the plain array. Take the new copy with `project:publish-views --group=dashboard --force` to get the
arrangeable dashboard.
