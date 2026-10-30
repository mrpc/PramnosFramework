---
use_cases:
  - Running a built-in console command
  - Checking whether a feature from app.php is active inside a command or daemon
  - Issuing a token so an assistant can reach a production installation
  - Starting the development server, or the SPA dev server and build
---

# Pramnos Console Commands Guide

The console pages:

- **Console** (this page)
- [Starting a project: init, project:setup, reconfiguring](Pramnos_Console_Init_Guide.md)
- [Generating models, controllers and views](Pramnos_Console_Model_Generation_Guide.md)
- [Generating APIs and front-ends](Pramnos_Console_API_Generation_Guide.md)
- [Migrations and seeds from the console](Pramnos_Console_Migrations_Guide.md)
- [Writing commands and daemons](Pramnos_Console_Writing_Commands_Guide.md)

## Overview

The Pramnos Framework includes a powerful console command system built on Symfony Console components. The console system provides code generation, maintenance tools, and administrative utilities to streamline development workflows.

## Available Commands

### Command taxonomy

Commands are grouped by a `namespace:` prefix that reflects what they act on:

| Namespace | Purpose | Examples |
|---|---|---|
| `create:` | Code generation (models, controllers, views, screens, components, APIs, migrations, tasks, commands, …) | `create:model`, `create:crud`, `create:screen`, `create:component`, `create:command`, `create:task` |
| `migrate`, `migrate:` | Database schema migrations | `migrate`, `migrate:status`, `migrate:rollback` |
| `schema:` | The tables against the framework's shape | `db:migrate-from-mysql` | Move the data from a MySQL database into this PostgreSQL one, values converted to the target's types ([guide](Pramnos_Moving_From_MySQL_Guide.md)) | `--source`, `--dry-run`, `--phase=full`, `--tables`, `--exclude-tables`, `--batch-size`, `--keep-constraints`, `--null-orphans`, `--transport=php\|fdw`, `--fdw-host`, `--pause-daemons` |
| `db:resume-daemons` | Resumes the worker pool(s)/scheduler `db:migrate-from-mysql --pause-daemons` stopped — never run automatically |
| `schema:diff` |
| `maintenance:` | Put the site up or down by hand | `maintenance:on`, `maintenance:off` |
| `project:` | **Set up / reconfigure an existing project** without re-running `init` | `project:reconfigure`, `project:install`, `project:publish-views`, `project:git-webhook` |
| `logs:` | Log-file tooling | `logs:convert` |
| `db:` | Database operations | `db:seed`, `db:fresh`, `db:wipe` |
| `cache:` | Cache management | `cache:clear` |
| `route:` | Routing introspection | `route:list` |
| `queue:` | Background job queue | `queue:process`, `queue:failed`, `queue:retry` |
| `user:` | User administration | `user:create`, `user:password` |
| `key:` | Secret/key management | `key:generate` |
| `push:` | Web push notifications | `push:setup`, `push:vapid-generate` |
| `mail:` | Deliverability, the outbox, and the mail log's size | `mail:dns-check`, `mail:flush`, `mail:prune` |
| `llm:` | The language-model call log ([guide](Pramnos_LLM_Guide.md)) | `llm:prune` |
| `audit:` | The operators' audit trail ([guide](Pramnos_Auth_Users_Guide.md#the-audit-trail-what-operators-did)) | `audit:prune` |
| `segments:` / `audience:` | Audiences kept up to date, and what people did ([guide](Pramnos_Audience_Guide.md)) | `segments:recompute`, `audience:prune-events`, `rules:run` |
| `schedule:` | Task scheduler | `schedule:run`, `schedule:list` |
| `spa:` | Front-end dev server and build (SPA projects) | `spa:dev`, `spa:build` |
| `scaffold:` | Add a whole feature to an **existing** project | `scaffold:spa` |
| `create:api-client` | Typed endpoint functions from the OpenAPI document | *(regenerated, never edited)* |
| `health:` / `debug:` | Diagnostics | `health:check`, `debug:status`, `debug:token` |
| `auth:` / `oauth:` | Login lockouts, second factors, tokens, webhooks, third-party OAuth2 tokens | `auth:unlock`, `auth:twofactor-status`, `auth:webhook-deliver`, `oauth:keys`, `oauth:refresh` |
| `messages:` | Mass-message delivery | `messages:dispatch` |
| `mcp:` | The framework's MCP server | `mcp:serve`, `mcp:call`, `mcp:token` |
| `api:` | API documentation | `api:docs` |
| `theme:` | Palette tokens | `theme:build` |
| `pagecache:` | Full-page cache | `pagecache:purge` |
| `broadcast:` | WebSocket broadcasting server | `broadcast:serve` |
| `timescale:` / `spool:` | Buffered writes and hypertable maintenance | `timescale:ensure`, `timescale:drain`, `spool:drain` |
| `service:` | Framework policies (retention, aggregate refresh) | `service:policy-engine` |
| *(top-level)* | Entry points, the REPL, the scheduler loop, migrations | `init`, `serve`, `tinker`, `work`, `migrate` |

