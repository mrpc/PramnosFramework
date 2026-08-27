---
date: 2026-08-27
categories: [Changelog]
---

# An argument a method does not declare is dropped silently

Two password checks were skipped that way. So the codebase was scanned for every other call
site doing the same thing, and the step-up checks were changed so the check cannot be skipped
by omission either.

<!-- more -->

## Changed

**`TwoFactorAuthService::disable()` and `regenerateBackupCodes()` now *require* the
password.** The earlier fix made it optional, which closed the call site that had the bug and
left the hole open for the next one: omit the argument and nothing is checked, silently.

A step-up check in front of *removing* the second factor is not something to skip by
accident, so skipping it now has a name:

| Call | Meaning |
| --- | --- |
| `disable($userId, $password)` | the user's own action — refused on a wrong **or empty** password |
| `disableForOperator($userId)` | administrative — an operator clearing 2FA off an account whose owner cannot reach it |
| `regenerateBackupCodes($userId, $password)` | the user's own action |
| `regenerateBackupCodesForOperator($userId)` | administrative, and destructive: it invalidates every code the owner holds |

A call with no password is now a `TypeError` before any code runs, which is the strongest
form the guarantee can take — and a test asserts the signature, not just the behaviour.

## Fixed

**The cache dashboard's "Categories" tile showed the number of items.**

`FileAdapter::getStats()` called `listDirectoryFiles($path, true)`, and that method takes one
parameter — so PHP dropped the `true` and returned the same recursive file list as the line
below it. The two tiles were always the same number, in all three bundled themes and in the
DevPanel. It now counts through `getCategories()`, which already listed the directories.

**`Auth::useraccess()` was being handed two arguments it did not declare.**
`User::hasaccess()` passes eight to a method that took six, so `$extraflag` (deprecated, and
declared as such by `setaccess()`) and a `$nonExistEqualsFalse` flag were discarded. The
flag matters: it distinguishes "denied" from "no rule was written", which is what a caller
wanting to fall back to its own policy needs — and it could not be asked for. Both are now
declared, and the flag is passed through to `Permissions::isAllowed()`.

The signature is longer, and no caller has to change: both additions are optional. The
contract test that pinned `useraccess()` at six parameters now pins what actually matters —
each parameter's **name and position**, and that anything added at the end is optional. A
bare count forbids exactly the change that fixes this, while allowing a rename that would
break every caller.

## Not a bug: `Controller::display()`

The scan also flags `exec()` calling `$this->display($args)` against a base `display()` that
declares nothing. Declaring the parameter is a fatal `must be compatible with` for every
existing `function display()` with no argument — `LogController` in this framework included.
The discarded argument is the mechanism that makes the parameter opt-in, and a controller
that wants the arguments declares `display(array $args = [])` and gets them. Documented in
place so the next reader does not "fix" it.

`Controller::getView()`'s `$args` is the third case: advertised, passed to a private method
that did not declare it, and consumed by nothing downstream. Declared and documented as
accepted-and-unused rather than left to be discovered.

## Documentation

- `Pramnos_Authentication_Guide.md` — the four management calls and why the password is not
  optional.
