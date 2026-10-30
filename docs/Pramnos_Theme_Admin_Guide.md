---
use_cases:
  - Theming the administration area as the application's own
  - Giving a theme settings an administrator changes
  - Declaring content types a theme draws
  - Giving the administration area its own layout with 'admin' => ['theme' => …]
  - Diagnosing admin screens that render unstyled, without navigation, or without "Saved." messages
---

# The administration theme, theme settings and content types

Part of the [theme guide](Pramnos_Theme_Guide.md).

## An administration area theme

An application can give its administration area a theme of its own:

```php
// app/app.php
'admin' => [
    'prefix' => 'admin',
    'theme'  => 'admin',   // app/themes/admin/
],
```

When a request is inside the area, the area's `theme` replaces the application's `theme` for that
request, and the theme is loaded the same way as any other: `app/themes/<name>/`, with
`theme.html.php` as the layout. Without the key the area uses the site's theme, which is what
`pramnos init` sets up — it writes the `admin` block without a `theme`, and only
`app/themes/default/`.

The theme is put back at the start of every request, so a worker or a `TestClient` that serves
`/admin/Users` and then `/` does not draw the public page in the admin theme.

### What the theme provides, and what it does not

**The screens are not the theme's.** Every bundled admin screen — Users, Roles, Organizations,
Tokens, Settings and the rest — is a view. It is found in `src/Admin/Views/`, then
`src/Views/`, then in the bundled scaffolding for the project's `scaffold_theme`. The partials
those views insert are found beside the view that inserts them:

| Partial | Inserted by | What it reads from the view |
| --- | --- | --- |
| `partials/admin_breadcrumb` | almost every admin screen | `$this->activeNav` (the page key), and per page `$this->user`, `$this->role`, `$this->org`, `$this->group`, `$this->template`, `$this->token`, `$this->action` |
| `partials/admin_screens` | a user's page and a role's page | `$this->adminScreens`, from `AdminScreenGrants::adminScreensFor()` |
| `partials/user_roles` | a user's page | `$this->heldRoles`, from `Role::heldBy()` |
| `partials/email_preferences` | a user's page | `$this->emailPreferences`, from `UsersController::emailPreferencesFor()` |

So an admin theme writes no views and no partials. To change a screen, publish it with
`pramnos project:publish-views` and edit the copy under `src/Admin/Views/`.

**A shell that draws its own breadcrumb** says so, and the screens stop drawing theirs:

```php
// app/app.php
'admin' => ['prefix' => 'admin', 'breadcrumb' => 'theme'],
```

`partials/admin_breadcrumb` then prints nothing and hands each step past Home and Dashboard to
`Application::addbreadcrumb()` — the label as text, `'#'` for the current page — for the theme to
draw where it draws the rest. Without it the screen draws the whole trail itself, which is what a
scaffolded theme, with no breadcrumb of its own, needs. Both go through
`Html\Breadcrumb::admin($steps)`.

**The theme provides the page around them** — and the screens are written in a CSS vocabulary
the theme has to load.

### Checklist

