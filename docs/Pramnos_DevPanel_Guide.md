---
use_cases:
  - Checking what a running installation is actually doing
  - Finding out why a cache read misses or a value looks stale
  - Seeing who is signed in and from where
  - Diagnosing an empty or wrong panel in the developer dashboard
  - Adding an application-specific panel to the developer dashboard
  - Reading the log while developing, without opening the administration area
---

# DevPanel Guide

A server-rendered dashboard for a running installation, mounted at `/devpanel`. It is
deliberately independent of the application's theme and document system: it emits its own
self-contained HTML page, so it looks and behaves the same in every project and keeps
working when the thing you are debugging is the theme.

It answers questions about **this installation, right now** — what the database holds, what
is in the cache, who is signed in, what is queued, what has not migrated. For per-request
timing and queries, use the [debug toolbar](Pramnos_Debug_Toolbar_Usage.md) instead; the two
are complementary and neither replaces the other.

## Enabling it

```php
// app.php
'features' => ['devpanel', 'cache', 'queue'],
```

Access requires **all** of:

- the `devpanel` feature enabled;
- a **development environment** — `APP_DEBUG=1` in `.env`, or the `DEVELOPMENT` constant;
- a signed-in user with `usertype >= 90`, or a policy callback that says yes.

Two optional keys, in `app.php` beside the feature list:

```php
'devpanel' => [
    'mount'        => 'devpanel',  // path to mount on
    'min_usertype' => 90,          // raise the floor above 90
],
```

### There is no setting that opens this panel

Not `debug`, not `development`, not a checkbox on `/admin/Settings`. The environment is the
only lock, and that is deliberate: this panel browses the database, reads the cache and dumps
the container. A row in the settings table makes that reachable by anybody who can open the
administration area, on a live server, with no deploy and nothing in the repository to say it
happened.

`devpanel.mount` and `devpanel.min_usertype` used to be **settings** too, with fields on that
screen. They read from `app.php` now, for the same reason — where a developer tool is mounted
and who may open it are properties of the deployment. (The fields never worked, as it
happens: PHP replaces the `.` in a form field name with `_`, so both inputs posted under a
name the controller never asked for, and every save wrote the default back. An installation
that thought it had moved the mount point had not.)

If you need the panel on a server that is not a development one, the answer is a deploy —
`APP_DEBUG=1` and a restart — not a switch. For reading a live production request instead,
use the [debug toolbar](Pramnos_Debug_Toolbar_Usage.md) with a signed one-browser token from
`debug:token`, which expires on its own.

## Adminer, at `/adminer`

Adminer is the database tool most people already use, and the usual way to have it on a server
is a PHP file dropped in the web root: a URL anybody can guess, protected by whatever the
database password happens to be, and forgotten after the afternoon it was needed.

```
composer require vrana/adminer
```

That is all. The framework serves it at `/adminer`, and the Database tab links to it when the
package is present.

**Who may open it**, either:

- **usertype ≥ 99** — `Root` in `UserTypes::DEFAULTS`, on any deployment **including production**,
  and with or without the `devpanel` feature. That half
  is deliberate: fixing data on a live server is a real thing an owner does, and a tool that
  only works in development means they do it in `psql` with no undo, or leave `adminer.php` in
  the web root for ever.
- **a development environment**, subject to this panel's own usertype floor.

Anything else gets a **404**, not a 403. A 403 confirms the route exists, and this is the one
URL on the site where that is worth withholding.

**Adminer keeps its own database login**, and this deliberately does not fill it in.
Auto-login would make "may this browser reach a URL" the only thing between somebody and every
row — and the accounts that can reach it are exactly the ones whose sessions are worth stealing.
Two locks, and the second is not the framework's to remove.

**A `suggest`, not a `require`.** A framework that shipped a database browser into every
application's `vendor/` would enlarge the attack surface of applications that never asked for
one, including the ones that do not read what a release added. With the package absent the route
answers 404 like any other unknown address.

### What locks it, on a public server

The gate on the URL *is* the authorisation — the connection is supplied, so reaching the page is
reaching the database. That makes the list of what holds it worth reading in full:

