---
date: 2026-08-16
categories:
  - Changelog
  - Added
tags:
  - auth
  - security
  - notifications
---

# An alarm that stays rare

Opt-in email when an account is signed in to from a browser or device it has not been
used from before. The hard part was not detecting it — it was not firing.

<!-- more -->

## The requirement that shaped everything

*No notification on a new IP address.* Consumer connections are dynamic, so an
address-based alarm fires on a router reboot, and by the second week nobody reads it.

That constraint has a **less obvious sibling**, and it is the one that would have
shipped: a fingerprint built from the `User-Agent` string changes every time the
browser updates. Chrome and Firefox ship a major version about every four weeks. That
is a monthly alarm for every user — the same failure, one step removed, and invisible
in a test that only checks the fingerprint is a string.

So `SignInFingerprint` keeps a **browser family and a platform family**, and nothing
else:

```
Chrome/109 … Windows NT 10.0   →  chrome|windows
Chrome/133 … Windows NT 10.0   →  chrome|windows     ← a year later, same value
iPhone OS 17_2 … Safari        →  safari|ios
iPhone OS 17_4 … Safari        →  safari|ios         ← after an OS update
```

Most of its tests are **stability** tests: browser update, OS point release, x64 to
ARM64 — one value. The discrimination tests are the easy half.

**The cost, stated rather than hidden:** two Chrome-on-Windows machines are
indistinguishable, so a colleague's identical laptop raises nothing. That is the price
of rarity, and rarity is the entire value. An application needing more should add a
signed device cookie — narrowing the user-agent parsing is the wrong lever.

One trap on the way: Edge announces itself as Chrome, Chrome announces itself as
Safari, and everything announces itself as Mozilla. Matched in the wrong order, every
desktop browser collapses into `safari` and the feature silently stops detecting
anything. There is a test for the ordering.

## The day-one problem

A device detector with no history says *everything is new*. Switch it on, and every
user who opted in is notified at once — about a sign-in they are performing right now.

So the history comes from `authserver.user_activity_log`, which has recorded a user
agent against every `login` since the auth feature shipped. Months of it. The first
sign-in after upgrading is recognised as familiar, which is what it is.

An account with **no** history is treated as not new, for the same reason. And only
successful logins count: `login_failed` carries a user agent too, and letting a failed
attempt make a browser familiar would turn the audit log into a way of switching the
alarm off.

## No migration

The opt-in is a `userdetails` row — the framework's per-user key/value store, where
password-reset tokens already live. No schema change, so it works on every installation
the moment the framework is upgraded, including those whose `migration_cutoff` skips
baseline migrations. It inherits that table's cascade on user deletion, which a
GDPR-relevant preference needs anyway.

A column on `user_privacy_settings` was written first and thrown away. It was tidier and
it would have left every installation waiting on a migration to get a security feature.

## What the email does not do

It does not print the IP address. Nobody recognises their own, and printing one invites
the compare-with-last-time habit this feature exists to avoid.

It offers no link. A link in an unexpected security email is the shape of the attack it
is warning about; the instruction is to open the site yourself.

It goes by mail only. A database notification would put the warning in the panel of the
session that triggered it — in the case worth warning about, the wrong person.

## Two mistakes the build caught

`NewSignInAlert` resolved its own connection through `Factory::getDatabase()`, so the
integration test could not point it at the test database. That is the lesson
[`Service::database()` already documented](2026-08-14-four-corrections-from-the-other-side.md)
in this framework, with 59 call sites behind it, arrived at again from the other end.
Every method takes an optional connection now.

And `authserver.user_activity_log` resolves to **`authserver_user_activity_log`** on
MySQL — the schema becomes part of the *name*. The test created the plain
`user_activity_log` on a confidently-worded assumption, so four tests failed reporting
*"no history"* rather than *"wrong table"* — a failure that points away from its cause.

## Added

- `Pramnos\Auth\SignInFingerprint` — coarse, stable browser/platform identity.
- `Pramnos\Auth\NewSignInAlert` — the opt-in, the history lookup, and the check.
- `Pramnos\Auth\Notifications\NewSignInNotification`.
- A checkbox on the Account privacy page, in all three scaffolded themes.
- The login lifecycle records the fingerprint in the activity log's details.

## Documentation

- [Authentication guide](../../Pramnos_Authentication_Guide.md) — *New sign-in alerts*:
  what counts as new, what deliberately does not, where the history comes from, and why
  the email says what it says.
