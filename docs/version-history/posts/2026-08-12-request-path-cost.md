---
date: 2026-08-12
categories:
  - Changelog
  - Fixed
  - Added
tags:
  - performance
  - database
  - sessions
  - debugbar
---

# Nine queries per request, most of them avoidable

A datatable asking for one page of results ran nine statements. Two of them read
settings that do not exist, one built a theme nothing would render, three logged
that the request had happened, and one asked whether the migrations were up to
date — again, having asked on the previous request a moment earlier.

<!-- more -->

## What was happening

Every request, including every XHR a page makes after it has rendered:

| Statement | Why |
|---|---|
| `DELETE FROM sessions WHERE time < …` | garbage collection, on every request |
| `SELECT logout FROM sessions WHERE visitorid = …` | to read a flag that is almost never set |
| `INSERT … ON CONFLICT … sessions` | recording the visit |
| `SELECT value FROM settings WHERE setting = 'theme_default_settings'` | a setting that does not exist |
| `SELECT value FROM settings WHERE setting = 'theme_default_widgets'` | another one |
| `SELECT 1 FROM schemaversion WHERE key = '__fw_auto_…'` | the auto-migration check |
| `SELECT urlid FROM urls WHERE hash = …` | resolving a URL to an id |
| `INSERT INTO tokenactions …` | logging the request |
| `SELECT LASTVAL()` | for an id only the API path uses |

## Fixed

**Settings that do not exist no longer cost a query each, every time.** The bulk
load already read every row in one statement; a key still missing afterwards is
missing, and asking the database again cannot change that. It was asking on
every read — so an absent setting cost one query per read, per request, for
ever. The two theme lookups were exactly that.

**The dead theme settings read is gone.** `Theme::loadSettings()` fetched
`theme_<name>_settings`, compared it to `''`, and did nothing with it: the only
statement inside the `if` was commented out years ago, when the settings form it
fed stopped existing.

**A theme is no longer built for a response that cannot render one.** The load
runs before the controller, which is before anything knows the response will be
JSON — so a datatable endpoint built a theme, read its widget configuration and
looked for its screenshot, for a reply with nowhere to put any of it. Opt-in
(`'lazytheme' => true` in `app.php`), because a controller is entitled to read
`$document->themeObject` while it runs.

**Session garbage collection is occasional.** Rows go stale five minutes after
their last request and nothing reads a stale row, so how promptly they are swept
does not matter — only that somebody does it. One request in
`session_gc_divisor` (default 100) sweeps; `1` restores the old behaviour and
`0` turns it off for a scheduled task to do instead.

**The forced-logout check is folded into the upsert it sat next to.** On
PostgreSQL the upsert no longer clears `logout` blindly; it returns the value the
row had, and the rare request that finds a `1` pays one extra statement to clear
it. Two statements become one for everybody else. MySQL keeps both, having no
`RETURNING` on an upsert.

**The auto-migration check answers from cache, keyed on the migration files
themselves.** A time-based cache would be wrong here: after a deploy that adds a
migration, a stale "all applied" leaves the schema behind the code. But the
fingerprint already describes the files — their count, the latest timestamp, the
cutoff — so using it as the key makes the cache invalidate itself. A deploy
changes the key; nothing else can. No lifetime has to be guessed, and there is
no window in which the code is ahead of the schema. APCu where available, a
marker file otherwise.

**`get_browser()` no longer warns on every request.** Two separate faults: the
call needs the `browscap` ini directive, which is unset by default, so it could
only fail — and the toolbar's error handler reported the warning even though the
code suppressed it with `@`, because a custom handler is called for suppressed
diagnostics too. The handler now respects `@`, and the call is not made when
there is no browscap file. While there, a request with no `User-Agent` header —
a health check, a script — stopped raising "Undefined array key".

