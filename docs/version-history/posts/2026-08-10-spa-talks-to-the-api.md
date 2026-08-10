---
date: 2026-08-10
categories:
  - Changelog
  - Fixed
  - Features
tags:
  - spa
  - api
  - auth
  - scaffolding
---

# The scaffolded SPA now actually talks to the API

A fresh SPA greeted its author with **"API answered 403"**. It called an endpoint
that was never generated, without the header the API layer requires. Both halves
are now scaffolded — a service, a controller, the route, and a client that speaks
the framework's real contract — plus a working sign-in flow when the auth feature
is on.

<!-- more -->

## Two things were missing

**The endpoint did not exist.** The demo screen probed `/health`, which nothing
ever generated. Every unknown route in the API layer answers 403, so the very
first thing a new project showed was a failure.

**The request was unauthenticated.** The framework's API layer rejects any request
without an `apiKey` header (`"API key is missing"`, HTTP 403) — so even a correct
path would have failed. The client also sent `Authorization: Bearer`, which is not
the header the framework reads (`accessToken`).

## The endpoint, in the shape the style prescribes

`init` now generates the whole vertical slice the front end talks to:

```
src/Services/StatusService.php     the behaviour (and its data access)
src/Api/Controllers/Status.php     thin: asks the service, shapes the response
src/Api/routes.php                 GET /status, public
```

So a Services + API + SPA project starts with one worked example of its own
layering, and the first screen shows real data:

```json
{"application":"myapp","status":"ok","database":"up","time":"2026-08-10T00:58:49+00:00"}
```

## The client speaks the framework's contract

The shell derives **this application's own API key** — the md5 of the site URL that
`Api::checkApiKey()` accepts — and publishes it, with the API prefix and the
enabled features, as `window.__PRAMNOS__`:

```php
'apiKey' => md5(str_replace('/api/', '/', getUrl())),
```

Nothing is hard-coded per environment, and the client attaches it to every call,
along with the framework's `accessToken` header when a token is held, and cookies
so that a user who signed in through the server-rendered pages is already
authenticated in the SPA.

## Sign-in, when the auth feature is on

`login()` / `logout()` / `currentUser()` wrap the endpoints that already existed
(`/account/login`, `/account/logout`, `/me`), storing the issued token — in
`localStorage`, with an in-memory fallback for private mode and tests. The Svelte
screen ships a real form; the vanilla stack the same flow without a framework.
Logging out clears the token even when the server refuses a stale one, and
`currentUser()` answers `null` for an anonymous visitor rather than throwing.

Without the auth feature none of this is emitted — a login form posting to
endpoints that were never scaffolded is worse than no form.

## `Authorization: Bearer` is accepted too

Independently of the SPA: the API used to read **only** the `accessToken` header,
so curl, Postman, RapiDoc's *Authorize* button and any OpenAPI-generated SDK — all
of which send `Authorization: Bearer …` — came through as anonymous, which reads
like a broken token rather than a header-name mismatch.

`Request::accessToken()` now resolves, in order: `accessToken`,
`Authorization: Bearer`, `REDIRECT_HTTP_AUTHORIZATION` (Apache with CGI/FastCGI
rewrites it). The framework header still wins when both are present, so nothing
changes for existing clients. `ApiAuthMiddleware` and `ApiAccount::logout()` use it.

## The generated docs know about it too

`CLAUDE.md` used to describe an MVC project no matter what was scaffolded — an
assistant reading it would add a server-rendered view to a SPA, edit generated
build output, or call the API without the header it requires. It now states the
application style up front and, for a SPA, carries a front-end chapter: where the
sources are, that `www/assets/spa/` is generated and off-limits, the
`./dockernpm` / `./testjs` loop, why the Vite port must not be opened, the API
contract, and how to add an endpoint through service → controller → route.

A **README.md** is written too — the scaffold used to explain itself only to an
AI assistant and not to the person cloning the repository. It carries the style,
how to start the project, the URL it answers on, the everyday commands, the
front-end workflow when there is one, and the API contract.

## Tests

`InitSpaScaffoldingTest` — the status slice across all three layers, the runtime
config the shell injects, the client's headers, the sign-in flow with auth on and
its absence with auth off. `RequestAccessTokenTest` — the header precedence, a
case-insensitive scheme, the REDIRECT_ variant, empty values, and that a `Basic`
credential is never mistaken for a token. The generated front-end suites cover the
`apiKey` header, `accessToken`, login/logout/currentUser. Plus: CLAUDE.md gains
the front-end chapter for a SPA and stays MVC-only without one, and the README
matches the stack it was generated for (npm instructions only where there is a
toolchain).
