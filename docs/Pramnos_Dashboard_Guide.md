---
use_cases:
  - Adding a card of your own to the administration dashboard
  - Letting each administrator arrange, resize, hide and add dashboard cards
  - Hiding a dashboard card from people who may not see its figures
  - Replacing or removing the framework's own dashboard cards
  - Letting each person set a card's period, market or chart type
  - Adding or redrawing a dashboard card without reloading the page
  - Making a slow dashboard card fast by working its figures out once for everybody
---

# Dashboard Guide

The administration dashboard (`DashboardController`, at `/admin/Dashboard`) is a grid of cards,
called widgets. Each person arranges it for themselves: which cards, in what order, how wide.
The framework brings twelve widgets; an application adds its own.

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
| `render()` | The card's inside, as HTML; `''` is "nothing to show today" | `present(data())` |
| `shared()`, `data()`, `present()`, `dataKey()` | A card whose figures are kept and shared — see [Slow cards](#slow-cards-shared-figures) | not shared |
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

**A card with its own scripts or styles.** A card's `render()` may include `<script>` and
`<style>` elements, such as a map library and the code that draws the map. On a full page load they
run as usual. A card that arrives later, added from the catalogue or redrawn after its settings
change, is fetched alone and put in with `innerHTML`, which runs no script. Its markup would also
carry the card request's CSP nonce, not the page's. So `pf-utils.js` re-creates each of them in
order with the page's nonce, waits for a script with a `src` to load before the next one, and
then fires `pf:dashboard:card` on the card (it bubbles; `event.detail.id` is the card's id) for
anything else that has to be set up once the card is on the board. An inline `style="…"`
attribute is not covered: a nonce cannot apply to an attribute, so put sizes in a `<style>`
element or a stylesheet.

## Slow cards: shared figures

A card that reads every log, or sums a month of API calls, should not do so on every dashboard
anybody opens. If its figures are the same for everybody who may see it with the same settings,
make it **shared**. Split `render()` into the figures and the drawing:

```php
final class OpenOrders extends Widget
{
    public function shared(): bool { return true; }

    /** Plain values only: no words in a language, no links, no HTML, nothing of the person. */
    public function data(): array
    {
        return ['open' => Order::openCount(), 'late' => Order::lateCount()];
    }

    /** Drawn for the person looking: their language, the links they may follow. */
    public function present(array $data): string
    {
        return StatTile::grid([
            StatTile::render(t('Open'), (string) $data['open'], link: $this->link()),
            StatTile::render(t('Late'), (string) $data['late'], state: StatTile::WARNING),
        ]);
    }
}
```

The dashboard keeps `data()` in the cache's `dashboard` category (`Dashboard\WidgetData`), under
`dataKey()`: the card's id and its settings, without `title` and `chart`, which only change the
drawing. Two people whose cards differ only in that share one entry. A card whose figures depend
on something else adds it, for example the market a site is showing:
`return parent::dataKey() . ':' . Market::current();`. The first dashboard after an entry expires
works the figures out and keeps them. The card handed to a view carries `at`, when they were
worked out, so a view can say "updated 3 minutes ago". It is null for a card drawn on the spot.

`allowed()` still decides who sees the card. Kept figures are only ever drawn for somebody the card
allows, and `card()` answers 404 before reading them.

**How long they are kept:** five minutes, or `'dashboard' => ['data_ttl' => 600]` in app.php.
**Nobody waiting for them:** `dashboard:precompute` works out every shared card with its default
settings and with every setting saved in the area's layouts. From cron:

```bash
*/5 * * * * cd /var/www/app && php bin/pramnos dashboard:precompute            # --area=partner for another
```

With the cron every five minutes, keep `data_ttl` longer than that, so an entry never expires
between runs. The command exits `1` when a card could not be worked out, and keeps the others.

- **Whose cards:** those of `--app=<name>`, or of the area's own application where there is one
  (`app/admin.php` for `--area=admin`), or of the application the console started. A console
  that boots the site would otherwise find only the framework's cards.
- **A controller:** each card is handed the application's dashboard controller, so a `data()`
  that builds a model with `$this->controller?->getModel()` finds the models the area's screens
  find. That is `'dashboard' => ['controller' => App\Admin\Controllers\Dashboard::class]`, or
  `Controllers\Dashboard` in the application's namespace, or a plain controller of it. There
  is no person and no request.
- **Settings nobody saved:** the command works out each card with its defaults and every saved
  layout's settings. A card whose data has variants that people use without saving them — a
  market taken from a cookie — spreads them in `precomputeSettings(array $settings): array`,
  one setting into several, each naming what it depends on. An application can also add whole
  layouts by overriding the command's `savedLayouts($area)`.

The framework's slow cards are shared: `logs.errors`, `ai.spend`, `messages`, `users.active`,
`database`, `queue` and `api.performance`.

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

### The channels of the `messages` card

A channel is a way the application reaches people: SMS, push, a chat app. It implements
`Pramnos\Dashboard\MessageChannel` and is named in app.php or registered in code:

```php
final class Sms implements \Pramnos\Dashboard\MessageChannel, \Pramnos\Dashboard\MessageReach
{
    public function id(): string     { return 'sms'; }
    public function label(): string  { return 'SMS'; }              // translated by the card
    public function link(): ?string  { return adminUrl('Sms'); }
    public function figures(int $from, int $to): array               // total and failed always
    {
        return ['total' => 200, 'failed' => 20, 'delivered' => 180, 'cost' => 12.4, 'currency' => '€'];
    }
    public function reach(int $from, int $to): ?array                // optional: MessageReach
    {
        return ['recipients' => 150, 'average' => 21.4, 'missing_days' => 2];
    }
}

// app/app.php
'dashboard' => ['channels' => [\App\Dashboard\Sms::class]],
// or: \Pramnos\Dashboard\WidgetRegistry::channel(new Sms());
```

Email comes first wherever the send history (`mails`) exists. Its figures come from
`Pramnos\Email\SendHistory`, the class the `email` card and the analyst's `email` tool also read:
by status, by module, and the failures by module. `reach()` should answer from figures the channel
keeps per day, because a distinct count over a long history cannot run inside a page request. It
says how many days it has no figure for. A channel that throws is a line on the card, not a
missing card.

### A period each person sets

The `Pramnos\Dashboard\HasPeriod` trait offers today, the last 7, 30 or 90 days, or two days of
one's own, and reads the choice back. The framework's `queue`, `email` and `ai.spend` cards use
it.

```php
final class Orders extends Widget
{
    use \Pramnos\Dashboard\HasPeriod;

    public function options(): array      { return $this->periodOptions(); }   // period, from, to
    public function defaultPeriod(): string { return '30'; }                     // '7' unless said
    public function maxDays(): int        { return 90; }                         // 0: no limit

    public function render(): string
    {
        $figures = (new OrdersTool())->execute($this->window());   // ['days' => 30] or ['from', 'to']
        [$from, $to] = $this->periodRange();                        // two moments, for a query of its own
        return StatTile::render('Orders', $figures['count'], $this->periodLabel());
    }
}
```

It is a trait rather than methods on `Widget`, so a card of an application's own that already has
`period()` or `window()` keeps them: a class's own method wins over a trait's. A custom period
without two days in order, or a value not offered, falls back to the card's default. A period
longer than `maxDays()` is cut to it.

## Registering widgets

```php
// app/app.php
'dashboard' => [
    'widgets'   => [\App\Widgets\OpenOrders::class],
    'framework' => false,   // leave out the framework's own
],
```

`framework` is `true` (all of the framework's cards, the default), `false` (none), or a list of
ids — only those, in that order, so taking one card does not bring the developer tools with it:

```php
'dashboard' => [
    'framework' => ['ai.spend', 'logs.errors', 'users.active'],
    // A framework card asks for the ability of the screen it summarises; rename it where yours differs
    'abilities' => ['email' => 'admin.logs.emails'],
    // …and leads to that screen: a path in the area, an address, or '' for no link
    'links'     => ['ai.spend' => 'Settings/ai', 'logs.errors' => ''],
    // Any card, the framework's or another's: whether a new dashboard has it, and a role from
    // which it is offered, on top of its own rule, which this can raise and never lower
    'cards'     => ['logs.errors' => ['default' => false, 'min_role' => 'root']],
],
```

To offer the framework's cards in the catalogue but put none of them on a new dashboard, say so
once rather than per id — a list of ids under `cards` goes stale the day the framework adds a
card, which then appears on new dashboards with nobody having chosen it:

```php
'dashboard' => [
    'framework_default' => false,
    'cards' => ['health' => ['default' => true]],   // a framework card you do want placed
],
```

The application's own cards keep their own default. The catalogue dialog is hidden by
`components.css` while closed even when it carries a theme's `.card` (a `display: flex` card
otherwise outranks the browser's own rule), and the grip falls back to a symbol font for the ⠿
glyph a theme font may not have. An mvc application never scaffolded from a theme gets that
stylesheet with `project:resync --css --all` (bootstrap's copy when `scaffold_theme` is unset).

The framework's cards say their tile labels through `t()` (`Figures::words()`), and the dashboard
translates each card's title, so a translation of the words puts the cards in the page's language.

Or in code, for example from a service provider:
`\Pramnos\Dashboard\WidgetRegistry::register(new OpenOrders())`. The order is the framework's
widgets, then app.php's, then the registered ones. A widget under an id that is already taken
replaces the earlier one. So an application can redraw a framework card under its id, and the
layouts people saved keep it.

The framework's widgets, in the order a new dashboard shows them:

| Id | Card | Who gets it |
|---|---|---|
| `users.active` | Signed in now, in the last day and week; opens the sessions list | the dashboard's role |
| `api.performance` | Requests, the share that failed, average and p95 latency over 24 hours | the dashboard's role |
| `database` | Size, connections, cache hit ratio; opens the database page | the dashboard's role |
| `queue` | Tasks waiting now; failed and finished in the period (today by default), failures critical; opens the queue | who may open `admin.queue`, where the `queue` feature is on |
| `email` | Email in the period (7 days by default) from the send history (`mails`): sent, failed (with the failure rate), queued; the share opened only where tracked mail was sent in it; opens the email screen | who may open `admin.emails` |
| `messages` | Every channel messages go out by, a row each, for the period: email from the send history, then the application's own (`MessageChannel`); sent, failed, queued, delivered, cost, people reached — full width, **not on a new dashboard** | who may open `admin.emails`, where there is a channel |
| `ai.spend` | Model calls in the period (30 days by default): cost, calls, failed; opens the AI settings | who may open `admin.ai`, where the `llm` feature is on and a key is set |
| `ai.pinned` | Pinned from Ask | The tables, charts and lists the person pinned from their Ask answers, each as it was kept | any signed-in person, where the `llm` feature is on | – |
| `health` | Every health check, with its message when not ok — full width | the dashboard's role |
| `logs.errors` | Errors in the logs in 24 hours and the commonest five; **not on a new dashboard** — it reads the log files — but in the catalogue | who may open `admin.logs` |
| `vitals` | Core Web Vitals from real visitors over 7 days: the 75th percentile of LCP, INP, CLS and TTFB per page group and device, coloured by web.dev's thresholds; **not on a new dashboard** — see the Logging Guide | who may open `admin.logs`, where `vitals.sample` is set |
| `help.news` | What's new: the help pages of the news sections (`News` and `Changelog`, or `'dashboard' => ['help_news' => ['sections' => [...], 'limit' => 5]]`), newest first by their `updated` front matter, each a link, and a link to all of the help; **not on a new dashboard** | who may open `admin.help`, where the `help` feature is on |
| `sessions.online` | Who is signed in now: each session of the last fifteen minutes, newest first — the account (a link), IP, time, page, browser — with a **Sign out** button (`Users/endsession`, POST of the session's `sid` with the token; this administrator's own session has none), and how many are signed in and how many are visitors; full width, **not on a new dashboard**. Extend it and answer `rowExtras(array $session)` to add a column, registered under the same id | who may open `admin.users` |

A card that summarises a screen is offered only to who may open that screen, at the framework
screens' floor (`Figures::opens()`), so the dashboard never shows more than the screen behind it.
A card whose feature has no table yet draws nothing.

The first four and `health` read their figures through `Dashboard\Widgets\Figures`, once per
request. `queue` and `ai.spend` read the analyst's own tools (`QueueTool`, `LlmSpendTool`), so the
dashboard and the analyst give the same numbers. `email` counts the send history by status in one
query over the `date` index — every message has a row there whatever became of it, tracked or not.
`logs.errors` reads `Logs\LogAnalytics::summary()`, as the log dashboard does.

## Boards: a dashboard for each screen

A board is a dashboard with a name, its own catalogue and its own defaults. Each person
arranges each board separately. The administration dashboard is the board `admin`, and a board
app.php does not describe is the top-level configuration above, as it always was.

```php
'dashboard' => [
    'boards' => [
        'wcm' => [
            'widgets'   => [\App\Widgets\WcmMap::class, \App\Widgets\WcmStats::class],
            'framework' => false,                    // none of the framework's cards on this board
            'default'   => ['wcm.stats', 'wcm.map'], // what a new board has, in this order
            'cards'     => ['wcm.map' => ['min_role' => 'manager']],
        ],
    ],
],
```

A board's keys replace the top level's where it names them, so it names only what differs:
`widgets`, `framework`, `framework_default`, `default` and `presets`. `cards` is merged by id over
the top level's. With a `default` list, that list alone decides what a new board has, in its
order. Without one, each card's own default applies, as at the top level.

A card can also choose its boards itself, which suits one registered in code:
`public function boards(): ?array { return ['wcm']; }`. It is then offered on those boards only.
The default, `null`, means every board.

A board's layouts are kept apart from the others, under `dashboard.{board}` in `usersettings`,
and are cleaned against that board's catalogue. A `DashboardController` subclass sets its board with
`protected string $dashboardArea = 'wcm';`.

### A board inside any screen

A board need not be a page of its own. Any screen can draw one above or instead of its own
content:

```php
// in the WCM screen's action
$html = \Pramnos\Dashboard\Board::render('wcm', 'Overview');   // '' when the board is not theirs
```

It works as the dashboard does: Customise, then move, resize, add from the board's catalogue. Its
controls talk to the application's dashboard controller with `?board=wcm`, so the screen needs no
actions of its own. That controller is the one `'dashboard' => ['controller' => …]` names, the
application's `Controllers\Dashboard`, or the framework's, whichever is a `DashboardController`
first. `?board=` may name only a board app.php lists; anything else is the controller's own
board, so a save never lands under a name no screen draws. `Board::render()` throws for a board
app.php does not list. It reaches `DashboardController::boardFor($controller, $board, $title)`,
which is static so that no public instance method on the controller reads as an action.

A board is open to anybody signed in, and each card still asks its own `allowed()`. A board can
raise the bar with `'boards' => ['wcm' => ['min_role' => 'manager']]`: below it, the board draws
nothing and its saves answer 403. Drawn this way, the view gets `$this->embedded = true` and
`$this->boardTitle`, and draws no page heading or frame.

## Tabs: several cards in one frame

Cards can share one frame, with one tab per card. Only the active tab's card is drawn. The others
are drawn by the server the first time they are opened (`card?id=…&part=body`), so a heavy card
costs nothing until someone looks at it. Each person's active tab is kept with their layout,
like a width.

**As a default or a preset**, write a group where a card would go:

```php
'boards' => ['wcm' => [
    'default' => ['wcm.stats', ['tabs' => ['wcm.map', 'wcm.status.location', 'wcm.status.deya', 'wcm.consumption'], 'title' => '']],
    'presets' => ['Operations' => ['wcm.stats', ['tabs' => ['wcm.map', 'wcm.consumption']]]],
]],
```

A group spans the board unless it is given a `size`. In a layout it is kept as
`{id: 'tabs:…', size, tabs: [{id, settings}, …], active, title}`, and it is cleaned like its
cards. A tab the person may not have is dropped, a card appears once in the whole layout, a group
left with one card becomes that card, and an empty group is removed.

**While customising**, a group moves, resizes and hides as one card. Grouping can be done by
dragging or with buttons, and the buttons work from a keyboard:

- Drag a card by its grip onto a group's strip of tabs, and when let go it becomes that group's
  active tab. The strip is outlined while it would take the card. The card button *Group into tabs
  with the next card* does the same with the card after it, or joins the group after it.
- Drag a tab along its strip to change its place, without a reload. Drag it out of its group and
  let go, and it becomes a card again just after the group. The group's buttons move the active tab
  left or right, or take it out.
- A group's gear opens the active tab's own settings, the same form as the card has on its own.
  Applying saves them with the layout and redraws that tab's contents in place, with no need to
  take the card out of the group first.

**A card's script and a hidden tab.** A card drawn into a hidden panel has no size until it is
shown, so a map, for example, draws at 0×0. When a tab becomes visible, and when a card or a
group is resized, the script fires `pf:dashboard:shown` on the card's element. The event bubbles,
and `event.detail.id` is the card's id:

```js
document.addEventListener('pf:dashboard:shown', (e) => { if (e.detail.id === 'wcm.map') { map.invalidateSize(); } });
```

**A theme draws its own tabs** by copying `views/dashboard/tabs.html.php`. The script relies only
on these attributes: `data-pf-widget-group` on the frame, `data-pf-tabs` on the strip (with
`data-active-class`, the theme's class for the active tab), `data-pf-tab` on each tab, and
`data-pf-tab-panel` on each panel (with `data-pf-tab-lazy` until the panel is drawn). Each panel
holds its card's settings form (`card_settings.html.php`, the same partial `card.html.php`
uses), and its contents go in `data-pf-tab-body`.

### What a card carries, from an application's controller

`DashboardController::cardFor(Widget $widget, array $item)` builds what the view draws for one
card — heading, settings, fields and its inside — and an application's controller may override it
to add its own keys:

```php
protected function cardFor(\Pramnos\Dashboard\Widget $widget, array $item): array
{
    return parent::cardFor($widget, $item) + ['subtitle' => $widget->subtitle()];
}
```

A tab not shown yet comes with `$item['draw'] === false` and no `html`; it is drawn when it is first
opened. Pass `$item` on unchanged and that keeps working.

## Presets: named layouts, saved and shared

A preset is a named layout of a board. Applying one **copies** it into the person's own layout,
so their later changes are theirs and the preset stays as it was. The *Presets* button in the
customise bar opens the board's presets.

**The application's presets** come from app.php, by board and name, and every person who has
the board sees them:

```php
'dashboard' => ['boards' => ['wcm' => ['presets' => [
    'Operations' => ['wcm.map', 'wcm.stats'],                    // ids, at their own widths
    'Management' => [['id' => 'wcm.stats', 'size' => 'full']],  // or items, as a layout keeps them
]]]],
```

**A person's presets** are saved from the dialog (*Save as preset*) as the board stands, and kept
in `pramnos.dashboard_presets`. Who sees one is the saver's choice:

| Who sees it | `visibility` | `audience` |
|---|---|---|
| Only me | `private` | — |
| From a user type up | `role` | the usertype it starts at, e.g. `90`: everybody at that usertype or above (a role's name, `manager`, is read too) |
| Members of an organisation | `organization` | its id: its members, as the application's organisation source says |
| Everyone | `everyone` | — |

**The user types** offered are the application's own: `'usertypes' => [90 => 'Administrator',
98 => 'Super Administrator', 99 => 'Root', …]` in app.php (`Pramnos\User\UserTypes`; an
application with its own list writes `'usertypes' => \App\User::getAllUserTypes()`). Only the types
from the board's floor up are offered, because nobody below it could open the board. That floor is
the administration's, or the board's `min_role` where that is higher. The same list serves *Set as
default*.

**Organisations are the application's to define.** An application whose people are grouped
differently (water utilities, branches, schools) gives its own source:

```php
'dashboard' => ['organizations' => \App\Dashboard\Utilities::class],   // implements Dashboard\OrganizationSource
'dashboard' => ['organizations' => false],                                // no such choice at all
```

`OrganizationSource` has three methods: `label()` (what one is called, for the dialog, translated
by the view), `choices()` (id => name) and `isMember($user, $id)`. Without a source, the framework's
own organisations are used (`FrameworkOrganizations`, active ones by name, with membership read where
the permission system reads it), and the choice appears only when there are any.

The owner deletes their own presets, and an administrator (the `admin` role) may delete any.

**A preset is cleaned for whoever applies it.** A card the receiver may not have is dropped,
not shown, and the page says how many were left out ("2 cards of this preset are not available
to you"). Sharing therefore never opens a card that a person's permissions keep closed.

**A role's default.** An administrator can make a saved preset what a new board starts from for
a role (*Set as default*). It applies to anybody of that role or above who has no layout of their
own and to anybody who resets to the default, and it takes precedence over the board's `default`.
Each role and board has one default preset, and marking another preset moves the mark. When a
person holds several roles, the highest one with a default wins.

**Copy my dashboard to somebody.** An administrator can copy their own board to another account
to set someone up. The person is chosen by name, username or email from a picker
(`Dashboard/people?q=…`, administrators only). It offers at most 15 active accounts that can open
the board, never the administrator themselves. The server holds a submitted id to the same rule.
The copy replaces that person's layout for the board, is cleaned for them, and is recorded in the
audit log as `dashboard.board_copied`.

**The dialog** is in tabs: *Presets*, *Save as preset*, *New dashboard*, *My dashboard* (on one's
own board only) and *Copy to a user* (administrators only). A field that belongs to one choice,
such as the user type for *From a user type up*, appears and is sent only for that choice. After a
form posts, the page reopens the dialog on the tab it was sent from (`#pf-presets=save`).
`presets_dialog.html.php` is the partial to copy for a theme of one's own. The script reads only
its `data-pf-*` attributes, which the file's docblock lists.

The administration's list of every saved preset is `/admin/Dashboard/presets`, linked as
*All presets* from the dialog. The dialog's actions are ordinary forms (`applypreset`,
`savepreset`, `deletepreset`, `presetdefault`, `copyboard`) that post with the session's token,
take `?board=`, and return to the page they came from. In PHP, `Dashboard\Presets` exposes
`visibleTo()`, `save()`, `delete()`, `setDefaultForRole()` and `defaultLayoutFor()`.

## A dashboard of one's own, in the menu

Anybody who uses the administration dashboard can make another dashboard for themselves:
*Presets and dashboards* → *A new dashboard of your own* → a name, and optionally a preset to
start from. It appears in their menu under **Dashboard**, offers the administration
dashboard's cards (the `admin` board's catalogue), and they arrange it as any board: Customise,
add, move, resize.

The owner can rename it and choose who has it in their menu, with the same choices as a preset:
only me, from a role up, an organisation's members, or everyone. Everybody who sees it arranges it
for themselves. Someone opening a dashboard that was shared with them starts from the owner's
arrangement, cleaned for what they may have, and from their first change onward it is their own.
The owner deletes it, or an administrator does, and every viewer's arrangement goes with it.

Such a board is named `my-{id}` wherever a board name goes (`?board=my-7`) and is kept in
`pramnos.dashboard_boards`. In PHP, `Dashboard\UserBoards` exposes `create()`, `update()`,
`delete()`, `find()`, `visibleTo()` and `startingLayout()`. The menu entries come from a
`NavRegistry::provide()` closure that the framework registers, using `ability: 'admin.dashboard'`,
so whoever may open the dashboard sees their boards — see the
[navigation guide](Pramnos_Theme_Navigation_Guide.md#items-that-depend-on-who-is-looking).

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
from a link, button or field in it. While it waits, the browser's own drag and text selection are
refused, because either would take the pointer, and the row's text is not selectable while
customising.

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

A layout is a user setting: `dashboard.{area}` in `usersettings` (or `DB_USERSETTINGSTABLE`), the
layout as its JSON value. `Dashboard\Layouts` reads and writes them:

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
