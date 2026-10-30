---
use_cases:
  - Placing widgets in a theme's areas
  - Building a menu, and marking the page a person is on
  - Adding navigation: breadcrumbs, the admin menu, links between screens
---

# Widgets, menus and navigation

Part of the [theme guide](Pramnos_Theme_Guide.md).

## Widget System

### Registering Widget Areas

```php
// In the theme class
class mytheme_theme extends \Pramnos\Theme\Theme
{
    public function init()
    {
        // registerWidgetArea($name, $description, $id, $options)
        $this->registerWidgetArea('Main Sidebar', 'Main sidebar widget area', 'sidebar-1', [
            'before_widget' => '<div class="widget">',
            'after_widget'  => '</div>',
            'before_title'  => '<h3 class="widget-title">',
            'after_title'   => '</h3>',
        ]);

        $this->registerWidgetArea('Footer Widget 1', 'First footer widget area', 'footer-1');

        return parent::init();
    }
}
```

`register_sidebar(['name' => …, 'id' => …, 'before_widget' => …])` is an alias that takes the
same values as one array.

### Displaying Widget Areas

```php
// In theme layout files. renderWidgetArea() returns '' for an unknown or empty area.
<?php $sidebar = $this->renderWidgetArea('sidebar-1'); ?>
<?php if ($sidebar !== '') : ?>
    <div class="sidebar"><?php echo $sidebar; ?></div>
<?php endif; ?>

// Multiple footer widgets
<div class="footer-widgets">
    <?php for ($i = 1; $i <= 3; $i++) : ?>
        <?php $area = $this->renderWidgetArea("footer-{$i}"); ?>
        <?php if ($area !== '') : ?>
            <div class="footer-widget-<?php echo $i; ?>"><?php echo $area; ?></div>
        <?php endif; ?>
    <?php endfor; ?>
</div>
```

### Custom widget development

A widget implements `Pramnos\Theme\WidgetInterface`, or extends `Pramnos\Theme\Widget` and
writes only its body:

```php
use Pramnos\Theme\Widget;

class LatestPosts extends Widget
{
    protected function content(array $args): string
    {
        return '<ul>…</ul>';
    }
}
```

Register the type once, so a stored widget record can find its class:

```php
$theme->widgets()->register('latest-posts', LatestPosts::class);

// or a factory, when the widget needs its stored settings
$theme->widgets()->register('html', fn (array $record) => new RawHtml($record['html'] ?? ''));
```

`renderWidgetArea()` then renders each stored widget in the area, wrapped in the
`before_widget` / `before_title` arguments the area was registered with:

```html
<aside class="widget">      ← before_widget
  <h3>Latest posts</h3>     ← before_title + title + after_title, when a title is set
  <ul>…</ul>                ← content()
</aside>                    ← after_widget
```

**A widget with nothing to say renders nothing at all** — no empty wrapper, no stray heading —
so a theme can test whether an area produced anything instead of asking each widget in advance.

**A stored record whose type is no longer registered is skipped, not fatal.** Widget records
outlive the code that renders them: a plugin is removed, a type is renamed, and the record is
still in the settings. A sidebar must not take the page down over one stale entry. Those types
are collected in `$theme->widgets()->unresolved()` so the situation is findable rather than
merely survivable.

#### What this costs a theme that has no widgets

Nothing, by design and by test:

- the stored widgets setting is read on **first use**, not when the theme is constructed;
- the registry is constructed on first use, so a project that registers nothing never builds
  one;
- `renderWidgetArea()` on an area with no stored widgets returns after one array lookup,
  without touching the registry at all.

There is no table and no migration — widget records live in the theme's existing settings.

## Menu System

### Registering Menu Areas

```php
// In the theme class
class mytheme_theme extends \Pramnos\Theme\Theme
{
    public function init()
    {
        // register_nav_menu($location, $description, $menuid = null)
        $this->register_nav_menu('primary', 'Primary Navigation');
        $this->register_nav_menu('footer', 'Footer Navigation');
        $this->register_nav_menu('social', 'Social Links');

        return parent::init();
    }
}
```

