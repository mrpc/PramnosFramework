---
date: 2026-08-26
categories: [Changelog]
---

# Three endpoints that had never worked

`grant_type=client_credentials` with a client secret answered `server_error`.
Introspection answered `{"active": false}` for every access token this server had ever
issued. Revocation answered `{"success": true}` and revoked nothing. All three had the
same two causes, and all three were reachable from the first release that had them.

<!-- more -->

## Fixed

- **The secret-authenticated `client_credentials` grant issues tokens.** It wrote
  `usertokens.userid = 0`, and 0 is not a row in `users`, so the foreign key refused the
  insert and the endpoint returned a 500. The ordinary form of the grant did not work at
  all.

  The reason only one form of it worked is instructive: the **JWT client assertion** path
  carries its own thirty-line block that creates a per-application system account, so a
  token issued that way had a real owner. The League-driven path — the one everything else
  uses — had nothing.

  That block is now `Auth\Application::systemUserId()`, called from both. An application
  gets one machine account, created on first use, reused afterwards, `usertype` 1 so it
  sits below every administrative threshold. It also stopped accepting `systemuser` values
  of 0 or 1: those are the guest and system rows, and a column left holding either would
  attribute an application's tokens to an identity shared with every other application
  that had the same gap.

- **Introspection finds the token.** A token issued through the League server is a
  **JWT**; what `persistNewAccessToken()` stores is its `jti`, the opaque identifier
  League generates. Both endpoints matched the presented value literally, so neither ever
  found an access token this server had issued.

  For a resource server that trusts introspection, that is every request refused. The
  lookup now tries the literal value first — so web-session and API tokens, which are
  stored verbatim, behave exactly as before — and falls back to the `jti` inside a JWT.

  The signature is deliberately not verified: the stored row is the authority on whether a
  token is active, a `jti` is only useful to somebody who already holds the token it came
  from, and requiring verification would make every token issued before a key rotation
  introspect as dead while it was still valid.

- **Revocation revokes.** Same cause, and worse consequence: RFC 7009 makes the endpoint
  answer 200 whether or not anything matched, so an application revoking on sign-out was
  told it had worked every single time while the token stayed valid until it expired on
  its own. Nothing anywhere reported it.

## Documentation

- [Third-Party Integration](../../Pramnos_AuthServer_Integration_Guide.md) gains "Client
  credentials, and the account behind the token" — which is where the `sys_*` username and
  the non-human `sub` in an introspection response come from.
