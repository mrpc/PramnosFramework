---
date: 2026-08-26
categories: [Changelog]
---

# One set of controllers, two addresses

Every project that has wanted its admin screens under `/admin` has written the same
thing: a second set of controllers, or a prefix check inside each one, or a rewrite rule
per screen. None of it is necessary. The controllers are already the right ones — all that
separates `/admin/Users` from `/Users` is the prefix.

<!-- more -->

## Added

- **An administration area under a URL prefix**, configured once:

  ```php
  // app/app.php
  'admin' => [
      'prefix'       => 'admin',
      'theme'        => 'admin',
      'min_usertype' => 80,
  ],
  ```

  The prefix is removed before anything splits the path into controller and action, so
  routing, actions, `_option` and the key/value tail all behave exactly as they do without
  it. There is no second code path to keep in step, and no controller knows which address
  it is being served at.

  Inside the area the configured theme replaces the site theme, and the usertype floor is
  enforced before a controller is even resolved — so a screen inside the area is never
  constructed for somebody who may not be there. The floor does not replace each
  controller's own check; those still run, and several are stricter. It is what stops the
  *area* being browsable, so a screen that forgot its own check is not the only thing
  between an ordinary account and the dashboard.

  The two refusals differ deliberately. A guest is sent to sign in carrying the address
  they asked for. A signed-in user below the floor is sent to the site root instead:
  showing them a login form they are already past reads as a broken session, and they
  retype their password rather than understanding they lack the privilege.

- **`Pramnos\Http\AdminArea`** — `isActive()`, `prefix()`, `url()`. Admin `NavItem`s now
  build their URLs through it, so they lead into the area from anywhere, including the
  public site header that shows the same section. With no area configured `url()` returns
  a plain application URL, which is what keeps the nav registration free of conditionals.

Two details are load-bearing enough to state.

**The prefix must match a whole segment.** `/administration` is not inside an area mounted
at `admin`. A `str_starts_with` check would put it there, restyle it, and hand routing a
mangled path — so the segment test has its own data-provider case per near-miss.

**`REQUEST_URI` is never rewritten.** Everything that sends somebody back where they were
reads it: a login redirect's `return=`, session tracking, a log line. A stripped one would
bring a refused administrator back to the public copy of the page they were trying to
reach.

## Documentation

- [Routing](../../Pramnos_Routing_Guide.md) gains "An administration area under a prefix" —
  the configuration, what changes inside the area, why the two refusals differ, and the one
  ordering constraint (detection happens in `Application::__construct()`, so a front
  controller that builds a `Request` first will route the prefix as a controller name).