`hasMenuAreas()` and `getMenuAreas()` report what was registered; `setMenu($location, $menuid)`
assigns a menu id to a location.

### Displaying Menus

```php
// Basic menu display — echoes by default
<?php $this->displayMenu('primary'); ?>

// With options
<?php $this->displayMenu('primary', [
    'container'       => 'nav',
    'container_class' => 'main-navigation',
    'container_id'    => 'site-navigation',
    'menu_class'      => 'nav-menu',
    'menu_id'         => 'primary-menu',
    'before'          => '<li class="menu-item">',
    'after'           => '</li>',
    'link_before'     => '<span>',
    'link_after'      => '</span>',
    'echo'            => true,
]); ?>

// As a string, to test whether anything rendered
<?php $menu = $this->displayMenu('primary', ['echo' => false]); ?>
<?php if ($menu !== '') : ?>
    <nav class="main-navigation"><?php echo $menu; ?></nav>
<?php endif; ?>
```

### Rendering a menu

The framework has **no menu storage** — menus are an application concern, and every project
that has them has its own table. So a theme says where items come from, once:

```php
$theme->setMenuItemsProvider(
    fn ($menuId, $location) => Menu::load($menuId)?->toTree()
);
```

`displayMenu()` then renders them through `Pramnos\Theme\MenuWalker`. An item is an array and
only `title` is required:

```php
['title' => 'Home', 'url' => '/', 'active' => true, 'children' => [...]]
```

| Key | Meaning |
| --- | --- |
| `title` | The link text. Escaped. Also accepted: `name`, `label`. |
| `url` | Where it goes. Escaped. Also accepted: `link`, `href`. **An item with no URL renders as a `<span>`** — an anchor with no `href` is a broken promise. |
| `active` | Marks the current item |
| `children` | Nested items, to any depth. Also accepted: `submenu`, `items`. |
| `class` | Extra classes on the `<li>` |
| `target`, `rel` | Passed through to the anchor when set |

The alternative spellings exist because menu rows come from tables that predate this class, and
renaming a column is not a reasonable price for rendering a list.

**With no provider, `displayMenu()` returns an empty string.** A theme asking for a menu that
has no source should render a page without a menu.

#### Customising the markup

Subclass the walker and override one method:

```php
class BootstrapWalker extends \Pramnos\Theme\MenuWalker
{
    protected function anchor(array $item): string
    {
        return '<a class="nav-link" href="' . htmlspecialchars($this->urlOf($item)) . '">'
            . htmlspecialchars($this->titleOf($item)) . '</a>';
    }
}

$theme->setMenuWalker(new BootstrapWalker());
```

The walker is a pure function of its inputs — items in, string out, no database and no
application — so it can be unit-tested on its own.

The `displayMenu()` arguments above work, including the `[URL]`, `[TITLE]`,
`[ACTIVE]…[/ACTIVE]` and `[HASSUB]…[/HASSUB]` markers in `topmenuoption` and `before`. A theme
that passes none of them gets sensible markup without knowing they exist.

## Navigation

A theme never hardcodes a menu link. Framework features and the application register
`NavItem`s with `Pramnos\Application\NavRegistry` once at boot, and the header (or sidebar)
asks for the items the current visitor may see:

```php
$user     = \Pramnos\User\User::getCurrentUser() ?: null;
$features = \Pramnos\Application\Application::currentInstance()?->applicationInfo['features'] ?? [];
$nav      = \Pramnos\Application\NavRegistry::getForUser($user, $features);
```

`$nav` is an array keyed by section (`'main'`, `'user'`, `'admin'`, `'feature'`), each a flat
list of `NavItem` objects sorted by `position`. Everything else — nesting, group headings,
icons, badges, the active item — is the theme's job, and the rest of this section shows how.

### Sections

`NavSection` is a string-backed enum. Every item belongs to exactly one section, and the
section says *where* a theme puts it, not who may see it.

