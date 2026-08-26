---
title: The scopes the server advertised and refused
date: 2026-08-26
categories:
  - Auth
  - Bugfix
---

# The scopes the server advertised and refused

`/.well-known/openid-configuration` published twelve scopes. The token endpoint
accepted four, and only one of them was on the list.

<!-- more -->

## What was happening

Two lists, and nothing connecting them.

`Pramnos\Auth\Scopes` is the framework's scope registry. The discovery document's
`scopes_supported` comes from it, so does the consent screen, so do the permission
checks. On a typical server it holds `profile`, `email`, `phone`, `address`,
`user`, `openid`, `offline_access` and the `system:*` scopes.

`Pramnos\Auth\OAuth2\Repositories\ScopeRepository` is what league/oauth2-server
asks when it validates a request. It carried its own list:

```php
private array $scopes = [
    'read'  => 'Read access',
    'write' => 'Write access',
    'admin' => 'Admin access',
    'user'  => 'User profile access',
];
```

It had `setScopes()` and `addScopes()` for an application to replace them, and
nothing anywhere ever called either. So the server advertised twelve scopes and
accepted four, overlapping on `user`.

Eleven of the twelve were refused as `invalid_scope`, and one of the eleven was
`openid` — which means OpenID Connect did not work at all. A client that read the
discovery document and asked for exactly what it offered got a 400 on its first
request.

## Why nobody noticed

Because each side is internally consistent, and nobody reads both.

Read the discovery document: the scopes are there, spelled correctly, in a
standards-shaped field. Read `ScopeRepository`: four scopes, coherent, documented
in the class docblock. Both files look right. The bug is the relationship between
them, which is not written down in either.

And it fails at integration time on somebody else's machine — the moment a new
client first calls the token endpoint, which is exactly when an integrator assumes
they have got their own request wrong.

## The fix

`ScopeRepository` reads the registry:

```php
$this->scopes = array_merge(self::LEGACY_SCOPES, \Pramnos\Auth\Scopes::getScopeDescriptions());
```

Resolved on first use rather than in a property initialiser, so constructing the
repository does not force the registry to load. `setScopes()` and `addScopes()`
still work — and `addScopes()` now resolves before merging, or adding a scope
before anything had read the list would have replaced it instead of extending it.

The four original identifiers are kept. An integration built against `read` and
`write` predates the registry, and a scope that stops being accepted is an outage
on somebody else's server.

## The test worth having

Not "the repository accepts `openid`" — a test per scope goes stale as the registry
grows. The test is the *relationship*:

```php
foreach (array_keys(Scopes::getScopeDescriptions()) as $scope) {
    $this->assertNotNull($repository->getScopeEntityByIdentifier($scope));
}
```

Every scope the server advertises, accepted. A scope added to the registry is
covered the day it is added, and a second list appearing anywhere fails
immediately.

## Documentation

- `Pramnos_AuthServer_Integration_Guide.md` — new "Which scopes you may ask for"
  section, with the dated note on what used to happen.
