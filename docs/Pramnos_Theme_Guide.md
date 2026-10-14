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
7. [Theme Settings](#theme-settings)
8. [Content Types](#content-types)
9. [One palette, every UI system](#one-palette-every-ui-system)
10. [Asset Management](#asset-management)
11. [Theme Development](#theme-development)
12. [Best Practices](#best-practices)

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
`app/themes/default`, writes `www/assets/css/style.css`, and vendors the framework's
CSS/JS for that system.

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
  setting could be switched on and never off.
- **A rejected submit writes nothing.** `saveSettings()` refuses to store an empty result, so a
  submission whose CSRF token does not check out leaves the existing settings alone instead of
  blanking every one of them.

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