> Top-level commands are `init`, `serve`, `tinker`, `work` and `migrate`; the rest
> follow the `namespace:verb` convention. A `DaemonOrchestrator` subclass registers
> itself as `daemons:start` unless it overrides `getOrchestratorCommandName()`.

Run `php bin/pramnos list` to see every command with its description.

### Code Generation Commands

The framework provides comprehensive code generation through the `create` command:

```bash
# Create a new model
php bin/pramnos create:model User

# Create a controller
php bin/pramnos create:controller User

# Create a view
php bin/pramnos create:view User

# Create complete CRUD system (model + controller + view)
php bin/pramnos create:crud User

# Create API endpoint
php bin/pramnos create:api User

# Create database migration
php bin/pramnos create:migration create_users_table
```

#### Running `create:model` again on a model that exists

Safe, and worth knowing exactly how safe. Adding a column and re-running the generator that produced
the model is the normal thing to do, so this path matters more than an «already exists» branch usually
would.

**The file is left alone.** Not regenerated — an existing model's own methods, properties and docblocks
stay exactly as they are, and the report says `Model updated.` rather than `Model created.` The only
change made to a file the generator did not just write is **adding `getApiList()` if it is missing**,
which is additive and inserted before the last closing brace.

No test file is generated and no registry entry is added, both for the same reason: a generated test
would overwrite whatever the developer wrote in its place, and a second registry entry for one model is
a lookup that answers twice.

So a new column does **not** reach the model by re-running this. Write the migration, run it, and add
the property — which is the trade this branch makes deliberately, because the alternative was losing
the model's own code.

#### Select2 changes what a foreign-key field loads

`create:crud` and `create:controller` generate an edit form whose foreign-key fields behave differently
depending on whether the project registered Select2:

| | without Select2 | with Select2 |
|---|---|---|
| the option list | loaded in full, server-side | fetched over AJAX from the generated `fkOptions()` |
| the selected row | one of the loaded options | loaded on its own, so the field is not blank |

The full list is fine for a status lookup and breaks for a foreign key to a table with thousands of
rows: a form that takes seconds to render and megabytes to send. So the Select2 form loads **only the
currently selected row** — which it still has to, or an existing record opens with an empty field where
its category belongs and saving clears the reference.

Availability is read from the Document — `isScriptRegistered('select2')` — and not by looking for
`www/assets/vendor/select2` on disk. A directory says a file exists; the registration says the project
opted in, which is the question being asked. If your `registerVendorLibraries()` registers it, the
generator uses it.

A foreign key to `users` is the one case that does not go through a generated model: it reaches for
`\Pramnos\User\User` and reads `username`, rather than the `name`/`title`/`label` chain every other
reference falls through. Generating `\App\Models\Users` would name a class the application does not
have, and the failure would arrive when somebody opened the form rather than when the code was written.

**An existing controller is never overwritten** — the generator refuses and names the file. Same reason
as `create:model` above: a generator that silently replaces a file somebody has edited loses work, and
the loss is invisible until something that called the removed method breaks.

### Server Commands

```bash
# Start development server
php bin/pramnos serve

# Start server on specific port
php bin/pramnos serve --port=8080
```

### Front-end commands (`spa:`) — SPA projects only

The front-end toolchain is npm, but the two things done daily belong in the CLI
the rest of the project uses:

```bash
php bin/pramnos spa:dev              # dev server with HMR (alias: spa:serve)
php bin/pramnos spa:build            # production build → www/assets/spa/
php bin/pramnos spa:build --watch    # rebuild on every change, no dev server
```

Both wrap npm, so `./dockernpm run <script>` still works for anything else in
`package.json`.

