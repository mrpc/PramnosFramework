---
date: 2026-08-27
categories: [Changelog]
---

# A view directory you half-own rendered a page shell

The scaffolding fallback worked on view *directories*. So an application that owned one
template in a group and not the others got an empty page for the others.

<!-- more -->

## Fixed

**`View::getTpl()` now falls back to the bundled scaffolding per template.**

`Controller::getView()` has always fallen back to the bundled theme — but it does so when it
cannot find the view *directory*, and the template lookup had no fallback at all. So the unit
of inheritance was the whole directory:

```
src/Views/services/logs.html.php      ← the application owns this one
src/Views/services/services.html.php  ← absent
```

`getView('services')` matched the directory, `getTpl('services')` did not find the file, and
the services list came back as a page shell. Status 200, chrome, no panel, and one line in a
log nobody reads. Any project that customised one screen out of a group was in this state for
the rest of the group.

Which is the shape a project actually wants inverted: **keep the screens you rewrote, inherit
the others** — and get their fixes with the next framework update rather than copying them
again. The lookup order is unchanged otherwise:

1. the theme's `views/<view>/<tpl>.html.php` override
2. the application's `src/Views/<view>/<tpl>.html.php`
3. the bundled `scaffolding/themes/<scaffold_theme>/views/<view>/<tpl>.html.php`

Silent when there is nothing to find, so the existing "cannot find view template" log — the
one that says where the lookup came from — stays in charge of that case.

Verified against a real application: 39 of its admin views deleted, its suite of 248 tests
still green, every screen served from the bundled theme.

## Documentation

- `Pramnos_Theme_Guide.md` — a new *Inheriting a bundled view, one template at a time*.
