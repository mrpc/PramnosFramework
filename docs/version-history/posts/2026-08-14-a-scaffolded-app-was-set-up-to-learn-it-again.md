---
date: 2026-08-14
categories:
  - Changelog
  - Fixed
tags:
  - testing
  - scaffolding
---

# A scaffolded application was set up to learn it the hard way

The two PHPUnit extensions that stop process-wide state leaking between tests were added
to the framework after they had cost it 135 failures once and three on another occasion.
This is about the obvious question nobody asked at the time: **does a project scaffolded by
`pramnos init` get them?**

It did not.

<!-- more -->

## The audit

The framework had just fixed four things. Every one of them was worth checking against
what `init` generates, because a scaffolded application inherits the framework's
singletons, its `Request`, its query builder and its test setup.

| Checked | Result |
| --- | --- |
| Isolation extensions in the generated `phpunit.xml` | ❌ **absent** |
| Generated API controllers reading a request body | ✅ `staticGet(…, 'post')` / `(…, 'put')` — the right store per method |
| `whereRaw` with a `?` placeholder in generated code | ✅ none |
| Hostnames that cannot resolve in generated test stubs | ✅ none |
| `--no-coverage` in the generated `dockertest` | ✅ present, with the same reasoning |
| Generated `www/index.php` under the new toolbar architecture | ✅ `echo $app->render()`, so it gets a toolbar |

One finding, and it was the one that matters, because of *how* it fails.

## Why an absent `<extensions>` block is worse than it looks

A leak of this kind never fails where it is caused. A middleware test seals an identity,
the process keeps it, and a controller test three hundred tests later finds itself signed
in as somebody it never authenticated. The failure names the controller test. The obvious
fix is to make that test explicit about its identity — which works, and leaves the trap
in place for the next test to walk into.

So a generated project was set up to spend somebody's afternoon rediscovering exactly what
the framework had already paid for, with a fix that would look correct.

## And the classes did not even ship

Fixing the generator surfaced a second problem. `.gitattributes` has:

```
/tests export-ignore
```

Both extensions lived in `tests/Support/`, and `composer.json` maps that namespace under
`autoload-dev`. So `Pramnos\Tests\Support\RequestIdentityIsolation` **does not exist inside
the composer package** — a generated `phpunit.xml` naming it would have failed to boot in
every consumer project, while passing every test in this repository.

Both classes now live in `src/Pramnos/Framework/Testing/`, next to `BaseTestCase` and
`TestEnvironment`, which is where testing support that has to travel belongs:

```xml
<extensions>
    <bootstrap class="Pramnos\Framework\Testing\RequestIdentityIsolation"/>
    <bootstrap class="Pramnos\Framework\Testing\DocumentIsolation"/>
</extensions>
```

Each is now a single class implementing both `Extension` and
`PreparationStartedSubscriber` and registering `$this`, rather than an extension wrapping
an anonymous subscriber — which is what makes the behaviour testable at all.

## Tested, including the part that has no symptom

A reset method nobody calls is a silent failure: the suite stays green and the leak comes
back. So the tests assert the reset *and* the subscription.

Proving the subscription from inside a running suite takes a small trick. PHPUnit seals its
event facade once a run has started, so registering anything after that throws
`EventFacadeIsSealedException` — and that exception is the proof that `bootstrap()` reached
`registerSubscriber()` instead of quietly doing nothing.

The generator test reads the class names *out of the generated XML* and asserts each one
loads and lives under `src/`. That is the assertion that would have caught the
`export-ignore` hazard on its own.

## Fixed

- `pramnos init` writes an `<extensions>` block registering both isolation extensions,
  with a comment saying what they are for so that tidying up does not remove them.
- `RequestIdentityIsolation` and `DocumentIsolation` moved to
  `Pramnos\Framework\Testing`, so they exist in a consumer's `vendor/`.
- The Testing Guide claimed a connect timeout fixes an 8-second DNS wait. It does not —
  [measured](../../Pramnos_Test_Suite_Performance.md) — and the row now says what actually
  works.

## Documentation

- [Testing Guide → Isolating process-wide state](../../Pramnos_Testing_Guide.md#isolating-process-wide-state)
  — what leaks, and why an extension rather than a `setUp()`.
- [Upgrade Guide → Test isolation extensions, for existing projects](../../Pramnos_Upgrade_Guide.md#test-isolation-extensions-for-existing-projects)
  — the two lines to add, and what to expect when a test starts failing or starts passing.