**Where npm comes from is decided for you.** The scaffolded CLI wrapper is
`docker-compose exec -u www-data app php <cli>.php`, so the console is usually
*already inside* the container: there npm runs directly, with `HOME=/tmp` so it
can write its cache. Run from the host instead and `./dockernpm` is used, which
enters the container as the right user — so build output never ends up owned by
root. Missing dependencies are installed first rather than reported.

**Do not open the Vite port.** `spa:dev` prints the URL to browse, which is the
application's. The dev server serves no HTML: while it runs it writes a hot file
that the shell reads, and the shell loads modules from it — so HMR happens
against the real backend on the application's own URL. The Vite port itself
answers nothing, which reads as a broken dev server.

Both commands refuse, with the reason, where they cannot apply: an MVC project
has no front end to build, and the build-less stack serves `www/assets/js/`
exactly as written, so there is nothing to build and nothing to serve.

### Operational & diagnostic commands

```bash
# Flush the application cache and the database's query cache
# (all categories, or one with --category; --all flushes the whole backend)
php bin/pramnos cache:clear
php bin/pramnos cache:clear --category=views

# List all registered routes (add --json for machine output)
php bin/pramnos route:list

# Queue: inspect and retry failed tasks
php bin/pramnos queue:failed
php bin/pramnos queue:retry 42        # one task by id
php bin/pramnos queue:retry --all     # all failed tasks

# Database lifecycle (destructive — require --force in non-interactive mode)
php bin/pramnos db:wipe --force       # drop all tables
php bin/pramnos db:fresh --force      # drop all tables, then migrate
php bin/pramnos db:fresh --force --seed

# Create a user / admin from the CLI
php bin/pramnos user:create --username=admin --email=admin@example.com --admin
php bin/pramnos user:create --username=editor --email=editor@example.com --usertype=50

# Set a password without an email round trip
php bin/pramnos user:password alice                 # prompts, hidden, twice
php bin/pramnos user:password alice --generate       # generates one and prints it

# Generate or rotate the application key in .env
php bin/pramnos key:generate          # refuses to clobber an existing key
php bin/pramnos key:generate --show   # print without writing
php bin/pramnos key:generate --force  # rotate (invalidates encrypted data/sessions)

# Interactive REPL with the framework bootstrapped (PsySH if installed)
php bin/pramnos tinker
```

### `user:password` — setting a password without email

```bash
php bin/pramnos user:password alice                      # prompts, hidden, twice
php bin/pramnos user:password alice@example.com --generate
php bin/pramnos user:password 42 --by=userid --password='…'
php bin/pramnos user:password alice --generate --revoke-sessions
```

The argument is a **username, an email address or a numeric user id**, tried in that
order — you know which one you have, and the command can work it out. `--by=username`,
`--by=email` or `--by=userid` restricts it, which is the answer to the only real
ambiguity: a numeric username.

#### It is four writes, not one

That is the reason to use it rather than a `UPDATE` by hand. Three of the four are the
ones that get forgotten:

| | |
|---|---|
| the password hash | through the `User` model, so it is salted with `md5(securitySalt . userid)` — a raw `password_hash()` writes a hash login can never match |
| pending reset tokens | **cleared.** Otherwise a link mailed out ten minutes ago still works and the account has two valid passwords, one of them held by whoever got the mail |
| a brute-force lockout | **lifted.** A locked-out account refuses the *correct* password with the same message as a wrong one, which is indistinguishable from "the reset did not work" — and is the first thing reported back |
| the activity log | a password set from a shell leaves no other trace, which is the whole argument for an audit log |

#### Sessions are left signed in

`--revoke-sessions` marks the user's live tokens revoked, and it is **not** the default.
The ordinary reason to run this command is that somebody cannot get in; signing them out
of every other device turns one problem into several. Pass it when the reason is a
suspected compromise, where the opposite is true.

#### The policy applies

The same rules the self-service form enforces — eight characters, a digit, a symbol —
because a password set here signs in through the same door. `--generate` produces one that
passes without anybody having to think about it, and prints it, since it is otherwise
unrecoverable.

`--force` accepts a password the policy would refuse, says so in the output, and records
the waiver in the audit entry — but **only when something was actually waived.** Forcing a
password that would have passed anyway waives nothing, and an audit entry claiming
otherwise is a false record of a security decision.

