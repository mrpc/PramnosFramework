---
date: 2026-08-14
categories:
  - Changelog
  - Added
  - Fixed
tags:
  - theme
---

# The widget area that rendered nothing

Correcting the theme guide turned up two things that were not documentation problems.
`renderWidgetArea()` returned an empty string **always** — the render loop was commented out.
And `displayMenu()` called a class from a deprecated CMS that the framework does not ship, so
it **fatalled** in every project without it.

Both extension points now exist, and an application that uses neither pays nothing for them.

<!-- more -->

## What was actually there

```php
public function renderWidgetArea($widgetArea, $args = array())
{
    // …
    foreach ($widgets as $widgetData) {
        // $widget = pramnos_theme_widget::getWidget(array_merge($args, $widgetData));
        // if (method_exists($widget, 'display')) {
        //    $return .= $widget->display($widgetData);
        // }
    }
    return $return;
}
```

A theme could register widget areas, store widgets in them from an admin screen, ask for an
area — and get an empty string. The class the loop needed was never in the framework.

`displayMenu()` was worse, because it was not silent:

```php
$menu = new pramnoscms_menu($this->getMenu($args['theme_location']));
```

Unqualified inside `namespace Pramnos\Theme`, that name resolves to *that* namespace, so it
could not be satisfied even by a project carrying the global class. Asking a theme for a menu
was a fatal error. **The framework's own test had to `eval()` a fake class to test the method
at all**, which is the kind of thing a test suite does instead of telling you.

## Widgets

```php
use Pramnos\Theme\Widget;

class LatestPosts extends Widget
{
    protected function content(array $args): string
    {
        return '<ul>…</ul>';
    }
}

$theme->widgets()->register('latest-posts', LatestPosts::class);
```

`Widget` handles the wrapping every theme passes — `before_widget`, `before_title` — and leaves
you the body. `WidgetInterface` is there for a widget that wants to own its wrapper.
`WidgetRegistry` maps a stored record's `type` back to a class, with a factory form for widgets
that need their settings at construction.

Two decisions worth stating:

**A widget with nothing to say renders nothing at all** — no empty wrapper, no stray heading. An
empty `<h3></h3>` is worse than no heading: it appears in the document outline and announces a
section with no name. And it means a theme can test whether an area produced anything rather
than asking each widget in advance.

**A stored record whose type is no longer registered is skipped.** Widget records outlive the
code that renders them — a plugin is removed, a type is renamed — and a sidebar must not take
the page down over one stale entry. Those types are collected in
`$theme->widgets()->unresolved()`, so a sidebar quietly missing one of its four widgets is
findable instead of a puzzle.

## Menus

The framework has no menu storage, and inventing one would have been the wrong answer: every
project that has menus has its own table. So a theme says where items come from.

```php
$theme->setMenuItemsProvider(fn ($menuId, $location) => Menu::load($menuId)?->toTree());
```

`MenuWalker` renders them. It is a pure function of its inputs — items in, string out, no
database, no application — so it can be unit-tested, and a theme can subclass it to change one
method rather than reimplement a nav menu.

It accepts alternative key spellings (`name`/`label` for `title`, `link`/`href` for `url`,
`submenu`/`items` for `children`) because menu rows come from tables that predate it, and
renaming a column is not a reasonable price for rendering a list. An item with **no URL renders
as a `<span>`**, not an anchor with no `href`. The legacy `[URL]`, `[TITLE]`, `[ACTIVE]` and
`[HASSUB]` markers in the documented `displayMenu()` defaults are honoured, so a theme passing
those defaults gets sensible markup.

**With no provider, `displayMenu()` returns an empty string** rather than failing. A theme
asking for a menu that has no source should render a page without a menu.

## What this costs an application that uses neither

The constraint this was built to. Asserted as behaviour, not intention:

- the stored-widgets setting is read on **first use**, not when a theme is constructed. It used
  to be read in the constructor, so every page of every application paid for it;
- the registry and the walker are built on first use — a project that registers no widgets and
  displays no menu never constructs either;
- `renderWidgetArea()` on an area with no stored widgets returns after **one array lookup**,
  without touching the registry. There is a test that reads the theme's internal registry
  property afterwards and asserts it is still null;
- no table, no migration. Widget records live in the theme's existing settings.

### The bug that made this worth a test

Moving the settings read out of the constructor introduced one, and a characterization test
caught it before it shipped. `addWidget()` serialises the **whole** collection back to the
setting:

```php
$this->widgets[$widget['widgetId']] = $widget;
Settings::setSetting('theme_' . $this->theme . '_widgets', serialize($this->widgets));
```

Adding to a collection that had not been loaded yet would persist only the new widget and
**silently discard every widget already stored**. Nothing would report it — the widgets would
simply be gone the next time an area rendered. Mutators now load first, and there is a test that
adds a widget to a theme with one already in its settings and asserts both survive.

The characterization test that caught it was itself order-dependent: it asserted a *count* of
zero after a rejected add, which was only ever true because a theme built without its
constructor never loaded anything. Its helper now marks the collection loaded, which is the
state a constructed theme is in — so the class stops depending on what a sibling test persisted.

## And the deprecated CMS is gone

Everything referring to `pramnoscms` and its siblings has been removed rather than
accommodated, which surfaced three more live problems:

- **`Theme::getThemes()` could never have run.** It called `pramnos_theme::getTheme()`, which
  inside its own namespace resolves to nothing. It is `self::getTheme()`.
- **Every user without an avatar got a 404.** `avatarurl` fell back to
  `media/img/pramnoscms/noavatar.jpg` — a path into a deprecated CMS's assets, for a file the
  framework has never shipped. It is now the `defaultAvatarUrl` setting, empty by default, so a
  template can render initials or an inline SVG instead of an image the framework cannot supply.
- **`l()` was declared twice and sometimes not at all.** It chose between the framework's
  Factory and `pramnos_factory`, and was skipped entirely if a `pramnos_theme` class existed —
  leaving the helper undefined for the one kind of application the guard was written for.

Also removed: `tests/stubs/pramnos_factory_stub.php`, loaded unconditionally on every test run
to stand in for a delegation `Auth` stopped doing (there is already a test asserting the source
no longer mentions it), and two dead commented-out `pramnos_html_form` lines.

The names still appear in this repository, but only in the comments explaining what was taken
out.

## Added

- `Pramnos\Theme\WidgetInterface`, `Pramnos\Theme\Widget`, `Pramnos\Theme\WidgetRegistry` —
  widgets that actually render.
- `Pramnos\Theme\MenuWalker`, `Theme::setMenuWalker()`, `Theme::setMenuItemsProvider()`.
- `Theme::widgets()` — the type registry, built on first use.

## Fixed

- `renderWidgetArea()` renders the widgets in an area instead of returning an empty string.
- `displayMenu()` no longer fatals in a project without a deprecated CMS class.
- `Theme::getThemes()` no longer calls a class name that cannot resolve.
- A user with no avatar gets the configured default, or nothing, rather than a broken image.
- `l()` is declared once, over the framework's own Factory.
- [The Theme guide](../../Pramnos_Theme_Guide.md) describes all of this, including what it
  costs a theme that uses none of it.
