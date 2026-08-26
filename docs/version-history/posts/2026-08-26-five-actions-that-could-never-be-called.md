---
title: Five actions that could never be called
date: 2026-08-26
categories:
  - Routing
  - Bugfix
---

# Five actions that could never be called

Every button on the services screen was a fatal error. So was the clear-log link.
Not under some condition — always, since they were written.

<!-- more -->

## What was happening

`Controller::exec()` dispatches every action identically:

```php
fn() => $this->$action($args)
```

`$args` is the request's arguments array. The bundled controllers are written for
that: an action takes `mixed $id = null`, ignores it, and reads the URL segment
with `Request::staticGetOption()`.

Five did not:

```php
public function stop(string $name = ''): void
public function start(string $name = ''): void
public function restart(string $name = ''): void
public function logs(string $name = ''): mixed      // ServicesController
public function clearFile(string $file = '')        // LogController
```

PHP is handed an array where a `string` is declared, and throws. There is no input
that makes this work. The four `ServicesController` actions are every control on
the services screen — stop, start, restart, view logs — so that screen listed
services and could do nothing to any of them.

## Why it lasted

A fatal on click looks like a broken page, and a broken page on an admin screen
that nobody opens on a normal day looks like nothing at all. The screen itself
rendered fine, which is what anybody testing it would have checked.

It surfaced from a test that did nothing more than open every screen in an
application and check for a 500 — which is also how it became clear that 52 of
that project's 71 view templates had never been rendered by anything.

## The fix

The five actions now match the convention:

```php
public function logs(mixed $name = null): mixed
{
    $name = (string) \Pramnos\Http\Request::staticGetOption();
    // …
}
```

## The test worth having

Not five tests. A structural one that walks every bundled controller and asserts
that no public action declares a first parameter the dispatcher cannot fill:

```php
$type = $method->getParameters()[0]->getType();
$this->assertContains($type->getName(), ['mixed', 'array', 'iterable']);
```

Reading the declaration is enough, and it needs no fixtures — whereas routing to
every action of every controller would need one screen's worth of data each. The
declaration is the thing that makes an action callable, so it is the thing to
assert.

## Also: a template nothing rendered

Found on the same sweep. `scaffolding/themes/*/views/health/check.html.php` was
published into every project, and `Health::check()` returns `Response::json(...)` —
it never touches a view. So the file sat next to `health/health.html.php`, named
after an action, looking exactly like the thing to edit if you wanted to change
what `/health/check` returns. Editing it changed nothing, silently.

Removed from all three bundled themes. A project that already published it can
delete its copy.

## Documentation

- `Pramnos_Routing_Guide.md` — new "What a classic-MVC action must accept" section,
  with the right and wrong signature side by side.
