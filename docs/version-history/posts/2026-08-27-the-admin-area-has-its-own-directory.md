---
date: 2026-08-27
categories: [Changelog]
---

# The administration area has its own directory

Every admin screen answered on two addresses. `/admin/Users` inside the area, and
`/Users` outside it — the same controller, in the public theme, with no sidebar and
outside the area's usertype floor. It was reported as a link that went to the wrong
place; the shape of it was a second front door to every administration page.

<!-- more -->

## Added

**`src/Admin/Controllers/` and `src/Admin/Views/`**, the counterpart of `src/Api/`.
Inside the area the framework resolves `<Ns>\Admin\Controllers\Users` and
`src/Admin/Views/users/` first, and **falls through** to the site's own — so an area
holds the screens that belong to it rather than a copy of the application. A shared
`Home`, a shared partial, and a project with no `src/Admin` at all behave exactly as
before.

Outside the area that directory is not in scope, which is the half that closes the door:
`/Users` finds nothing. `Application::$area` carries it, is empty for every project that
has not moved anything, and is re-derived per request like the theme — a first request to
`/admin` must not leave the area's controllers in scope for the public page after it.

`pramnos init` now writes the twelve admin screens there — Users, Settings, Dashboard,
Logs, Applications, Tokens, TokenActions, Permissions, Organizations, Emails, Services,
Queue — and `pramnos project:publish-views` publishes an admin view group to
`src/Admin/Views/`. `Health` stays with the public controllers on purpose: `/health/check`
is the JSON endpoint an uptime monitor calls, and putting a usertype floor in front of a
monitoring URL is how a project finds out its monitor has been reporting "down" for a
week.

To move an existing project's screens: move the file, change its namespace. Nothing else
refers to it. `'area' => 'Ops'` in the `admin` config block names the directory something
else.

## Fixed

**Administration screens link within the area — 114 places.** Every breadcrumb, every
redirect after a save, every row-action link and every datatable's ajax source in the
bundled admin controllers built its URLs as `sURL . 'Logs/viewer'`, so a click or a save
inside `/admin` landed on the public copy of the page. The redirect is the worst of the
set, because the visitor is not clicking anything: they save a user and arrive somewhere
that looks like having been signed out.

They go through `adminUrl()` now, in `UsersController`, `LogController`,
`SettingsController`, `EmailsController`, `ServicesController`,
`OrganizationsController`, `PermissionsController`, `TokenActionsController`,
`ApplicationsController` and `TokensController`, and in the admin views of all three
bundled themes. The public links in the same files — the password-reset URL that goes in
an email, `login`, `account` — are deliberately still bare.

A test now walks those controllers looking for the bare form, alongside the one that
already walked the views. The report was one link. The cause was 114.

## Documentation

- `Pramnos_Routing_Guide.md` — a new *Where the area's code lives*, and the danger note
  about the floor now says what the layout does and does not close.