### Webhook delivery

```bash
php bin/pramnos auth:webhook-deliver              # send what is due
php bin/pramnos auth:webhook-deliver --batch=200  # a bigger bite
php bin/pramnos auth:webhook-deliver --purge=30   # also drop settled events older than 30 days
```

Registered in the framework schedule to run **every five minutes**, which is where
the retry back-off starts — a slower cadence would not delay only the first attempt,
it would delay every one of them.

The command is quiet when the queue is empty, because it runs 288 times a day and a
line per run buries the ones that matter. A failed delivery exits `0` on purpose:
the event keeps its attempts and its back-off, and a non-zero exit would make a
scheduler treat an unreachable relying party as a broken command.

An installation without the authserver feature has no webhook tables; the command
notices and succeeds quietly rather than failing on every schedule tick.

### The tier `--admin` grants

`--admin` creates the account at **usertype 99 — Root**, and so does the first
administrator `init` creates during scaffolding. The two paths are deliberately the
same number; `ProjectSetup` reaches this command for exactly that reason.

**Not because 90 is locked out of anything.** Every administrative screen the
framework ships is reachable at 90 — they gate on `minUserType` /
`requiredUserType`, which top out at 90:

| Screen | Minimum usertype |
|---|---|
| Users, Settings, Logs, Dashboard, Health, Services, Organizations, Emails, Push, Queue | 80 |
| Applications, Tokens, Permissions, Roles, Mass messages, `/health/phpinfo`, the dev panel | 90 |

It is about which tier this account should be *given*. `UserTypes` calls 99 **Root**
and grants it `['*']` — every capability, including ones added later — against a
fixed list at 90 and 98. On a fresh installation this is the owner of the
deployment and usually the only account there is, so it should be the tier that
never needs a hand-edit in the `users` table after an upgrade, and there should
not be an unused tier above them.

For anything below root, name it:

```bash
php bin/pramnos user:create --username=editor --email=editor@example.com --usertype=50
```

`--usertype` wins over `--admin`, and a value that is not a whole number is
refused rather than coerced — `(int)` on a typo would create an ordinary account
and report success, leaving nothing to tell it apart from one that was meant to be
ordinary.

### Code generators (`create:`)

Beyond model/controller/view/crud/api/migration/seeder/event/listener/middleware, the
`create:` family also scaffolds:

```bash
php bin/pramnos create:command MyCommand    # a Symfony console command
php bin/pramnos create:task MyTask           # a Pramnos\Queue\AbstractTask
php bin/pramnos create:provider MyProvider   # a service provider
php bin/pramnos create:policy MyPolicy       # an authorization policy skeleton
php bin/pramnos create:test MySubject        # a PHPUnit test class
```

#### What `create:command` writes

```bash
php bin/pramnos create:command CollectChannels
```

| Thing | Value | How it is decided |
|---|---|---|
| File | `src/ConsoleCommands/CollectChannels.php` | **Discovered**: `src/ConsoleCommands/` when the project has it (what `init` writes), `src/Console/Commands/` when that is what it has instead |
| CLI name | `channels:collect` | `noun:verb`, from the class name — the first PascalCase word is the verb. One word keeps `app:`. Override with `--command-name=feeds:pull` |
| Registration | appended to `src/Console.php` | On the `// Register your custom commands here:` line `init` writes, idempotently |
| Test | `tests/Unit/CollectChannelsCommandTest.php` | Asserts the command answers to its name and runs — a command's name is its whole public surface, and its first failure mode is a fatal before it prints anything |

A project that has restructured its console gets the registration *reported* rather than
forced: the command says where the line could not be added, and what the line is.

### Scheduled tasks (`schedule:`)

Scheduled tasks are declared **in code** (not stored in a database), in the app's
`app/schedule.php` file — scaffolded by `init` and loaded by both schedule commands:

```php
<?php
use Pramnos\Scheduling\Scheduler;

Scheduler::command('cache:clear')->daily()->at('03:00');
Scheduler::command('queue:cleanup')->hourly();
Scheduler::call(fn() => /* ... */)->everyFifteenMinutes()->withoutOverlapping();
```

Wire a single system cron entry to run the due tasks every minute:

```bash
* * * * * cd /path/to/app && php <cli> schedule:run >> /dev/null 2>&1
```

