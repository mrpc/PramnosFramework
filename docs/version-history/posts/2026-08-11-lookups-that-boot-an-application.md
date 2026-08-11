---
date: 2026-08-11
categories:
  - Changelog
  - Added
  - Fixed
tags:
  - application
  - database
  - http
  - user
---

# `getInstance()` is a factory, and two call sites forgot

Reading one setting could build an entire application — database, language and
session included. On the connection path that meant querying the database
through the connection still being opened.

<!-- more -->

## Added

**`Application::currentInstance(): ?self`** — the current application if one
exists, without creating one.

```php
$app = \Pramnos\Application\Application::currentInstance();
if ($app === null) {
    // no application: fall back, do not build one
}
```

`getInstance()` is a *factory*. Given no existing instance it reads `app.php`,
defines constants and runs the whole constructor, which sets up the database,
the language and the session. That is correct for a caller that wants an
application, and wrong for one that only wants to read a setting.

Low-level code should use `currentInstance()`. The distinction is not academic:
both bugs below were the same mistake, made in the same change.

## Fixed

**A CSRF check could boot an application.** `Session::getFingerprint()` began
asking for the trusted-proxy list, which reads application config. CSRF
verification and rate-limit middleware run before the request has decided
anything — they are the two places least able to absorb a side effect of that
size. A reference application's login tests failed on "security token invalid or
expired" because a second application was being constructed underneath them.

**The connection path resolved configuration.** `Database::setTrackingInfo()`
runs while the PostgreSQL connection is being opened, stamping tracing variables
onto it, and called `Application::getInstance()` to read the application name.
Building an application there sets up `Settings`, which queries the database —
through the connection still being established. It surfaced as a MySQL-quoted
statement arriving at PostgreSQL:

```
ERROR:  syntax error at or near ","
LINE 1: select `setting`, `value` from `settings`
```

Backticks untranslated and `#PREFIX#` empty: the signature of a query issued on
a connection that did not yet know its own driver. The `!== null` check beside
the call had already been written as though it could not construct anything; now
it cannot.

**An empty `REMOTE_ADDR` is not an absent one.** The CSRF fingerprint was
`$_SERVER['REMOTE_ADDR'] ?? 'none'`, and `??` does not fire for the empty
string, so an empty value hashed as `''`. A rewrite that substituted `'none'`
for both cases changed the fingerprint — and the fingerprint is hashed into a
token issued by one request and verified by the next, so every form in flight
would have broken at deploy. The fallback now reproduces the original expression
exactly.

**`User` serializes through `__serialize()`.** It used `__sleep()`, which
returns property *names* that PHP then looks up on the object. Private
properties are stored under a mangled name, so serializing a *subclass*
instance — the normal case, since applications extend this class — emitted
`"_userstable returned as member variable from __sleep() but does not exist"`
for every private property, on every serialize.

!!! note "If you overrode `__sleep()` on a User subclass"
    It is no longer called: `__serialize()` takes precedence. Rename your
    override to `__serialize()` (returning the data array rather than a list of
    names) and add the matching `__unserialize()`. The plaintext password is
    still excluded, which is what this machinery exists for.

## Added — a guard for the whole class of error

`ConnectionPathPurityTest` reads the source and fails when anything on the
connection-establishment path calls a configuration lookup. It is a source check
rather than a runtime one because the bug is structural: the call is wrong even
on the runs where it happens to work.

It earned its place immediately. It was written to cover a defect this work had
introduced, and the first thing it reported was the older one in
`setTrackingInfo()` that had been sitting there unnoticed.

## How this was found

None of it by this framework's own suite, which is green at 8836 tests. All four
defects came from running a reference application's 5401-test suite against the
framework — a second application to construct, a `REMOTE_ADDR` set to an empty
string, a `User` subclass to serialize, a live connection to open. The framework
alone has none of those.

Both versions were then run against the same database: pinned release 5401 OK,
this branch 5401 OK, identical assertion counts.
