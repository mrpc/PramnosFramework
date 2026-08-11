---
date: 2026-08-11
categories:
  - Changelog
  - Fixed
tags:
  - devpanel
  - cache
  - database
  - testing
---

# Five developer panels had been empty for years, and nothing said why

They queried a table called `tokens`. The framework's is called `usertokens`.
Every query threw, an empty `catch` swallowed it, and the panel rendered as
"nothing to show".

<!-- more -->

## Fixed

The DevPanel's Active Sessions, Login Lockouts, Token detail, Slow users and
Queue stats sections were broken on every installation. Not subtly:

| Asked for | Actually exists |
|---|---|
| `tokens` | `usertokens` |
| `last_used`, `ip_address` | `lastused`, `ipaddress` |
| `tokentype IN (1,3)` | a **text** column — `auth`, `access_token` |
| `loginlockouts` with `identifier`, `lockout_until` | `authserver.loginlockouts` with `displayvalue`, `lockoutuntil` |
| `queue_jobs` | `queueitems` |
| `{$prefix}` from a `PREFIX` constant | never defined anywhere in the framework |

The test above them was green, because an empty panel still contains its own
headings. It asserts on the values now.

**`SchemaBuilder`'s TimescaleDB methods no longer return `false` for two
different things.** They returned it when the backend lacked the extension —
documented, deliberate — and when the statement failed. Both silent, so a
migration whose `createHypertable()` failed looked exactly like one running on
MySQL, and the table stayed unpartitioned with nothing anywhere saying so. The
signature is unchanged; what changed is that only the no-op is quiet now, and a
real failure is logged with the statement that produced it.

**`keys()` says whether it can look.** Adapters that cannot enumerate — File,
Array, Memcached — returned `[]`, which reads exactly like "nothing matched".
`supportsKeyEnumeration()` answers the question directly, and `FlatCache` says
so once in the log rather than handing back a convincing empty list.

**`ApiAccount::revokeToken()` is now actually tested.** It carried
`@codeCoverageIgnore — exercised via integration, not unit tests`, and no such
test existed. Rather than delete the claim, three integration tests make it
true: the row is deactivated, the token stops identifying its user, and
revoking an unknown token is harmless. That last one matters because API tokens
have no expiry by default on existing installations — revocation is the only
thing that ends a session.

**Every silent `catch` in `src/` now says why the failure does not matter.**
There were 68; 30 said nothing at all. Swallowing an exception is sometimes
right — instrumentation must not break a response, a webhook must answer even
if its log write fails — but doing it wordlessly leaves the next reader unable
to tell a considered decision from an unfinished one.

## Added

`SilentFailureTest` reads the whole of `src/` and fails when a `catch` discards
an exception without a comment, or when a coverage exclusion promises tests
that do not mention the class. It chases no particular bug; it makes the shape
expensive. Every serious finding in this audit had that shape — a failure that
looked like a result.
