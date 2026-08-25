---
date: 2026-08-26
categories: [Changelog]
---

# The logout that revoked nothing

`POST /oauth/logout` answered `{"success": true}` and left every token valid. It had
done so for as long as it has existed.

<!-- more -->

## Fixed

- **The endpoint revokes tokens again.** Its lookup selected `usertokens.sid` — a column
  that has never existed in that table. The query failed, the query builder swallowed the
  failure and returned nothing, and the endpoint took its token-not-found branch for
  *every* token it was given. It reported success and did nothing.

  That is the worst shape a security bug can take. An application calling this on sign-out
  had every reason to believe the user was signed out; the tokens stayed valid until they
  expired on their own.

  A session is now the **token family**: the access token and the refresh token issued
  with it, linked by `usertokens.parentToken` — the column the refresh-token repository
  writes precisely so that "revocation can cascade", as its own docblock says. Presenting
  either one revokes both. A token issued to another device belongs to another family and
  is left alone, which is what distinguishes this from signing out of everything.

  The revocation is scoped to the owning user as well as to the family, so a crafted
  `parentToken` cannot reach another account's tokens.

- **`logoutwebsession=1` does what the parameter says.** It was accepted and ignored;
  it now ends the browser session too. Without it the browser session is deliberately left
  alone.

- **The response says what happened.** `tokens_revoked` is in the body, so a caller can
  tell a real revocation from a token that was not found — which, given the above, is a
  distinction worth being able to make.

An unknown token still answers `{"success": true}`, and that is deliberate: in the spirit
of RFC 7009, an endpoint that distinguished a real token from an invented one would be an
oracle for which tokens exist.

`Oauth::extractBearerToken()` also became `protected`. Nothing outside the class could call
it before and nothing can now; widening it is what lets the endpoint's decisions be tested
without building a request, which is how a query against a column that does not exist went
unnoticed for so long.

## Documentation

- [Third-Party Integration](../../Pramnos_AuthServer_Integration_Guide.md) gains "Signing
  out": both endpoints, what a token family is, and why an unknown token still succeeds.
