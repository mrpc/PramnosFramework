---
date: 2026-07-27
categories:
  - Changelog
  - Routing
tags:
  - router
  - controllers
  - attributes
  - bugfix
---

# Attribute-routed controller actions now dispatch

`Route::execute()` now runs `[Controller::class, 'method']` actions — the shape
`RouteDiscovery` builds for `#[Route(...)]` attributes — resolving the controller
through the IoC container and injecting matched URI parameters.

<!-- more -->

## Fixed

Previously `Route::execute()` only handled closures: it called
`new \ReflectionFunction($this->action)` and guarded with `is_callable()`. For an
array action that meant:

- a **non-static** controller method → `is_callable(['Ctrl', 'index'])` is `false`,
  so the route did **nothing** (a silent no-op — no controller, no response);
- a **static** method → `is_callable` is `true`, but `new \ReflectionFunction([...])`
  threw `TypeError: must be of type Closure|string, array given`.

So attribute-routed controller classes never dispatched, and the `$container`
passed to `execute()` was unused. `RouteDiscovery` produces exactly these array
actions, so the two halves of the router were inconsistent.

`execute()` now:

- reflects the **method** (`ReflectionMethod`) for array actions,
- resolves the controller via the container (`make()`/`get()`, falling back to a
  plain `new`), so constructor dependencies are autowired,
- invokes it — static or on the resolved instance — with URI parameters passed by
  name (unchanged injection behaviour).

Closures, plain function names, `[$object, 'method']` and invokable objects keep
working exactly as before. Backward compatible.

## Tests

`RouteTest` gains coverage for a non-static controller action (the regression) and
a static one; the full routing suite (unit + characterization) stays green.
