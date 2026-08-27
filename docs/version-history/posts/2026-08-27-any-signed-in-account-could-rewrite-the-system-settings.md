---
date: 2026-08-27
categories: [Changelog]
---

# Any signed-in account could rewrite the system settings

`SettingsController` had no usertype floor. `addAuthAction()` requires only being signed in,
and the administration area's floor does not cover a path that skips the prefix.

<!-- more -->

## Fixed

**`SettingsController` now declares `requiredUserType = 80` and calls
`requireMinUserType()` in every action** — `display`, `saveSystem`, `list`, `edit`, `save`
and `delete`.

It was the only administration controller without one. Dashboard, Users, Organizations, Logs
and Services all carry their own floor; this one declared its actions with
`addAuthAction()` and nothing else, so *being signed in* was the whole check. An account
created a minute earlier could:

- read the settings form, which renders the SMTP host, user and **password** into fields;
- `POST /Settings/saveSystem` and rewrite `site_url`, `forcessl`, `admin_mail`, every SMTP
  field and the login lockout rules;
- and reach the raw editor behind it (`list`, `edit`, `save`, `delete`), which is the same
  settings by another screen.

**The administration area's floor did not cover it, and could not.** `AdminArea` strips the
prefix before routing, so `/admin/Settings` and `/Settings` are served by the same
controller. `/admin/settings` correctly refused an ordinary account with a 302; `/settings`
answered 200 with the form. A floor that applies to requests arriving through a prefix
protects exactly the paths that nobody has to use.

The test subclass in `SettingsControllerIntegrationTest` had overridden
`requireMinUserType()` with `return false; // bypass for tests` all along — somebody expected
the floor to be there. It was not.

## Documentation

- `Pramnos_Routing_Guide.md` — *What changes inside the area* now states plainly that the
  area's floor is defence in depth and never the check, with this as the example.
