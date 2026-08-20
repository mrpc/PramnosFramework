---
date: 2026-08-20
categories: [Changelog]
---

# A dashboard that reported empty

Four panels of the DevPanel were blank on a working installation, each for its own reason,
and every one of them looked identical from the browser: a table with "No data". Reported
from a live PostgreSQL project — users, performance and half the overview showing nothing
while the database held hundreds of rows.

<!-- more -->

## Fixed

**The Migrations card read "— / — / —" everywhere.** It constructed
`\Pramnos\Database\Migrations\MigrationLoader` and `…\Migrations\MigrationRunner`. There is
no `Migrations` namespace — both classes live in `\Pramnos\Database\` — so the line threw
`Class not found` into a `catch (\Throwable)` written for a missing history table. It now
resolves the same directories `migrate:status` does, counts a migration as applied only when
its history row says `result = 1`, and skips the `__fw_auto_*` fingerprint rows, which carry
no batch, sort last, and were being reported as "last applied".

**The Database tab listed no tables on PostgreSQL.** `pg_size_pretty(pg_total_relation_size(oid))`
over `pg_class JOIN pg_namespace` — where both relations have an `oid` — is
`column reference "oid" is ambiguous`, and `n_live_tup` is not a `pg_class` column at all.
Qualified, and joined to `pg_stat_user_tables` for the row counts. The MySQL branch bound its
schema name to a `?`, which the framework's prepared statements do not use; it is `%s` now.
Sizes are also no longer printed as "552 kB KB".

**The Users tab listed no sessions.** The panel is headed "Active Sessions (web + API)" and
filtered on `['auth', 'access_token']` — the two API types. A browser login is a
`web_session`, so on any application that is not itself an API the panel was empty while the
table held hundreds of rows. Measured on the reporting installation: 316 tokens, all
`web_session`, all excluded.

**The Performance tab threw on every request.** Both queries used
`servertime >= NOW() - INTERVAL 24 HOUR` — MySQL's interval syntax, which PostgreSQL rejects,
comparing a timestamp to a column that holds a unix integer in every dialect. They also
joined `#PREFIX#tokenactions` to a bare `usertokens`, `users` and `applications`, so a
prefixed installation named tables that do not exist. Both are query-builder now, over an
epoch window, with every table prefixed, and the endpoint resolved to its URL through the
`urls` table instead of printing a row id under a heading that says URL.

**Timestamps are rendered as timestamps.** `lastused`, `servertime` and `userlog.date` are
unix integers, and three panels printed them raw. Empty ones render as an em-dash rather
than as 1970.

**Sub-panels went through the query builder**, so token history and user logs are no longer
half-prefixed hand-built SQL.

## Added

**A failed section says so.** `panelError()` wrote to a log file and nothing else, so a
failed query and an empty table were the same page — which is how four broken queries
survived. Failures now render as a warning above the panel, and the rest of the panel still
renders.

**Sessions have a time limit.** A `web_session` token is minted per login and carries no
expiry, so "active" meant "every login ever made": one installation had 342, all for one
user. The panel now defaults to sessions used in the last 24 hours, with 1h / 6h / 24h / 7d /
30d / All, and leads with a per-user summary — who, which token type, how many, last seen —
so a truncated list of 50 identical rows is no longer the whole answer. The count line says
how many there are in total.

**Background Work card**, on the overview: rows waiting in the write spool and the backend
holding them, plus how many scheduled tasks are defined. `tokenactions` is written through
the spool, so an installation that never runs `schedule:run` accumulates rows in a file while
every panel reading the drained table shows "no data" — two facts that were impossible to
connect from the dashboard. The Performance tab now names the spool count in its empty state
too.

**The cache item Inspect button opens where you can see it.** It was an ordinary `div` under
a table of up to 100 rows, so it opened below the fold and clicking Inspect looked like
nothing happening. It is a fixed overlay with a close button, and a response that is not JSON
— a session that expired mid-page, most often — now says so instead of surfacing a parser
error.

**The Redis item browser tolerates keys it did not write.** A Redis instance is shared with
everything else the application keeps there; `getAllItems()` ran every value through
`unserialize()`, which raises a *warning* rather than throwing, so the `catch (\Exception)`
never fired and `Warning: unserialize(): Error at offset 0` was printed into the page whose
job is to show the cache. Foreign values are listed as type `raw`. `load()` had the same
unguarded call, and then read `['data']` from its `false` result.

## Added — guard

`tests/Unit/Framework/MissingClassReferenceTest.php`: no file under `src/` names a
fully-qualified `\Pramnos\…` class the autoloader cannot resolve. It is
`LegacyClassReferenceTest` in modern clothes — that one catches the CMS-era
`pramnos_theme::getTheme()`; this catches the same mistake made with a namespace that looks
entirely plausible, which is what the migrations card was. Review does not catch it: the name
is well-formed, the file it should be in exists, and the class it should name exists one
segment away.

## Documentation

`Pramnos_DevPanel_Guide.md` — a page of its own, wired into the nav: what each tab reads,
what the session window means and why it exists, and a section on what to check when a panel
looks empty.
