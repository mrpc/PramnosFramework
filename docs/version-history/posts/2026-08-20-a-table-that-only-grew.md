---
date: 2026-08-20
categories: [Changelog]
---

# A table that only grew

A `web_session` token is created on every web login. It had no expiry, `loadByToken()`
reads 0 and NULL as "never expires", and the cleanup that exists covered only `auth` and
`access_token` — and had no caller anywhere in the framework.

<!-- more -->

## Added

Reported from a two-day-old development installation with a single user:

```
tokentype   | status |  n   | expires NULL/0
web_session |      1 | 7255 | 7255
```

About 230 an hour. `usertokens` is also the table `tokenactions` points a foreign key at,
so those rows are not only dead weight — they are what a buffered write ends up
[outliving](2026-08-20-rows-that-could-never-be-written.md).

Three things had to be true and none of them was:

**A new token knows when it stops being valid.** `createWebSessionToken()` now sets an
expiry — 30 days by default, `web_session_lifetime` to change it, `0` to keep tokens that
never expire for an installation that has a reason to want them. Generous next to the PHP
session the token belongs to, whose idle timeout is 24 minutes out of the box.

**The cleanup covers the type that accumulates.** `cleanupAllAuthTokens()` retires every
session-bearing type, and takes an optional list for a caller that wants only some of them.
Retiring is `status = 2`, not a delete: the row stays for the audit trail and stops being
accepted.

**Something runs it.** `auth:token-cleanup`, scheduled daily by the framework. The method
it calls has existed for a long time; what was missing was anything that called it. An
application with no token table is not a failure — the command recognises a missing table
and says nothing, because a daily red line for a table that was never meant to exist is how
a log stops being read.

`lastused` is updated on every request that presents a token, so "idle for a month" means
what it says. Existing rows keep their `expires = 0` and are retired by idleness rather than
by expiry, which is the only safe reading of a token created before the rule existed.

## Documentation

`Pramnos_Authentication_Guide.md` — web-session tokens, the lifetime setting, and the
scheduled cleanup.
