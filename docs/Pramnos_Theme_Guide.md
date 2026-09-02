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
12. [Advanced Features](#advanced-features)
13. [Best Practices](#best-practices)

## Architecture Overview

The theme system is built around the `\Pramnos\Theme\Theme` class and integrates seamlessly with the document and view systems:

```
themes/
├── default/                 # Default theme
│   ├── theme.html.php      # Main template file
│   ├── style.css           # Theme stylesheet
│   ├── screenshot.png      # Theme preview image
│   ├── header.php          # Header template
│   ├── footer.php          # Footer template
│   └── views/              # View overrides
│       └── ControllerName/
│           └── template.html.php
└── custom-theme/           # Custom theme
    ├── theme.html.php
    ├── style.css
    └── functions.php       # Theme customization
```

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

// Get theme with specific path
$theme = \Pramnos\Theme\Theme::getTheme('my-theme', '/custom/themes/path');

// Load theme for display
$theme = \Pramnos\Theme\Theme::getTheme('my-theme', '', true);
```

### Setting Active Theme

```php
// Set theme in application settings
\Pramnos\Application\Settings::setSetting('theme', 'my-theme');

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

The `<main>` landmark has been in these themes all along — only the link to it was missing, so
reaching a page's content meant tabbing through the whole navigation, on every page.

Style it **off-screen, not hidden**: `display: none` takes it out of the tab order, which removes
the one thing it exists to provide.

```css
.pf-skip-link { position: absolute; left: -9999px; }
.pf-skip-link:focus { left: 0; }
```

**And a `viewport` you do not have to remember.** It is on the `Html` document type now, so a theme
that omits it still gets one. It used to live only here, and a theme that forgot produced a page
Google labels not mobile-friendly with no signal anywhere that anything was missing.

### Nothing about the visitor leaves the page

The scaffolded consent screen used to fall back to Gravatar when an account had no avatar of its
own:

```php
$this->user->avatar ?? 'https://www.gravatar.com/avatar/' . md5(strtolower($this->user->email))
```

So every render of `/oauth/authorize` sent `md5(email)` of the person signing in to another
company — from the one page in the whole flow where they are deciding what to disclose, and to a
party they were never asked about.

An md5 of an address is not anonymous. It is the address, hashed: the set of email addresses that
matter is small enough to enumerate, and that lookup is Gravatar's entire product.

The avatar renders when the account has one and is omitted when it does not. **If you write your own
consent screen, do not reintroduce it** — nor any other third-party asset on an authentication page.
A remote image is a request that carries a `Referer`, a timestamp and an IP to somebody else.

### Main Template File (theme.html.php)

The main template file defines the overall page structure:

```php
<!DOCTYPE html>
<html lang="<?php echo $lang->_('LangShort'); ?>">
<head>
    <meta charset="<?php echo $lang->_('CHARSET'); ?>">
    <title><?php echo $doc->title; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo $doc->header; // Framework-generated head content ?>
</head>
<body>
    <header>
        <h1><?php echo \Pramnos\Application\Settings::getSetting('sitename'); ?></h1>
        <?php 
        // Display navigation menu
        wp_nav_menu(['theme_location' => 'primary']); 
        ?>
    </header>
    
    <main>
        <?php echo '[MODULE]'; // Framework content insertion point ?>
    </main>
    
    <aside>
        <?php dynamic_sidebar('sidebar-1'); // Widget area ?>
    </aside>
    
    <footer>
        <p>&copy; <?php echo date('Y'); ?> <?php echo $sitename; ?></p>
    </footer>
</body>
</html>
```

### How a page's content reaches `[MODULE]`

`[MODULE]` is where the page goes, and the theme guide used to stop there — leaving *how
the page gets there* undocumented. It cost a consuming project real time, so it is spelled
out here.

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

!!! note "`$document->content` also works now, and used to be a trap"
    The property is public and looks exactly like the seam, but only `Document::render()`
    read it — and every concrete document type (`Html`, `Amp`, `Json`, `Png`, `Raw`)
    overrides `render()` and read a static buffer instead. So on an HTML page
    `$document->content = $html` produced a correct header, a correct footer, and **nothing
    between them**: the theme visibly working, the page visibly empty, and no error to look
    up.

    Since 2026-08-17 every type falls back to the property when the buffer is empty, so
    both work. Prefer `setContent()` anyway: it is what the framework itself uses, and it
    is the one that composes with `addContent()`.

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
  hands back has read nothing yet. Before the lazy load existed, an application that
  assigned `themeObject` and rendered through any route other than `Html::render()` — which
  calls `loadTheme()` itself — got an object that reported no error and produced the
  framework's bare default, because the accessors were splitting an empty string.
- An explicit `loadtheme()` call still re-reads, every time. That is deliberate: the file it
  picks depends on the content type, so setting a content type and reloading is how you
  switch templates. And a `body` you assign yourself is never read over.

### Content Type Templates

Themes can have specific templates for different content types:

```php
// In theme constructor or init method
$this->elements = [
    'index' => 'theme.html.php',      // Default template
    'page' => 'page.html.php',        // Page template
    'single' => 'single.html.php',    // Single post template
    'archive' => 'archive.html.php',  // Archive template
    'search' => 'search.html.php',    // Search results
    '404' => '404.html.php',          // Error page
    'login' => 'login.php',           // Login and the rest of the auth flow
    'header' => 'header.php',         // Header include
    'footer' => 'footer.php',         // Footer include
    'sidebar' => 'sidebar.php',       // Sidebar include
    'style' => 'style.css',           // Main stylesheet
    'dynamicStyle' => 'style.php'     // Dynamic CSS
];
```

### Where themes are looked for

`getTheme()`, the constructor, `getThemes()` and `getThemeObjects()` all search
`APP_PATH/themes` and then `ROOT/themes`, merging what they find — a project may
legitimately have both, `app/themes/` for its own and `ROOT/themes/` inherited from an
older layout.

They did not always agree. `getThemes()` looked **only** at `ROOT/themes`, so on the
layout `init` creates it returned an empty array *silently* — visible as an empty theme
picker with nothing in any log. `getThemeObjects()` was worse: it opened that directory
with no existence check, so it warned, got `false`, and handed `false` to `readdir()` — a
TypeError on PHP 8.

`getThemeObjects()` is now built on `getThemes()` rather than repeating the directory
walk, which is what let the two drift in the first place.

!!! note "A theme class is included once, and the check comes first"
    `getTheme()` asks `class_exists($name, false)` **before** including the theme's PHP
    file. The check used to come after, where it could not prevent what it was there to
    prevent: a class already defined — by another loader, by an autoloader, by a second
    path to the same file — made the include a fatal `Cannot redeclare class`.

    `include_once` would not have fixed it: it keys on the *resolved path*, so two routes
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

**Per template, not per directory.** Until 2026-08-27 only `Controller::getView()`
had a fallback, and it applied when the view *directory* could not be found — so
an application with `src/Views/services/logs.html.php` and no `services.html.php`
matched at the directory, failed at the template, and the services list came back
as a page shell: 200, no panel, one line in a log nobody reads. Now each template
resolves on its own.

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
CSS/JS for that system. The scaffolded views themselves are resolved per-system from the
bundled scaffolding, so nothing needs copying per screen.

| System | What it is |
| --- | --- |
| `plain-css` | hand-written CSS, no framework, no vendored assets |
| `bootstrap` | Bootstrap 5, vendored locally |
| `tailwind` | Tailwind 4 **and daisyUI 5** — see below |

#### The tailwind theme is a daisyUI theme

It renders through daisyUI components — `btn btn-primary`, `card bg-base-100`,
`alert alert-error`, `navbar`, `menu`, `table` — and reads colours from daisyUI's tokens
(`base-100`, `base-200`, `base-300`, `base-content`, `primary`, `error`) rather than from
Tailwind's palette. Both halves matter:

- **A component carries the theme; a utility carries one palette.** `bg-white` and
  `text-gray-700` are invisible or unreadable under `data-theme="dark"`, and nothing in
  any log says so — the page renders, the text is simply not there. This theme had
  Bootstrap classes leak into it once by the same route, so
  `tests/Unit/Http/AdminUrlInViewsTest.php` now fails on a hardcoded palette in any
  bundled view.
- **Light and dark both ship.** A toggle in the header stores the choice, and `head.php`
  applies it to `data-theme` before the first paint — inline and synchronous, because
  deferring it paints light and then flips.

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

**A project that wants a build step should have one.** Tailwind's browser build is a
runtime compile and daisyUI's prebuilt CSS is the whole library, unpurged: fine for a
scaffolded application, and not what you want in front of real traffic. An application
with npm should compile `@import "tailwindcss"; @plugin "daisyui";` into
`www/assets/css/style.css` and define its own themes as token blocks — the theme's markup
does not change, because it was written against the tokens rather than against a palette.

Both need `'unsafe-inline'` in the CSP's `style-src` while the browser build is in use;
`init` and `project:switch-ui` set that for the tailwind system and leave the other two
strict.

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

That was the case for every scaffolded theme, and it went unnoticed for as long as
stylesheets were the only thing involved: a browser hoists `<link rel="stylesheet">` out
of the body and applies it. It does **not** honour `<link rel="manifest">` there. So a
project shipped a manifest, linked it, served it with a 200 — and devtools said *No
manifest detected*, with the link plainly visible in the page source.

The `<body>` tag matters for the other half: it is what makes `Theme::loadtheme()` set
`$body` to the body content alone, so the split at `[MODULE]` cannot pick up the head
assets as page content and emit them twice.

**`head.php` and `header.php` are different things**, which is the distinction the old
single file lost: `head.php` is the document head — stylesheets, the favicon set, the
manifest link, `renderCss()`. `header.php` is the *visible* site header — the logo and
the navigation. Only one of them can be moved into `<head>`.

A hand-written theme with neither tag keeps working exactly as it did: assets in the
body, hoisted by the browser, and no manifest.

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
[MODULE]
    <script src="<?php echo sURL; ?>assets/js/pf-utils.js"></script>
    <?php $this->document->renderJs(); ?>
</body>
```

Three things about that file are deliberate:

- **`<head>` and `<body>` are written out**, unlike `theme.html.php`. `getheader()`
  extracts `<head>…</head>` and the document appends it inside its own head, and the
  `<body>` tag is what stops the split at `[MODULE]` from treating the stylesheet
  links as body content.
- **`renderCss()` and `renderJs()` are still there.** A standalone layout is not a
  suppressed one: the login page enqueues assets like any other — the passkey flow is
  one — and dropping those calls breaks it in a way that looks like a JavaScript bug.
- **The navigation is not built at all.** Not hidden with CSS: `NavRegistry` is never
  consulted, so nothing queries the user's permissions to assemble a menu that is not
  going to be shown.

**A theme with no `login.php` is unaffected.** `loadtheme()` falls back to
`theme.html.php` for any content type whose file is missing, so a hand-written theme
that predates this keeps rendering exactly as it did — `Account` asks every theme and
takes the answer it gets.

!!! warning "The content type is per-request state on a cached object"
    `Theme::getTheme()` caches by name, so one theme object serves every request in the
    process — and `setContentType()` writes to it. Nothing put it back, so in any process
    serving more than one request, **every page after a sign-in page rendered with no
    header and no footer**: the navigation simply absent, status 200, nothing in any log.

    One process, one request hides it completely. A worker, a daemon and every test that
    visits `/login` and then anything else see it. `Document::reset()` now calls
    `Theme::reset()` — a document carries a theme, so resetting documents without
    resetting themes left half the state behind — and `TestClient` calls that per request.

    If you serve several requests in one PHP lifetime by other means, call
    `Document::reset()` between them.

### Theme Functions File

Create a `functions.php` file for theme customization:

```php
<?php
// Theme functions and customization

// Theme setup
add_action('after_setup_theme', 'my_theme_setup');

function my_theme_setup() {
    // Add theme support for various features
    add_theme_support('post-thumbnails');
    add_theme_support('custom-logo');
    
    // Register navigation menus
    register_nav_menus([
        'primary' => 'Primary Menu',
        'footer' => 'Footer Menu'
    ]);
    
    // Register widget areas
    register_sidebar([
        'name' => 'Main Sidebar',
        'id' => 'sidebar-1',
        'before_widget' => '<div class="widget">',
        'after_widget' => '</div>',
        'before_title' => '<h3 class="widget-title">',
        'after_title' => '</h3>'
    ]);
}

// Enqueue theme assets
add_action('wp_enqueue_scripts', 'my_theme_scripts');

function my_theme_scripts() {
    $doc = \Pramnos\Framework\Factory::getDocument();
    
    // Theme stylesheet
    $doc->enqueueStyle('theme-style', get_template_directory_uri() . '/style.css');
    
    // Theme JavaScript
    $doc->enqueueScript('theme-js', get_template_directory_uri() . '/js/theme.js', ['jquery']);
}
```

## Template System

### View Overrides

Themes can override framework views by placing template files in the `views/` directory:

```
themes/my-theme/
└── views/
    ├── User/
    │   ├── profile.html.php    # Override User/profile view
    │   └── dashboard.html.php  # Override User/dashboard view
    └── Blog/
        ├── post.html.php       # Override Blog/post view
        └── archive.html.php    # Override Blog/archive view
```

### Template Hierarchy

The framework follows this template hierarchy:

1. Theme-specific view override (`themes/mytheme/views/Controller/template.html.php`)
2. Application view (`src/Views/Controller/template.html.php`)
3. Framework default view (if applicable)

### Template Variables

Templates have access to these variables:

```php
// In any template file
echo $this->variableName;    // View variables
echo $doc->title;            // Document properties
echo $lang->_('STRING');     // Language translations
echo sURL;                   // Base URL constant
echo $_url;                  // Controller URL
```

### Including Template Parts

```php
// In theme templates
<?php get_header(); ?>      // Include header.php
<?php get_footer(); ?>      // Include footer.php
<?php get_sidebar(); ?>     // Include sidebar.php

// Include custom template parts
<?php include 'template-parts/hero.php'; ?>
<?php include 'template-parts/content-' . $post_type . '.php'; ?>
```

## Widget System

### Registering Widget Areas

```php
// In theme functions.php or theme class
class MyTheme extends \Pramnos\Theme\Theme
{
    public function init()
    {
        // Register widget areas (sidebars)
        $this->addSidebar('sidebar-1', 'Main Sidebar', [
            'description' => 'Main sidebar widget area',
            'before_widget' => '<div class="widget %2$s">',
            'after_widget' => '</div>',
            'before_title' => '<h3 class="widget-title">',
            'after_title' => '</h3>'
        ]);
        
        $this->addSidebar('footer-1', 'Footer Widget 1', [
            'description' => 'First footer widget area'
        ]);
        
        return $this;
    }
}
```

### Displaying Widget Areas

```php
// In theme templates
<?php if (is_active_sidebar('sidebar-1')) : ?>
    <div class="sidebar">
        <?php dynamic_sidebar('sidebar-1'); ?>
    </div>
<?php endif; ?>

// Multiple footer widgets
<div class="footer-widgets">
    <?php for ($i = 1; $i <= 3; $i++) : ?>
        <?php if (is_active_sidebar("footer-{$i}")) : ?>
            <div class="footer-widget-<?php echo $i; ?>">
                <?php dynamic_sidebar("footer-{$i}"); ?>
            </div>
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

> Until 2026-08-14 `renderWidgetArea()` returned an **empty string always**: the render loop
> was commented out and referred to a `pramnos_theme_widget` class from a deprecated CMS that
> the framework does not ship. A theme could declare widget areas and nothing would ever appear
> in them. The `Pramnos\Theme\Widget` base class this section used to document did not exist
> either.

## Menu System

### Registering Menu Areas

```php
// In theme class or functions.php
class MyTheme extends \Pramnos\Theme\Theme
{
    public function init()
    {
        // Register menu locations
        $this->addMenuArea('primary', 'Primary Navigation', [
            'description' => 'Main site navigation'
        ]);
        
        $this->addMenuArea('footer', 'Footer Navigation', [
            'description' => 'Footer links'
        ]);
        
        $this->addMenuArea('social', 'Social Links', [
            'description' => 'Social media links'
        ]);
        
        return $this;
    }
}

// Alternative registration
register_nav_menus([
    'primary' => 'Primary Menu',
    'footer' => 'Footer Menu',
    'social' => 'Social Links'
]);
```

### Displaying Menus

```php
// Basic menu display
<?php wp_nav_menu(['theme_location' => 'primary']); ?>

// Advanced menu with custom options
<?php wp_nav_menu([
    'theme_location' => 'primary',
    'container' => 'nav',
    'container_class' => 'main-navigation',
    'container_id' => 'site-navigation',
    'menu_class' => 'nav-menu',
    'menu_id' => 'primary-menu',
    'before' => '<li class="menu-item">',
    'after' => '</li>',
    'link_before' => '<span>',
    'link_after' => '</span>',
    'echo' => true
]); ?>

// Conditional menu display
<?php if (has_nav_menu('primary')) : ?>
    <nav class="main-navigation">
        <?php wp_nav_menu(['theme_location' => 'primary']); ?>
    </nav>
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

The documented `displayMenu()` arguments still work, including the legacy `[URL]`, `[TITLE]`,
`[ACTIVE]…[/ACTIVE]` and `[HASSUB]…[/HASSUB]` markers in `topmenuoption` and `before`. A theme
that passes none of them gets sensible markup without knowing they exist.

> Until 2026-08-14 `displayMenu()` instantiated `pramnoscms_menu` unconditionally — a class
> from a deprecated CMS that the framework does not ship. Written unqualified inside the theme's
> namespace, the name resolved to that namespace rather than the global one, so it could not be
> satisfied even by a project that had the global class. **The method fatalled in every project
> without it**, and the framework's own test had to `eval()` a fake one to test it at all. The `MenuWalker` this section used to document did not exist; the version described here
> is real, and the API that section showed was WordPress's `Walker_Nav_Menu`.

## Theme Settings

A theme declares the settings it wants; the framework renders them, reads them back and
persists them. That is the whole contract, and it is the same shape as Django's `Form`,
Rails' model validations and WordPress's Settings API: **you describe fields, not markup.**

!!! warning "This section was wrong until 2026-08-17, and the feature did not work"
    It documented `$this->addField(...)` on a theme. `addField()` is real, but it belongs to
    the **form**, not the theme: the chain was `Theme::addSetting()` →
    `pramnos_html_form::addField()`, and the guide had written the inner call as though it were
    the theme's own. It also used a `'value,Label|value,Label'` option format the code never
    parsed.

    And it would not have mattered either way: every settings method fatalled. The form object
    they called into was a legacy class (`pramnos_html_form`) that was never ported, and the
    line building it arrived from the legacy framework **already commented out**, so `$_form`
    was null from the first day of the file.

    The name is kept on the new form class — `SettingsForm::addField()` — precisely so that
    knowledge lands on the right object instead of the wrong one.

    Both are fixed. The feature works, and this section describes what it does.

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
theme declared nothing — it is not an error, and it used to be a fatal.

Three behaviours worth relying on:

- **Everything is escaped.** Values, labels, descriptions and every option. A `"` in a value
  cannot end the attribute it sits in. The class this replaced interpolated values straight
  into `value="…"`, which on a settings page is the worst possible place for it: the values are
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
fields, and it was non-functional for the same reason — so **no addon could have settings at
all** until this was fixed. `Addon::getProperty($name, $language)` returns the translated
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
        <?php dynamic_sidebar('sidebar-1'); ?>
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

// A path or URL. `image` is the legacy name for this type and renders a text input:
// nothing in the framework has ever drawn a picker for it.
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
        // Set content type for theme template selection
        $this->setContentType('archive');
        
        // Theme will use archive.html.php if available
        $view = $this->getView('Blog');
        return $view->display('archive');
    }
    
    public function post()
    {
        $this->setContentType('single');
        
        $view = $this->getView('Blog');
        return $view->display('post');
    }
}
```

### Template Selection Logic

```php
// Theme automatically selects templates based on content type
// Priority order:
// 1. {content-type}.{format}.php (e.g., single.html.php)
// 2. theme.{format}.php (e.g., theme.html.php) 
// 3. Default framework template

