---
use_cases:
  - Checking what a running installation is actually doing
  - Finding out why a cache read misses or a value looks stale
  - Seeing who is signed in and from where
  - Diagnosing an empty or wrong panel in the developer dashboard
  - Adding an application-specific panel to the developer dashboard
  - Reading the log while developing, without opening the administration area
  - Restricting the developer tools to named people rather than a usertype
---

# DevPanel Guide

A server-rendered dashboard for a running installation, mounted at `/devpanel`. It is
deliberately independent of the application's theme and document system: it emits its own
self-contained HTML page, so it looks and behaves the same in every project and keeps
working when the thing you are debugging is the theme.

Served from an administration area or a secondary application — `/admin/devpanel`,
`/admin/adminer` — every link the panel, Adminer and the toolbar grant build stays there:
tabs, Adminer's stylesheet and scripts, the toolbar switch. They take their base from
`AdminArea::servingUrl()` (see the Routing Guide), not from `sURL`.

It answers questions about **this installation, right now** — what the database holds, what
is in the cache, who is signed in, what is queued, what has not migrated. For per-request
timing and queries, use the [debug toolbar](Pramnos_Debug_Toolbar_Usage.md) instead; the two
are complementary and neither replaces the other.

## Enabling it

```php
// app.php
'features' => ['devpanel', 'cache', 'queue'],
```

Access requires the `devpanel` feature enabled, and then **either** route in:

| Route | What it needs |
|---|---|
| Development | a **development environment** — `APP_DEBUG=1` in `.env`, or the `DEVELOPMENT` constant — **and** a signed-in user of the `root` role (99 unless `roles` says), or a policy callback that says yes |
| Any deployment | a signed-in user named in `app.php`: the `root` role, or listed in `usertypes`, or listed in `userids` |

The routes are alternatives, and the second is checked first. A policy callback replaces the
development route's floor only, so it cannot refuse somebody the second route admits: root
opens the panel whatever the callback says. To keep an account out, narrow the lists, for
example by raising `production_min_usertype` out of reach and naming people in `userids`.

