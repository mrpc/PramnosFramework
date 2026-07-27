---
date: 2026-07-27
categories:
  - Changelog
  - Testing
tags:
  - tests
  - oauth2
  - console
  - bugfix
---

# The test suite no longer leaves an `app/` directory in the repo

Every run wrote a root-owned `app/` into the framework checkout — an RSA key
pair plus a model registry — that nothing cleaned up and that the host user
could not even list, let alone delete. Two unrelated side effects, both now
cleaned up by the tests that cause them.

<!-- more -->

## Fixed

**`app/keys/{private,public,encryption}.key`** — `Oauth::__construct()` runs

```php
$this->oauth2Factory = new OAuth2ServerFactory($this);   // no paths given
$this->oauth2Factory->generateKeyPair();
```

With no explicit paths `OAuth2ServerFactory` defaults to
`ROOT . '/app/keys/private.key'` / `public.key`, and its constructor persists
`encryption.key` next to them. Under PHPUnit `ROOT` is the framework checkout, so
`OauthTest`, `OauthCoverageTest` and `OauthControllerIntegrationTest` each
`mkdir`ed `app/keys` (0750) and generated a real RSA-2048 pair into the repo.
`OAuth2MiddlewareTest` left an `encryption.key` behind the same way —
`OAuth2Middleware::__construct()` also builds the factory without paths.

The four classes now use the new `Pramnos\Tests\Support\PreservesAppKeys`
trait: `snapshotAppKeys()` in `setUp()` records what already exists,
`restoreAppKeys()` in `tearDown()` deletes **only** what the test created. Key
*files* that were there beforehand are never touched — running the suite from
inside a real project must not destroy its signing key, which would invalidate
every issued token. Empty `app/keys` and `app/` directories are removed
regardless: an empty one carries no information, and `rmdir()` refuses to touch a
populated `app/`.

**`app/model-registry.json`** — `MakeCommandBase::registerModelInRegistry()`
writes (and `mkdir`s) `ROOT/app/model-registry.json`.
`MakeCommandBaseExtendedTest` already filtered its own entries out in
`tearDown()`; `MakeCommandGeneratorsTest` did not, so `TestEntity`,
`IntroModelEntity`, `SchemaModel` and `TestCrudEntity` accumulated there
permanently. It now removes its four entries the same way, deleting the file —
and the `app/` directory when it is left empty — instead of leaking them.

The three registry tests that already deleted the file
(`MakeCommandBaseExtendedTest`, `MakeCommandBaseCoverageTest`,
`MakeCommandBaseRegistryAndWizardTest`) left the `app/` directory itself behind;
they now `rmdir()` it too, which fails harmlessly on a real project's populated
`app/`. `testRegisterModelInRegistryHandlesCorruptRegistryFile()` seeds the
registry file directly, so it creates the directory first rather than assuming a
previous test left it there.

Because PHPUnit runs as root inside the Docker container, the leaked directory
was created `drwxr-x--- root:root`: `git status` reported it as untracked
forever while `ls` and `rm -rf` from the host both failed with
*Permission denied*. (It also makes the leak easy to misdiagnose — a host-side
`find app` returns the directory and silently nothing inside it.) Removing a
stale one, if you still have it:

```bash
docker exec pramnos_php rm -rf /var/www/html/app
```

**`$_SERVER['PHP_SELF']` in the console application** — Symfony's
`DumpCompletionCommand::configure()`, registered by every
`Pramnos\Console\Application`, reads `$_SERVER['PHP_SELF']` unguarded and passes
it to `basename()`. PHP always populates it on a real CLI run, but an embedded
console application can be built with it absent — and a dozen test classes reset
`$_SERVER = []` — which produced an `Undefined array key "PHP_SELF"` warning plus
a `basename(): Passing null to parameter #1` deprecation across 23 tests in some
execution orders. The constructor now back-fills it (from `SCRIPT_NAME` /
`SCRIPT_FILENAME`, else `'pramnos'`) alongside the `HTTP_HOST` defaults it
already sets, and never overwrites an existing value.

## Added

`/app` in `.gitignore`, as a backstop should another code path create it.

## Tests

`ConsoleApplicationCoverageTest` covers both branches of the `PHP_SELF`
back-fill: filled in when missing (with the completion command still
registered), left untouched when already set.