| Case | Value | Meant for | Framework items |
| --- | --- | --- | --- |
| `NavSection::Main` | `main` | primary links for every visitor | `main.home` |
| `NavSection::User` | `user` | the account cluster | `user.login`, `user.account`, `user.messages`, `user.chat`, `user.logout` |
| `NavSection::Admin` | `admin` | administration screens — a dropdown or a sidebar | `admin.dashboard`, `admin.users`, `admin.settings`, `admin.logs`, … |
| `NavSection::Feature` | `feature` | links an optional feature adds | none; available to applications |

Index the result with `NavSection::Admin->value`, not a literal, so a typo is an error
rather than an empty menu. A section with no visible items is **absent** from the array, so
always read it with `?? []`.

### `NavItem` fields

`NavItem` is a `readonly` value object: to change an item, register a new one with the same
id. Arguments after `section` are optional and are normally passed by name.

| Field | Type | Default | Meaning |
| --- | --- | --- | --- |
| `id` | `string` | — | Unique key. Registering the same id again **replaces** the item; `remove()` takes it. For an Admin item it is also the screen's ability (see below). |
| `label` | `string` | — | Text shown. Rendered as given — escape it in the template. |
| `url` | `string` | — | Full URL. Build public links with `sURL`, admin links with `adminUrl()` / `AdminArea::url()` so they lead into the administration area. |
| `section` | `NavSection` | — | Which section the item belongs to. |
| `position` | `int` | `50` | Sort order inside its section, ascending. Equal positions keep registration order. |
| `requireAuth` | `bool` | `false` | Hidden from guests. |
| `minUserType` | `int` | `0` | Minimum `usertype`; `0` means no floor. |
| `permission` | `?string` | `null` | Permission name checked against the permission store (see filtering). |
| `feature` | `?string` | `null` | Shown only when this key is in the features passed to `getForUser()`. |
| `icon` | `?string` | `null` | An icon **name** (`gauge`, `users`, `mail`, `shield`, …). The theme maps it to its own icon set. |
| `guestOnly` | `bool` | `false` | Hidden from signed-in users — the Login link. |
| `parent` | `?string` | `null` | Id of the item this one is nested under. |
| `group` | `?string` | `null` | Heading this item sits under in a sidebar (`People`, `System`, `Access`). |
| `badge` | `?\Closure` | `null` | `fn (int $userId): int` — a count shown beside the label. |
| `when` | `?\Closure` | `null` | `fn (?User $user): bool` — asked at render time; hidden when it returns false or throws. |

