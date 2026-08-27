---
date: 2026-08-27
categories: [Changelog]
---

# The lockout settings configured nothing

The settings screen has an editor for the progressive lockout ladder, validation for it, and
a warning when it clamps what you typed. None of it reached the lockout.

<!-- more -->

## Fixed

**`Loginlockout` now reads `loginlockoutsteps` and `loginlockoutwindowseconds`.**

`calculateDuration()` consulted `self::DEFAULT_STEPS` and nothing else, and the window
arithmetic used `DEFAULT_WINDOW_SECONDS` directly. So an operator could tighten the ladder,
the page would confirm "Settings saved.", and every account kept locking on the shipped
3/5/7/10 → 60/300/900/3600. The two settings were written by the form, validated by the
controller, warned about when clamped — and read by nothing.

It is the kind of gap that only shows up from the outside: every unit test of
`calculateDuration()` passed, because they assert the defaults, and the defaults were all it
ever used.

An unusable `loginlockoutsteps` — not JSON, empty, or with no usable `attempts: seconds`
pair — falls back to `DEFAULT_STEPS`, and a window outside 60–86400 falls back to 900.
Never to an empty ladder: a malformed setting must not be a way to switch brute-force
protection off, and a window of zero would reset the counter on every attempt, which is the
same thing by another route.

## Documentation

- `Pramnos_Authentication_Guide.md` — a new *Configuring the ladder* under **Login Lockout**.