```bash
php bin/pramnos schedule:list            # show registered tasks
php bin/pramnos schedule:run             # run tasks due now
php bin/pramnos schedule:run --pretend   # dry run (list due tasks)
```

Overlap protection (`withoutOverlapping()`) uses lock files in the system temp dir.

Each run is recorded to the **`schedule`** log channel (`schedule.log`, visible in the log
viewer) — one entry per task with the outcome (`ran … in Nms`, `skipped … overlapping`,
`failed … <error>`). Since cron usually sends `schedule:run` output to `/dev/null`, this log
is the durable record of what actually ran.

### Log-file maintenance

```bash
# Convert legacy log files to the structured single-line JSON format
php bin/pramnos logs:convert /path/to/logs --all
php bin/pramnos logs:convert /path/to/file.log
php bin/pramnos logs:convert /path/to/file.log --no-backup
```

### Other commands, by namespace

`php bin/pramnos help <command>` prints the full option list of any of these.

**Code generation**

| Command | Purpose | Key options |
|---|---|---|
| `create:service <Name>` | A service class, with a test stub | `--table`, `--schema` |
| `create:event <Name>` / `create:listener <Name>` | An event class (its `EVENT` name — `JobPostCreated` is `jobpost.created` — a readonly payload, `jsonSerialize()`/`fromQueue()`) / an event listener class, each with a test stub | `create:listener`: `--event=JobPostCreated` (a typed `handle()`), `--queued` (extends `QueuedListener`) |
| `create:middleware <Name>` | A middleware class, with a test stub | — |
| `create:seeder <Name>` | A database seeder class | — |
| `create:api-client` | Typed endpoint functions from the OpenAPI document | `--document` (default `www/api/openapi.json`), `--output`, `--dry-run` |
| `project:git-webhook` | Write `www/webhook.php`, a standalone git webhook receiver | `--branch` (default `main`), `--force` |
| `project:switch-ui <framework>` | Switch the scaffolding UI framework (`plain-css`, `bootstrap`, `tailwind`); in a Svelte SPA it also rewrites `frontend/ui.css` — rebuild afterwards | — |

**Migrations**

| Command | Purpose | Key options |
|---|---|---|
| `migrate [migration]` | Run pending migrations (or one by slug/class) | `--scope=app\|framework`, `--feature`, `--force/-f` (include `autorun=false`), `--cutoff`, `--path`, `--adopt-baseline` |
| `migrate:status` | List migrations and whether they ran | `--path` |
| `schema:diff` | Compare the tables with the shape the framework's migrations would make, against a reference they build; exits 1 while differences remain ([guide](Pramnos_Migration_Guide.md#comparing-an-installation-with-the-frameworks-shape-schemadiff)) | `--apply`, `--reference`, `--keep`, `--allow`, `--json`, `-v` |
| `migrate:rollback` | Roll back the last batch | `--batch`, `--path` |
| `migrate:reset` | Roll back **all** batches | `--force/-f`, `--path` |
| `migrate:refresh` | Roll back everything and re-run it | `--force/-f`, `--path` |
| `migrate:adopt-legacy` | Record migrations the legacy version ledger says already ran | `--dry-run`, `--path` |

**Queue, scheduler and workers**

| Command | Purpose | Key options |
|---|---|---|
| `work` | Run the framework and application schedule continuously | `--once`, `--interval/-i`, `--max-runtime` |
| `queue:process` | Process queued tasks (one pass, or as a daemon) | `--daemon/-d`, `--runtime/-r`, `--sleep/-s`, `--limit/-l`, `--batch/-b`, `--type/-t`, `--worker-id/-w`, `--force/-f` |
| `queue:cleanup` | Delete old completed tasks from the queue table | `--hours` (default `queue.retention_hours`, else 24), `--[no-]include-failed`, `--include-warning`, `--limit`, `--[no-]reclaim`, `--reclaim-grace` |
| `queue:reclaim` | Requeue or fail tasks abandoned by a worker that died holding them | `--grace`, `--type`, `--dry-run` |
| `queue:health` | Report arrivals against completions; exits 1 when the queue is losing | `--window`, `--type`, `--per-type`, `--history`, `--json` |
| `service:policy-engine` | Run all due framework policies (retention, aggregate refresh, …) | `--list/-l`, `--pretend` |
| `spool:drain` | Write rows buffered out of the request path | `--status/-s`, `--max-attempts` |
| `timescale:ensure` | Convert declared tables to hypertables and (re)apply compression | `--dry-run`, `--table/-t` |
| `timescale:drain` | Write queued rows into their hypertables, per chunk, within a WAL budget | `--table/-t`, `--status/-s`, `--retry-failed`, `--wal-budget=MB`, `--replica-lag=MB` |