Every floor below defaults to the **`root` role** — `'roles' => ['root' => 99]`, see
[Moving the lines](Pramnos_Auth_Users_Guide.md#moving-the-lines-roles) — so moving root moves
all three tools. A key set here wins over the role:

```php
'devpanel' => [
    'mount'                   => 'devpanel',  // path to mount on
    'min_usertype'            => 99,          // the development floor
    'production_min_usertype' => 99,          // opens it on any deployment
    'usertypes'               => [95],        // these types too, wherever they are
    'userids'                 => [7],         // and these people, whatever their type

    // Adminer, at /adminer. Unset lists fall back to the panel's two above.
    'adminer_min_usertype'    => 99,
    'adminer_usertypes'       => [],
    'adminer_userids'         => [],
],
'debug' => [
    // The toolbar grant, at /debugbar. Same fallback.
    'grant_min_usertype'      => 99,
    'grant_usertypes'         => [],
    'grant_userids'           => [],
],
```

All three read the same three ideas — see
[All three developer tools read the same names](#all-three-developer-tools-read-the-same-names).

### Opening it on a server that is not a development one

`APP_DEBUG` is the wrong switch for "let me see the DevPanel here". It also turns on error
display, changes what a page carries and opens the debug toolbar for every visitor — a much
larger statement than the one anybody means to make to reach one screen.

So the environment is not the only key. The second is a **name in `app.php`**: versioned,
reviewed, and visible in the deployment rather than in a table. Which is the arrangement
[Adminer](#adminer-at-adminer) already had, in the more dangerous of the two tools — an owner
could open a full database client on a live server and *not* the panel whose tab links to it.

**The three ways in widen; they never narrow.** Any one of them suffices. To restrict the
panel to named people and nobody else, raise `production_min_usertype` out of reach and list
the ids:

```php
'devpanel' => ['production_min_usertype' => 100, 'userids' => [7]],
```

Any value above every usertype the installation uses works for `production_min_usertype`.
Keep the *development* floor, `min_usertype`, inside 1..100:
`DevPanelServiceProvider::bootHttp()` logs a warning on every request when it is outside that
range.

Every opening outside a development environment is written to the `auth` log with the user,
their type and the address. On a development machine the environment is the trace; on a live
one nothing else would be.

### All three developer tools read the same names

`/devpanel`, `/adminer` and `/debugbar` are three routes with three gates, and each of them
can name a person, not only a usertype floor.

**A usertype is a role, and on a real installation it is a role granted to other
organisations.** Several organisations can each hold usertype-99 accounts, and every one of
those would be able to open a full database client against a live database; every account at
90 or above could issue itself a toolbar grant carrying the query log of a live request. A
floor alone cannot say *this account and nobody else*, so off would be the only state narrower
than "everybody at that type".

Every gate reads the same three ideas through
`Pramnos\Application\DeveloperAccess`, and each tool has its own lists that **fall back to
the panel's**:

| | floor | usertypes | userids | default floor |
|---|---|---|---|---|
| `/devpanel` | `devpanel.production_min_usertype` | `devpanel.usertypes` | `devpanel.userids` | 99 |
| `/adminer` | `devpanel.adminer_min_usertype` | `devpanel.adminer_usertypes` | `devpanel.adminer_userids` | 99 |
| `/debugbar` | `debug.grant_min_usertype` | `debug.grant_usertypes` | `debug.grant_userids` | 99 |

One line gives one person everything:

```php
'devpanel' => ['production_min_usertype' => 100, 'adminer_min_usertype' => 100, 'userids' => [7]],
'debug'    => ['grant_min_usertype' => 100],
```

`devpanel.userids` reaches all three, so there is no third list to forget. A tool that names
its own people uses those instead — which is how Adminer is made narrower than the panel:

```php
'devpanel' => [
    'userids'         => [7, 8],   // the panel, for two people
    'adminer_userids' => [7],      // the database client, for one
],
```

**The fallback is one-way.** Naming somebody for Adminer does not give them the panel, which
browses the database, reads the cache and dumps the container.

Every opening of any of the three outside a development environment goes to the `auth` log,
naming the tool.

!!! note "Developer means root"
    The three tools share one floor, the `root` role, because whoever may use them is whoever
    owns the deployment. An installation that wants administrators to issue themselves the
    toolbar grant, which carries one request's queries, session keys and logs for a bounded
    time, lowers `grant_min_usertype` or names them in `grant_usertypes` / `grant_userids`.

### There is no setting that opens this panel

Not `debug`, not `development`, not a checkbox on `/admin/Settings`. The environment is the
only lock, and that is deliberate: this panel browses the database, reads the cache and dumps
the container. A row in the settings table makes that reachable by anybody who can open the
administration area, on a live server, with no deploy and nothing in the repository to say it
happened.

`devpanel.mount` and `devpanel.min_usertype` are read from `app.php` only, for the same
reason — where a developer tool is mounted and who may open it are properties of the
deployment. A `devpanel.*` row in the settings table is ignored.

There is no row anybody can tick to open this. The alternative to `APP_DEBUG=1` is a name in
`app.php`, which is also a deploy, and a narrower one than turning the whole deployment into a
development environment.

For reading a live production request rather than opening the panel, the
[debug toolbar](Pramnos_Debug_Toolbar_Usage.md) is the smaller tool: a signed one-browser
grant from `debug:token` or `/debugbar`, which expires on its own.

### Reaching it while the site is in maintenance

Maintenance keeps traffic off a schema in flux — and the person who raised it is usually the
one who needs to *look* at that schema. So `/adminer` is exempt by default:

```php
'maintenance' => ['exempt' => ['adminer', 'devpanel']],   // naming replaces the default
```

The exemption grants exactly one thing: that the application **boots** for that path. Every
route's own authorisation still runs — for Adminer that is signed in plus its floor (99) or
one of its named lists, or a development environment — so anybody else gets the same 404 they would get with the site
up, and it is logged. Auto-migrations stay held off by the flag either way, so opening this
cannot start the thing maintenance was raised to prevent.

## Adminer, at `/adminer`

> **Sessions.** This route hands its session over completely before Adminer boots: closed,
> `$_SESSION` emptied, and the session id set to **Adminer's own**, from its `adminer_sid`
> cookie. Two applications, two session files, and each keeps its own CSRF token.
>
> There is no third state to leave the id in. `session_write_close()` leaves `session_id()`
> answering, so doing nothing hands Adminer *our* session — one file for both, each
> destroying the other's `token`. And `session_id('')` does not mean *read the cookie*: it
> means the id is set, to nothing, and PHP never consults the cookie again, so Adminer gets
> a new session on every request and the token it just issued is never read back. Both
> answer *«Invalid CSRF token»* on every `POST` while navigation keeps working, because
> navigation is `GET` and Adminer does not check it.
>
> A cookie naming *this* session is refused and dropped: honouring it would put both
> applications back in one session file. So is anything that is not a session id.


Adminer is the database tool most people already use, and the usual way to have it on a server
is a PHP file dropped in the web root: a URL anybody can guess, protected by whatever the
database password happens to be, and forgotten after the afternoon it was needed.

```
composer require vrana/adminer
```

That is all. The framework serves it at `/adminer`, and the Database tab links to it when the
package is present.

**Who may open it**, either:

- **usertype ≥ 99** — `Root` in `UserTypes::DEFAULTS`, or the installation's
  `devpanel.adminer_min_usertype` — **or listed** in `devpanel.adminer_usertypes` /
  `devpanel.adminer_userids` (which fall back to the panel's `usertypes` / `userids`), on any
  deployment **including production**, and with or without the `devpanel` feature. That half
  is deliberate: fixing data on a live server is a real thing an owner does, and a tool that
  only works in development means they do it in `psql` with no undo, or leave `adminer.php` in
  the web root for ever.
- **a development environment**, subject to this panel's own usertype floor.

Anything else gets a **404**, not a 403. A 403 confirms the route exists, and this is the one
URL on the site where that is worth withholding.

**Adminer is logged in with the application's own connection by default** — asking an
operator to retype the production database password is asking them to keep it somewhere they
can copy it from. That makes "may this browser reach this URL" the only thing between somebody
and every row, and the accounts that can reach it are exactly the ones whose sessions are worth
stealing. For two locks instead of one, turn it off and Adminer shows its own login form:

```php
'devpanel' => ['adminer_autologin' => false],
```

**A `suggest`, not a `require`.** A framework that shipped a database browser into every
application's `vendor/` would enlarge the attack surface of applications that never asked for
one, including the ones that do not read what a release added. With the package absent the route
answers 404 like any other unknown address.

### The two clauses of the gate

Both must hold for an account the first check does not admit:

```php
// Floor (99, or adminer_min_usertype), adminer_usertypes, adminer_userids —
// the lists falling back to the panel's. Anywhere, devpanel or not.
if (DeveloperAccess::permits(DeveloperAccess::ADMINER, static::rootFloor())) {
    return true;
}

if (!Application::isDeveloperEnvironment()) {
    return false;
}

if (!FeatureRegistry::isEnabled('devpanel')) {    // the floor below belongs to that panel
    return false;
}

return $usertype >= DevPanelController::developerFloor();   // min_usertype, else root
```

The third clause reads as belt-and-braces and is not: the floor it is about to apply belongs to
the DevPanel, and a floor configured for a panel the installation does not have is a number with
nothing behind it. Letting an account through on the strength of it would be a gate
configured by accident.

And **signed in means both**: `User::getCurrentUser()` and `Session::staticIsLogged()` are
independent questions, and this route needs yes to each. So an API-authenticated request — a
sealed identity with no session — is refused, deliberately: this is a browser tool, and a bearer
token is not the credential it asks for.

### What this route hands Adminer, and what it takes away first

Three of these are load-bearing and none of them is visible in the page that comes back.

**`$_POST['auth']` and `$_POST['logout']` are removed before the include.** Adminer's
`auth.inc.php` acts on `$_POST['auth']` — driver, server, username, password, database — before
anything else. Removing its login form took away the *page* that submits that, not the ability to
submit it: a hand-made POST, or a form on another site aimed at this URL, would have logged this
Adminer into any host reachable from the server with any credentials the sender knew. The gate on this
route is permission to read *this* database, not a general-purpose database client. `permanent` goes
with it — the field that asks Adminer to write an encrypted copy of the password into a cookie.

**The session is closed, emptied, and replaced with Adminer's.** `session_write_close()` writes the
data and releases the handle; `$_SESSION` keeps its contents in memory. Adminer starts a session of
its own only when none is active, and when it decides not to it reads and writes our keys. One of them
is `token`: Adminer's CSRF token is `rand() ^ $_SESSION["token"]`, this framework's value is a hex
string, and the result is «A non-numeric value encountered» twice per page and a CSRF check that
cannot work.

The id is then set to what the `adminer_sid` cookie names, so Adminer resumes the session it wrote
last time. `handOverSession()` also removes `pwds` from this session on the way out — the slot
Adminer stores a database password in, in cleartext, which it could only have written there while the
two sessions were one.

**The URL rewrite is an output-buffer callback, not code after the include.** Adminer is a script and
several of its paths end with `exit`, the login page among them — so code after the include would not
run, PHP would flush the buffer at shutdown, and the page would go out with Adminer's own
`./static/default.css` links, which resolve to `/static/…` under `/adminer`: no stylesheet. A callback is
invoked by the buffer's own flush, `exit` included.

If you override `serveAdminer()`, all three come with it.

### The bar above Adminer's page

Adminer is a whole application and every link it draws stays inside itself — it has no idea this
route exists. So the chrome is injected: the panel's tab strip with Adminer marked active, the
site's name, and a Back link, because a visitor who opened it directly otherwise has the browser
button and nothing else.

Two details that are decisions rather than styling:

- **`position: fixed`, not `sticky`.** Adminer's pages are wider than the viewport whenever a table
  has many columns, so the page scrolls sideways — and `sticky` pins only the vertical axis, taking
  Back off the screen exactly when somebody is lost in a wide table.
- **Anything without a `<body>` is returned untouched.** Adminer answers things that are not pages:
  a redirect, a download, a fragment for its own JavaScript. Injecting a `div` and a stylesheet into
  any of those corrupts the response, so the injection is guarded on finding a body tag rather than
  guessing.

The site name comes from a setting an operator typed and is escaped on the way in.

### What locks it, on a public server

The gate on the URL *is* the authorisation — the connection is supplied, so reaching the page is
reaching the database. That makes the list of what holds it worth reading in full:

| | |
| --- | --- |
| **Who** | signed in, and usertype ≥ 99 (`Root`) — or a development environment plus the DevPanel's floor. Everybody else gets a **404**, not a 403 |
| **Credentials** | come from the configuration (`adminer_autologin`, on by default), and a posted login is discarded. With autologin off, the operator signs in with their own username and password — on the application's server and driver, whatever the form names, and never with a password kept in a cookie |
| **Which connections** | the primary and the declared replicas (`database.read` / `database.write`). A request naming any other host, user or database gets the default rather than what it asked for |
| **Audit** | every open **and every refusal** is logged to `auth` with the account, the address and the URL |
| **Headers** | own CSP with `frame-ancestors 'none'`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex` |
| **Session** | ours is closed and emptied before Adminer runs; Adminer gets its own `adminer_sid` namespace |
| **Which files it will send** | only `static/…` or `externals/…` ending in `.css`, `.js`, `.png`, `.gif`, `.svg`, `.woff`, `.woff2` — matched against the whitelist **before** any filesystem call, then resolved with `realpath()` and required to be inside the package. A `.php` under `static/` is refused, which is the case worth naming: nothing here would execute it, but it would be read out verbatim |
| **What its links carry** | the connection is stripped from every `href` and `action` the page contains — `server`, `pgsql`, `sqlite`, `mssql`, `oracle`, `mongo`, `elastic`, `username`, `db`. Left in, every link on the page would name this installation's database host and account, in the address bar, in the browser history, and in a `Referer` on the way to adminer.org. Absolute URLs are untouched: those are Adminer's own outbound links |
| **No package** | 404, like any other unknown address |

Two of those are worth the reasoning. **Removing a login form does not remove the ability to
log in**: `auth.inc.php` acts on `$_POST['auth']` before anything else, so a hand-made POST — or a
form on another site aimed at this URL — would point this Adminer at any host reachable from the
server, with any credentials the sender knew. And Adminer's own design lets **the query string
say which server to connect to**; behind a single-purpose gate it must not, so any other host,
user or database is replaced by the default.

What this does **not** protect against is somebody who already has a root account's session. If
that is a concern, keep `adminer_autologin` off, and the operator types the database password —
two locks instead of one.

### The session repair

Adminer starts a session only when none is active. One of the keys it uses is `token`: ours is a
hex string and its is `rand() ^ $_SESSION["token"]`, which gives «A non-numeric value encountered»
twice a page and a CSRF check that cannot work.

Handing over the session prevents that for a new visitor; it does nothing for an `adminer_sid`
session that already holds a non-numeric `token`. So `AdminerBridge::repairSession()` removes it,
rather than only preventing it: the alternative is telling people to clear their cookies, which is
what software says when it cannot fix itself.

It is deliberately narrow. Only `token`, and only when it is not numeric — everything else in
there is Adminer's. Only when no session is already open, because starting a second one over a live
session would take the visitor's own with it. Only for a cookie shaped like a session id, because
that value is under the visitor's control and `session_id()` rejects anything else noisily.

And it restores what it changed: the session name, and `session.use_cookies`.
`session_start($options)` applies its options as ini settings **for the rest of the request**, so
without the restore Adminer's own `session_set_cookie_params()` would warn «Session cookies cannot be
used when session.use_cookies is disabled» at the top of every page.

Two details worth knowing:

- Its assets live in `vendor/`, which no web root serves, and it links them as
  `./static/default.css`. The output is rewritten to `?file=…` and those requests are served
  from the package — the same trick Adminer's own single-file build uses. Paths are whitelisted
  and resolved, because a whitelist that allows dots is one somebody gets through.
- The route sends its own **CSP**. The site's policy is nonce-based and Adminer is full of
  inline `onclick` handlers that a nonce policy blocks whatever the nonces say; the relaxation
  applies to this URL and nothing else.

## The tabs

### Overview

Runtime facts: PHP and framework version, memory, host uptime, load and RAM, database
driver and version, git HEAD, migrations, background work, and the queue.

**Migrations** counts every migration file the loader resolves — the same directories
`migrate:status` uses, which is `app/Migrations` plus each framework feature directory —
against the `schemaversion` history. A migration counts as applied only when its history row
has `result = 1`, so a failed attempt shows as pending, which is what it is. Auto-migration
fingerprint rows (`__fw_auto_*`) are bookkeeping and are skipped.

**Background work** is the one to read when another panel looks empty:

| Row | Meaning |
| --- | --- |
| Write spool | Rows buffered out of the request path by [`WriteSpool`](Pramnos_Database_API_Guide.md), waiting for a drain — and which backend holds them. |
| Scheduled tasks | How many tasks `Scheduler` knows about, framework and application together. |

A non-zero spool with nothing draining it is the normal cause of an empty Performance tab.
The framework schedules `spool:drain` every minute in `FrameworkSchedule`, but **a schedule
only runs when something runs `schedule:run`** — a cron entry, or a supervised daemon. An
installation whose daemons are all application workers has no scheduler, and the rows stay
in the spool for ever.

### Database

**More than `/admin/dashboard/database`, not a smaller copy of it.** That screen answers an
operator's questions — how big, how busy, how far behind. This one answers the questions
somebody changing the schema has.

**The administration screen's sections, in its order** — overview, processes, replication,
tables, views, TimescaleDB — and then the three this tab adds.

| Section | Answers |
| --- | --- |
| This database | version, size, connections, transactions, cache-hit ratio |
| Active Processes | what it is doing now, with **Copy** on the query and **Kill** on the row |
| Replication Status | connected standbys and their lag |
| Table Sizes | what is in here and how big, with each name linking into Adminer |
| Public Schema Views | the definitions, moved here from the administration screen |
| TimescaleDB | hypertables, chunks, compression, jobs, aggregates, **and the job error history** |
| Indexes nothing uses | which indexes cost a write on every insert and buy nothing |
| Read the hard way | which tables are scanned sequentially, and how many rows that costs |
| Statements | queries per second since the last reset, per database and role, the top statements, and who is connected now per application |

All of it comes from the shared `DatabaseInspector`, the same one the administration screen
uses, so the two screens cannot disagree about a table's size.

**Kill ends a backend** — `pg_terminate_backend`, not `pg_cancel_backend`: cancel asks a query
to stop and a backend stuck in a lock wait ignores it, which is exactly the backend somebody is
trying to end. It asks first, because the connection dies with the query. It is here rather than
on the administration screen deliberately: ending somebody's query is a developer's action
against a development database, and this panel is already behind a development-mode lock and a
usertype floor.

**A wait is only shown when it is a problem.** Every idle PostgreSQL backend sits on
`Client/ClientRead` — waiting for the application to send the next statement, which is what an
idle pooled connection is *for*. Rendered as a warning on every row it says the database is in
trouble when nothing is wrong. Only `Lock`, `LWLock`, `BufferPin` and `IO` are shown, and only
for a backend that is running something.

**The job error history is rendered even when nothing has failed.** Hidden until there is
something to show, it is a section nobody can find — and "no job has failed" is an answer
somebody came here for as often as the list is. Same for replication on a standalone instance.

**Active processes distinguishes running from idle.** `active_sec` is the running query's own
age and is null unless the backend is running one; `idle_sec` is how long a pooled connection
has been sitting there. Reported as one number, an idle pooled connection would read as a query
running for hours, and a column that cries wolf is a column nobody reads.

**Unused indexes exclude primary keys and unique constraints.** They are not there to be
scanned — they are there to make a duplicate impossible — so listing them as dead weight is
telling somebody to drop the thing holding their data together.

**Statements** come from `pg_stat_statements`, which keeps counters across the whole server
from its last reset, and answers four questions:

- **How much.** Calls, execution time, WAL written and calls per second since
  `stats_reset` (from `pg_stat_statements_info`). A non-zero *evicted* count means
  `pg_stat_statements.max` is too small for the number of distinct statements, so the oldest
  statements are being dropped.
- **Whose.** Calls, calls per second, time and WAL per database and role.
- **Which.** The top 25 statements with calls, total and mean time, rows, the shared-buffer
  hit ratio and WAL bytes. Click a column header to sort by it (`?statements_sort=total`,
  `calls`, `mean`, `rows`, `read`, `wal`). Sorting by *Hit %* puts first the statements that
  read the most blocks from disk. The list is ordered by total time unless another column is
  chosen: a query taking two milliseconds four million times is the one to fix, and a list
  ordered by mean never shows it.
- **Who, right now.** Connections per `application_name`, role and database, and how many of
  them are active or idle in a transaction. This comes from `pg_stat_activity`, because
  `pg_stat_statements` does not record `application_name`. It is a live count, not history.

**Reset statistics** calls `pg_stat_statements_reset()` after a confirmation and a CSRF check.
It zeroes the counters for every database on the server. By default only a superuser may run
it, and a refusal is shown on the page.

The page names the reason when the extension is missing, because each reason is fixed
somewhere else:

| What the page says | Fix |
| --- | --- |
| not loaded | add `pg_stat_statements` to `shared_preload_libraries` (beside `timescaledb`) and restart the server |
| loaded but not created | `CREATE EXTENSION pg_stat_statements;` in the database |
| not created, and this role cannot read `shared_preload_libraries` | both of the above. Reading the setting takes `pg_read_all_settings`, which an application role usually lacks. Once the extension exists, the page checks by reading it instead |
| no `pg_read_all_stats` | `GRANT pg_read_all_stats TO <role>;` (or `pg_monitor`). Without it the counters still include every role, but other roles' statement text reads `<insufficient privilege>`, and their connections never count as active |

The summary and the per-role split read `pg_stat_statements(false)`, which skips the
query-text file. Only the top list loads statement text, and it is limited to 25 rows. On MySQL
the section is not shown.

**TimescaleDB chunks are not listed as tables.** A hypertable's storage lives in
`_timescaledb_internal` as one table per chunk — `_hyper_7_15_chunk` and forty like it — and
they are not tables anybody put anything in. Listed, they crowd out the tables somebody was
looking for and double-count storage the hypertable already reports. They are counted in the
TimescaleDB section instead — in the shared inspector, so the administration screen does not
list them either.

**The jobs are on the same screen as the hypertables.** A hypertable whose compression policy
has been failing for a week looks perfectly healthy from the hypertable list alone.

The index and statement sections are PostgreSQL only. MySQL can say an index exists; it cannot
say whether anything has ever used it.

### Cache

Adapter, item count and namespaces, a paginated item browser (first 100), a namespace
filter, per-item **Inspect**, and a flush button.

The adapter named here is the store the instance actually ended up with — see
[the cache guide](Pramnos_Cache_Guide.md#fallback-strategy) for what happens when the
configured backend cannot be reached. If this says `file` on an installation configured for
Redis, that is a fallback and the log will have a warning saying so.

The browser lists **everything in the store**, including keys written by other components —
sessions, queue payloads, anything else sharing the same Redis instance. Those are shown as
type `raw`: they are not this cache's envelopes and are not decoded.

### Users

Sessions and login security.

**Sessions are limited to a recency window** — last used within 1h / 6h / 24h (default) /
7d / 30d, or `All`. The window is not cosmetic. A `web_session` token is created per login,
so without one the panel lists every login the installation has ever had. If the `All` count
is large and the 1h count is one, that is not a busy server, it is an accumulating table.

**A web session is bounded by the PHP session it belongs to, whatever the window says.** It
is accepted through `$_SESSION['usertoken']`, so once PHP has expired the session —
`session.gc_maxlifetime`, 24 minutes out of the box — the row cannot be used by the browser
that owns it. A login from this morning is not a session, it is a row. API tokens (`auth`,
`access_token`) have no such bound and use the window you selected. `All` lifts both.

Two tables: **Sessions by User**, which is the summary to read first (who, which token type,
how many, last seen), and the 50 most recently used sessions in detail. The count line says
how many there are in total, so a capped list is never mistaken for a complete one.

A session is listed when its token has `status = 1` and an expiry that has not passed — the
same test `User::loadByToken()` applies when deciding whether a token still works — and its
type is one of `web_session`, `auth` or `access_token`.

Click a token for its request history; click a user for their audit log. Both are paginated.

**Login Lockouts** lists identifiers currently locked out, with attempt counts and the
lockout expiry.

### Performance

Slowest endpoints and slowest users/applications over a selectable window, read from
`tokenactions`. An endpoint is shown by URL, resolved through the `urls` table.

**Only calls that were timed appear here.** A row with no duration has nothing to say about
speed, and including it would do worse than say nothing: `ORDER BY avg_ms DESC` puts NULLs
*first* on PostgreSQL, so unmeasured rows would fill the top of the list as `0.0 ms` and push
the real measurements off it. On an installation with old, untimed history that can be most of
the table. When rows exist but none are timed, the panel says that instead.

`tokenactions` rows are written through the write spool, so this panel shows nothing until
something drains it. When the table is empty the panel says so — and says how many rows are
waiting in the spool — rather than reporting "no data for this period", which is a different
problem with a different fix.

### MCP

Every registered MCP tool, each one's schema rendered **as a form**, and the answer on the
page. It is here because `mcp:serve` cannot be watched: it speaks JSON-RPC on stdio and, under
a real client, does not own its own pipes.

```
Tools
  ▸ log-analytics
      Summarise this installation's logs: entry trend, counts per level, …
      timespan [1h ▾]   files [comma separated]
      [ Call ]  ☐ show the JSON-RPC envelope              41 ms
      // sent {"timespan":"1h"}
      { "trends": { … }, "levels": { … } }
```

Four things it is careful about, each of them a wrong answer avoided:

- **Every field can be left out.** An omitted argument and an empty string are different
  things — a tool with a default gets to keep it — so each control carries an explicit
  `— omit —`, and a boolean is a tri-state select rather than a checkbox, because an
  unchecked box cannot express "leave it out".
- **The arguments actually sent are printed above the answer.** `{"limit": "5"}` and
  `{"limit": 5}` are different calls, and a schema that rejected the first is otherwise a
  mystery.
- **A tool that threw is shown as a failure.** The protocol reports it as a *successful*
  response whose content happens to be the exception message, so without that it reads as
  the result.
- **The call goes through `McpServer::dispatch()`**, the same method the stdio loop calls. A
  tool that works when invoked directly and fails through the protocol is a real bug, and
  only the envelope shows it — the checkbox prints it.

The tab builds its own server when the container has none, so it works with the `mcp` feature
off, and says so: what the feature adds is the container binding that an application's *own*
tools are registered into. The POST that runs a tool carries a CSRF token, because the panel's
other endpoints read and this one executes whatever a project registered.

The traffic log row says whether `mcp:serve --log` has ever run, and links `mcp.log` into the
log viewer. The panel cannot switch it on: that log belongs to the server process the client
started, which is not this one. See the
[MCP guide](Pramnos_MCP_Guide.md) for `mcp:call` and `--log`.

### Logs

`/devpanel/logs` — **the administration area's log viewer**, served here.

One viewer, not two: pagination, reverse order, follow, per-level filtering and the rest are
the `LogController` component's, and the panel embeds it. `renderViewer()` takes the base URL
its `raw` endpoint is built from, which is what lets the same component be served from more than
one address:

```php
(new LogViewer($files, $controller))->renderViewer($file, $base);
```

so the panel serves the same component from its own address, behind its own guard — a signed
debug grant rather than an admin session, which is the whole reason to read a log from here.
`/devpanel/raw` is this panel's copy of the endpoint the frame loads from, guarded the same way.

**What the panel keeps of its own** is the part the administration screen has nothing like: the
requests that failed, grouped by request id. That section is short on purpose and empty on a
server nobody is debugging — lines carry a request id only while the debug toolbar is active for
that visitor, because on a live server everybody else is logging into the same seconds and their
lines are not a developer's to read.

**What it does not reproduce** is linked instead: statistics, cross-file search, filter and
export stay in `/admin/Logs`, and the panel says plainly that they need an admin session.
A second copy of each would drift from the first.

**A file name from the URL is a choice, never a path.** It is compared against the names
actually on disk rather than joined to the log directory, so there is nothing to get subtly
wrong about how many `..` a path can contain.

`/devpanel/logs?request=<id>` still answers JSON, which is what the debug toolbar asks for. The
same address serves both because the toolbar always passes an id and a person never does, so a
person opening `/devpanel/logs` gets the viewer rather than an error about a parameter they
could not know existed.

### Git, PHP Info

HEAD commit, branches and remotes; and `phpinfo()` for admins.

## Adding your own panel

```php
\Pramnos\DevPanel\DevPanelController::registerPanel(
    'billing',
    'Billing',
    fn(): string => '<p>Anything you can render as HTML.</p>'
);
```

Register it from a service provider. The slug becomes a route (`/devpanel/billing`) and a
tab, and inherits the same access guard.

## When a panel looks empty

Empty is an answer, and often enough the wrong one to be worth a section.

- **A section that could not load says so.** Failures are rendered as a warning above the
  panel, and logged to the `devpanel` log. A panel that shows an empty table and no warning
  really did find nothing.
- **Check the Background Work card** before concluding a table is empty: rows may be spooled.
- **Check the window** on Users and Performance — both default to a bounded period.
- **Check the cache adapter** on the Cache tab: an unexpected `file` means a fallback, and a
  fallback means the data you are looking for is in another store.
