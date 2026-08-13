---
date: 2026-08-10
categories:
  - Changelog
  - Features
tags:
  - spa
  - admin
  - api
  - scaffolding
---

# A SPA project gets an administration screen

The MVC scaffold has always generated whole admin areas — users, settings, logs.
A SPA project got none of them, so "the SPA should have the application's
functions" meant hand-writing every one. It now starts with the same three
things an administrator opens first.

<!-- more -->

## What is scaffolded

With the `auth` feature on a Svelte SPA, `init` generates
`frontend/screens/Admin.svelte` and registers it, so it appears in the navigation
like any `create:crud` screen. It has three tabs:

- **Overview** — user and session counts, PHP version;
- **Users** — the user list with server-side paging and search;
- **Logs** — one page of a log file.

The endpoints are **framework-side** (`Pramnos\Auth\Controllers\ApiAdmin`), so
the only generated file is the screen: `frontend/screens/Admin.svelte`. The routes
instantiate the framework controller directly — no wrapper is generated, so
overriding one means adding your own route ahead of it.

*(Corrected 2026-08-14: this post originally described a generated
`src/Api/Controllers/Admin.php` wrapper. No such file has ever been written.)*

## Read-only, deliberately

Creating and deactivating users has consequences — sessions, tokens, GDPR
records — that the existing server-rendered flows already handle correctly.
Duplicating them behind a thinner API is how two implementations drift apart
until one of them is wrong. Listing, searching and inspecting is what an admin
screen needs most, and it is safe to serve twice.

The user list is served by the User model's own `_getApiList()` pipeline, so
paging, sorting and searching behave exactly as they do everywhere else rather
than being re-implemented for one screen.

The log endpoint takes a **name**, validated against the viewer's whitelist, not
a path: a log endpoint that accepts a path is a file-disclosure endpoint. An
unknown name answers 404 instead of reading whatever was asked for.

## Authorisation

Every action goes through `ApiCrudController::guard()`, so each is authenticated
and permission-checked separately — a project can grant `admin.users` without
granting `admin.logs`. The screen distinguishes the answers too: a 403 reads
"This account does not have permission for this section.", not "could not load",
because on an admin screen those are different problems with different fixes.

## The vanilla stacks

They get the endpoints — those are framework-side — but no generated screen.
Hand-written DOM for three tabs is not a starting point anybody wants, and
`create:crud` already covers the screens people actually build.

## Tests

`InitSpaScaffoldingTest` — the screen is generated, registered and calls the
three endpoints, the routes and the wrapper controller exist, the 403 case has
its own message, and nothing at all is generated without the auth feature.
Verified live: every admin endpoint answers `401 not_authenticated` to an
unauthenticated caller, and the built bundle includes the screen.
