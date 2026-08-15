---
date: 2026-08-16
categories:
  - Changelog
  - Added
  - Documentation
tags:
  - validation
  - middleware
  - forms
---

# The redirect the guide promised

The Validation guide describes flash-and-redirect as something the framework does for
you. It does — inside `Application::exec()`, which an application routing with
`Router::dispatch()` never calls.

<!-- more -->

## Where the behaviour lives

`Application::exec()` catches `ValidationException`, writes `_validation_errors` and
`_old_input` into the session, and redirects to the referer. The form redraws itself
with the errors and the visitor's typing intact, and nothing in a controller has to
know about any of it.

That is the MVC request cycle. An application with a thin dispatcher and
`Router::dispatch()` — the layout the Application Styles guide recommends for a JSON
API — gets an **uncaught exception** where this page promises a redirect, and no
sentence anywhere says why.

Third time this week the same shape has turned up: a capability implemented once,
inside the kernel, and unreachable from the routing style the framework also
recommends. `ApiDebugMiddleware` was the first, the maintenance response the second.

```php
$router->addGlobalMiddleware(new ValidationRedirectMiddleware());
```

It catches **only** `ValidationException`. A middleware that swallowed everything would
turn a real fault into a redirect back to the form, which is the most confusing outcome
available: the visitor sees the page again with nothing wrong on it.

## And one bug not copied

`Application::exec()` redirects to `$_SERVER['HTTP_REFERER'] ?? URL`. When `URL` is
defined and **empty** — which it is under test, and can be in a misconfigured
install — that is a redirect to the empty string. A redirect to nowhere is a worse
outcome than the uncaught exception it replaced, so the middleware checks the constant
for content rather than existence and falls through to `/`.

Found by a test asserting the fallback goes *somewhere*, which is the kind of assertion
that looks like padding until it fails.

## Two session conventions that do not interoperate

Documented rather than changed, because both have users:

| Written by | Keys | Read by |
| --- | --- | --- |
| `Request::validate()`, `Application::exec()`, this middleware | `_validation_errors`, `_old_input` | `$this->errors` in a view; `Request::old()` |
| `FormRequest::failWith()` | `_form_errors`, `_form_old_input` | `FormRequest`'s own statics |

**A view using `$this->errors` sees nothing after a `FormRequest` failure.** The form
redraws with no errors on it and no indication why — which reads as "validation is
broken" rather than as "two conventions", and sends whoever hits it into the validator.

Neither guide said so. One of them does now.

## Added

- `Pramnos\Http\Middleware\ValidationRedirectMiddleware` — the `Application::exec()`
  validation flash, available to a router-dispatched application in one line, with an
  optional fixed redirect target.

## Documentation

- [Validation guide](../../Pramnos_Validation_System_Guide.md) — where the redirect is
  implemented and what that means if you do not call `exec()`, plus the two session
  conventions side by side.