**The session row is not rewritten twice a second.** It records who is online
and what they are looking at, and a page that loads and then calls its own API
wrote it twice with the same values. One write per `session_write_interval`
seconds (default 60) — but a **change of URL always writes**, because "what are
they looking at" is the field somebody actually watches, so only the timestamp
goes stale. The cost is that a visitor leaves the online list up to a minute
later than they might, and a forced logout is noticed up to a minute later; set
the interval to `0` for the old behaviour.

**A datatable's count is cached on the same terms as its rows.** `count()` took
no caching parameters, so it could not be cached at all: a datatable that asked
for caching served its page from cache and then ran a full `COUNT(*)` anyway, on
every request, for a number that changes far less often than the rows do.

**A datatable no longer counts the same rows twice.** The unfiltered and
filtered counts were both issued unconditionally, and with no search typed they
are character-for-character identical — on a large table the most expensive
statement of the request, run twice.

## Added

**`Pramnos\Database\WriteSpool`** — a buffer for writes that should not be paid
for while somebody is waiting. An audit row, an access log, a counter: worth
keeping, worth nothing individually, written on every request and read in bulk
much later.

The backend order is measured rather than assumed, per row, against a real
PostgreSQL and a real Redis:

| | ms/row |
|---|---|
| INSERT into the real table (hypertable + indexes) | 2.807 |
| INSERT into a plain, unindexed spool table | 2.362 |
| Redis `RPUSH` | 0.041 |
| file append under `LOCK_EX` | 0.003 |

Both obvious guesses are wrong. **A spool table in the database is not worth
building**: the cost is the round trip, not the indexes, so an unindexed table
saves 16% for the price of a table, a migration and a drain. And **the file beats
Redis** — Redis is also a round trip, to another host, while an append is a
syscall. So the file is the default; Redis is the setting to reach for when the
buffer must be shared between servers.

The spool streams when it drains. Reading a 100 MB backlog into an array peaked
at 130 MB, which on a default `memory_limit` is a fatal error — and a fatal
error there spirals, because the spool that could not be drained is the one that
keeps growing. Batches of 500 hold nothing, and measured twice as fast.

**`php pramnos spool:drain`**, and a framework schedule that runs it.

**`Pramnos\Scheduling\FrameworkSchedule`** — the framework's own periodic work,
declared once. `app/schedule.php` is written at scaffold time, so a framework
that ships a background command and then relies on every project to add a line
to it has shipped an obligation, not a feature. These register whether or not the
application has a schedule file; `FrameworkSchedule::disable()` and
`disableAll()` opt out.

**`php pramnos work`** — one process that runs the schedule continuously, for
containers and anywhere else without a crontab. Not the queue worker:
`queue:process` runs jobs and polls constantly to keep latency low, this runs the
clock and sleeps a minute at a time.

## Changed

**`Token::addAction()` holds its row instead of writing it.** Logging an API call
was an INSERT, then an UPDATE of the row it had just made, plus a round trip for
the generated id — all on the critical path. The row is held until the response
is known and written **once**, through the spool. `updateAction()`'s signature is
unchanged and its old behaviour is intact for a caller that passes a real id.

The URL travels as a URL. Resolving it to an id meant a `SELECT` against the
registry on every logged request, to look up a value that never changes; the
drain does it instead — a long-running process, whose memory of what it has
resolved is worth far more than a per-request one. A bounded cache, because a
worker runs for days and a site can generate URLs without limit.

`WriteSpool::transform()` is the general form of that: a buffered row can be
cheaper to produce than the row the table wants, and the difference is made up
where there is time.

**The token row is not rewritten on every request.** Logging a request called
`save()`, which UPDATEs every column of `usertokens` — the token itself, the
device description, the scope — in order to move `lastused` forward and add one
to `actions`. Neither needs to be accurate to the second, so it is written once
a minute. A new address or a new device writes immediately regardless: those are
what somebody investigating a stolen token looks at, and delaying them to save a
write would be saving the wrong thing.

**Prepared statements appear in the query log, with their values.** They were
absent from it entirely — which is most of what an application runs, since
everything the query builder produces goes through that path. The ones that did
appear showed their template: `WHERE userid = %i`, with no way to see which user
or paste the statement into a client.
