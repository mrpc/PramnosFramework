---
date: 2026-08-25
categories: [Changelog]
---

# The discovery controller had no address

`init` has scaffolded a `Discovery` controller for every authserver project for a while
now. Its docblock names the endpoints it serves — `/.well-known/openid-configuration`,
`/.well-known/jwks.json`, `/.well-known/oauth-authorization-server` — and every one of
them answered 404, because the `.htaccess` `init` wrote in the same run had no rule that
could reach them.

<!-- more -->

## Fixed

- **The `.well-known` paths reach `Discovery`.** Those paths are fixed by specification,
  so they do not fit the framework's `controller/action` URL shape and cannot be routed by
  accident. `init` now writes the five rules — including the underscore spelling of
  `openid_configuration`, which is in no specification and in plenty of clients — whenever
  the `authserver` feature is on, and omits them entirely when it is not, since they name a
  controller only that feature scaffolds.

  They go **above** the catch-all rule. `mod_rewrite` runs rules in order and the catch-all
  matches every path, so a discovery rule below it never fires — a bug that would leave the
  rules present and the endpoint still broken. There is a test asserting on positions rather
  than on presence for exactly that reason.

- **The `Authorization` header reaches PHP.** Apache does not pass it to PHP-FPM or CGI
  unless it is copied into the environment, and it was not being copied. Every request
  authenticating with `Authorization: Bearer …` — which is every generic HTTP client, every
  OpenAPI console, every `curl` in a support ticket — arrived looking anonymous. That reads
  as a rejected credential, so the investigation goes into the token; the token was fine.

  This one is written for **every** project, not only authorization servers. Any REST API
  that takes a bearer token needs it.

Both blocks were missing from all three application styles, and each style wrote its own web
root config, so the fix is one shared helper rather than three parallel edits. A SPA project
was the worst affected: its shell fallback answered a discovery request with the
application's HTML and a 200, so a client saw malformed JSON instead of a missing endpoint.

## Documentation

- [Third-Party Integration](../../Pramnos_AuthServer_Integration_Guide.md) gains two
  troubleshooting sections — "If discovery answers 404" and "If a bearer token reads as no
  token" — with the rule block and the ordering constraint spelled out.
- [Console](../../Pramnos_Console_Guide.md) describes what `init` now writes into the web
  root config, and that all three application styles get it.

A project scaffolded before today keeps its own `.htaccess`: version control does not update
it, so the block has to be added by hand, above the catch-all.
