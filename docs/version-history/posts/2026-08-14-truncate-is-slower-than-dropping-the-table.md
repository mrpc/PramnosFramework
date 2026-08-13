---
date: 2026-08-14
categories:
  - Changelog
  - Added
  - Fixed
tags:
  - testing
  - security
  - database
---

# `TRUNCATE` is slower than dropping the table

78 seconds off the suite, from two classes. One of them was slow for the reason the
performance study guessed. The other was not, and finding out involved a measurement that
contradicts what everybody assumes about emptying a table.

<!-- more -->

## `MediaObjectTest` — 46.8 s, and the images were innocent

The study guessed "one fixture image being regenerated 86 times". The images are 10×10
JPEGs and cost nothing. Reading `setUp()` was enough:

```php
foreach (['usertokens', 'userstogroups', 'userdetails', 'users', 'usergroups'] as $t) {
    $this->db->query("DROP TABLE IF EXISTS `{$t}`");
}
User::setupDb();
// ... then DROP + CREATE for media and mediause
```

**Seven drops and seven creates per test.** Not one of the 86 tests asserts anything about
the schema — they assert what `MediaObject` does with rows and files.

So the schema moved to `setUpBeforeClass()` and `setUp()` empties the tables instead. Which
raised a question worth measuring rather than assuming.

## The measurement

Two tables, on this project's MySQL container:

| | Per cycle |
| --- | --- |
| `DROP` + `CREATE` | 128.6 ms |
| `TRUNCATE` | **159.5 ms** |
| `DELETE` + `ALTER … AUTO_INCREMENT = 1` | 18.7 ms |
| `DELETE` | **0.22 ms** |

**`TRUNCATE` is slower than dropping and recreating the table.** It looks like the fast path
— one statement, no row-by-row work — and it is an implicit DDL statement that drops and
recreates the table internally, plus a metadata lock.

And the auto-increment reset is 18 ms of the 18.7. This class does not need it: every id
assertion in it is `assertGreaterThan(0, $id)` or a comparison against another id, never a
literal `1`. So the per-test reset is a plain `DELETE`.

**46.8 s → 7.2 s**, same 86 tests, same 223 assertions.

The drop-and-recreate with `FOREIGN_KEY_CHECKS = 0` was kept, in `setUpBeforeClass()`,
because the reason it was written still holds: another class may have dropped `users` before
this one runs, and InnoDB then refuses to create a table whose foreign key points at a table
that is not there. Classes run sequentially, so once per class is as safe as 86 times.

## `TwoFactorAuthService` — this half the study got right

It was bcrypt. On PHP 8.5, `PASSWORD_DEFAULT` is bcrypt at cost 12 — **142.9 ms per hash** —
and enabling 2FA hashes **ten** backup codes, so a single call cost 1.43 s. That is exactly
the runtime of `testCompleteSetupInsertsNewRowOnSuccess`, to the hundredth.

```
cost  4:   0.71 ms
cost  8:   9.05 ms
cost 12: 142.9 ms   ← the default
```

The framework had three `password_hash($plain, PASSWORD_DEFAULT)` call sites — `User`, the
database auth driver and the 2FA backup codes. They now go through
**`Pramnos\Auth\PasswordHash::make()`**, which behaves exactly as the bare call it replaced
unless `PRAMNOS_BCRYPT_COST` says otherwise. `tests/bootstrap.php` sets `4`.

| Class | Before | After |
| --- | --- | --- |
| `TwoFactorAuthServiceMySQLTest` | 21.2 s | **4.2 s** |
| `TwoFactorAuthServicePostgreSQLTest` | 21.3 s | **4.2 s** |
| `TwoFactorAuthServiceTest` | 4.3 s | **0.03 s** |

**The algorithm under test does not change.** Cost is a bcrypt parameter, so a hash made at
cost 4 is verified by the same `password_verify()` that ships — `PasswordHashTest` asserts
that directly, and asserts the salt is still per-hash, because a "make hashing cheaper"
change that quietly went deterministic would pass every other test in the class.

### The knob is built to resist being turned

`143 ms` is not an accident to be optimised away; it is what makes an offline attack on a
stolen hash expensive. So every invalid value falls back to **PHP's default**, never to
something cheap: below 4, above 31, not a number, empty. And it does not raise — a typo in a
deployment's environment must not be able to stop people logging in, and a hash at the
default cost is never the unsafe outcome.

A production deployment should leave `PRAMNOS_BCRYPT_COST` unset. The
[Security Guide](../../Pramnos_Security_Guide.md#the-cost-and-why-you-should-leave-it-alone)
says so where somebody configuring a deployment will read it.

## Where the suite stands

**396 s delivered** across the four items: 56 + 136 + 126 + 78. The full run with coverage
has gone **17:02 → 8:27** — 515 s, more than the 396 s measured without coverage, because
instrumentation is a multiplier on work done and removing work removes its share too.

Item 4 makes it four for four on the same lesson: the written plan named the fixture, the
measurement named the schema. The
[performance study](../../Pramnos_Test_Suite_Performance.md) records what each guess got
wrong, because that turns out to be the more useful half of the page.

## Added

- `Pramnos\Auth\PasswordHash` — the one place the framework turns a secret into a hash, with
  `PRAMNOS_BCRYPT_COST` for the one environment that should lower it.

## Fixed

- `MediaObjectTest` builds its schema once per class and empties tables with `DELETE`.
- The [Testing Guide](../../Pramnos_Testing_Guide.md) records the `TRUNCATE` measurement and
  the hashing cost among the habits that make a test slow.