**Authentication**

| Command | Purpose | Key options |
|---|---|---|
| `auth:unlock [identifier]` | Lift a login lockout for a user, identifier or IP | `--scope/-s`, `--list/-l`, `--all` |
| `auth:twofactor-status [user]` | Show what second factor an account holds, or who holds none | `--missing/-m`, `--from` |
| `auth:twofactor-reset <user>` | Clear an account's second-factor enrolment and trusted browsers so it can enrol again; audited | `--passkeys/-p`, `--dry-run`, `--by=<userid>` |
| `auth:twofactor-cleanup` | Delete spent and expired second-factor codes and enrolments | — |
| `auth:token-cleanup` | Retire session tokens that have been idle | `--days/-d` |
| `oauth:keys` | Generate the RSA pair OAuth2 / OpenID Connect tokens are signed with, and `encryption.key` once, in `app/keys/`; refuses to replace a pair, since a new key invalidates every token signed with the old; warns when run as root without `--owner` | `--force`, `--owner=<user>` |
| `oauth:refresh` | Refresh third-party OAuth2 tokens that are close to expiring | `--within`, `--provider`, `--dry-run` |

**Site, cache and messaging**

| Command | Purpose | Key options |
|---|---|---|
| `maintenance:on` / `maintenance:off` | Put the site down or bring it back | `--reason` / `--force/-f` |
| `pagecache:purge [url]` | Purge cached pages by URL, by tag, or all | `--tag` (repeatable), `--all` |
| `messages:dispatch` | Deliver pending recipients of due mass messages | `--limit/-l` |
| `mail:flush` / `mail:prune` / `mail:dns-check [domain]` | Send the mail queued in the outbox / strip old bodies and remove old rows by policy / check SPF, DKIM, DMARC and BIMI on the sending domain | `--limit`, `--deadline`, `--dry-run` / `--strip-after`, `--delete-after`, `--recipients-after`, `--apply` |
| `push:setup` / `push:vapid-generate` | Set up web push (migration, keys, service worker, browser script, library) / generate the VAPID key pair | `--apply`, `--no-install` / `--force` |
| `audit:prune` | Delete audit-log rows past the retention (`audit.retention_days` in app.php: days, or days per event such as `['admin.page' => 180, '*' => 730]`); deletes nothing until one is set | `--days` |
| `segments:recompute` | Work segments out again — who entered, who left — each after the segments its filter names; scheduled hourly by the framework | `--segment=<id>` |
| `events:consume` | Read the event stream for its consumer groups — every queued listener set to `stream`, or the ones named — as a daemon until stopped or `--max-time` ([guide](Pramnos_Queue_Guide.md#the-stream-one-entry-per-event)) | `--group` (repeatable), `--once`, `--limit`, `--max-time`, `--sleep` |
| `events:prune` | Delete event-stream entries older than `events.log_days` (30) that every group has read and nothing waits to retry. Scheduled daily | `--days` |
| `conversations:beacon` | The conversations' beacon, from the primary: `sync` the team to it and `import` what was written there (both scheduled every minute; nothing where there is no beacon), `test <account>` a drill, `keys` a new signing pair ([guide](Pramnos_Conversations_Guide.md#when-the-server-is-down-the-beacon)) | `what`, `person` |
| `conversations:thumbnails` | Makes the thumbnail of every image sent in the conversations again: fitted with its own proportions, of its own type, none for an image small enough to be shown itself ([guide](Pramnos_Conversations_Guide.md#files)) | — |
| `events:status` | Which transport each queued listener is set to use, and the one it would use here — a dry run ([guide](Pramnos_Queue_Guide.md#which-transport-carries-a-listeners-events)) | `--json` |
| `audience:prune-events` | Delete rule run-log rows older than `audience.rule_log_days` (90; 0 keeps them) and segment stays that ended more than `audience.segment_history_days` ago (365). Each person event is the change log's to keep. Scheduled daily | `--days` (the rule log's) |
| `rules:run` | Start the rules whose schedule is due this minute: each takes everybody who matches, in the queue. Scheduled every minute | — |
| `llm:prune` / `llm:routines` | Drop language-model call log rows older than the kept window / ask the scheduled questions that are due | `--days` / — |
| `broadcast:serve` | WebSocket broadcasting server (Pusher-compatible) | `--host`, `--port/-p`, `--app-key`, `--channels` (defaults from `broadcasting.websocket.listen_host`/`listen_port`/`app_key` and `broadcasting.ingest`, else `127.0.0.1`, `6001`, `pramnos-local`), `--log-file` |

**Diagnostics and tooling**

| Command | Purpose | Key options |
|---|---|---|
| `health:check` | Run all registered health checks | `--json`, `--only` |
| `test:js` | Run the application's JavaScript tests with `node --test` and the framework's `pramnos-testing` helpers | `--dir`, files |
| `lint:js` | ESLint over the application's JavaScript with the framework's rules | `--fix`, paths |
| `debug:status` | Show the debug configuration (APP_DEBUG, Xdebug, port 9003) | — |
| `debug:token` | Issue a signed token that opens the debug toolbar for one browser | `--ttl`, `--url/-u` |
| `api:docs` | Generate an OpenAPI document from `#[Route]` controllers (and RapiDoc HTML) | `--controllers`, `--namespace`, `--routes`, `--output`, `--title`, `--api-version`, `--description`, `--server` (repeatable), `--overrides`, `--allow-empty`, `--no-html` |
| `mcp:serve` | Start an MCP server on stdio for an AI assistant | `--log` |
| `mcp:call [tool]` | Call one MCP tool and print what it returns; omit the tool to list them | `--json`, `--raw` |

## Development Server

### Starting the Server

```bash
# Start on default port (8000)
php bin/pramnos serve

# Start on custom port
php bin/pramnos serve --port=8080     # or -p 8080

# Start with custom host (default: localhost)
php bin/pramnos serve --host=0.0.0.0 --port=8080
```

`serve` runs PHP's built-in web server (`php -S <host>:<port>`) with the web root's `index.php`
(`www/`, `public/`, …) as the router script, so every request goes through the application's front controller. It is a
development server only — single-process, with no TLS.

## Configuration

### Console Application Setup

The framework's commands are registered in `Pramnos\Console\Application::registerCommands()`.
An application adds its own in `src/Console.php`, which `init` scaffolds as a subclass:

```php
<?php
namespace MyApp;

class Console extends \Pramnos\Console\Application
{
    protected function registerCommands(): void
    {
        parent::registerCommands();
        // Register your custom commands here:
        // $this->add(new \MyApp\ConsoleCommands\MyCommand());
    }
}
```

`create:command` appends its registration on that comment line (see
[What `create:command` writes](#what-createcommand-writes)).

### Custom Command Creation

You can create custom console commands by extending Symfony's Command class:

```php
<?php
namespace MyApp\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class CustomCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('custom:task');
        $this->setDescription('Execute custom task');
        $this->setHelp('This command performs a custom task');
        
        $this->addArgument('parameter', InputArgument::REQUIRED, 'Required parameter');
        $this->addOption('option', 'o', InputOption::VALUE_OPTIONAL, 'Optional parameter');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $parameter = $input->getArgument('parameter');
        $option = $input->getOption('option');
        
        $output->writeln("Executing custom task with parameter: $parameter");
        
        // Your custom logic here
        
        return Command::SUCCESS;
    }
}
```

**`execute()` returns an `int` on every path**: `Command::SUCCESS`, `Command::FAILURE` or
another exit code. symfony/console 6 raises a `TypeError` when it returns nothing, and a bare
`return;` on an error path is the usual way that happens. Declare `: int` on `execute()` and
`: void` on `configure()`. symfony/console 7 requires both, and a command declared this way
works on every version the framework supports.

## Best Practices

### Model Generation

1. **Use descriptive table names** - The generator uses table names to create class names
2. **Define proper primary keys** - Ensure your tables have clear primary key definitions
3. **Add column comments** - Database column comments become PHPDoc annotations
4. **Use consistent naming** - Follow your project's naming conventions

### Controller Generation

1. **Plan your actions** - Consider which actions need authentication
2. **Use meaningful names** - Controller names should reflect their purpose
3. **Review generated code** - Always review and customize generated controllers
4. **Add validation** - Implement proper input validation in save methods

### API Development

1. **Design RESTful endpoints** - Follow REST conventions for API design
2. **Implement proper authentication** - Use JWT or session-based auth
3. **Add input validation** - Validate all API inputs
4. **Handle errors gracefully** - Return appropriate HTTP status codes
5. **Document your APIs** - Maintain the generated API documentation

### Database Migrations

1. **Make migrations idempotent** - Migrations should be safe to run multiple times
2. **Use descriptive names** - Migration names should describe what they do
3. **Test migrations** - Always test migrations on development data first
4. **Keep migrations small** - Break large changes into smaller migrations

### Development Workflow

1. **Start with models** - Generate models first to establish data structure
2. **Create controllers** - Build controllers with required business logic
3. **Design views** - Create user-friendly interfaces
4. **Build APIs** - Add API endpoints for mobile/frontend integration
5. **Write tests** - Create tests for critical functionality

## Troubleshooting

### Common Issues

**Database Connection Errors**
```bash
# Ensure database configuration is correct in your app settings
# Check database credentials and connection
```

**Permission Errors**
```bash
# The user running the command must be able to write to:
# - app/ directory (model registry, migrations)
# - src/ directory (generated classes)
# - var/ directory (logs, cache, lock files)

chmod -R u+w app/ src/ var/
```

**Generated Code Issues**
- Review generated code for customization needs
- Check namespace configuration in application settings
- Verify database table structure matches expectations

**Console Command Not Found**
- Ensure `bin/pramnos` has execute permissions
- Check PHP CLI is available and working
- Verify Composer dependencies are installed

The Pramnos console system provides a comprehensive set of tools for rapid application development, making it easy to scaffold complete applications with minimal manual coding while maintaining code quality and consistency.

---

## `mcp:token` — a credential for this installation's MCP endpoint

Run it **on the installation you want to reach**; paste what it prints into the client on the
machine you work from.

```bash
php bin/pramnos mcp:token --user=you@example.com --scopes=diagnostics,logs,db --days=30
```

| Option | |
|---|---|
| `--user` | Id, email or username. **Required** — a token acts as somebody, and every call it makes is recorded as theirs. |
| `--scopes` | `diagnostics`, `logs`, `db`, or full scope names. Default `diagnostics`. `mcp` is always added. |
| `--days` | Days until expiry, default `30`. `0` never expires, which is rarely what you want. |
| `--url` | The API's base URL for the printed config (`https://example.com/api`); the endpoint is `<url>/<version>/mcp`. Defaults to the API's own base. |
| `--name` | What the server is called in `.mcp.json`. Default `production`. |

It prints the token **once** — `usertokens.token` is encrypted at rest, so this is the only
moment it is readable — followed by a ready `.mcp.json` block with the bearer header filled
in. The token is signed with the **API's** key, whatever application the console runs as,
because the endpoint is an API route; it carries a `jti`, so two minted in one second differ. A
`usertokens.token` too short for the encrypted token (an older `varchar(255)`) mints nothing and
says to widen the column. Losing it means minting another and revoking this one, which is cheap: a token is one
revocable grant, not an identity.

What it will **not** do is tell you whether the endpoint offers those scopes. The call that
offers the tools runs in an application `ServiceProvider`, booted by `Application::init()`,
which a web request runs and a console command does not — so from a terminal the scope
registry looks empty on every installation. Ask the endpoint itself with the `whoami` tool.

See [Reaching it from off the box](Pramnos_MCP_Guide.md#reaching-it-from-off-the-box) for the
other three steps, and [the security guide](Pramnos_Security_Guide.md#personal-data-and-the-denial-list)
for what each scope discloses.

## Related Documentation

- **[Framework Guide](Pramnos_Framework_Guide.md)** — MVC architecture for generated code
- **[Migration Guide](Pramnos_Migration_Guide.md)** — Migration system and MigrationRunner
- **[Database API Guide](Pramnos_Database_API_Guide.md)** — Database patterns used in generated models
- **[Authentication Guide](Pramnos_Authentication_Guide.md)** — Implementing authentication in generated controllers
- **[Routing Guide](Pramnos_Routing_Guide.md)** — Route registration for generated controllers
- **[Logging System Guide](Pramnos_Logging_Guide.md)** — Log migration tools and monitoring
