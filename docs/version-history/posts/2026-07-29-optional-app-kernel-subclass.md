---
date: 2026-07-29
categories:
  - Changelog
  - Features
tags:
  - application
  - bootstrap
  - scaffolding
---

# An app-specific Application subclass is now optional

`Application::getInstance()` falls back to the base kernel when an app declares a
namespace but ships no `<Namespace>\Application` subclass — so an app that needs no
custom kernel behaviour no longer has to carry an empty one.

<!-- more -->

## Before

`getInstance()` built `\<namespace>\Application` from `app.php`'s `namespace` and
instantiated it only if the class existed — with **no fallback**. An app that set
`namespace` (as every real app does) therefore *had* to provide a
`<Namespace>\Application` class, even a one-line empty subclass, or `getInstance()`
returned nothing (and a `namespace`-less config resolved to `\Pramnos\Application`,
a namespace rather than a class — also nothing). The empty subclass was pure
boilerplate the framework forced on every app.

## After

Resolution is extracted into a testable
`Application::resolveApplicationClass(array $config): string`:

- `<Namespace>\Application` exists → use it (**unchanged** for apps with a custom kernel).
- namespace set but no such class → the base `Pramnos\Application\Application`.
- no namespace → the base kernel.

So an app can delete its empty `Application` subclass and keep working; the base
kernel is instantiated and `getInstance()` behaves exactly as before otherwise.
Only previously-broken paths change (missing class / absent namespace now resolve
instead of returning nothing) — additive and BC.

## Consequential fix — `service:policy-engine` guard

Because `getInstance()` no longer returns `null` for an app without a kernel
subclass, a command that used `!$app instanceof Application` to mean "no usable
application" would proceed into a kernel with no database. `PolicyEngine::execute()`
now guards on a **usable database** (`$app->database instanceof Database`), so it
still fails gracefully ("No application instance available", `Command::FAILURE`)
instead of crashing — a more accurate check regardless of the fallback.

## Tests

`tests/Unit/Pramnos/Application/ApplicationClassResolutionTest.php` — no-namespace,
empty-namespace and missing-class all fall back to the base kernel; an app that
ships its own kernel subclass is still honoured (via a fixture subclass).
