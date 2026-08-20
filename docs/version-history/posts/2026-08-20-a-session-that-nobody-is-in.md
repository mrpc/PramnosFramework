---
date: 2026-08-20
categories: [Changelog]
---

# A session that nobody is in, and a report that ranked the unmeasured first

Two follow-ups from the same screen, both reported after the fixes that were supposed to
settle it.

<!-- more -->

## Fixed

**The slowest-endpoints report still read `0.0 ms`** — for a reason underneath the one
already fixed. Web requests now carry a duration, but every row written before that does
not, and `ORDER BY avg_ms DESC` puts NULLs **first** on PostgreSQL. So the top twenty were
the unmeasured historical rows, each rendered as `0.0 ms`, with the real measurements pushed
off the list. Measured on the reporting installation: 32 rows, 2 of them timed, and the two
were invisible.

The report now reads only calls that were timed — a row with no duration has nothing to say
about speed — and when rows exist but none of them are timed, it says that rather than
"no data for this period". After the change, that installation's report reads
`/devpanel/logs GET 2 calls 20.1 ms avg 25.2 ms max`.

**A web session is now bounded by the PHP session it belongs to.** `web_session` is accepted
through `$_SESSION['usertoken']`, so once PHP has expired the session —
`session.gc_maxlifetime`, 24 minutes out of the box — the row cannot be used by the browser
that owns it, whatever expiry the token itself carries. Listing it as an active session lists
something nobody can use.

The panel applies the bound per type: web sessions inside the idle timeout, API tokens inside
the window you selected, and `All` lifts both. It says so on the page, because a session
visible in the database and absent from the panel is otherwise a puzzle rather than an
answer.

The same installation went from 404 "active sessions" — one user, two days of logins — to 0,
which is the true number for a browser that last did something this morning.