| | |
| --- | --- |
| **Who** | signed in, and usertype ≥ 99 (`Root`) — or a development environment plus the DevPanel's floor. Everybody else gets a **404**, not a 403 |
| **Credentials** | come from the configuration only. Adminer's login form is removed, and `$_POST['auth']` is discarded before Adminer sees the request |
| **Which connections** | the primary and the declared replicas (`database.read` / `database.write`). A request naming any other host, user or database gets the default rather than what it asked for |
| **Audit** | every open **and every refusal** is logged to `auth` with the account, the address and the URL |
| **Headers** | own CSP with `frame-ancestors 'none'`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex` |
| **Session** | ours is closed and emptied before Adminer runs; Adminer gets its own `adminer_sid` namespace |
| **No package** | 404, like any other unknown address |

Two of those were holes and are worth naming rather than listing. **Removing the login form did
not remove the ability to log in**: `auth.inc.php` acts on `$_POST['auth']` before anything else,
so a hand-made POST — or a form on another site aimed at this URL — could have pointed this
Adminer at any host reachable from the server, with any credentials the sender knew. And a
**request naming another server** was obeyed, because that is Adminer's own design: the query
string says who to connect as. Behind a single-purpose gate it must not.

What this does **not** protect against is somebody who already has a root account's session. If
that is a concern, keep `adminer_autologin` off, and the operator types the database password —
two locks instead of one.

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

Tables by size (top 30) with live row counts, and — where the extension is present —
TimescaleDB hypertables with chunk counts and compression state. The queries are
driver-specific by necessity: PostgreSQL reads `pg_class` joined to `pg_stat_user_tables`,
MySQL reads `information_schema.tables`.

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
speed, and it did worse than say nothing: `ORDER BY avg_ms DESC` puts NULLs *first* on
PostgreSQL, so a table holding unmeasured rows showed twenty of them at the top, each
rendered as `0.0 ms`, with the real measurements pushed off the list. Web requests carried no
duration before 2026-08-20, so on an installation with history that was the whole report.
When rows exist but none are timed, the panel says that instead.

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

`/devpanel/logs` — the last lines of every log file, newest first, with a level floor, a
substring search and a file selector. Every filter is a query parameter, so a useful view is a
URL somebody can paste into a message.

The administration area already has a log screen — charts, a datatable, its own controller —
and it is the wrong thing to reach for while developing: it is behind an admin session, it is
styled like the application, and what a developer wants is the last fifty lines and a way to
grep them.

Three details that matter more than they look:

- **The tail, not the file.** A log is hundreds of megabytes on a server that has been up a
  while, and the lines being looked for were written a minute ago.
- **Ordered by parsed time, not string.** The log writes `d/m/Y H:i:s`, and `01/09` sorts before
  `29/08` — so a string sort puts the oldest lines at the top for the first days of every month
  and is right again by the tenth. Which is the shape of a "the log viewer is broken" report
  that nobody can reproduce.
- **A file name from the URL is a filter, never a path.** It is compared against the names
  actually on disk rather than joined to the log directory, so there is nothing to get subtly
  wrong about how many `..` a path can contain.

Above the lines, the requests that failed — from the same log, grouped by request id. That
section is short on purpose and empty on a server nobody is debugging: lines carry a request id
only while the debug toolbar is active for that visitor, because on a live server everybody
else is logging into the same seconds and their lines are not a developer's to read.

`/devpanel/logs?request=<id>` still answers JSON, which is what the debug toolbar asks for. The
same address serves both because the toolbar always passes an id and a person never does — and
before this, a person who opened `/devpanel/logs` got a 400 about a parameter they had no way
to know existed.

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

Empty is an answer, and it used to be the wrong one often enough to be worth a section.

- **A section that could not load says so.** Failures are rendered as a warning above the
  panel, and logged to the `devpanel` log. A panel that shows an empty table and no warning
  really did find nothing.
- **Check the Background Work card** before concluding a table is empty: rows may be spooled.
- **Check the window** on Users and Performance — both default to a bounded period.
- **Check the cache adapter** on the Cache tab: an unexpected `file` means a fallback, and a
  fallback means the data you are looking for is in another store.