| The theme must… | Because, without it… |
| --- | --- |
| Live at `app/themes/<name>/` with a `theme.html.php` | Nothing reports a missing theme. The page renders as the bare screen with no stylesheet, no navigation and no scripts. |
| Put `[MODULE]` in `theme.html.php`, inside `<body>` | The document splits the layout at `[MODULE]`; the screen's HTML goes there. |
| Write `<head>…</head>` and `<body>…</body>` in `theme.html.php` | The document moves what is between the `<head>` tags into its real `<head>`. Without them, stylesheet links land in the body and a manifest link is ignored. See [`head.php`](Pramnos_Theme_Guide.md#headphp-and-why-head-has-to-be-in-the-layout). |
| Load the stylesheet of the project's `scaffold_theme` — Bootstrap, or Tailwind with daisyUI, or the plain-css `style.css` | The bundled admin views are written in that system's classes (`card`, `alert alert-danger`, `btn btn-primary`). With another CSS framework, or none, every screen renders unstyled. |
| Link `assets/css/theme-tokens.css` before `assets/css/style.css` | The project's palette reaches the screens only through the tokens. `style.css` also styles the search box, the breadcrumb trail and the screens' small layouts. |
| Call `$this->document->renderCss()` in the head and `$this->document->renderJs()` at the end of the body | Screens enqueue what they need. A datatable, for example, enqueues DataTables, and without these calls the list is an empty table. Each asset comes out once: the stylesheets where `renderCss()` is, the head scripts in the document's `<head>` (before the content, so an inline script in a screen can use them), the footer and inline scripts where `renderJs()` is. A layout drawn without the document's head gets the head scripts from `renderJs()`. |
| Load `assets/js/pf-utils.js` | It handles `data-confirm`, `data-href`, the search box and the other `data-*` behaviours the screens use. The framework's CSP refuses inline handlers, so nothing else can do this work. |
| Echo `\Pramnos\Conversations\Dock::render()` in the footer | The team's conversations over every page of the area: a button with what is unread and a panel. It prints nothing without the `conversations` feature, for whoever may not use them, and on the conversations page itself. See the [Conversations Guide](Pramnos_Conversations_Guide.md#the-dock-and-the-basic-page). |
| Draw the `NavSection::Admin` items from `NavRegistry::getForUser()` | The bundled screens and the application's own register themselves there. Without the menu, a screen can still be opened but there is no link to it. |
| Show flashed messages and errors: `Html\Flash::render()` above `[MODULE]` | Many admin actions call `addMessage('Saved.')` or `addError(…)` and then redirect. The screen they land on does not print the flash, so the layout is the only place it can appear. |

### Optional, and what each adds

- **The cross-entity search box.** Draw it only when the application registered searches:
  `Search\Registry::loadDefinitions()` reads `app/search.php` and returns false when nothing is
  registered. `new Html\SearchBox()` defaults to `/<api_prefix>/admin/search`. It renders
  markup only; `pf-utils.js` provides the behaviour and `style.css` the styling.
- **The side panel button.** `Llm\Analyst\Analyst::headerButton($before, $after)` returns
  "Ask" when the person may use the analyst, "Help" when the page has help, and an empty
  string when there is neither. It includes its own `<template>` and the
  `assets/js/pf-assist.js` tag, so all the theme does is echo it. `$before` and `$after`
  wrap it, for example in an `<li>`.
- **Unread badges.** `NavItem::badgeCount($userId)` and `badgeLabel($userId)` give the
  count for an item that registered a badge (the inbox does), and 0 for every other item.
- **`login.php`.** Used only when the area's own front controller shows a sign-in page.
  A guest inside a prefixed area is redirected to the site's sign-in, which uses the
  site's theme.
- **A theme class**, `app/themes/<name>/<name>.php` defining `<name>_theme` (for example
  `admin_theme`). Needed only for settings, widget areas, menu locations or
  `$allowViewOverrides`.
- **A light/dark toggle.** The tailwind `head.php` applies the stored choice before the
  first paint. Reusing that file gives the area the same behaviour.

- **The notification bell.** `Html\NotificationBell::render()` draws the count and the latest
  notifications; `pf-utils.js` provides the behaviour.

### An area with a build of its own

An administration that compiles its own stylesheet, with a layout of its own, takes the
framework's component styles from its UI system's `components.css` and leaves `style.css`
alone. Each of `tailwind`, `bootstrap` and `plain-css` has one, styling the same components;
a framework test keeps the three in step. With Tailwind and daisyUI:

```css
@import "tailwindcss";
@plugin "daisyui";
@import "../../vendor/mrpc/pramnosframework/scaffolding/themes/tailwind/components.css";
```

`components.css` styles only the framework's `pf-*` hooks. These are the search box, the side
panel, the notification bell, navigation badges, flash messages, the help button, datatable
actions, breadcrumbs, pagination and stat tiles. `style.css` imports it and adds the page, which
would take over the area's own layout: the `body` rules and, per theme, the home page's
typography, the plain theme's header and forms, and the `pf-ui-*` hooks the SPA's shared
components draw with on `bootstrap` and `plain-css`. Those hooks stay in `style.css` because they
have to come after the classes they adjust. Imported from `vendor/`, the file follows `composer update`
with no copy to keep in step.

**Import `components.css` outside any layer**, as above. Its rules adjust daisyUI's: table cell
padding, the alignment of a data table's cells, the size of a button. Imported into
`@layer components`, every one of them loses to daisyUI's `utilities` layer, whatever its
selector. `layer(utilities)` keeps them in force too.

**daisyUI puts its components in the `utilities` layer.** A rule written in `@layer components`
to change a daisyUI `.btn` therefore loses whatever its specificity, because a later layer wins
first. Write such an override in `@layer utilities` or outside any layer.

### A minimal admin theme

The shortest correct theme reuses the site's `head.php` and `footer.php`, which `init`
generated for the project's UI system and its palette. It writes only its own header and
layout:

```
app/themes/admin/
├── theme.html.php # the layout: [MODULE] and the flash above it
├── head.php       # includes ../default/head.php
├── header.php     # navigation, search, side panel
└── footer.php     # includes ../default/footer.php
```

```php
<?php // app/themes/admin/head.php — $this is the theme object, so the include works as-is
include __DIR__ . '/../default/head.php';
```

```php
<?php // app/themes/admin/footer.php — renderJs(), pf-utils.js and the cookie consent tag
include __DIR__ . '/../default/footer.php';
```

```php
<?php // app/themes/admin/theme.html.php ?>
<head>
<?php $this->getElement('head'); ?>
</head>
<body>
<a class="pf-skip-link" href="#main-content">Skip to content</a>
<?php $this->get_header(); ?>
<main id="main-content">
    <?php echo \Pramnos\Html\Flash::render(); ?>
    [MODULE]
</main>
<?php $this->get_footer(); ?>
</body>
```

```php
<?php
// app/themes/admin/header.php
$_e     = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$_user  = \Pramnos\User\User::getCurrentUser() ?: null;
$_app   = \Pramnos\Application\Application::currentInstance();
$_nav   = \Pramnos\Application\NavRegistry::getForUser($_user, $_app?->applicationInfo['features'] ?? []);
$_admin = $_nav[\Pramnos\Application\NavSection::Admin->value] ?? [];

// Items may name a parent; nest them rather than listing thirty screens flat.
$_top = [];
$_sub = [];
foreach ($_admin as $_item) {
    if ($_item->parent === null) {
        $_top[] = $_item;
    } else {
        $_sub[$_item->parent][] = $_item;
    }
}
?>
<header class="admin-header">
    <a href="<?php echo $_e(adminUrl()); ?>"><?php echo $_e((string) ($_app?->applicationInfo['name'] ?? '')); ?></a>

    <?php if ($_admin !== [] && \Pramnos\Search\Registry::loadDefinitions()): ?>
        <div class="pf-omnibox-item"><?php echo (new \Pramnos\Html\SearchBox())->render(); ?></div>
    <?php endif; ?>

    <?php echo \Pramnos\Llm\Analyst\Analyst::headerButton(); ?>
</header>

<nav class="admin-nav" aria-label="Administration">
    <ul>
        <?php foreach ($_top as $_item): ?>
        <li>
            <a href="<?php echo $_e($_item->url); ?>"><?php echo $_e($_item->label); ?></a>
            <?php if (!empty($_sub[$_item->id])): ?>
            <ul>
                <?php foreach ($_sub[$_item->id] as $_child): ?>
                <li><a href="<?php echo $_e($_child->url); ?>"><?php echo $_e($_child->label); ?></a></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</nav>
```

The flash is shown by `theme.html.php`, above `[MODULE]`, with `Html\Flash::render()`: escaped,
taken so that it is shown once, and styled by the scaffolded stylesheets' `pf-flash` rules. See
[Flash messages and errors](Pramnos_Framework_Guide.md#flash-messages-and-errors).

!!! tip "Follow the palette rather than restating it"
    An admin theme that styles its own chrome should read the same tokens as everything else:
    `--color-*` directly under tailwind, `--bs-*` under bootstrap, or the plain-css names.
    `theme-tokens.css` defines all of them from `app/themes/theme.css`. A colour written as a
    literal stays that colour through every palette change and in dark mode. See
    [One palette, every UI system](Pramnos_Theme_UI_Systems_Guide.md#one-palette-every-ui-system).

## Theme Settings

A theme declares the settings it wants; the framework renders them, reads them back and
persists them. That is the whole contract, and it is the same shape as Django's `Form`,
Rails' model validations and WordPress's Settings API: **you describe fields, not markup.**

The form underneath is `Pramnos\Html\Form\SettingsForm`; `Theme::addSetting()` declares a
field on it (`SettingsForm::addField()`), so the theme never builds markup itself.

### Declaring settings

```php
class MyTheme extends \Pramnos\Theme\Theme
{
    public function init()
    {
        $this->addSetting(
            'logo_url',                 // name
            'Logo URL',                 // label
            'url',                      // type
            null,                       // options (selects only)
            'Address of your site logo' // description
        );

        // A select. Three option shapes are accepted — see below.
        $this->addSetting(
            'color_scheme',
            'Colour scheme',
            'select',
            ['default' => 'Default', 'dark' => 'Dark', 'light' => 'Light'],
            'Choose the colour scheme',
            false,
            'default'                   // the default value
        );

        $this->addSetting('enable_sidebar', 'Enable sidebar', 'checkbox', null, null, false, '1');
        $this->addSetting('footer_text', 'Footer text', 'textarea');

        return $this;
    }
}
```

**A name that begins with a digit is renamed.** `2fa_enabled` becomes `_2fa_enabled`, because the
name is used as a form field and as a property and neither may start with a digit. The rename is
silent, so it matters for reading the value back: declare `2fa_enabled` and you will find it under
`_2fa_enabled`. A digit anywhere else is left alone — `oauth2_key` stays as written.

**Types:** `textfield` (or `text`), `textarea`, `checkbox`, `select` (or `selectbox`),
`number`, `email`, `url`, `password`, `date`, `time`, `datetime`, `color`, `hidden`. An
unrecognised type renders as a text input rather than nothing, so a typo costs the wrong
control and not a missing setting.

**Select options** are accepted in three shapes, because both callers of this API already use
all three:

```php
'one, two, three'                       // comma-separated: value === label
['red', 'green']                        // a list: value === label
['1' => 'Enabled', '0' => 'Disabled']   // value => label
[['No', 0], ['Yes', 1]]                 // [label, value] pairs
```

One case is undecidable and worth knowing rather than discovering: `[0 => 'No', 1 => 'Yes']`
is indistinguishable from the list `['No', 'Yes']`, because PHP represents them identically.
It is read as a list. Use the `[label, value]` pair form when your keys are `0` and `1` in
that order.

### Rendering and saving

```php
if ($theme->hasSettings()) {
    echo '<form method="post">'
        . $theme->renderSettings()          // the fields, escaped
        . '<button type="submit">Save</button></form>';
}

// On submit:
$theme->saveSettings();
```

`renderSettings()` returns **the fields only**, without a `<form>` tag, so an administration
panel can put several themes' or addons' settings inside one submit. An empty string means the
theme declared nothing — it is not an error.

Three behaviours worth relying on:

- **Everything is escaped.** Values, labels, descriptions and every option. A `"` in a value
  cannot end the attribute it sits in. That matters most here: settings values are
  administrator-supplied and re-rendered after every save.
- **A checkbox can be turned off.** Browsers submit nothing for an unchecked box, so each one
  is rendered with a hidden `0` companion that the checkbox overwrites with `1`. Without it a
  setting could be switched on and never off. The `0` reads back as off.
- **Either CSRF token is accepted.** That is the form's own field, or the synchronizer token
  (`_csrf_token`, or the `X-CSRF-Token` header), as `Controller::isVerifiedWrite()` accepts. A
  page that embeds the fields in a form of its own therefore needs nothing extra.
- **A rejected submit writes nothing.** `SettingsForm::getData()` throws
  `Html\Form\TokenRejected` when the token does not check out, rather than returning an empty
  array that a caller might save over the stored values. `saveSettings()` catches it and leaves
  the settings alone. Code of your own that calls `getData()` can ask `tokenFailed()` first, to
  show a message instead.

### Where the values live

`saveSettings()` serialises them into the application setting
`theme_<name>_settings`. Reading them back is the theme's own business, and
`loadSettings()` is the hook for it:

```php
protected function loadSettings()
{
    $stored = unserialize(
        (string) \Pramnos\Application\Settings::getSetting('theme_' . $this->theme . '_settings')
    );

    // Anything that is not an array — including the `false` from a missing setting — is
    // ignored, so a fresh installation shows the declared defaults.
    $this->settingsForm()->setValues($stored);

    return $this;
}
```

### Addons declare settings the same way

`Addon::addSetting()` has the identical signature, plus a ninth argument for multilanguage
fields, and builds on the same `SettingsForm`. `Addon::getProperty($name, $language)` returns the translated
value when one was declared for that language and the addon's own property otherwise.

Multilanguage fields are copied once per language in `ROOT/language/*.php`. An installation
with no such directory gets the base field and no copies, rather than an error.

### The form class underneath

`Pramnos\Html\Form\SettingsForm` is available directly for an application's own settings
pages. It is **deliberately narrow** — settings, not CRUD — and the reasoning is on the class
itself: Laravel removed its form builder from core, this framework already generates CRUD forms
from column introspection in `make:` commands, and validation already belongs to `FormRequest`.
A general runtime form builder would be a third way to render a field.

### Using Theme Settings

```php
// In theme templates
$theme = \Pramnos\Theme\Theme::getTheme();

// Get setting values — the value if one was saved, the declared default otherwise
$logoUrl = $theme->getSetting('logo_url');
$colorScheme = $theme->getSetting('color_scheme');
$enableSidebar = $theme->getSetting('enable_sidebar');
$footerText = $theme->getSetting('footer_text');

// Use in templates
<?php if ($logoUrl) : ?>
    <img src="<?php echo $logoUrl; ?>" alt="Site Logo" class="site-logo">
<?php endif; ?>

<body class="color-scheme-<?php echo $colorScheme; ?>">

<?php if ($enableSidebar) : ?>
    <aside class="sidebar">
        <?php echo $theme->renderWidgetArea('sidebar-1'); ?>
    </aside>
<?php endif; ?>

<footer>
    <?php echo $footerText ?: 'Default footer text'; ?>
</footer>
```

### Field Types

Available field types for theme settings:

```php
// Text input
$this->addSetting('site_tagline', 'Site Tagline', 'textfield');

// Number input
$this->addSetting('posts_per_page', 'Posts Per Page', 'number');

// Email input
$this->addSetting('contact_email', 'Contact Email', 'email');

// URL input
$this->addSetting('social_facebook', 'Facebook URL', 'url');

// Textarea
$this->addSetting('about_text', 'About Text', 'textarea');

// Checkbox
$this->addSetting('show_breadcrumbs', 'Show Breadcrumbs', 'checkbox');

// Select dropdown — a value => label map
$this->addSetting('layout', 'Layout', 'select', [
    'full'  => 'Full Width',
    'boxed' => 'Boxed',
    'fluid' => 'Fluid',
]);

// A path or URL. `image` renders a plain text input — there is no picker.
$this->addSetting('header_image', 'Header Image', 'image');

// Colour, date and time map to the matching HTML input types
$this->addSetting('primary_color', 'Primary Colour', 'color');
$this->addSetting('publish_from', 'Publish From', 'date');
```

## Content Types

### Setting Content Types

```php
// In controllers
class BlogController extends \Pramnos\Application\Controller
{
    public function display()
    {
        // Selects $elements['archive'] as the layout — archive.php by default
        $this->setContentType('archive');

        $view = $this->getView('Blog');
        return $view->display('archive');
    }

    public function post()
    {
        $this->setContentType('single');   // single.php by default

        $view = $this->getView('Blog');
        return $view->display('post');
    }
}
```

`Controller::setContentType()` forwards to the document's theme object, when there is one.

### Template Selection Logic

`loadtheme()` picks the layout file in this order:

1. `$elements[<content type>]` — with `.html.php` turned into `.<document type>.php`;
2. `$elements['index']`, the same way (`theme.html.php` → `theme.json.php` for JSON);
3. `theme.html.php`.

A content type with no entry in `$elements` uses `theme.<document type>.php`. Add entries in
the theme class:

```php
class mytheme_theme extends \Pramnos\Theme\Theme
{
    public function init()
    {
        $this->elements['product'] = 'templates/product.html.php';
        $this->elements['event']   = 'templates/event.html.php';
        return parent::init();
    }
}
```
