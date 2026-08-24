---
date: 2026-08-25
categories: [Changelog]
---

# Two answers to "where do the views live?"

Two filings from a consuming application, both about the view layer assuming a
layout instead of asking for one.

<!-- more -->

## Added

- **`View::$tplSubdirectory`** — empty by default. Set it to `'tpl'` on a base view
  and templates resolve one directory down.

## Fixed

- **`Controller::getView()` reads `APPS_PATH`** when it is defined, falling back to
  `ROOT/<INCLUDES>` exactly as before.

## The subdirectory is a declaration, not a search

The reporting application has **820 templates in 131 `views/<module>/tpl/`
directories** — the legacy `pramnos_application_view` built that path, the modern
`View` does not, and that single difference is why its 135 migrated controllers
still construct legacy view objects. 135 controllers moved; 0 views.

The obvious fix is to try `tpl/` whenever the flat path misses. Checking the actual
convention first is what changed the answer: the reference application has **no
`tpl/` directories at all**, and neither do any of the three scaffolded themes. Flat
is not an omission, it is the convention — and a fallback search would put a
`file_exists()` on every render of every project, for ever, to serve a layout none of
them use. It would also establish a second convention by accident, which the
framework would then owe support for.

So the application that has the directory says so, once, and pays for it alone.

## `INCLUDES` was not the problem the filing thought it was

The report said the framework hardcodes `ROOT/includes/<app>`. It does not —
`INCLUDES` has always defaulted to `src`:

```php
if (!defined('INCLUDES')) {
    define('INCLUDES', 'src');
}
```

The `'includes'` came from the reporting application's own legacy bootstrap, which
defines the constant before the framework can, and the `!defined()` guard honours it.

The other half of the filing stands, though, and is the real one: the fallback was
built from `INCLUDES`, which describes where the **code** lives, where the legacy
controller used `APPS_PATH`, which describes where the **applications** live.
`Translator\StringFinder` already reads `APPS_PATH`; this was the one place in the
framework answering the same question differently.

In a stock layout the two are the same directory, which is exactly why nobody
noticed. When they are not, the fallback searches a path that does not exist — and
finds nothing, which looks identical to a view that is genuinely absent.

## Documentation

- [Framework Guide](../../Pramnos_Framework_Guide.md) — two new sections under Views
  and Templates: templates in a subdirectory, and where the framework looks for an
  application's views.
