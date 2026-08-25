---
date: 2026-08-25
categories: [Changelog]
---

# Three endpoints, three opinions about health

`/health/check` ran every registered check. `health:check` on the command line ran them
too. And `/.well-known/health` ran a `SELECT 1` of its own and reported on that alone —
so it was the only one of the three that could not see a full disk, an unreachable cache
or a missing signing key. Three probes are three answers, and the interesting day is the
one they disagree on.

<!-- more -->

## Fixed

- **`/.well-known/health` reads `HealthRegistry`.** Its response shape is unchanged —
  `status`, `timestamp`, and a `components` map — but `components` now lists every check
  the application registered instead of a hardcoded pair, so it grows with the application
  rather than describing a subset of it. The three-way `ok` / `degraded` / `down` collapses
  to the `ok` / `error` this endpoint has always spoken; a caller that needs the
  distinction has `/health/check`.

  One guard came with it, and it is the reason this was not a two-line change:
  `HealthRegistry::runAll()` on an **empty** registry answers `ok`. A controller reached
  from a script, or from a boot that registered nothing, would therefore have reported an
  authorization server with no database as healthy — strictly worse than the `SELECT 1` it
  replaced. So the endpoint ensures a database check is registered before it runs, and
  treats a missing database verdict as a failure rather than as silence.

## Added

- **`GET /health/status`** — the same verdict as `/health/check`, flattened to
  `{status, timestamp, service}`, plus the *names* of the failing checks when something is
  wrong.

  Two reasons it exists next to the full report rather than instead of it. Some probes
  cannot read a nested document — a load balancer check, a status page widget, a shell
  script wants one field, and asking it to walk `checks.*.status` is how a probe ends up
  parsing JSON with `grep`. And `/health/check` publishes versions, drivers, paths and
  latencies in `details`: a fair trade on a private network, less so on an endpoint
  reachable from the internet, where a database version and a driver name are a starting
  point for somebody looking for one. This gives away whether the application is well and
  where to look — what an operator needs, and all an attacker gets.

  It does not re-probe: both endpoints read the same `runAll()`.

- **`GET /Discovery/serverConfig`** — a summary of the server built for a person rather
  than for a client library. The URLs, the grants that work here, the scopes that exist,
  which optional features are on. The page you paste into a ticket.

  It is explicitly not a standards document — `/.well-known/openid-configuration` is, and
  a client should read that. What matters about this one is that every list comes from
  whatever actually decides it: `Scopes` for the scopes, `app.php` features for the flags.
  A hand-written integration note goes stale silently; this cannot.

## Documentation

- [Health checks](../../Pramnos_Health_Guide.md) documents both JSON endpoints, when to
  prefer the flattened one, and why degraded answers 503.
- [Third-Party Integration](../../Pramnos_AuthServer_Integration_Guide.md) gains
  "A summary built for a person" and "Is the server up?".
