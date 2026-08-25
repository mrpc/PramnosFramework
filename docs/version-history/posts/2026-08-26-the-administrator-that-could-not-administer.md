---
date: 2026-08-26
categories: [Changelog]
---

# The administrator that could not administer

`user:create --admin` printed "created successfully (admin)" and produced an account
that could not open a single administrative page. It set `usertype = 1`; every
administrative screen in the framework requires 80 or 90.

<!-- more -->

## Fixed

- **`--admin` now creates the account at usertype 90.** That is the tier the screens
  actually ask for: Users, Settings, Logs, Dashboard, Services, Organizations, Emails and
  Queue want 80 or more; Applications, Tokens, Permissions, `/health/phpinfo` and the dev
  panel want 90.

  `init` has always created its own first administrator at 90, so the two paths disagreed
  — and the broken one is the one `init` points at when it cannot create the account
  itself ("Run manually: … `user:create --admin`"). Somebody following that instruction
  got an account that signed in perfectly and was refused everywhere.

  The success line now names the tier (`usertype=90, administrator`) rather than a bare
  "admin", so what the command did is visible in its output instead of having to be
  inferred.

## Added

- **`--usertype=N`**, for the tiers between an ordinary account and an administrator. It
  wins over `--admin`, because somebody who names a number has a number in mind.

  A value that is not a non-negative whole number is **refused**, not coerced. `(int)` on
  a typo yields 0, which would create an ordinary account, report success, and leave
  nothing to distinguish it afterwards from one that was meant to be ordinary. There is a
  data-provider case per way of getting it wrong: a word, a negative, a fraction, a
  trailing character.

## Documentation

- [Console](../../Pramnos_Console_Guide.md) gains "The tier `--admin` grants", with the
  per-screen minimums in a table and the reason the number matters.
