---
use_cases:
  - Building or modifying a theme
  - Working with templates, widgets or menus
  - Registering theme assets (CSS/JS) or theme settings
  - Getting a page's HTML to appear inside a theme's [MODULE] placeholder
  - Diagnosing a theme that renders a header and footer with an empty page
  - Rendering a document outside the framework's own MVC path
  - Choosing or switching the scaffolded UI framework (plain-css / bootstrap / tailwind)
  - Changing the application's colours, or sharing one palette between a server-rendered
    side and a SPA
  - Styling a screen so it stays readable in the dark theme
  - Building the admin sidebar or header menu from registered screens
  - Adding an application's own page to the site or admin navigation
  - Giving the administration area its own layout with 'admin' => ['theme' => …]
  - Diagnosing admin screens that render unstyled, without navigation, or without "Saved." messages
  - Keeping an application's own view templates rendering correctly after project:switch-ui
  - Choosing between writing Bootstrap markup directly and using the HTML components in many templates
---

# Pramnos Framework - Theme System Guide

The Pramnos Framework includes a powerful theming system that provides flexible template management, widget support, menu systems, and complete design customization. This guide covers everything from basic theme usage to advanced theme development.

## Dates

Never `date('Y-m-d')` in a view. Three helpers, and they follow the language the page is written
in:

```php
<?php echo localDate($row['created']); ?>       <!-- 28/08/2026   (el)   2026-08-28   (en) -->
<?php echo localDateTime($row['created']); ?>   <!-- 28/08/2026 14:32 -->
<?php echo localTime($row['created']); ?>       <!-- 14:32 -->
```

`0`, `null` and an empty column return the empty string rather than *1 January 1970*; pass a
second argument for a dash. The patterns come from the `date_format` / `datetime_format` settings,
then `app.php`'s `'dates' => ['el' => [...]]`, then the framework's table — see
`Pramnos\General\DateFormat`.