// Custom content type handling
class MyTheme extends \Pramnos\Theme\Theme
{
    public function loadtheme()
    {
        $contentType = $this->getContentType();
        
        // Custom logic for specific content types
        switch ($contentType) {
            case 'product':
                $this->elements['product'] = 'templates/product.html.php';
                break;
            case 'event':
                $this->elements['event'] = 'templates/event.html.php';
                break;
        }
        
        parent::loadtheme();
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

The generated stylesheet puts each theme under `[data-theme="<name>"]`, the one flagged
`default` on `:root` as well, and the one flagged `prefersdark` inside a
`prefers-color-scheme: dark` block scoped to `:root:not([data-theme])` — so the
operating system decides for a visitor who has not chosen, and an explicit choice still
wins. Without that scoping a theme switch works only for visitors whose OS is already
in light mode.

A scaffolded SPA's `scripts/build-theme.mjs` reads `app/themes/theme.css` too, on every build
and every dev-server start. It used to scrape the server theme's `:root` properties and
map what it recognised — which meant guessing the two thirds of the palette it had no
name for.

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

And for a test that needs a palette of its own: prime it rather than writing the file.
`ThemeTokens::flush()` is public for exactly this, and the real palette lives in the project root
where a test has no business writing.

```php
(new \ReflectionProperty(ThemeTokens::class, 'cache'))
    ->setValue(null, [ThemeTokens::locate() => $themes]);
```


### What this does not do

**Bootstrap's own variables are not generated.** Bootstrap 5 wants `--bs-primary` as a
hex plus a `--bs-primary-rgb` triplet, and an `oklch()` value cannot be decomposed into
one without a colour-space conversion this framework has no business doing at build
time. A Bootstrap project gets the tokens and uses them directly
(`background: var(--color-primary)`); theming Bootstrap's own components still means
Bootstrap's own Sass.

## Asset Management

### Theme Stylesheets

```php
// In theme class or functions.php
public function displayInit()
{
    $doc = \Pramnos\Framework\Factory::getDocument();
    
    // Main theme stylesheet
    $doc->enqueueStyle('theme-style', $this->getThemeUrl() . '/style.css');
    
    // Additional stylesheets
    $doc->enqueueStyle('theme-layout', $this->getThemeUrl() . '/css/layout.css', ['theme-style']);
    
    // Conditional stylesheets
    if ($this->getSetting('enable_dark_mode')) {
        $doc->enqueueStyle('theme-dark', $this->getThemeUrl() . '/css/dark.css');
    }
    
    // Responsive stylesheets
    $doc->enqueueStyle('theme-responsive', $this->getThemeUrl() . '/css/responsive.css', [], '', 'screen and (max-width: 768px)');
    
    return $this;
}
```

### Theme JavaScript

```php
public function displayInit()
{
    $doc = \Pramnos\Framework\Factory::getDocument();
    
    // Theme JavaScript
    $doc->enqueueScript('theme-js', $this->getThemeUrl() . '/js/theme.js', ['jquery'], '', true);
    
    // Conditional scripts
    if ($this->getSetting('enable_animations')) {
        $doc->enqueueScript('theme-animations', $this->getThemeUrl() . '/js/animations.js', ['theme-js'], '', true);
    }
    
    // Localized scripts
    $doc->addScriptDeclaration('
        var themeSettings = {
            ajaxUrl: "' . sURL . 'ajax/",
            nonce: "' . wp_create_nonce('theme_ajax') . '",
            colorScheme: "' . $this->getSetting('color_scheme') . '"
        };
    ');
    
    return $this;
}
```

### Dynamic CSS

Create a `style.php` file for dynamic CSS generation:

```php
<?php
// style.php - Dynamic CSS based on theme settings
header('Content-Type: text/css');

$theme = \Pramnos\Theme\Theme::getTheme();
$primaryColor = $theme->getSetting('primary_color') ?: '#007cba';
$secondaryColor = $theme->getSetting('secondary_color') ?: '#005a87';
$fontFamily = $theme->getSetting('font_family') ?: 'Arial, sans-serif';
?>

:root {
    --primary-color: <?php echo $primaryColor; ?>;
    --secondary-color: <?php echo $secondaryColor; ?>;
    --font-family: <?php echo $fontFamily; ?>;
}

body {
    font-family: var(--font-family);
    color: var(--primary-color);
}

.button {
    background-color: var(--primary-color);
    border-color: var(--secondary-color);
}

.button:hover {
    background-color: var(--secondary-color);
}

<?php if ($theme->getSetting('enable_shadows')) : ?>
.card {
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
<?php endif; ?>

<?php if ($theme->getSetting('layout_width')) : ?>
.container {
    max-width: <?php echo $theme->getSetting('layout_width'); ?>px;
}
<?php endif; ?>
```

## Theme Development

### Creating a New Theme

1. **Create theme directory structure:**

```bash
mkdir themes/my-new-theme
cd themes/my-new-theme
```

2. **Create basic files:**

```php
// theme.html.php - Main template
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $doc->title; ?></title>
    <?php echo $doc->header; ?>
</head>
<body <?php body_class(); ?>>
    <div class="site-wrapper">
        <header class="site-header">
            <?php get_header(); ?>
        </header>
        
        <main class="site-main">
            <?php echo '[MODULE]'; ?>
        </main>
        
        <footer class="site-footer">
            <?php get_footer(); ?>
        </footer>
    </div>
</body>
</html>
```

```css
/* style.css - Main stylesheet */
/*
Theme Name: My New Theme
Description: A custom theme for Pramnos Framework
Author: Your Name
Version: 1.0
*/

/* Reset and base styles */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Arial, sans-serif;
    line-height: 1.6;
    color: #333;
}

/* Layout */
.site-wrapper {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

.site-main {
    flex: 1;
    padding: 20px;
}

/* Header */
.site-header {
    background: #f8f9fa;
    padding: 20px;
    border-bottom: 1px solid #e9ecef;
}

/* Footer */
.site-footer {
    background: #343a40;
    color: white;
    padding: 20px;
    text-align: center;
}
```

3. **Create theme class:**

```php
// functions.php or in theme directory
class MyNewTheme extends \Pramnos\Theme\Theme
{
    public function init()
    {
        // Theme setup
        $this->title = 'My New Theme';
        $this->author = 'Your Name';
        $this->info = 'A custom theme for Pramnos Framework';
        
        // Register menus
        $this->addMenuArea('primary', 'Primary Menu');
        $this->addMenuArea('footer', 'Footer Menu');
        
        // Register sidebars
        $this->addSidebar('sidebar-main', 'Main Sidebar');
        $this->addSidebar('footer-1', 'Footer Widget 1');
        
        // Add theme settings
        $this->addSetting('logo', 'Site Logo', 'image');
        $this->addSetting('primary_color', 'Primary Color', 'color');
        $this->addSetting('show_sidebar', 'Show Sidebar', 'checkbox');
        
        return $this;
    }
    
    public function displayInit()
    {
        parent::displayInit();
        
        $doc = \Pramnos\Framework\Factory::getDocument();
        
        // Enqueue theme assets
        $doc->enqueueScript('theme-main', $this->getThemeUrl() . '/js/main.js', ['jquery']);
        
        return $this;
    }
}
```

### Theme Testing

```php
// Create test data and scenarios
class ThemeTest
{
    public function testThemeLoading()
    {
        $theme = \Pramnos\Theme\Theme::getTheme('my-new-theme');
        assert($theme instanceof \Pramnos\Theme\Theme);
        assert($theme->theme === 'my-new-theme');
    }
    
    public function testThemeSettings()
    {
        $theme = \Pramnos\Theme\Theme::getTheme('my-new-theme');
        
        // Test setting values
        $theme->setSetting('primary_color', '#ff0000');
        assert($theme->getSetting('primary_color') === '#ff0000');
    }
    
    public function testWidgetAreas()
    {
        $theme = \Pramnos\Theme\Theme::getTheme('my-new-theme');
        $widgetAreas = $theme->getWidgetAreas();
        
        assert(isset($widgetAreas['sidebar-main']));
        assert(isset($widgetAreas['footer-1']));
    }
}
```

## Advanced Features

### Custom Post Types Integration

```php
// Support for custom post types
class BlogTheme extends \Pramnos\Theme\Theme
{
    public function init()
    {
        // Define templates for custom post types
        $this->elements['product'] = 'single-product.html.php';
        $this->elements['event'] = 'single-event.html.php';
        $this->elements['portfolio'] = 'archive-portfolio.html.php';
        
        return $this;
    }
    
    public function loadtheme()
    {
        // Custom template selection logic
        $contentType = $this->getContentType();
        $postType = $this->getCurrentPostType();
        
        if ($postType && isset($this->elements[$postType])) {
            $this->_contentType = $postType;
        }
        
        parent::loadtheme();
    }
}
```

### Theme Child Support

```php
// Support for child themes
class ChildTheme extends \Pramnos\Theme\Theme
{
    protected $parentTheme = 'parent-theme-name';
    
    public function loadtheme()
    {
        // Load parent theme first
        if ($this->parentTheme) {
            $parentPath = $this->path . DS . $this->parentTheme;
            if (file_exists($parentPath . DS . 'theme.html.php')) {
                // Inherit parent elements
                $parent = new Theme($this->parentTheme, $this->path);
                $this->elements = array_merge($parent->elements, $this->elements);
            }
        }
        
        parent::loadtheme();
    }
}
```

### AJAX Theme Integration

```php
// AJAX support in themes
public function displayInit()
{
    $doc = \Pramnos\Framework\Factory::getDocument();
    
    // Add AJAX support
    $doc->addScriptDeclaration('
        var ajaxSettings = {
            url: "' . sURL . 'ajax/theme",
            nonce: "' . wp_create_nonce('theme_ajax') . '"
        };
    ');
    
    return $this;
}

// AJAX handler
public function handleAjax($action, $data)
{
    switch ($action) {
        case 'load_more_posts':
            return $this->loadMorePosts($data);
        case 'update_theme_setting':
            return $this->updateThemeSetting($data);
        default:
            return ['error' => 'Invalid action'];
    }
}
```

## Best Practices

### Theme Performance

```php
// Optimize theme performance
public function displayInit()
{
    $doc = \Pramnos\Framework\Factory::getDocument();
    
    // Minify and combine assets
    if (ENVIRONMENT === 'production') {
        $doc->enqueueStyle('theme-combined', $this->getThemeUrl() . '/css/combined.min.css');
        $doc->enqueueScript('theme-combined', $this->getThemeUrl() . '/js/combined.min.js');
    } else {
        // Development assets
        $doc->enqueueStyle('theme-style', $this->getThemeUrl() . '/css/style.css');
        $doc->enqueueScript('theme-script', $this->getThemeUrl() . '/js/script.js');
    }
    
    return $this;
}
```

### Responsive Design

```css
/* Mobile-first responsive design */
.container {
    width: 100%;
    padding: 0 15px;
}

@media (min-width: 576px) {
    .container {
        max-width: 540px;
        margin: 0 auto;
    }
}

@media (min-width: 768px) {
    .container {
        max-width: 720px;
    }
}

@media (min-width: 992px) {
    .container {
        max-width: 960px;
    }
}

@media (min-width: 1200px) {
    .container {
        max-width: 1140px;
    }
}
```

### Accessibility

```php
// Accessibility best practices in themes
// In template files:

// Proper heading hierarchy
<h1><?php echo $doc->title; ?></h1>
<h2>Section Title</h2>
<h3>Subsection Title</h3>

// Alt text for images
<img src="<?php echo $imageUrl; ?>" alt="<?php echo $imageAlt; ?>">

// Skip links
<a class="skip-link screen-reader-text" href="#main">Skip to main content</a>

// ARIA labels
<nav aria-label="Primary Navigation">
    <?php wp_nav_menu(['theme_location' => 'primary']); ?>
</nav>

// Form labels
<label for="search">Search:</label>
<input type="search" id="search" name="search">
```

### Security

```php
// Security best practices
// Sanitize output
echo htmlspecialchars($userInput);
echo esc_attr($attribute);
echo esc_url($url);

// Validate input
$color = $this->getSetting('primary_color');
if (!preg_match('/^#[a-f0-9]{6}$/i', $color)) {
    $color = '#007cba'; // Default color
}

// Nonce verification for AJAX
if (!wp_verify_nonce($_POST['nonce'], 'theme_ajax')) {
    die('Security check failed');
}

// Capability checks
if (!current_user_can('manage_options')) {
    die('Insufficient permissions');
}
```

---

## Related Documentation

- **[Framework Guide](Pramnos_Framework_Guide.md)** - MVC architecture and view system
- **[Document & Output Guide](Pramnos_Document_Output_Guide.md)** - Document system integration
- **[Authentication Guide](Pramnos_Authentication_Guide.md)** - User-based theme features
- **[Cache System Guide](Pramnos_Cache_Guide.md)** - Caching theme assets and output

---

The Pramnos Theme System provides a comprehensive foundation for building flexible, maintainable, and feature-rich themes. With support for widgets, menus, settings, and complete customization, you can create themes that meet any design requirement while maintaining clean separation between presentation and logic.
