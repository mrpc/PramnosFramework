---
date: 2026-07-23
categories:
  - Changelog
  - Authentication
tags:
  - auth
  - loginflow
  - activity-log
  - twofactor
  - passkey
  - authserver
  - model
  - datatables
---

# Login method recorded in the activity log

The activity log now distinguishes **how** a user logged in — `password`,
`twofactor` or `passkey` — instead of recording every login as a generic
`login`. This makes the security audit trail meaningfully richer: a step-up
login is now visibly different from a plain password login.

<!-- more -->

## Added

- **`Auth::setLoginMethod(?string $method)`** — a new, additive public method
  that tags the authentication method for the *next* login the built-in
  lifecycle establishes. The tag is consumed by that login and then reset, so it
  can never leak into a later login on the same `Auth` instance. A null (or
  unset) tag falls back to `password` — the default for the plain `Auth::auth()`
  path.

- **`LoginFlow` now tags each completion path.** `attempt()` (straight password
  login) tags `password`, `completeTwoFactor()` tags `twofactor`, and
  `completePasskey()` tags `passkey`. The `login` row's `details` JSON therefore
  carries the real method, e.g. `{"method":"twofactor","remember":false}`.

## Why the signature did not change (BC)

The natural fix — threading the method through `Auth::loginById()` — would add a
parameter to a public method that scaffolded apps override, an incompatible
signature change PHP rejects (CLAUDE.md §6). Instead the method is set on the
`Auth` instance just before the session is established: `LoginFlow::finishLogin()`
calls `$this->auth()->setLoginMethod($method)` ahead of `establishSession()`, and
`Auth::buildLoginResponse()` reads it into the login response the lifecycle
records. No public signature changed; the capability is purely additive.

## Tests

- **Unit** (`LoginFlowTest`) — each completion path tags the correct method, and
  a failed step-up tags nothing (no session, no tag).
- **Characterization** (`AuthCharacterizationTest`) — `setLoginMethod()` stores a
  one-shot tag that reaches the login lifecycle and is reset afterwards, and an
  untagged login defaults to `password`.
- **Integration** (`LoginFlowActivityTest`, real MySQL) — a password, a
  two-factor and a passkey login each write exactly one `login` row whose
  details record `password` / `twofactor` / `passkey` respectively.

## Fixed — DataTables `recordsTotal` in `Model::_getApiList()`

`Model::_getApiList(format: 'datatables')` set both `recordsTotal` and
`recordsFiltered` to the **filtered** row count. DataTables treats `recordsTotal`
as the grand total *before* the search box and `recordsFiltered` as the count
*after* it — so once a server-side search was applied, the "showing X of Y
(filtered from Z)" label and the pagination totals were wrong.

`recordsFiltered` now stays the filter+search count, while `recordsTotal` is
recomputed from the base `$filter` only (the extra count query is skipped when no
search is active, so the common case pays nothing). The same fix is applied to
the `User::_getApiList()` override, where `recordsTotal` is now the grand total
of all users. Both the paginated and unpaginated datatables paths are covered.

Verified with characterization tests across MySQL, PostgreSQL and TimescaleDB
(`ModelListApi*CharacterizationTest`, `UserCharacterizationTest`): a search that
matches a subset now yields `recordsTotal > recordsFiltered`.

## Fixed — DataTables "Show all" capped at the default page size

`Datatable\Datasource`'s modern DataTables 1.10+ parameter translation mapped a
page length of `-1` (the "Show all" option) to `maxlimit` (default 50), so a
client asking for every row silently received only the first 50 — unlike the
legacy path, which honours `-1` as "no limit". The translation also ignored a
pre-set legacy `iDisplayLength`, so a caller mixing the modern `draw` parameter
with the legacy length param (a DT 1.9 → 1.10 transition pattern) was capped
too.

The translation now (1) preserves `length = -1` as unlimited, matching the
legacy path, and (2) falls back to a pre-set legacy `iDisplayLength` when no
modern `length` is supplied, before defaulting to `maxlimit`. Ordinary requests
without any length parameter still fall back to `maxlimit`, so the safety bound
is unchanged. Legacy (non-modern) callers are unaffected.

Covered by three `DatasourceTest` regression cases (with `maxlimit` forced low so
the cap is observable): modern `length=-1` → all rows, modern `draw` + legacy
`iDisplayLength=-1` → all rows, and no length → `maxlimit`.