A value a machine reads is the exception: an `<input type="date">`, a `<time datetime>` attribute
and an export filename stay ISO, and `date()` is right there.

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Basic Theme Usage](#basic-theme-usage)
3. [Theme Structure](#theme-structure)
4. [Template System](#template-system)
5. [Widget System](#widget-system)
6. [Menu System](#menu-system)
7. [Navigation](#navigation)
8. [An administration area theme](#an-administration-area-theme)
9. [Theme Settings](#theme-settings)
10. [Content Types](#content-types)
11. [One palette, every UI system](#one-palette-every-ui-system)
12. [Writing views that survive a UI switch](#writing-views-that-survive-a-ui-switch)
13. [Asset Management](#asset-management)
14. [Theme Development](#theme-development)
15. [Best Practices](#best-practices)

## Architecture Overview

The theme system is built around the `\Pramnos\Theme\Theme` class and integrates seamlessly with the document and view systems. Themes live under `app/themes/` (`APP_PATH/themes`):

```
app/themes/
├── theme.css                # The project's palette (see "One palette, every UI system")
└── default/                 # One directory per theme
    ├── default.php          # Optional theme class: `class default_theme extends \Pramnos\Theme\Theme`
    ├── theme.html.php       # The page layout (the body; `[MODULE]` marks the page content)
    ├── head.php             # The document head: stylesheets, favicons, manifest link
    ├── header.php           # The visible site header
    ├── footer.php           # The visible site footer
    ├── login.php            # Standalone layout for the auth screens
    ├── style.css            # Linked automatically by displayInit()
    ├── screenshot.png       # Theme preview image
    └── views/               # View overrides
        └── ControllerName/
            └── template.html.php
```

A theme class is optional. When `<theme>/<theme>.php` exists and defines `<theme>_theme`,
`getTheme()` instantiates that class; otherwise it uses `\Pramnos\Theme\Theme` itself.

### Key Components

- **Theme Templates**: HTML/PHP files that define layout structure
- **View Overrides**: Theme-specific view templates
- **Widget Areas**: Customizable content regions
- **Menu Areas**: Navigation management
- **Settings System**: Configurable theme options
- **Asset Integration**: CSS/JS management

## Basic Theme Usage

### Getting the Current Theme

```php
// Get active theme instance
$theme = \Pramnos\Theme\Theme::getTheme();

// Get theme from another directory — the path is relative to ROOT
$theme = \Pramnos\Theme\Theme::getTheme('my-theme', 'custom/themes');

// Load theme for display
$theme = \Pramnos\Theme\Theme::getTheme('my-theme', '', true);
```

### Setting Active Theme

```php
// app/app.php — the theme the application loads
'theme' => 'my-theme',

// Or programmatically in controllers
$doc = \Pramnos\Framework\Factory::getDocument();
$doc->themeObject = \Pramnos\Theme\Theme::getTheme('my-theme');
```

### Basic Theme Information

```php
$theme = \Pramnos\Theme\Theme::getTheme();

echo $theme->title;        // Theme display name
echo $theme->author;       // Theme author
echo $theme->copyright;    // Copyright information
echo $theme->url;          // Author URL
echo $theme->info;         // Theme description
echo $theme->thumbnail;    // Screenshot URL
```

## Theme Structure

### Two things every theme owes its readers

Both are one line of markup and a few of CSS, and both are the first items on any accessibility
audit. The scaffolded themes carry them; a hand-written theme has to.

**A skip link, as the first focusable thing on the page.**

```html
<body>
<a class="pf-skip-link" href="#main-content">Skip to content</a>
…
<main id="main-content">
```

Without the link, reaching a page's content means tabbing through the whole navigation, on every
page.

Style it **off-screen, not hidden**: `display: none` takes it out of the tab order, which removes
the one thing it exists to provide.

```css
.pf-skip-link { position: absolute; left: -9999px; }
.pf-skip-link:focus { left: 0; }
```

**And a `viewport` you do not have to remember.** The `Html` document type emits it, so a theme
that omits it still gets one — without it, a page is labelled not mobile-friendly with no signal
anywhere that anything is missing.

### Nothing about the visitor leaves the page

The scaffolded OAuth consent screen (`/oauth/authorize`) renders the account's avatar when it
has one and omits it when it does not. It does **not** fall back to a remote avatar service: a
URL built from `md5(email)` sends the address of the person signing in to another company, from
the one page in the flow where they are deciding what to disclose. An md5 of an address is not
anonymous — the set of addresses that matter is small enough to enumerate.

**If you write your own consent screen, keep it that way** — no third-party asset on an
authentication page. A remote image is a request that carries a `Referer`, a timestamp and an IP to
somebody else.

### Main Template File (theme.html.php)

The main template file defines the page layout. It is included with `$this` bound to the
theme object, and it writes **the body only** — the document emits `<!DOCTYPE html>`, `<html>`,
the title and its own head around it:

```php
<head>
<?php $this->getElement('head'); ?>
</head>
<body>
<a class="pf-skip-link" href="#main-content">Skip to content</a>

<?php $this->get_header(); ?>
<main id="main-content">
    [MODULE]
    <aside><?php echo $this->renderWidgetArea('sidebar-1'); ?></aside>
</main>
<?php $this->get_footer(); ?>
</body>
```

`[MODULE]` is where the page content goes. `get_header()` / `get_footer()` include the files
named by the `header` and `footer` elements; navigation is drawn with `$this->displayMenu()` —
see [Menu System](#menu-system).

### How a page's content reaches `[MODULE]`

`[MODULE]` is where the page goes. How it gets there:

**Set it with `setContent()` or `addContent()` on the document:**

```php
$document = \Pramnos\Document\Document::getInstance();

$document->setContent($html);        // replaces whatever is there
$document->addContent($moreHtml);    // appends
echo $document->getContent();        // reads it back
```

That is the whole API, and in an MVC application the framework already calls it for you —
a controller's view output is put there before the document renders. You need it directly
when you render outside that path: a custom route, a service-layer application adding
server-rendered pages, an error page assembled by hand.

!!! note "`$document->content` also works"
    The property is public, and every concrete document type (`Html`, `Amp`, `Json`, `Png`,
    `Raw`) falls back to it when the content buffer is empty, so
    `$document->content = $html` renders. Prefer `setContent()` anyway: it is what the
    framework itself uses, and it is the one that composes with `addContent()`.

**The buffer is `static`.** One process, one buffer, shared by every document instance. It
matters in exactly two places: a long-running worker that renders more than one document
must reset it (`$document->setContent('')`) between them, and a test that forgets to reset
it reads the previous test's page.

### When the theme body is loaded

Lazily, by the first accessor that needs it — `gethead()`, `getfoot()` or `getheader()`
reads `theme.<type>.php` if nothing has read it yet. So this works:

```php
$document->themeObject = \Pramnos\Theme\Theme::getTheme('mytheme', 'app/themes', false);
echo $document->render();
```

Two details worth knowing:

- `Document::loadtheme()` passes `$load = false` to `Theme::getTheme()`, so the object it
  hands back has read nothing yet; the first of those accessors reads it. That is what lets
  an application assign `themeObject` and render through a route other than
  `Html::render()` (which calls `loadTheme()` itself).
- An explicit `loadtheme()` call still re-reads, every time. That is deliberate: the file it
  picks depends on the content type, so setting a content type and reloading is how you
  switch templates. And a `body` you assign yourself is never read over.

### Content Type Templates

`setContentType()` picks which entry of `$this->elements` is the layout. The defaults
include `'index' => 'theme.html.php'`, `'login' => 'login.php'`, `'head' => 'head.php'`,
`'header' => 'header.php'`, `'footer' => 'footer.php'`, `'sidebar' => 'sidebar.php'`,
`'style' => 'style.css'`, and WordPress-style names for the rest (`'single' => 'single.php'`,
`'page' => 'page.php'`, `'archive' => 'archive.php'`, `'search' => 'search.php'`,
`'404' => '404.php'`, …). A theme class overrides or adds entries:

```php
class mytheme_theme extends \Pramnos\Theme\Theme
{
    public function init()
    {
        $this->elements['single']  = 'single.html.php';
        $this->elements['product'] = 'product.html.php';
        return parent::init();
    }
}
```

A value ending in `.html.php` has `html` replaced by the document type (`single.json.php` for a
JSON document); any other name is used as written.

### Where themes are looked for

Each entry point resolves the directory its own way:

| Method | Looks in |
| --- | --- |
| `getTheme($name)` | `APP_PATH/themes` only |
| `getTheme($name, $path)` | `ROOT/<path>` |
| `new Theme($name)` | `ROOT/themes` (pass a path to change it) |
| `getThemes()` | `APP_PATH/themes` **and** `ROOT/themes`, merged — a project may have `app/themes/` for its own and `ROOT/themes/` from an older layout; a missing directory is skipped |
| `getThemeObjects()` | the names `getThemes()` returns, each built from the directory it was found in (`APP_PATH/themes` or `ROOT/themes`) |

`getThemeObjects()` excludes `default`, which is the fallback rather than a choice.

!!! note "A theme class is included once, and the check comes first"
    `getTheme()` asks `class_exists($name, false)` **before** including the theme's PHP
    file, so a class already defined — by another loader, by an autoloader, by a second
    path to the same file — is reused instead of being redeclared (a fatal
    `Cannot redeclare class`).

    `include_once` would not be enough: it keys on the *resolved path*, so two routes
    to one file (a symlink, or `theme.php` against `Theme.php` on a case-insensitive
    filesystem) load it twice and redeclare anyway.

    The `false` argument matters too — do not invoke the autoloader. The question is "is
    this class already in memory", and an autoloader would answer a different one.

### Inheriting a bundled view, one template at a time

An application does not have to own every screen. When it has no template of its
own, the lookup falls through to the bundled scaffolding for the configured
`scaffold_theme` — so a project can keep the three screens it rewrote and inherit
the other thirty-six, and pick up their fixes with the next framework update
instead of copying them again.

```
app/themes/<theme>/views/<view>/<tpl>.html.php   ← a theme override, if any
src/Views/<view>/<tpl>.html.php                  ← the application's own
scaffolding/themes/<scaffold_theme>/views/…      ← the bundled fallback
```

**Per template, not per directory.** Each template resolves on its own, so an
application with `src/Views/services/logs.html.php` and no `services.html.php` still
gets the bundled `services.html.php`. A per-directory fallback would match the
directory, fail at the template, and return a page shell: 200, no panel, one line in a
log nobody reads.

`project:publish-views` copies a bundled view into the application when you do
want to own one.

### The bundled scaffold themes

`init` installs one of three, and `project:switch-ui` swaps between them in an existing
project:

```bash
./myapp project:switch-ui bootstrap
./myapp project:switch-ui tailwind
./myapp project:switch-ui plain-css
```

It rewrites `scaffold_theme` in `app/app.php`, re-installs the theme chrome into
`app/themes/default`, writes `assets/css/style.css` in the web root the project serves
(`www/`, `public/`, …), and vendors the framework's CSS/JS for that system.

What it replaces, it keeps: every layout file or `style.css` whose contents the switch changed
is copied first to `var/switch-ui/<time>/`, and the output lists them. It also names the views
copied with `project:publish-views`, which stay in the old system's markup, and says when the
CSP has no `style-src` for Tailwind's `'unsafe-inline'`.

**It does not touch `app/themes/theme.css`.** The palette is the project's, not the
scaffold's, and switching UI framework is not a decision about colours — it regenerates
`theme-tokens.css` *from whatever palette the project has*, so the new stylesheet arrives
already wearing them. A project that has no palette yet gets the template, named after
the application. The scaffolded views themselves are resolved per-system from the
bundled scaffolding, so nothing needs copying per screen.

| System | What it is | The vocabulary its `style.css` reads |
| --- | --- | --- |
| `plain-css` | hand-written CSS, no framework, no vendored assets | its own names — `--primary-color`, `--text-main`, `--surface`, `--bg-subtle`, `--border-color` |
| `bootstrap` | Bootstrap 5, vendored locally | Bootstrap's `--bs-*` |
| `tailwind` | Tailwind 4 **and daisyUI 5** — see below | the palette's tokens directly, `--color-*` and the rest |

All three follow the project's palette, because `theme-tokens.css` declares the first two
vocabularies **as aliases of the third** — see [One palette, every UI
system](#one-palette-every-ui-system).

**Stay inside the row that applies.** `var(--name, #literal)` never fails: if nothing
declares `--name`, the browser silently takes the literal, so a rule reading a vocabulary
the page does not have looks opinionated rather than broken — and stays that colour
through every palette change and every theme switch.
`tests/Unit/Theme/ScaffoldThemeStylesheetTokensTest.php` checks each bundled stylesheet
against the palette and against the alias table, so a rule in the wrong vocabulary — or
one whose alias was never added — fails the suite instead of shipping.

#### The tailwind theme is a daisyUI theme

It renders through daisyUI components — `btn btn-primary`, `card bg-base-100`,
`alert alert-error`, `navbar`, `menu`, `table` — and reads colours from daisyUI's tokens
(`base-100`, `base-200`, `base-300`, `base-content`, `primary`, `error`) rather than from
Tailwind's palette. Both halves matter:

- **A component carries the theme; a utility carries one palette.** `bg-white` and
  `text-gray-700` are invisible or unreadable under `data-theme="dark"`, and nothing in
  any log says so — the page renders, the text is simply not there.
  `tests/Unit/Http/AdminUrlInViewsTest.php` fails on a hardcoded palette in any bundled
  view.
- **Light and dark both ship.** A toggle in the header stores the choice, and `head.php`
  applies it to `data-theme` before the first paint — inline and synchronous, because
  deferring it paints light and then flips.
- **The logo follows the theme.** `init` copies two ink variants into
  `www/assets/img/` — `logo.png` (dark ink) and `logo-inverse.png` (light) — and the
  brand image carries the class `pf-logo`, which `style.css` swaps with
  `content: url(…)` under `[data-theme$="-dark"]` and under a dark OS preference with
  nothing stored. Replacing the artwork means replacing both files; nothing in the
  markup or in JavaScript has to change.

**No build step.** daisyUI 5 is a Tailwind *plugin*, and a plugin needs module resolution,
which Tailwind's browser build cannot do — so `@plugin "daisyui"` is not available to a
scaffolded project, and a scaffolded project has no npm. What it uses instead is the
prebuilt stylesheet daisyUI publishes for exactly this case: one file with every component
and both token sets, vendored locally like any other asset. The order in `head.php` is
load-bearing:

1. Tailwind's browser build (a script — it scans the DOM and generates the utilities)
2. `daisyui.css` — components, in a `daisyui` sublayer of `utilities`
3. `style.css` — the project's own

so Tailwind's utilities override a component's defaults, and the project overrides both.

**Layers, not load order, decide the last two** — and a rule that is in no layer wins
against every rule that is. daisyUI 5 ships entirely inside `@layer base / daisyui /
utilities`, so an **unlayered** selector in `style.css` beats every component whatever its
specificity: `html *` is (0,0,1) and `.btn-ghost` is (0,1,0), and the first one won. The
symptom is a border on components that explicitly asked for none — a `btn-ghost` sets
`--btn-border: #0000` and still shows an outline, and so do inputs and cards.

So anything in `style.css` addressing *everything* belongs in a layer:

```css
@layer base {
    html *, html ::before, html ::after {
        border-color: var(--color-base-300, #e5e7eb);
    }
}
```

Inside `base` the intent still holds — Tailwind's Preflight is in the same layer, where
specificity does decide — and `base` comes before `daisyui`, so a component's own border
wins as it should. Selectors scoped to this theme's own markup (`.pf-home h1`,
`.breadcrumb`) need no layer: daisyUI has no opinion about them.

This is worth knowing because reasoning about specificity does not find it: a SPA and a
server-rendered page with identical classes and components can still look different,
because only the server-rendered one loads this file.

**A project that wants a build step should have one.** Tailwind's browser build is a
runtime compile and daisyUI's prebuilt CSS is the whole library, unpurged: fine for a
scaffolded application, and not what you want in front of real traffic. An application
with npm should compile `@import "tailwindcss"; @plugin "daisyui";` into
`www/assets/css/style.css` and define its own themes as token blocks — the theme's markup
does not change, because it was written against the tokens rather than against a palette.

Both need `'unsafe-inline'` in the CSP's `style-src` while the browser build is in use;
`init` and `project:switch-ui` set that for the tailwind system and leave the other two
strict.

#### The account screens at phone width

All three scaffold themes lay the account area out for a 390px screen as well as a desktop,
each in its own idiom — Tailwind and Bootstrap with their responsive utilities, plain-css
with classes in its `style.css` (an inline style cannot be overridden by a media query):

- **The account menu** (`partials/account_sidebar`) is a single row that scrolls sideways
  on a phone and the vertical sidebar from `md` up. `.pf-account-nav` hides the scrollbar;
  the current entry carries `aria-current="page"`.
- **A description beside its action** — "Enable 2FA", "Manage Passkeys", a session's
  "Sign out", an application's "Revoke" — stacks below `sm` instead of squeezing both.
  Tailwind: `flex flex-col items-start gap-2 sm:flex-row sm:items-center`; Bootstrap:
  `d-flex flex-column flex-sm-row align-items-start align-items-sm-center`; plain-css:
  `.pf-row-split`.
- **The dashboard heading** wraps beside its 2FA badge, and the badge never breaks its
  text.
- **Below 640px** each `style.css` lets the omnibox input shrink to 7rem, pins its results
  panel across the screen (`position: fixed`, `0.5rem` from each side), and keeps a
  breadcrumb trail on one sideways-scrolling line with each crumb truncated at 12rem.

A copied view in `app/views` keeps whatever layout it was copied with; to get these, diff
it against the bundled one. `tests/Unit/Html/ScaffoldAccountMobileTest.php` pins the rules.

### `head.php`, and why `<head>` has to be in the layout

`theme.html.php` writes `<head>` and `<body>` out explicitly, and includes a separate
`head.php` for the document head:

```php
<head>
<?php $this->getElement('head'); ?>
</head>
<body>
<?php $this->get_Header(); ?>
<main>[MODULE]</main>
<?php $this->get_Footer(); ?>
</body>
```

Both tags are load-bearing.

`Theme::getheader()` exists to lift `<head>…</head>` out of the theme's output so the
document can append it inside its own head. With no `<head>` tag it finds nothing and
returns an empty string, and **everything** the theme emits goes through `gethead()`
instead — which the document writes *after* `<body>`.

A browser hoists a `<link rel="stylesheet">` out of the body and applies it, so the
difference is easy to miss — but it does **not** honour `<link rel="manifest">` there: the
link is in the page source, the file is served with a 200, and devtools says *No manifest
detected*.

The `<body>` tag matters for the other half: it is what makes `Theme::loadtheme()` set
`$body` to the body content alone, so the split at `[MODULE]` cannot pick up the head
assets as page content and emit them twice.

**`head.php` and `header.php` are different things**: `head.php` is the document head — stylesheets, the favicon set, the
manifest link, `renderCss()`. `header.php` is the *visible* site header — the logo and
the navigation. Only one of them can be moved into `<head>`.

A hand-written theme with neither tag still renders: its assets end up in the body,
hoisted by the browser, and a manifest link is ignored.

### `login.php` — the standalone layout

The `'login'` content type is the one entry in that map the framework selects for
you, and it is what keeps the login page out of the site chrome.

Every built-in auth view — login, the second factor, forgot-password,
reset-password — is written as a full-page centred card: `min-height: 100vh` in the
plain-CSS and Bootstrap themes, `min-h-screen` under Tailwind. Wrapped in
`theme.html.php` that renders as the site header, the whole navigation and a *Sign in*
link pointing at the page the visitor is already on, and then a full viewport of card
underneath it.

`Pramnos\Auth\Controllers\Account` therefore calls `setContentType('login')` before
rendering any of them, and a scaffolded theme ships `login.php`:

```php
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?php echo sURL; ?>assets/css/style.css">
    <?php $this->document->renderCss(); ?>
</head>
<body>
    <a href="<?php echo sURL; ?>" class="pf-back-link" style="position:absolute;left:16px;top:16px">&larr; <?php echo htmlspecialchars(/* app name */); ?></a>
[MODULE]
    <?php echo \Pramnos\Security\CookieConsent::tag(sURL); ?>
    <script src="<?php echo sURL; ?>assets/js/pf-utils.js"></script>
    <?php $this->document->renderJs(); ?>
</body>
```

Four things about that file are deliberate:

- **`<head>` and `<body>` are written out**, unlike `theme.html.php`. `getheader()`
  extracts `<head>…</head>` and the document appends it inside its own head, and the
  `<body>` tag is what stops the split at `[MODULE]` from treating the stylesheet
  links as body content.
- **`renderCss()` and `renderJs()` are still there.** A standalone layout is not a
  suppressed one: the login page enqueues assets like any other — the passkey flow is
  one — and dropping those calls breaks it in a way that looks like a JavaScript bug.
- **"← <app name>" is the way back.** The header is what carried the link to the home
  page, so without it somebody who reached `/login` by mistake had only the browser's
  back button. It is positioned out of the flow (`absolute`, top left), so the centred
  card does not move. The classes are the UI system's own: `btn btn-link` under
  Bootstrap, `btn btn-ghost` under Tailwind, `pf-back-link` in plain CSS.
- **The navigation is not built at all.** Not hidden with CSS: `NavRegistry` is never
  consulted, so nothing queries the user's permissions to assemble a menu that is not
  going to be shown.

**A theme with no `login.php` is unaffected.** `loadtheme()` falls back to
`theme.html.php` for any content type whose file is missing, so a hand-written theme
without one renders the auth screens inside its normal layout — `Account` asks every theme
and takes the answer it gets.

!!! warning "The content type is per-request state on a cached object"
    `Theme::getTheme()` caches by name, so one theme object serves every request in the
    process — and `setContentType()` writes to it. In a process serving more than one
    request, every page after a sign-in page would render with the `login` layout — no
    header, no footer, status 200 — unless the state is reset.

    `Document::reset()` calls `Theme::reset()` (a document carries a theme, so resetting
    one without the other leaves half the state behind), and `TestClient` calls it per
    request. If you serve several requests in one PHP lifetime by other means — a worker,
    a daemon — call `Document::reset()` between them.

### Static files: `assetUrl()`

Load a script or stylesheet through `assetUrl()` rather than `sURL . 'assets/…'`:

```php
<script src="<?php echo assetUrl('assets/js/pf-auth.js'); ?>"></script>
<link rel="stylesheet" href="<?php echo assetUrl('assets/css/style.css'); ?>">
```

It appends `?v=<modification time>`. A file served at a fixed URL is kept by whatever sits
in front of it (a CDN answering `HIT` until `max-age` runs out, or a browser cache), so a
deployed fix reaches nobody until then. The stamp changes the URL when the file changes,
and only then. The file is looked up beside the front controller that is running, then in each
directory above it up to the document root (an application mounted at `www/admin/` serves the
site's `assets/`), then in the project's web root. A file that is not there gets no stamp.

Every framework asset the scaffold loads goes through it: the `pf-*.js` scripts, `push.js`,
`style.css` and the cookie banner.

### The theme class

A theme customises itself in an optional class, `app/themes/<name>/<name>.php`, named
`<name>_theme`. `getTheme()` includes the file and instantiates the class; `init()` runs from
the constructor, `displayInit()` when the theme is loaded for display.

```php
<?php
// app/themes/mytheme/mytheme.php

class mytheme_theme extends \Pramnos\Theme\Theme
{
    public function init()
    {
        // Navigation locations
        $this->register_nav_menu('primary', 'Primary Menu');
        $this->register_nav_menu('footer', 'Footer Menu');

        // Widget areas: name, description, id, wrapper options
        $this->registerWidgetArea('Main Sidebar', '', 'sidebar-1', [
            'before_widget' => '<div class="widget">',
            'after_widget'  => '</div>',
            'before_title'  => '<h3 class="widget-title">',
            'after_title'   => '</h3>',
        ]);

        return parent::init();   // fires the `after_setup_theme` addon action
    }

    public function displayInit()
    {
        parent::displayInit();   // links the theme's style.css

        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->enqueueScript('theme-js', sURL . 'themes/' . $this->theme . '/js/theme.js');

        return $this;
    }
}
```

An addon can hook theme setup without subclassing, through the action `init()` fires:

```php
\Pramnos\Addon\Addon::addAction('after_setup_theme', function () {
    // …
});
```

## Template System

### View Overrides

Themes can override views by placing template files in their `views/` directory:

```
app/themes/my-theme/
└── views/
    ├── User/
    │   ├── profile.html.php    # Override User/profile view
    │   └── dashboard.html.php  # Override User/dashboard view
    └── Blog/
        ├── post.html.php       # Override Blog/post view
        └── archive.html.php    # Override Blog/archive view
```

### Template Hierarchy

The framework follows this template hierarchy (see
[Inheriting a bundled view](#inheriting-a-bundled-view-one-template-at-a-time)):

1. Theme-specific view override (`app/themes/<theme>/views/<view>/<tpl>.html.php`)
2. Application view (`src/Views/<view>/<tpl>.html.php`)
3. The bundled scaffold view for the configured `scaffold_theme`

### Template Variables

View templates (under `views/`) run with `$this` bound to the view and have these in scope:

```php
// In a view template
echo $this->variableName;    // View variables
echo $doc->title;            // The document
echo $lang->_('STRING');     // Language translations
echo $model->title;          // The view's default model, or false when it has none
echo sURL;                   // Base URL constant
```

Theme layout files (`theme.html.php`, `header.php`, …) run with `$this` bound to the theme;
reach the document through `$this->document`.

### Including Template Parts

```php
// In theme layout files, where $this is the theme
<?php $this->get_header(); ?>      // Include header.php
<?php $this->get_footer(); ?>      // Include footer.php
<?php $this->get_sidebar(); ?>     // Include sidebar.php
<?php $this->getElement('head'); ?> // Any entry of $this->elements

// Include custom template parts
<?php include __DIR__ . '/template-parts/hero.php'; ?>
```

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
| Write `<head>…</head>` and `<body>…</body>` in `theme.html.php` | The document moves what is between the `<head>` tags into its real `<head>`. Without them, stylesheet links land in the body and a manifest link is ignored. See [`head.php`](#headphp-and-why-head-has-to-be-in-the-layout). |
| Load the stylesheet of the project's `scaffold_theme` — Bootstrap, or Tailwind with daisyUI, or the plain-css `style.css` | The bundled admin views are written in that system's classes (`card`, `alert alert-danger`, `btn btn-primary`). With another CSS framework, or none, every screen renders unstyled. |
| Link `assets/css/theme-tokens.css` before `assets/css/style.css` | The project's palette reaches the screens only through the tokens. `style.css` also styles the search box, the breadcrumb trail and the screens' small layouts. |
| Call `$this->document->renderCss()` in the head and `$this->document->renderJs()` at the end of the body | Screens enqueue what they need. A datatable, for example, enqueues DataTables, and without these calls the list is an empty table. Each asset comes out once: the stylesheets where `renderCss()` is, the head scripts in the document's `<head>` (before the content, so an inline script in a screen can use them), the footer and inline scripts where `renderJs()` is. A layout drawn without the document's head gets the head scripts from `renderJs()`. |
| Load `assets/js/pf-utils.js` | It handles `data-confirm`, `data-href`, the search box and the other `data-*` behaviours the screens use. The framework's CSP refuses inline handlers, so nothing else can do this work. |
| Echo `\Pramnos\Conversations\Dock::render()` in the footer | The team's conversations over every page of the area: a button with what is unread and a panel. It prints nothing without the `conversations` feature, for whoever may not use them, and on the conversations page itself. See the [Conversations Guide](Pramnos_Conversations_Guide.md#the-page-and-the-dock). |
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
    [One palette, every UI system](#one-palette-every-ui-system).

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

## One palette, every UI system

A project's colours live in **one file**, `app/themes/theme.css`, written in the format
[daisyUI's theme generator](https://daisyui.com/theme-generator/) already emits:

```css
@plugin "daisyui/theme" {
    name: "acme";
    default: true;
    color-scheme: light;

    --color-base-100: oklch(100% 0 0);
    --color-base-content: oklch(21% 0.032 264.7);
    --color-primary: oklch(54.6% 0.215 262.9);
    --color-primary-content: oklch(100% 0 0);
    --radius-box: 0.75rem;
}

@plugin "daisyui/theme" {
    name: "acme-dark";
    prefersdark: true;
    color-scheme: dark;
    /* … */
}
```

`pramnos init` writes it, named after the application, and nothing else in a scaffolded
project carries a colour value.

**Annotate it freely.** Comments are stripped before anything is parsed, so `/* … */`
is safe anywhere in the file — beside a declaration, on a line of its own inside a
block, or wrapped around a declaration you are trying without deleting:

```css
@plugin "daisyui/theme" {
    name: "acme";
    /* The surfaces: white cards on a light grey page. */
    --color-base-100: #ffffff;
    --color-base-200: #f7f7f7;   /* the page behind them */
}
```

**Why that format rather than a config file of our own.** It is the one a designer can
produce without this framework existing — pick colours on daisyUI's site, copy the
block, paste it in — and for a Tailwind project with npm it needs no build step at all:
`assets/src/app.css` imports the file and the plugin reads the blocks directly.

### The build tool

Everything that is not Tailwind-with-npm needs the same tokens in a form it can read,
and that is generated:

```bash
pramnos theme:build            # write both outputs
pramnos theme:build --check    # exit 1 if they are stale — for CI
```

| Output | Read by |
|---|---|
| `www/assets/css/theme-tokens.css` | Every server-rendered theme — buildless Tailwind, Bootstrap, plain CSS. Linked from `head.php` before the theme's own stylesheet. |
| `www/assets/theme-tokens.json` | A SPA's own components, and anything else that reads JavaScript rather than CSS. |

#### The same palette, under each system's own names

Only the tailwind theme speaks daisyUI. Bootstrap's components read `--bs-*` and the
plain-CSS theme reads a vocabulary of its own, so `theme-tokens.css` also emits the
palette **as aliases of those names**:

```css
:root, [data-theme="acme"] {
    --color-primary: oklch(54.6% 0.215 262.9);
    /* The same palette, for Bootstrap and the plain-CSS theme. */
    --bs-primary: var(--color-primary);
    --bs-primary-rgb: 37, 99, 235;
    --primary-color: var(--color-primary);
}
```

Aliasing rather than rewriting those stylesheets is deliberate, and it buys something a
rewrite cannot: **Bootstrap's own components read `--bs-*` too**, so redefining the
variable reaches `.btn-primary`, `.navbar` and `.card` as well as the theme's rules —
and nothing here could edit Bootstrap. `head.php` links this file after the UI
framework's, so an equally specific `:root` in Bootstrap's stylesheet loses on source
order.

The aliases are written into every theme block, including the
`prefers-color-scheme: dark` one, so the dark palette carries its own.

**The `-rgb` triplets are computed, not aliased.** `.bg-primary` is
`rgba(var(--bs-primary-rgb), var(--bs-bg-opacity))` — and the scaffolded bootstrap
navbar is a `.bg-primary`. A palette written in `oklch()` cannot be fed to `rgba()` and
CSS cannot decompose it, so `theme:build` converts those values to sRGB itself, by the
conversion CSS Color 4 defines. `oklch()`, a hex literal and `rgb()` all convert; a value
it cannot resolve — `color-mix()`, a named colour, a `var()` reference — simply gets no
`-rgb` alias, and Bootstrap keeps its own for that one colour. A missing triplet is a
colour that disagrees; a guessed one is a colour nobody chose.

**What this turns on.** A `prefersdark` theme reaches the bootstrap and plain-CSS
themes as well, through `theme-tokens.css`'s `prefers-color-scheme` block — so a project
on either will follow the visitor's operating system unless it says otherwise. The
controls for that are the ones that were already there: drop `prefersdark: true` from the
palette, or set `data-theme` on `<html>`.

The generated stylesheet puts each theme under `[data-theme="<name>"]`, the one flagged
`default` on `:root` as well, and the one flagged `prefersdark` inside a
`prefers-color-scheme: dark` block scoped to `:root:not([data-theme])` — so the
operating system decides for a visitor who has not chosen, and an explicit choice still
wins. Without that scoping a theme switch works only for visitors whose OS is already
in light mode.

A scaffolded SPA's `scripts/build-theme.mjs` reads `app/themes/theme.css` too, on every
build and every dev-server start — the same file, rather than the server theme's
rendered `:root` properties, so no part of the palette has to be guessed back from CSS.

**That makes two readers of one file**, and they have to agree about what it means:
`ThemeTokens` for the server-rendered themes, `build-theme.mjs` for the SPA. Where they
drift is over what is *not* a declaration — a comment — so both strip comments first,
and `tests/js/spa-build-theme.test.js` runs the script against a commented palette.
Change the parsing on one side and change it on the other.

### Reading a token from PHP

For the places a custom property cannot reach — `<meta name="theme-color">`, an HTML
email:

```php
$brand = \Pramnos\Theme\ThemeTokens::token('--color-primary', fallback: '#2563eb');
```

The file is read once per request. `ThemeTokens::load()` returns every theme;
`defaultTheme()` picks the one a page gets when it asks for none — the one flagged
`default`, or the first declared, which is what daisyUI itself does.

Three things about `token()` worth relying on:

- **It never throws and never returns nothing you did not choose.** A theme that is not declared, a
  token that is not declared, a palette file that is missing or unparsable — every one of them gives
  back your `fallback`. A chart with the wrong shade is a cosmetic bug; an exception in a mail
  template is a mail nobody receives.
- **The leading `--` is optional.** `token('primary')` and `token('--primary')` are the same lookup,
  because half of the callers think in token names and half in custom properties.
- **With no `fallback`, a miss is `''`.** Not `null` — which would otherwise reach string
  concatenation in every template that omits the third argument.

**For an HTML email, use `hex()` instead.**

```php
$brand = \Pramnos\Theme\ThemeTokens::hex('--color-primary', fallback: '#2563eb');
```

`token()` returns what the palette says, and daisyUI's generator writes `oklch()`. No
mail client parses that: the declaration is dropped and the colour becomes whatever the
surrounding markup says, which in a dark template is black on black. `hex()` converts —
the same conversion `theme:build` uses for Bootstrap's `-rgb` triplets, so an email and
the page agree by construction. A value it cannot resolve to a colour, such as a
`color-mix()`, returns the fallback rather than itself: a caller that asked for a hex and
received `color-mix(…)` has the same unreadable email one step later.

`<meta name="theme-color">` is the softer case — current browsers parse `oklch()`, older
ones ignore the tag — but `hex()` is the safe answer there too.

And for a test that needs a palette of its own: prime it rather than writing the file.
`ThemeTokens::flush()` is public for exactly this, and the real palette lives in the project root
where a test has no business writing.

```php
(new \ReflectionProperty(ThemeTokens::class, 'cache'))
    ->setValue(null, [ThemeTokens::locate() => $themes]);
```


### What this does not do

**Bootstrap's Sass variables are not generated.** `theme:build` reaches Bootstrap through its
CSS custom properties (`--bs-*` and the computed `--bs-*-rgb` triplets above); anything Bootstrap
only exposes as a Sass variable at build time still means Bootstrap's own Sass.

## Writing views that survive a UI switch

`project:switch-ui` changes which CSS framework a project is dressed in. It does not rewrite a
single one of your templates. Whether your screens still look right afterwards depends entirely on
what those templates contain, so it pays to know exactly where the line is.

### What the switch changes, and what it leaves alone

| Changed by `project:switch-ui <system>` | Not touched |
|---|---|
| `scaffold_theme` in `app/app.php` | Every template under `src/Views/` (or `src/Admin/…`): yours, and any you copied with `project:publish-views` |
| CSP `style-src` in `app/app.php` (`'unsafe-inline'` for tailwind, empty otherwise), when the key is already present; when it is not, the output says what to add | `component_classes` in `app/app.php` |
| `app/themes/default/` `theme.html.php`, `head.php`, `header.php`, `footer.php`, `login.php`, **overwritten**; a changed one kept in `var/switch-ui/` | View overrides under `app/themes/<theme>/views/` |
| `www/assets/css/style.css` (in the web root served), **overwritten**; a changed one kept in `var/switch-ui/` | `app/themes/theme.css`, the palette (read, never rewritten) |
| `www/assets/js/pf-*.js`, plus the system's vendored CSS/JS | Any other stylesheet or script of your own |
| `www/assets/css/theme-tokens.css`, regenerated from your palette | |

Two consequences follow:

- **A bundled screen you never copied switches with the project.** View resolution reads
  `scaffold_theme` on every request. When neither the application nor the theme has the template,
  the lookup falls through to `scaffolding/themes/<scaffold_theme>/views/<view>/<tpl>.html.php`,
  so the inherited screens are always in the current system's markup.
- **A screen you own does not switch.** That includes a screen you copied with
  `project:publish-views`. It was copied in the markup of the system that was current when you ran
  the command, and it keeps that markup. To take the new system's copy, run
  `project:publish-views --group=<name> --force`. It overwrites the file, so re-apply your changes
  afterwards. Before you switch, list the groups you published, because every one of them is now a
  screen you maintain.

!!! warning "The theme chrome and `style.css` are scaffold-owned"
    The switch rewrites the five layout files in `app/themes/default/` and the web root's
    `assets/css/style.css`. A file it changed is kept under `var/switch-ui/<time>/` and named in
    the output, so nothing is lost, but your changes are no longer in effect until you re-apply
    them. Put the application's own rules in a stylesheet of your own, such as
    `www/assets/css/app.css`, and load it with `assetUrl()` from a place the switch does not
    write (see [Asset Management](#asset-management)).

    The switch lists the views copied with `project:publish-views` that stay in the old
    system's markup.

### What adapts on its own

These components need nothing from you. A template that uses them renders correctly under all three
systems.

| Component | Why it adapts |
|---|---|
| `Html\Pagination` | Emits the neutral hooks `pf-pagination` and `current`, which every scaffold `style.css` styles |
| `Html\StatTile` | `pf-stat-tile`, `pf-stat-grid`, and BEM children (`__label`, `__value`, `__progress`) |
| `Html\SearchBox` | `pf-omnibox` and its `pf-omnibox-*` children |
| `Html\Icon` and its action links | `pf-icon`, `pf-action`, `pf-action-danger` |
| `Html\Breadcrumb` | The list is `pf-breadcrumb`. Each item carries `breadcrumb-item` and `active`, and all three stylesheets style both |
| `Html\Form\Field` | Styled by a per-system preset. It adapts only when you pass the preset the application declared (below) |
| `Html\Form\SettingsForm` | Defaults to the declared system with no argument |
| `Html\Input`, `Html\Select` | Emit no framework class at all. Style them from your own CSS, or wrap them in a `Field` |

The `pf-*` hooks adapt because the class name stays the same and only the stylesheet behind it
changes. A test asserts that every hook has a rule in every scaffold theme, so the switch swaps in a
stylesheet that already knows them.

`Form\Field::render()` takes the preset as a required argument. Pass the declared one, never a
literal:

```php
use Pramnos\Html\Form\FieldStyles;

echo $field->render(FieldStyles::for(FieldStyles::configured()));   // follows scaffold_theme
echo $field->render(FieldStyles::for('bootstrap'));                 // pinned: will not follow a switch
```

`FieldStyles::configured()` resolves `scaffold_theme` to a preset (`bootstrap`, `tailwind`, or
`plain` for anything else, `plain-css` included). Resolve it once per view, in a variable, if you
render many fields.

### What does not adapt

- **`Html\Datatable` follows the UI system only in part.** `$bootstrap` defaults to on under
  `scaffold_theme = bootstrap` (or none declared) and off under tailwind and plain-css. On, it
  appends `table table-striped table-bordered table-hover` and draws the column picker as a
  Bootstrap dropdown that carries both 3's and 5's attributes. Off, the table keeps DataTables' own
  `display` look and the picker is plain links, with `pf-footsearch` and `pf-action` for the
  filters and row actions. `$jui = true` draws jQuery UI markup instead. A table built while one
  system is declared does not change its markup when another is; set the property to choose.

    ```php
    $dt = new \Pramnos\Html\Datatable('dt-orders');
    $dt->bootstrap = false;   // neutral markup, whatever the system
    ```

- **`Html\Date`.** The input carries Bootstrap's `form-control` by default, which all three
  scaffolded stylesheets style. To change the class, use the `date` key in `component_classes`, but
  read the next item first.
- **`component_classes`.** It is one static map, not one map per system, and the switch does not
  touch it. If you set `'pagination' => 'pagination'` to suit Bootstrap, every system receives
  that class, and the `pf-pagination` rules no longer apply. Use it only when a script needs a
  specific class name, as its own docblock says. For anything visual, style the `pf-*` hook instead.
- **Raw markup in your templates.** The framework cannot see inside it. Every class you write is a
  promise about which stylesheet is loaded.

### What stays tied to a system no matter what

Some markup has no neutral form, because the framework ships no component for it:

- **Layout grids.** `row` / `col-md-6` is Bootstrap, and `grid grid-cols-2 gap-4` is Tailwind.
  plain-css has a small compatibility grid (`.row`, `.col-md-2/3/4/6`). The other `col-*` names it
  covers (`col-md-12`, `col-sm-12`, `col-lg-4`, `col-lg-6`, `col-xl-3`) are always full width, and
  it has no breakpoints. The tailwind system defines no `.row` or `.col-*` at all.
- **Cards, alerts, badges, buttons.** daisyUI shares some of Bootstrap's names (`btn`,
  `btn-primary`, `card`, `card-body`, `table`, `alert`, `badge`), so a Bootstrap template looks
  roughly right under tailwind. Its modifiers differ, though (`alert-danger` against `alert-error`,
  `table-striped` against `table-zebra`), and `card-header`, `form-control` and `form-select` do
  not exist there. plain-css styles a minimal Bootstrap-compatible subset (`card`, `btn-*`,
  `table`, `alert`, `badge`, `dropdown-menu`, `form-control`) so the framework's own Bootstrap-flavoured
  screens stay legible. It is not full Bootstrap.
- **A daisyUI alert is a grid.** Each child of `.alert` gets a column of its own — meant for an
  icon beside its message — so `<strong>Title</strong> text <p>more</p>` is three narrow columns,
  the text breaking word by word. An alert holding text with elements carries `block` (or
  `flex-col`); `TailwindAlertLayoutTest` fails on one that does not.
- **JavaScript behaviour.** Dropdowns, modals and collapses depend on the system's own script and
  its `data-*` attribute names.

!!! note "Close enough is not the same as equivalent"
    That overlap is why a Bootstrap template looks almost right after a switch. It renders, nothing
    errors, and the differences show up as a missing border or an unstyled alert. A test can only
    catch this by rendering the page.

### Recommended approach

1. **Reach for a component first.** Pagination, breadcrumbs, stat tiles, search, icons, action links
   and form fields already adapt. Every one you use is markup you no longer port.
2. **Put your own structure behind your own hooks.** For anything the components do not cover, such
   as a page header, a panel or a two-column layout, give the element one semantic class (`app-panel`,
   `app-split`) and style it in your own stylesheet from the palette tokens
   (`var(--color-primary)`; see [One palette, every UI system](#one-palette-every-ui-system)). The
   template then stays the same across a switch, and you rewrite one stylesheet, not 300 templates.
   This is the same convention the framework follows with `pf-*`.
3. **Pass `FieldStyles::configured()`, never a system name.**
4. **Leave `Datatable::$bootstrap` to follow the system**, or set it to `false` for markup that
   is the same everywhere.
5. **Keep `component_classes` for script-facing names only.**
6. **Own as few bundled screens as you can.** Every bundled screen you inherit, rather than
   publish, switches for free.

### Before and after

A dashboard block written directly against Bootstrap 5:

```php
<div class="row">
  <div class="col-md-6">
    <div class="card"><div class="card-body">
      <h5 class="card-title">Open orders</h5>
      <p class="display-6"><?php echo $open; ?></p>
    </div></div>
  </div>
</div>
<nav><ul class="pagination">…hand-built page links…</ul></nav>
```

The same block written to survive a switch:

```php
<?php use Pramnos\Html\StatTile; ?>
<div class="app-split">
  <?php echo StatTile::grid([
      StatTile::render('Open orders', number_format($open), link: URL . 'orders'),
  ]); ?>
</div>
<?php echo (new \Pramnos\Html\Pagination($pages, $page, URL . 'orders/page/:page'))->render(); ?>
```

```css
/* www/assets/css/app.css: yours, untouched by project:switch-ui */
.app-split { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr)); }
```

The tile and the pager are dressed by whichever system is active. The only layout rule is in a file
the switch does not write.

### Choosing, for a project with many templates

!!! tip "Decision rule"
    **Does the project expect to change UI system again?**

    - **No, Bootstrap 5 is the destination.** Write Bootstrap 5 markup directly. It is the most
      readable option for a team that knows Bootstrap, and nothing here penalises it. Still use the
      components where they exist, set `Datatable::$bootstrap = false`, and pass
      `FieldStyles::configured()`. Those three cost nothing and remove the known breakages.
    - **Yes, or not sure.** Write components plus your own semantic hooks, and keep framework
      classes out of templates. The cost moves from N templates to one stylesheet per system.
    - **Already have 300 Bootstrap templates and must switch now.** Switch to tailwind before
      plain-css, because daisyUI's shared names carry the most. Then fix the grid first, since it
      is the part that breaks visibly, and convert screens to hooks as you touch them rather than
      in one pass.

## Asset Management

`Theme::displayInit()` links the theme's `style.css` (the `style` element) on its own, at
`sURL . 'themes/<theme>/style.css'`. Anything else is enqueued from an override of it.

### Theme Stylesheets

```php
// In the theme class
public function displayInit()
{
    parent::displayInit();   // style.css

    $doc  = \Pramnos\Framework\Factory::getDocument();
    $base = sURL . 'themes/' . $this->theme;

    // enqueueStyle($handle, $src, $deps = [], $version = '', $media = 'all')
    $doc->enqueueStyle('theme-layout', $base . '/css/layout.css');

    if ($this->getSetting('enable_dark_mode')) {
        $doc->enqueueStyle('theme-dark', $base . '/css/dark.css', ['theme-layout']);
    }

    $doc->enqueueStyle('theme-responsive', $base . '/css/responsive.css', [], '', 'screen and (max-width: 768px)');

    return $this;
}
```

### Theme JavaScript

```php
public function displayInit()
{
    parent::displayInit();

    $doc  = \Pramnos\Framework\Factory::getDocument();
    $base = sURL . 'themes/' . $this->theme;

    // enqueueScript($handle, $src, $deps = [], $version = '', $footer = false)
    $doc->enqueueScript('theme-js', $base . '/js/theme.js', [], '', true);

    if ($this->getSetting('enable_animations')) {
        $doc->enqueueScript('theme-animations', $base . '/js/animations.js', ['theme-js'], '', true);
    }

    // Inline configuration — carries the page's CSP nonce
    $doc->addInlineScript('var themeSettings = ' . json_encode([
        'ajaxUrl'     => sURL . 'ajax/',
        'colorScheme' => $this->getSetting('color_scheme'),
    ]) . ';');

    return $this;
}
```

For a file served from the web root, prefer [`assetUrl()`](#static-files-asseturl), which adds a
cache-busting `?v=<mtime>`.

### Dynamic CSS

`$elements` lists a `dynamicStyle` entry (`style.php`), but the framework neither serves nor links
it. Colours that change per installation belong in the palette —
[One palette, every UI system](#one-palette-every-ui-system) — or in an inline style the theme
emits from a setting:

```php
// In a theme layout file
<style nonce="<?php echo \Pramnos\Application\Application::getInstance()->cspNonce; ?>">
:root { --brand: <?php echo htmlspecialchars((string) ($this->getSetting('primary_color') ?: '#007cba')); ?>; }
</style>
```

## Theme Development

### Creating a New Theme

1. **Create the theme directory** under `app/themes/`:

```bash
mkdir -p app/themes/my_new_theme
```

2. **Create the layout** — `app/themes/my_new_theme/theme.html.php`:

```php
<head>
<?php $this->getElement('head'); ?>
</head>
<body>
<a class="pf-skip-link" href="#main-content">Skip to content</a>
<div class="site-wrapper">
    <header class="site-header"><?php $this->get_header(); ?></header>
    <main id="main-content" class="site-main">[MODULE]</main>
    <footer class="site-footer"><?php $this->get_footer(); ?></footer>
</div>
</body>
```

with `head.php`, `header.php` and `footer.php` beside it, and a `style.css`:

```css
/* style.css */
.site-wrapper { min-height: 100vh; display: flex; flex-direction: column; }
.site-main    { flex: 1; padding: 20px; }
.site-header  { padding: 20px; border-bottom: 1px solid var(--color-base-300, #e9ecef); }
.site-footer  { padding: 20px; text-align: center; }
```

3. **Create the theme class** — `app/themes/my_new_theme/my_new_theme.php`. The class name is the
   directory name plus `_theme`, which is why a theme that has a class needs a directory name
   that is a valid PHP identifier:

```php
<?php
// app/themes/my_new_theme/my_new_theme.php
class my_new_theme_theme extends \Pramnos\Theme\Theme
{
    public function init()
    {
        $this->title  = 'My New Theme';
        $this->author = 'Your Name';
        $this->info   = 'A custom theme for Pramnos Framework';

        $this->register_nav_menu('primary', 'Primary Menu');
        $this->register_nav_menu('footer', 'Footer Menu');

        $this->registerWidgetArea('Main Sidebar', '', 'sidebar-main');
        $this->registerWidgetArea('Footer Widget 1', '', 'footer-1');

        $this->addSetting('logo', 'Site Logo', 'image');
        $this->addSetting('primary_color', 'Primary Color', 'color');
        $this->addSetting('show_sidebar', 'Show Sidebar', 'checkbox');

        return parent::init();
    }
}
```

4. **Select it** with `'theme' => 'my_new_theme'` in `app/app.php`.

### Theme Testing

```php
use PHPUnit\Framework\TestCase;

class ThemeTest extends TestCase
{
    protected function tearDown(): void
    {
        \Pramnos\Document\Document::reset();   // also resets the cached theme objects
    }

    public function testThemeLoading(): void
    {
        $theme = \Pramnos\Theme\Theme::getTheme('my_new_theme', '', false);
        $this->assertInstanceOf(\Pramnos\Theme\Theme::class, $theme);
        $this->assertSame('my_new_theme', $theme->theme);
    }

    public function testWidgetAreas(): void
    {
        $theme = \Pramnos\Theme\Theme::getTheme('my_new_theme', '', false);
        $areas = $theme->getWidgetAreas();

        $this->assertArrayHasKey('sidebar-main', $areas);
        $this->assertArrayHasKey('footer-1', $areas);
    }
}
```

There is no `setSetting()` on a theme: values are saved from a submitted form with
`saveSettings()` and read with `getSetting()`.

## Best Practices

### Theme Performance

```php
public function displayInit()
{
    parent::displayInit();

    $doc  = \Pramnos\Framework\Factory::getDocument();
    $base = sURL . 'themes/' . $this->theme;

    if (\Pramnos\Application\Application::getInstance()->isDebugMode()) {
        $doc->enqueueStyle('theme-style', $base . '/css/style.css');
        $doc->enqueueScript('theme-script', $base . '/js/script.js');
    } else {
        $doc->enqueueStyle('theme-combined', $base . '/css/combined.min.css');
        $doc->enqueueScript('theme-combined', $base . '/js/combined.min.js');
    }

    return $this;
}
```

### Accessibility

- A skip link as the first focusable element, styled off-screen rather than hidden — see
  [Two things every theme owes its readers](#two-things-every-theme-owes-its-readers).
- One `<main>` landmark, and a heading hierarchy that starts at `<h1>`.
- `alt` on every image; `aria-label` on each `<nav>` when a page has more than one.
- A `<label for>` on every form control.

### Security

```php
// Escape output — the framework ships no esc_* helpers; use PHP's
echo htmlspecialchars($userInput, ENT_QUOTES);
echo htmlspecialchars($url, ENT_QUOTES);

// Validate settings before using them in CSS or markup
$color = (string) $this->getSetting('primary_color');
if (!preg_match('/^#[a-f0-9]{6}$/i', $color)) {
    $color = '#007cba';
}
```

Requests that change state go through a controller, where CSRF protection and permission checks
apply — see the [Authentication Guide](Pramnos_Authentication_Guide.md).

---

## Related Documentation

- **[Framework Guide](Pramnos_Framework_Guide.md)** - MVC architecture and view system
- **[Document & Output Guide](Pramnos_Document_Output_Guide.md)** - Document system integration
- **[Authentication Guide](Pramnos_Authentication_Guide.md)** - User-based theme features
- **[Cache System Guide](Pramnos_Cache_Guide.md)** - Caching theme assets and output

---

The Pramnos Theme System provides a comprehensive foundation for building flexible, maintainable, and feature-rich themes. With support for widgets, menus, settings, and complete customization, you can create themes that meet any design requirement while maintaining clean separation between presentation and logic.