Three methods read the badge: `badgeCount(int $userId): int`, `badgeLabel(int $userId): string`
(which writes anything over 99 as `99+`), and `badgeHtml(int $userId): string`, the markup a
scaffolded header prints after the label: a `pf-nav-badge` span named "3 unread", or nothing. Both answer zero for a guest, for an item without a
badge, and when the closure throws; the value is memoised per item and user for the request,
so a header and a mobile menu can both ask. The badge's design is described in the
[Authorization Guide](Pramnos_Authorization_Guide.md#a-count-beside-a-label).

### What `getForUser()` filters, and in what order

`getForUser(?User $user, array $enabledFeatures = [])` keeps an item only if every check
passes. "Signed in" means a user object **and** a live session.

1. `guestOnly` and signed in → hidden.
2. `requireAuth` and not signed in → hidden.
3. **Usertype.** For an Admin item when `admin_access` is `permissions` or `mixed`, the item is
   shown only when `AdminAccess::allows($user, $item->id, $item->minUserType)` — the same
   question the screen itself asks. Otherwise, a `minUserType > 0` hides the item from guests
   and from users below it.
4. **Permission**, only for a signed-in user: an explicit deny hides the item; an allow or no
   rule at all keeps it. A user object with its own `hasPermission()` method is asked instead
   of the store. A store error keeps the item.
5. `feature` not in `$enabledFeatures` → hidden.
6. `when` returns false or throws → hidden.

Then each section is sorted by `position`. Nothing else is done: no nesting, no grouping.

Hiding a link is not access control. Every screen enforces its own check; the registry only
avoids showing a link the screen would refuse. That is why "no rule" keeps an item — a
permission name nobody has granted yet would otherwise empty the menu.

!!! note "Pass the features"
    `$enabledFeatures` defaults to `[]`. Call `getForUser($user)` without it and every item
    with a `feature` — Messages, Ask AI, Applications, Tokens, Roles, Queue — disappears.

### `parent` and `group`

They answer different questions, and an item may have both.

- **`group` is a heading.** It labels a block of items and is always visible: a sidebar
  shows *People* over Users, Invitations and Organizations. A group has no URL and no
  registration of its own — it is just the string the items share.
- **`parent` is a fold.** The parent is itself a clickable item; its children sit under it
  (a submenu, an indented list). Use it only where a screen genuinely belongs inside
  another — the framework uses it once: Token Actions under Users.

Both are hints. `getForUser()` returns each section flat, ordered by `position` only, and a
theme that ignores them still shows every item. Groups appear wherever their items' positions
put them, so give a group's items adjacent positions if you want the blocks contiguous.

### Where an application registers its items

The framework registers its own items in `Application::registerDefaultNavItems()`, which runs
as the **last** step of `init()`. Register yours after that — in the application's `init()`,
after `parent::init()`:

```php
namespace MyApp;

use Pramnos\Application\NavItem;
use Pramnos\Application\NavRegistry;
use Pramnos\Application\NavSection;

class Application extends \Pramnos\Application\Application
{
    public function init($settingsFile = '')
    {
        parent::init($settingsFile);

        NavRegistry::register(new NavItem(
            'main.pricing', 'Pricing', sURL . 'pricing',
            NavSection::Main, 10,
        ));

        // An administration screen of the application's own.
        NavRegistry::register(new NavItem(
            id:          'admin.reports',
            label:       'Reports',
            url:         adminUrl('Reports'),
            section:     NavSection::Admin,
            position:    40,
            requireAuth: true,
            minUserType: 90,
            icon:        'chart',
            group:       'Operations',
        ));

        // A child of it, folded under Reports.
        NavRegistry::register(new NavItem(
            id:          'admin.reports.exports',
            label:       'Exports',
            url:         adminUrl('Reports/exports'),
            section:     NavSection::Admin,
            position:    41,
            requireAuth: true,
            minUserType: 90,
            parent:      'admin.reports',
            group:       'Operations',
        ));

        // Replace or drop a framework item by id.
        NavRegistry::remove('admin.organizations');

        return $this;
    }
}
```

An Admin item's `id` is also the ability its screen is granted by: declare the same string as
`$adminAbility` on the controller, and under `admin_access = permissions` the screen becomes
grantable from the panel with no second list. See
[Administration screens](Pramnos_Authorization_Guide.md#administration-screens-by-usertype-or-by-permission).

!!! warning "Service providers and `AppInit` run too early to override"
    Service providers boot, and the `AppInit` addon hook fires, **before**
    `registerDefaultNavItems()`. A new id registered there is fine. But replacing a framework
    item (same id) or calling `NavRegistry::remove()` on one there is silently undone a moment
    later, when the defaults are registered. Do overrides after `parent::init()`.

The registry is static and process-wide. Tests isolate it with `NavRegistry::reset()`;
`NavRegistry::all()` and `getIds()` return everything registered, unfiltered.

### A sidebar with groups, children, badges and the active item

The registry has **no notion of the current page**: no item is marked active, and there is no
helper for it. The theme compares each item's URL path with the request path. The template
below does that, folds children under their parent, prints a heading whenever the group
changes, and maps icon names to the theme's own icons.

```php
<?php
use Pramnos\Application\Application;
use Pramnos\Application\NavRegistry;
use Pramnos\Application\NavSection;
use Pramnos\User\User;

$_user  = User::getCurrentUser() ?: null;
$_nav   = NavRegistry::getForUser(
    $_user,
    Application::currentInstance()?->applicationInfo['features'] ?? []
);
$_items = $_nav[NavSection::Admin->value] ?? [];
$_uid   = (int) ($_user->userid ?? 0);
$_e     = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

// Split into top level and children. A child whose parent was filtered out is promoted to
// the top level: being allowed the child and not the parent must not hide the child.
$_visible  = [];
foreach ($_items as $_item) {
    $_visible[$_item->id] = true;
}
$_top      = [];
$_children = [];
foreach ($_items as $_item) {
    if ($_item->parent !== null && isset($_visible[$_item->parent])) {
        $_children[$_item->parent][] = $_item;
    } else {
        $_top[] = $_item;
    }
}

// Active item: the longest URL path the request path equals or sits under, so
// /admin/users/edit/5 lights up Users, and a child beats its parent.
$_path   = strtolower(rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/'));
$_active = null;
$_best   = -1;
foreach ($_items as $_item) {
    $_p = strtolower(rtrim(parse_url($_item->url, PHP_URL_PATH) ?: '/', '/'));
    if ($_p !== '' && ($_path === $_p || str_starts_with($_path, $_p . '/')) && strlen($_p) > $_best) {
        $_active = $_item->id;
        $_best   = strlen($_p);
    }
}

// Icon names to this theme's icon set — here Bootstrap Icons classes.
$_icons = ['gauge' => 'bi-speedometer2', 'users' => 'bi-people', 'settings' => 'bi-gear',
           'document' => 'bi-journal-text', 'mail' => 'bi-envelope', 'shield' => 'bi-shield-lock'];

$_link = static function ($item, bool $isActive) use ($_e, $_icons, $_uid): string {
    $icon  = $item->icon !== null ? '<i class="bi ' . $_e($_icons[$item->icon] ?? 'bi-dot') . '"></i> ' : '';
    $badge = $item->badgeCount($_uid) > 0
        ? ' <span class="badge" aria-label="' . $item->badgeCount($_uid) . ' new">'
          . $_e($item->badgeLabel($_uid)) . '</span>'
        : '';
    return '<a href="' . $_e($item->url) . '"' . ($isActive ? ' class="active" aria-current="page"' : '')
        . '>' . $icon . $_e($item->label) . $badge . '</a>';
};
?>
<?php if ($_top): ?>
<nav class="sidebar" aria-label="Administration">
    <ul>
    <?php $_group = null; ?>
    <?php foreach ($_top as $_item): ?>
        <?php if (($_item->group ?? '') !== ($_group ?? '')): $_group = $_item->group; ?>
        <li class="sidebar-heading"><?php echo $_e((string) $_group); ?></li>
        <?php endif; ?>
        <?php
        $_kids      = $_children[$_item->id] ?? [];
        $_kidActive  = in_array($_active, array_map(fn ($k) => $k->id, $_kids), true);
        ?>
        <li class="<?php echo $_kidActive ? 'open' : ''; ?>">
            <?php echo $_link($_item, $_active === $_item->id); ?>
            <?php if ($_kids): ?>
            <ul>
                <?php foreach ($_kids as $_kid): ?>
                <li><?php echo $_link($_kid, $_active === $_kid->id); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ul>
</nav>
<?php endif; ?>
```

The same split works for a header dropdown: the scaffolded Tailwind header builds the
`parent === null` / children arrays the same way and renders a parent that has children as a
nested submenu. Read the menu once per page and pass `$_nav` to every partial that needs it;
`getForUser()` re-runs the permission checks on each call.

### Limitations

- **No active-item detection.** The theme must work it out from the request path, as above.
- **One level of nesting is what the shipped headers render.** `parent` may point at a child,
  but nothing renders grandchildren unless your template recurses.
- **Orphans.** If a parent is hidden for this user, `getForUser()` still returns its visible
  children with `parent` set. A template that nests must promote them, as the example and the
  scaffolded Tailwind header do, or they are filed under nothing and never printed.
- **`parent` and `group` are not validated.** A `parent` id that names nothing, or an item
  in another section, is accepted silently.
- **Groups and icons are not rendered by the scaffolded headers.** They show the Admin items
  flat (Bootstrap, plain CSS) or with parent/child folding (Tailwind); `group` and `icon` are left
  for the theme to draw. Badges are drawn, with `NavItem::badgeHtml($userId)`.
- **Labels are not translated** by the registry; pass a translated string at registration.
