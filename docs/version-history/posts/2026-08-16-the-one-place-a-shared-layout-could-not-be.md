---
date: 2026-08-16
categories:
  - Changelog
  - Fixed
  - Documentation
tags:
  - view
  - mvc
---

# The one place a shared layout could not be

`View::resolveTemplatePath()` searched the view's own directory, `ROOT/views` and a
theme override. It never searched `src/Views` — the directory holding the view
directories, and the obvious place to put a layout shared between them.

<!-- more -->

## Where it looked

For a view at `src/Views/Home`, `$this->layout('layouts/main')` looked in:

- `src/Views/Home/layouts/main.html.php`
- `ROOT/views/layouts/main.html.php`
- the theme's `views/layouts/main.html.php`

Not `src/Views/layouts/main.html.php`, which is where a developer with four view
directories and one layout puts it. `src/Views` is now searched, **appended last**, so
nothing that resolved before resolves differently — a per-view override keeps its
priority over a shared file that has been sitting there all along.

## The half that matters more

When a declared layout does not resolve, `getTpl()` renders the child **alone**. No
exception, no log, and a `200`. The page comes back with no `<head>`, no navigation and
no stylesheet link, which presents as *"the CSS did not load"* — so the next hour goes
into asset paths and caching headers.

It is logged now:

```
Layout not found: layouts/absent (searched from /srv/app/src/Views/Home). The view was rendered without it.
```

A framework cannot know every place somebody will put a file. It can refuse to be
silent about not finding one. Adding the directory fixes the case we know about;
logging fixes the ones we do not.

## The feature had no guide page

`layout()`, `section()`, `endsection()`, `yield()` and `insert()` appeared in exactly
one place in the documentation: `1.2-new-features.md`, which is **frozen**. So the
resolution order could not be corrected there even if somebody had noticed it, and a
reader looking for how layouts work in the current framework had nowhere to land.

The Framework guide's *Views and Templates* section now carries them, with the search
order as a numbered list and the missing-layout symptom named — because *"the page
looks unstyled"* is what somebody will actually be searching for.

This is the third feature this week found documented only in a page that cannot be
updated. The habit that catches it is cheap: when fixing something, grep the guides for
the method name before writing the fix, not after.

## Fixed

- `View::resolveTemplatePath()` searches `dirname($this->path)` — `src/Views` for a
  standard layout — after the existing locations.
- A declared layout that cannot be resolved is logged instead of silently dropping the
  page's entire structure.

## Documentation

- [Framework guide](../../Pramnos_Framework_Guide.md) — *Layouts and partials*: the
  five search locations in order, and what a missing layout looks like from the
  browser.
