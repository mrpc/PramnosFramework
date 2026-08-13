---
date: 2026-08-14
categories:
  - Changelog
  - Added
tags:
  - debug
  - api
  - middleware
---

# Debug data for an application that does not use the API layer

Reported by a project that routes `#[Route]` attributes to controllers returning
`Response::json()`, with no `src/Api` at all — a style the framework supports and the
SPA scaffolding assumes. The debug payload design is right, production is off by
construction, and `Server-Timing` comes free. Two things made it more work than it
should have been.

<!-- more -->

## Attaching the payload was private to `Api`

`Api::_attachDebugPayload()` and `Api::_sendServerTiming()` are `protected`, so an
application not built on that layer had to re-implement both — about thirty lines
that decode the body, refuse a top-level list, merge the key and set the header.
Every attribute-routed project would write the same file, and each one gets to
rediscover, from an empty panel, that a JSON **array** has nowhere to put a key.

`Pramnos\Debug\ApiDebugMiddleware` now ships. One line covers every routing style:

```php
$pipeline->add(new \Pramnos\Debug\ApiDebugMiddleware());
```

It handles both shapes a controller returns — a `Response` object and a bare string —
and returns the *same* instance when there was nothing to attach, so a later `===`
in the pipeline still means what it says. The rule about which bodies can carry the
key lives in `ApiDebugPayload::attachTo()` and nowhere else: a top-level array, a
plain string, HTML, or a body that already has a `_debug` key all come back
untouched. `Api` uses the same method rather than its own copy.

Inert in production: `ApiDebugPayload::isEnabled()` asks the toolbar whether any
collector is registered, and collectors are registered only in debug mode. One array
check per request.

## The provider only booted inside `Application::init()`

`bootServiceProviders()` was called from `init()`, so an application that
deliberately does not run the MVC boot — a console-safe bootstrap, for instance — got
**no collectors**, while looking fully configured. Listing `'debug'` in `app.php`'s
`features` was necessary and not sufficient, with nothing saying so. The symptom is
that everything looks right and no response ever carries a payload.

`Application::bootFeatureProviders()` is now public, so a partial boot can opt in:

```php
$app = \Pramnos\Application\Application::getInstance();
$app->bootFeatureProviders();   // registers the providers the features array lists
```

## `isDebugMode()` is public

An application could not ask "are we in development?" without re-implementing the
four-way check — environment variable, `DEVELOPMENT`, and two settings — which is
precisely how two definitions of "development" drift apart. It is one question with
one answer, so it is now askable.

## Documentation

- [Debugging Guide](../../Pramnos_Debugging_Guide.md) — the middleware, and what the
  features array does and does not do without `init()`.
