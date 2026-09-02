---
use_cases:
  - Running a built-in console command
  - Generating a model, controller, view, API or full CRUD set
  - Generating a Svelte screen or component for a SPA project
  - Writing a new console command
  - Refreshing framework-owned files in an existing project (project:resync)
  - Finding out what `init` scaffolds, including the files aimed at AI assistants
  - Starting the development server, or the SPA dev server and build
  - Checking whether a feature from app.php is active inside a command or daemon
---

# Pramnos Console Commands Guide

## Overview

The Pramnos Framework includes a powerful console command system built on Symfony Console components. The console system provides code generation, maintenance tools, and administrative utilities to streamline development workflows.

## Available Commands

### Command taxonomy

Commands are grouped by a `namespace:` prefix that reflects what they act on:

| Namespace | Purpose | Examples |
|---|---|---|
| `create:` | Code generation (models, controllers, views, screens, components, APIs, migrations, tasks, commands, …) | `create:model`, `create:crud`, `create:screen`, `create:component`, `create:command`, `create:task` |
| `migrate`, `migrate:` | Database schema migrations | `migrate`, `migrate:status`, `migrate:rollback` |
| `project:` | **Set up / reconfigure an existing project** without re-running `init` | `project:reconfigure`, `project:install`, `project:publish-views`, `project:git-webhook` |
| `logs:` | Log-file tooling | `logs:convert` |
| `db:` | Database operations | `db:seed`, `db:fresh`, `db:wipe` |
| `cache:` | Cache management | `cache:clear` |
| `route:` | Routing introspection | `route:list` |
| `queue:` | Background job queue | `queue:process`, `queue:failed`, `queue:retry` |
| `user:` | User administration | `user:create`, `user:password` |
| `key:` | Secret/key management | `key:generate` |
| `push:` | Web push notifications | `push:setup`, `push:vapid-generate` |
| `mail:` | Deliverability, and the mail log's size | `mail:dns-check`, `mail:prune` |
| `schedule:` | Task scheduler | `schedule:run`, `schedule:list` |
| `spa:` | Front-end dev server and build (SPA projects) | `spa:dev`, `spa:build` |
| `scaffold:` | Add a whole feature to an **existing** project | `scaffold:spa` |
| `create:api-client` | Typed endpoint functions from the OpenAPI document | *(regenerated, never edited)* |
| `health:` / `debug:` | Diagnostics | `health:check`, `debug:status` |
| *(top-level)* | Entry-point & REPL | `init`, `serve`, `tinker` |

> Only genuine entry-point commands (`init`, `serve`) stay top-level; everything
> else follows the `namespace:verb` convention.

Run `php bin/pramnos list` to see every command with its description.

### `init` will not scaffold over an existing application

`init` writes `app/app.php`, `composer.json`, `CLAUDE.md`, `README.md`, the Docker
files, `phpunit.xml` and `src/Console.php` unconditionally, and adds stock MVC
controllers to `src/Controllers/` — which in an **attribute-routed** application
become live routes, because the loader takes whatever is in that directory. None of
that is recoverable without version control.

So a directory that already contains `app/app.php` is **refused**:

```
This directory already holds an application.
  Found: app/app.php

  Running init here would overwrite files including:
    app/app.php
    composer.json
    …
  --dry-run lists everything that would be written, and writes nothing.
  --force   proceeds anyway.
```

The exit status is non-zero, so a script notices.

| Flag | What it does |
| --- | --- |
| `--dry-run` | Asks the same questions, writes **nothing**, and prints every file it would create or overwrite. Allowed in an existing project — a preview is exactly what is wanted there. |
| `--force` | Proceeds, and says on stdout that it is proceeding. |

A dry run also runs **no external commands** — no composer, no `docker-compose`, no
migrations, no asset downloads — and prints each one it skipped. It does not append
to `.gitignore`, merge `package.json`, copy brand images or generate an RSA key
pair either: a "dry" run that changes the working tree would be a trap rather than a
preview.

### Scaffolding without installing — `--no-install`

`init` finishes by running `composer update` and `composer dump-autoload`, because an
application that cannot boot is not a finished scaffold. `--no-install` writes every
file and skips that step:

```bash
php bin/pramnos init --no-install
```

```
  Skipped installing dependencies (--no-install).
  Run composer install before serving the application.
```

It is reported twice — where the step would have happened, and again in the closing
next-steps list — because the alternative is a fatal about a missing autoloader with
nothing to connect it to.

Reach for it when installing is somebody else's job:

- **CI that installs from its own lockfile**, or with different flags than `init` uses;
- **no network**, or a slow one — `init` also fetches front-end assets, which
  `--no-download` skips separately;
- **a project whose `vendor/` is committed**, where `composer update` would be an
  unwanted change;
- **tests that scaffold**. This framework's own suite scaffolded 61 projects per run and
  therefore ran `composer update` 61 times — [85% of that class's
  runtime](Pramnos_Test_Suite_Performance.md), and a dependency on the network from
  inside a unit test.

With Docker (`--docker=y`), `--no-install` also skips the framework migrations that
follow, since those run the new application's own CLI and need the autoloader that was
not generated.

### The dependency sync retries — and why

The in-container `composer update` runs up to **three** times before `init` gives up:

```
Syncing dependencies (in container) FAILED
  ...
  Install of phpunit/php-code-coverage failed
  In RecursiveDirectoryIterator.php line 48:
    RecursiveDirectoryIterator::__construct(/var/www/html/vendor/phpunit/php-code-coverage):
    Failed to open directory: No such file or directory
Syncing dependencies (retry 2/3) DONE
```

That failure is not a dependency problem, and the named package is arbitrary — it is
whichever one lost a race. Composer extracts every package into `vendor/`, which is a
Docker bind mount of your project directory. `ArchiveDownloader::install()` creates the
target directory, asks `file_exists()` (yes), then opens it — and on Docker Desktop for
macOS the mount occasionally answers `ENOENT` for a directory it created a moment
earlier. Metadata coherence, nothing more; the next attempt succeeds.

The retry exists because the failure is not proportionate to its cause: a failed sync
sets `autoloadSuccess = false`, which skips the framework migrations, which skips the
admin user — one stale stat and the scaffold finishes unusable. If all three attempts
fail, the cause is real and the closing summary says what to run by hand.

### `project:setup` — bringing a clone up

`init` creates a project. This brings an existing checkout to a working local
environment, and it exists because of a deliberate gap: the credentials now live in
`.env`, which is **not committed**, so a fresh `git clone` cannot connect to anything
and nothing said what to create.

```bash
./pramnos project:setup
```

Seven steps, each skipped when it is already done — so running it twice is safe, and
running it after a `git pull` is a reasonable way to catch up:

| | |
|---|---|
| 1 | `.env` from `.env.example`, prompting only for the password |
| 2 | `docker-compose up -d --build` |
| 3 | `composer install` in the container |
| 4 | wait for the database to accept connections |
| 5 | framework migrations |
| 6 | an administrator, if you want one |
| 7 | `npm install` and a first build, if the project has a front end |

**The host user ids are read from this machine, not copied.** `.env.example` carries
`UID=1000`, which is the first non-root user on a Debian host and wrong on plenty of
others — and getting it wrong means everything the container writes into the bind mount
is owned by somebody who is not you. Nobody knows their own ids by heart, so it does not
ask.

**An existing `.env` is left alone** unless you pass `--force-env`. It is the one file in
the project that is not in version control, so it is the only thing here that
`git checkout` cannot undo.

**Step 4 is not a courtesy.** `docker-compose up -d` returns as soon as the containers
are *created*, and a fresh Postgres or MySQL volume takes several seconds more to accept
a connection. Migrating into that window fails with a connection error that reads exactly
like a configuration mistake.

```bash
./pramnos project:setup --dry-run          # print the plan, change nothing
./pramnos project:setup --no-docker        # a host running its own stack
./pramnos project:setup --db-pass=…        # for CI, instead of the prompt
./pramnos project:setup --no-admin         # skip the account offer
```

**It writes no project file** — only `.env`. Not one of the steps above is a scaffolding
step: `project:reconfigure` and `project:resync` own that. A command that both set up an
environment *and* edited tracked files would be one nobody could safely run on a
checkout with local changes.

For a project scaffolded before the credentials moved there is no `.env.example`, and it
says so rather than writing an empty `.env`: those projects still keep their settings in
`app/config/settings.php`.

### `--service-worker` — caching assets in the browser

Off by default. `y` writes `<web-root>/sw.js` and the lines that register it, in the
theme footer and — for a SPA — in the shell as well:

```bash
php bin/pramnos init --service-worker=y
```

It caches static assets only: `GET`, same-origin, and only stylesheet/script/font/image
paths. HTML is never intercepted, which is what makes it safe to hand to a project by
name rather than by decision. The full account of what it refuses to do, and why each
refusal is there, is in the [Service Worker Guide](Pramnos_Service_Worker_Guide.md).

The prompt is step 2e in the interactive wizard, and it defaults to **no** — a service
worker keeps itself alive across reloads, so unlike every other file `init` writes, a
mistake in one is not corrected by the next deployment.

### Serving from a directory other than `www` — `--web-root`

The scaffold writes its document root as `www/` by convention, and that was hardcoded in 38
places. A consumer reported it through the one that hurt: the SPA build wrote to
`www/assets/spa` whatever the project's real document root was, so a project served from
anywhere else built its front end into a directory nothing serves — **and the symptom is a blank
page, not an error**, because the manifest is simply not where the shell looks for it.

```bash
php bin/pramnos init --web-root=public
```

Everything under the document root follows it: the directory, the front controller, `.htaccess`,
assets, favicons, the API entry point, the SPA shell and build output, the `.gitignore` lines,
the Docker `DocumentRoot`, and the prose in generated files that names the path. A half-applied
option would be worse than none — a project that looks configured and is broken in a way the
configuration appears to explain — so there is a test that scaffolds with a non-default root and
asserts on the **tree**, including that nothing was left in `www/`.

Slashes and whitespace are trimmed, so `--web-root=/public/` works. An empty value falls back to
`www` rather than scaffolding a front controller into the repository root, which is easy to
produce from a shell variable that did not expand and difficult to undo.

### The web root config `init` writes

All three application styles — `mvc`, `spa`, `hybrid` — get the same two blocks
above their own rules, because both were missing from all three:

- **The `Authorization` header, forwarded into the environment.** Apache does not
  pass it to PHP-FPM or CGI on its own, so a bearer token arrives as no token.
- **The `.well-known` discovery paths**, mapped to the `Discovery` controller —
  only when the `authserver` feature is enabled, since that is what scaffolds the
  controller they name.

The specific rules are emitted above the catch-all deliberately: `mod_rewrite`
runs rules in order, and the catch-all matches everything. See
[Third-Party Integration](Pramnos_AuthServer_Integration_Guide.md#if-discovery-answers-404)
for the block itself and for what to add to a project scaffolded before it
existed.

| Flag | Skips |
| --- | --- |
| `--no-install` | `composer update` and `dump-autoload` (and, under Docker, the migrations that need them) |
| `--no-download` | Fetching front-end library assets over HTTP |
| `--no-migrations` | `migrate --scope=framework` after Docker startup |
| `--dry-run` | Everything, including all file writes |

To add pieces to a project that already exists, use the `project:` commands
(`project:reconfigure`, `project:install`, `project:resync`) rather than `init` — and
`scaffold:spa` to add a front end, which writes what `init --app-style=spa` writes
without touching a file the project already has.

### Code Generation Commands

The framework provides comprehensive code generation through the `create` command:

```bash
# Create a new model
php bin/pramnos create:model User

# Create a controller
php bin/pramnos create:controller UserController

# Create a view
php bin/pramnos create:view User

# Create complete CRUD system (model + controller + view)
php bin/pramnos create:crud User

# Create API endpoint
php bin/pramnos create:api UserAPI

# Create database migration
php bin/pramnos create:migration CreateUsersTable
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

> **Fixed 2026-09-02.** It was not true before. The branch set a flag and printed «left untouched», and
> an unconditional `file_put_contents()` a few statements further down regenerated the file from the
> schema — so every hand-written method went, while the command reported success. And that was the
> *second* version of this path: the first called `updateModel()`, which does not exist anywhere in the
> framework, so the same command died with a fatal error instead. Neither had ever been executed by a
> test, because the branch turns on `class_exists()` and a generated model is not autoloadable in the
> framework's own checkout.

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
its category used to be and saving clears the reference.

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

### Project Reconfiguration Commands (`project:`)

These commands change an **existing** project after it was scaffolded — no need to re-run
`init`. They are BC-safe and idempotent (re-running does nothing new).

!!! warning "The supervisor decides the lock path, not the worker"
    `DaemonOrchestrator` writes the `.stop` sentinel beside the `lockFile` declared in
    a worker's desired-process entry, and exports that path to the child as
    `PRAMNOS_JOB_LOCK_FILE`. `CommandBase` reads it in preference to anything the
    command computes, so **an override cannot disagree with the supervisor.**

    It used to. The worker resolved its own path through `getJobLockFilePath()`, which
    has its own default (`ROOT/var/<job>`) and is overridable — two independent
    computations of one path, and nothing could notice when they diverged: **a sentinel
    read where nothing writes is indistinguishable from no sentinel.** No error, no
    log, a worker reporting itself healthy while ignoring every stop request.

    Reported by a project whose loop workers overrode the method to match their
    supervisor and whose realtime worker did not, so adopting the WebSocket stop seam
    landed as a no-op — the fix for a silent failure reproduced it, in the project that
    had just filed it.

    `getJobLockFilePath()` stays overridable: it is a legitimate application default,
    and it still applies when a command is run by hand with no supervisor. What is no
    longer overridable is the part that prefers the supervisor's answer.

!!! info "`features` from `app.php` are active on the CLI too"
    A command — and any daemon it runs — sees the same feature state as the web
    application: `FeatureRegistry::isEnabled('authserver')` answers from the
    `features` array in `app/app.php`, whichever entry point asked.

    That was not always true. `Application::getInstance()` reads `app.php` into
    `applicationInfo`, but the call that turns its `features` list into registry
    state lived only in `Application::init()`, which the web lifecycle runs and a
    console command does not. So every feature read as **disabled** inside every
    command, however `app.php` was written — and a long-running daemon deciding
    anything from a feature flag reached the opposite conclusion from the web
    application reading the same file, with nothing to report that the two
    disagreed. Feature state has to mean one thing per installation, not one thing
    per entry point.

    One consequence to know about: a `features` entry naming something unregistered
    now raises `UnknownFeatureException` on the CLI as it already did on the web,
    rather than being silently ignored. The message names the unknown key and lists
    the valid ones.

```bash
# Interactive umbrella: enable features and/or add libraries
php bin/pramnos project:reconfigure

# Show what is currently enabled/installed
php bin/pramnos project:reconfigure --status

# Enable framework features (records them in app/app.php, installs each
# feature's declared front-end libraries, prints follow-up steps)
php bin/pramnos project:reconfigure --enable-feature=queue,messaging

# Add front-end libraries (installs into www/assets/vendor and registers them
# in src/Application.php)
php bin/pramnos project:reconfigure --add-library=leaflet,select2

# Install only the missing/mandatory libraries (focused, scriptable)
php bin/pramnos project:install

# See per-library install/registration status
php bin/pramnos project:install --list

# Re-download even if present / download without editing Application.php
php bin/pramnos project:install --force
php bin/pramnos project:install --no-register
```

**Mandatory libraries.** A library flagged `"mandatory": true` in
`scaffolding/assets.json` (currently Chart.js, required by the log analytics dashboard) is
always ensured by both `init` and the `project:` commands — so upgrading an old project to
get Chart.js is simply `php bin/pramnos project:install`.

**Feature → libraries.** A feature can declare the front-end libraries it needs via the
`libraries` key in its `FeatureRegistry` definition; enabling it with
`project:reconfigure --enable-feature=` installs them automatically.

**One palette, built for every UI system.** `theme:build` reads `app/themes/theme.css` — the
format daisyUI's theme generator emits — and writes the tokens as plain custom
properties plus JSON, which is what a project without npm needs to be themed:

```bash
pramnos theme:build            # www/assets/css/theme-tokens.css + theme-tokens.json
pramnos theme:build --check    # exit 1 if they are stale — for CI
```

See [One palette, every UI system](Pramnos_Theme_Guide.md#one-palette-every-ui-system).

**A vendored stylesheet brings what it points at.** A CSS file is rarely the whole
library: FontAwesome's `all.min.css` is nothing but `@font-face` rules naming
`../webfonts/*.woff2`, and a Google Fonts stylesheet is a list of absolute
`fonts.gstatic.com` URLs. Every `url()` in a downloaded stylesheet is now fetched into a
`files/` directory beside it and the reference rewritten, so "install locally" means
locally. A failed download leaves the original URL in place — a stylesheet that
half-works beats one pointing at a file that is not there.

Two keys exist for hosts that care who is asking:

```json
"inter": {
    "css": ["https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700"],
    "user_agent": "Mozilla/5.0 (…) Chrome/126.0 Safari/537.36",
    "local_path": "assets/vendor/inter/latest"
}
```

`user_agent` is sent for that entry — Google serves `woff2` to a browser and `ttf` to
anything else. And a stylesheet URL that is not a filename (`css2?family=…`) is saved as
`<key>.css` rather than as a file with no extension that no server sends as CSS.

The `plain-css` theme uses this for its typeface: `init` vendors Inter instead of linking
Google's copy, which the project's own generated CSP (`style-src 'self'`) refused
outright.

After enabling a feature, run its migrations (`php bin/pramnos migrate`) and, if it ships
views, publish them (`php bin/pramnos project:publish-views --list` then `--group=<name>`).

**A published view is yours, and stops receiving fixes.** That is the trade: publishing
gives you a copy to edit, and the bundled copy keeps being corrected without you. When a
changelog entry names a view group, `--force` takes the newer copy over yours:

```bash
php bin/pramnos project:publish-views --group=queue,permissions,organizations --force
```

It also brings **new** screens in a group you have already published — an entity that
gained a `view` screen has a view file your copy of the group does not.

**Render a published view once with a row in it.** The bugs that survive longest in a view
are the ones an empty table cannot show: with no rows there are no action links, so a link
built from a key the controller never returns — `$job['id']` where the column is `taskid` —
renders fine and addresses record zero on every row. An empty test database renders nothing
else.

#### `project:resync` — refresh framework-owned files

Some scaffolded files stay the framework's: it keeps improving them, and an existing project
keeps its stale copy forever unless something re-copies it. `project:resync` is that
something.

```bash
php bin/pramnos project:resync                # refresh every framework-owned file present
php bin/pramnos project:resync --dry-run      # preview, write nothing
php bin/pramnos project:resync --all          # also copy files not in the project yet

php bin/pramnos project:resync --js           # only the pf-*.js CSP-safe UI hooks
php bin/pramnos project:resync --scripts      # only the API-docs tooling
php bin/pramnos project:resync --debug-panel  # only the SPA debug panel (lib/debug.js)
```

**By default it only refreshes what is already there.** A file the project never had is
reported as skipped, so the command never adds tooling nobody opted into; `--all` is the
explicit "yes, add it" switch.

**A file it could not write is reported as `failed`, and the command exits non-zero.**

```
  failed    frontend/lib/debug.js (could not write)
            Usually a permissions problem: the user running this command must be able
            to write the file. Check ownership, or re-run as the user that owns the
            project.

Done. 0 created, 0 updated, 0 unchanged, 0 skipped, 1 FAILED.
```

Until 2026-08-16 that write was unchecked: a resync that could not write still printed
`updated`, counted the file as updated in the summary, and exited `0`. The only trace
was a PHP warning on stderr, which a CI job or a habitual `2>/dev/null` discards — so a
deploy script checking the exit code, which is the right way to run this, was told the
resync had succeeded while the file on disk was still the old one.

That inverts the point of the command. Run it from CI and **check the exit code**; the
whole value of `project:resync` is being able to say a framework-owned file downstream
*is* the framework's current one.

The three groups:

| Flag | What it owns |
|------|--------------|
| `--js` | `www/assets/js/pf-*.js` — the CSP-safe UI hook scripts |
| `--scripts` | `scripts/apidoc-to-openapi.cjs`, `scripts/doc.sh`, plus the API-docs npm scripts merged into `package.json` and the framework endpoints merged into `src/Api/openapi-overrides.json` |
| `--debug-panel` | `lib/debug.js` — the SPA debug panel, in `frontend/lib/` or `www/assets/js/lib/` depending on this project's stack (read from `app_style`/`spa_stack`, not guessed) |

Merges preserve what the project declared: user-added npm scripts, extra OpenAPI paths and
schemas survive. What is **not** rewritten is `lib/api.js` — it is the project's own file and
gets edited, so if it never calls `recordDebug` the command says so and prints the two lines
to add rather than regenerating it. See the
[Debugging guide](Pramnos_Debugging_Guide.md#in-a-single-page-application) for why the debug
panel is framework-owned.

### Operational & diagnostic commands

```bash
# Flush the application cache (all categories, or one with --category)
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

`--admin` creates the account at **usertype 90** — the tier the framework's own
administrative screens require:

| Screen | Minimum usertype |
|---|---|
| Users, Settings, Logs, Dashboard, Services, Organizations, Emails, Queue | 80 |
| Applications, Tokens, Permissions, `/health/phpinfo`, the dev panel | 90 |

This is worth stating because the option used to set 1, which satisfies none of
them: the command printed "created successfully (admin)" and the account it made
could not open a single administrative page. `init` has always created its first
administrator at 90, so the two paths disagreed and the one this command produced
was the broken one.

For anything in between, name the tier:

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

### What `init` writes for AI assistants

Every scaffolded project gets two files aimed at coding assistants:

- **`CLAUDE.md`** — the project's own conventions: stack, namespace, how to run tests, where
  the front end lives, and **where the framework's guides are**. The docs directory ships
  inside the composer package, so it is present in the project:

  ```
  vendor/mrpc/pramnosframework/docs/
  ```

  Those guides match the installed framework version — they cannot drift from the code the
  way an external web page can. Each opens with `use_cases:` frontmatter naming the tasks it
  covers, so the right page can be chosen without reading the directory:

  ```bash
  head -12 vendor/mrpc/pramnosframework/docs/*.md
  ```

  The generated `CLAUDE.md` states the two consequences explicitly: the guides describe
  current state while `docs/version-history/posts/` is history, and a capability documented in
  a guide is not to be reimplemented in the project. That instruction exists because the
  opposite happened — the SPA debug panel was rebuilt in a project beside the framework's
  working one, which had been documented only in changelog posts at the time.

- **`.mcp.json`** — registers the framework's own MCP server (`mcp:serve`), which exposes this
  application's tables, schema, migrations, models and routes to an assistant instead of
  requiring a separate database MCP server.

  The server reports **`app/app.php`'s `name`** as its own, so a client that lists
  several projects can tell them apart. It reads the configuration file rather than a
  database-stored setting: the name is right there, no connection is involved, and a
  project whose settings load fails for any reason would otherwise have shown the
  framework's generic default for every one of its servers.

  **The five tools, and what `route-list` can actually see.** `list-tables`,
  `migration-status`, `model-inspect`, `query-schema` and `route-list`.

  `route-list` runs under the **console** kernel, which builds no router — routing is an HTTP
  concern. So it builds one and discovers `#[Route]` attributes, using the PSR-4 map in the
  project's own `composer.json` rather than assuming `src/Controllers`. That means:

  - **attribute-routed controllers are listed**, with methods, URIs, actions and permissions;
  - **routes registered inside a `routes.php` that dispatches at the end cannot be** — including
    that file would serve a request rather than describe one, and the tool says so instead of
    returning nothing.

  Until 2026-08-14 it answered `{"error": "No router available"}` on every call, and
  `query-schema` returned PostgreSQL's `ERROR: column "conname" does not exist` — both inside
  `content[0].text` with `isError` false, so a server advertising five working tools had two that
  could not answer and nothing said so. Worth knowing as a shape: an MCP tool that returns an
  error *as a result* is invisible to anything watching for failures.

### Favicons & branding

`init` scaffolds a complete favicon / PWA-icon set into every new project, copied from the
framework's canonical `brand/favicons/` directory:

- `www/favicon.ico`, `www/manifest.json`, `www/browserconfig.xml` — at the web root.
- `www/assets/favicons/*.png` — all sized Apple / Android / Windows-tile icons.

The `manifest.json` is stamped with the application name and its icon paths (and the
`browserconfig.xml` tile paths) are rewritten to the `assets/favicons/` subdir, so they
resolve correctly under any base path. The matching `<link>` / `<meta>` tags are injected
into the theme header for all UI systems. Replace the files under `www/assets/favicons/`
(and `www/favicon.ico`) with your own artwork to rebrand a project.

The theme header also renders a **logo image** (rather than the app name as text). `init`
copies two ink variants — `www/assets/img/logo.png` (dark ink, for light navbars) and
`www/assets/img/logo-inverse.png` (light ink, for dark navbars) — and each theme references
the one that reads on its navbar background. The app name is kept as the image's `alt` text.
Replace these files with your own logo to rebrand.

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

## Model Generation

### Basic Model Creation

```bash
php bin/pramnos create:model User
```

This generates a model class with:
- Database table mapping
- Primary key configuration
- Basic CRUD methods
- Type-safe property declarations
- API list method with pagination

### Which column becomes the primary key

From the table, when there is a table to ask. `create:model` reads the key out of the schema, so a
legacy table keyed on `customer_id` gets a model that says `customer_id` — it used to derive the
name by convention (singular table name plus `id`) and produce `customerid`, which is not a column:
such a model loads nothing and inserts a new row on every save, which presents as «the edit form
does not work». `create:api` had always read the schema, so the two generators disagreed about the
same table.

The convention is still the answer in the two cases where the schema cannot be asked:

- the **migration-wizard path**, where the migration has been written and not yet run, so the table
  does not exist;
- a **composite primary key**, because `Pramnos\Application\Model` addresses a row by one column
  and there is no honest single answer — the convention at least produces a name a developer will
  recognise as needing an edit.

### Generated Model Structure

```php
<?php
namespace MyApp\Models;

/**
 * User Model
 * Auto generated at: 25/12/2024 10:30
 */
class User extends \Pramnos\Application\Model
{
    /**
     * User ID
     * @var int
     */
    public $userid;
    
    /**
     * Username
     * @var string
     */
    public $username;
    
    /**
     * Email address
     * @var string
     */
    public $email;
    
    /**
     * Primary key in database
     * @var string
     */
    protected $_primaryKey = "userid";

    /**
     * Database table
     * @var string
     */
    protected $_dbtable = "users";

    /**
     * Load from database
     * @param int $userid ID to load
     * @param string $key Primary key on database
     * @param boolean $debug Show debug information
     * @return $this
     */
    public function load($userid, $key = NULL, $debug = false)
    {
        return parent::_load($userid, null, $key, $debug);
    }

    /**
     * Save to database
     * @param boolean $autoGetValues If true, get all values from $_REQUEST
     * @param boolean $debug Show debug information (and die)
     * @return $this
     */
    public function save($autoGetValues = false, $debug = false)
    {
        return parent::_save(null, null, $autoGetValues, $debug);
    }

    /**
     * Delete from database
     * @param integer $userid ID to delete
     * @return $this
     */
    public function delete($userid)
    {
        return parent::_delete($userid, null, null);
    }

    /**
     * Get an API-formatted list with pagination, field selection, and search capabilities
     */
    public function getApiList($fields = array(), $search = '', 
        $order = '', $page = 0, $itemsPerPage = 10, 
        $debug = false, $returnAsModels = false, $useGetData = true)
    {
        return parent::_getApiList(
            $fields, $search, $order, '', '', '',
            null, null, $page, $itemsPerPage, $debug, $returnAsModels, $useGetData
        );
    }
}
```

### Model Generation Options

```bash
# Generate model for specific table
php bin/pramnos create:model User --table=custom_users

# Generate model with schema specification (PostgreSQL)
php bin/pramnos create:model User --schema=public
```

### Model Registry

Generated models are automatically registered in `app/model-registry.json`:

```json
[
    {
        "className": "User",
        "namespace": "MyApp\\Models",
        "fullClassName": "MyApp\\Models\\User",
        "table": "users",
        "schema": "",
        "createdAt": "2024-12-25T10:30:00+00:00",
        "updatedAt": "2024-12-25T10:30:00+00:00"
    }
]
```

## Controller Generation

`create:controller` always generates a complete CRUD controller from the
database table schema. (The former "simple skeleton" mode and the `--full`
flag have been removed — the command has a single, predictable behaviour.)

```bash
php bin/pramnos create:controller User
```

The table for the entity must already exist (create it first with
`create:migration`); the generator introspects it. If the table is missing you
get a clear error pointing you to `create:migration`.

This generates a complete CRUD controller with:
- Display action (list view)
- Show action (detail view)
- Edit action (create/update form)
- Save action (form processing)
- Delete action (record removal)
- JSON data method for datatables

### Generated Controller Structure

```php
<?php
namespace MyApp\Controllers;

/**
 * User Controller
 * Auto generated at: 25/12/2024 10:30
 */
class User extends \Pramnos\Application\Controller
{
    /**
     * User controller constructor
     * @param Application $application
     */
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        // Public reads (detail + the JSON data feed) vs. login-gated writes.
        $this->addaction(['show', 'data']);
        $this->addAuthAction(['edit', 'save', 'delete']);
        parent::__construct($application);
    }
    
    /**
     * Display a listing of the resource
     * @return string
     */
    public function display()
    {
        // Server-side DataTable: the view renders the shell; rows load over
        // AJAX from data(), so the list scales to large tables.
        $dt = new \Pramnos\Html\Datatable('dt-user');
        $dt->source = sURL . 'User/data';
        $dt->addColumn('Username')
           ->addColumn('Email')
           ->addColumn('Actions', true, false, false, 'html');

        $view = $this->getView('user');
        $view->datatable = $dt;
        $this->application->addbreadcrumb('User', sURL . 'User');
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'User';
        return $view->display();
    }

    /**
     * Display the specified resource
     * @return string
     */
    public function show()
    {
        $view = $this->getView('user');
        $model = new \MyApp\Models\User($this);
        $request = new \Pramnos\Http\Request();
        $model->load($request->getOption());
        $view->addModel($model);
        $this->application->addbreadcrumb('User', sURL . 'User');
        $this->application->addbreadcrumb('View ' . $model->userid, sURL . 'User/show/' . $model->userid);
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->title = $model->userid . ' | User';
        return $view->display('show');
    }

    /**
     * Show the form for creating a new resource or editing an existing one
     * @return string
     */
    public function edit()
    {
        $view = $this->getView('user');
        $model = new \MyApp\Models\User($this);
        $request = new \Pramnos\Http\Request();
        $model->load($request->getOption());
        $view->addModel($model);

        $this->application->addbreadcrumb('User', sURL . 'User');
        if ($model->userid > 0) {
            $this->application->addbreadcrumb('View ' . $model->userid, sURL . 'User/show/' . $model->userid);
            $this->application->addbreadcrumb('Edit', sURL . 'User/edit/' . $model->userid);
        } else {
            $this->application->addbreadcrumb('Create', sURL . 'User/edit/0');
        }
        
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->title = ($model->userid > 0 ? 'Edit' : 'Create') . ' | User';
        
        return $view->display('edit');
    }

    /**
     * Store a newly created or edited resource in storage.
     */
    public function save()
    {
        $model = new \MyApp\Models\User($this);
        $request = new \Pramnos\Http\Request();
        $model->load($request->getOption());
        
        // Auto-generated field assignments based on database schema
        $model->username = trim(strip_tags($request->get('username', '', 'post')));
        $model->email = trim(strip_tags($request->get('email', '', 'post')));
        $model->firstname = trim(strip_tags($request->get('firstname', '', 'post')));
        
        $model->save();
        $this->redirect(sURL . 'User');
    }

    /**
     * Remove the specified resource from storage
     */
    public function delete()
    {
        $model = new \MyApp\Models\User($this);
        $request = new \Pramnos\Http\Request();
        $model->delete($request->getOption());
        $this->redirect(sURL . 'User');
    }

    /**
     * AJAX data endpoint for the server-side DataTable — returns JSON.
     */
    public function data()
    {
        \Pramnos\Framework\Factory::getDocument('json');
        $result = \Pramnos\Html\Datatable\Datasource::getList(
            'users', ['userid', 'username', 'email'], false
        );
        // Each row gets View/Edit/Delete action links appended here.
        echo json_encode($result);
        $this->terminate();
    }
}
```

## View Generation

### Basic View Creation

```bash
php bin/pramnos create:view User
```

### Full CRUD Views

```bash
php bin/pramnos create:view User --full
```

`--full` introspects the entity's table and generates the complete admin-style,
per-theme (plain-css / bootstrap / tailwind) CRUD view set — the same output as
`create:crud`. The table must already exist (otherwise it errors, pointing you to
`create:migration`). Without `--full`, `create:view` writes a single, table-free
placeholder view instead.

The full set:
- `<view>.html.php` - List view (renders the controller's server-side DataTable)
- `edit.html.php` - Create/edit form
- `show.html.php` - Detail view

## Front-End Generation (SPA projects)

The counterparts of `create:view` and `create:service`, for the Svelte stack.

### `create:screen`

```bash
php bin/pramnos create:screen Invoices --table=invoices   # a CRUD screen
php bin/pramnos create:screen Dashboard --blank           # no list
php bin/pramnos create:screen Invoices --resource=invoice  # over an endpoint
```

With `--table` it produces exactly what `create:crud` produces for its
front-end half, and nothing else: no model, no API controller, no routes. That
is the case where the API already exists — hand-written, or somebody else's —
and only the screen is missing.

`--blank` writes a screen with no list, for a dashboard, a report or a settings
page. The generated CRUD screen is a poor starting point for those: two thirds
of it is list plumbing to delete, and what remains imports components it no
longer uses.

Every screen registers itself in `screens/registry.js`, so it is reachable
without editing `App.svelte`. A screen the registry does not name is a file the
bundler does not even include.

> Before this command existed, `createSpaScreen()` was reachable only through
> `create:crud` — so the way to add a dashboard was to generate a CRUD for a
> table you did not want and delete most of it. `create:view` exists on the MVC
> side for exactly that reason.

### `create:component`

```bash
php bin/pramnos create:component StatusBadge
```

Writes **two** files: `components/StatusBadge.svelte` and
`__tests__/StatusBadge.test.js`. The test is the point of the command rather
than a nicety — `create:service` writes a test stub, which is why services in a
scaffolded project have tests, and the front end had no such command, which is
why components did not.

The name is used as you typed it (`sales-report` becomes `SalesReport`); it is
not singularised or pluralised, because a component is not a database table.

### What `create:crud` generates for a SPA

The screen is a peer of the generated API controller, and every control matches
the column's **type**, read from the same introspection the MVC generator uses:

| Column | Control |
|---|---|
| `boolean` / `tinyint(1)` | checkbox |
| `text` / `longtext` / `json` | textarea |
| `date` | `<input type="date">` |
| `datetime` / `timestamp` | `<input type="datetime-local">`, converted to the space form the database wants |
| `integer` / `decimal` / `float` | `<input type="number">`, with `step="any"` where fractions are allowed |
| a foreign key | a searchable picker against the referenced resource's own list endpoint |

The `COLUMN COMMENT` becomes the field's label, `NOT NULL` becomes `required`,
and a blank nullable field is saved as `null` rather than `''` — they compare,
sort and `COALESCE` differently, and a form that cannot express the difference
converts every unset optional column to `''` on the first save.

Columns in the generator's exclusion list — `password`, `salt`, `apikey`,
`token`, the model-maintained timestamps — are never offered.

The list sorts, searches and pages **on the server**, and its state lives in the
URL: a link to "page 3, sorted by listeners" is a link somebody can send, and a
background re-read leaves the reader where they were.

### The shared components

A generated screen imports five files, written once per project and **never
overwritten** afterwards:

| File | What it is |
|---|---|
| `components/DataTable.svelte` | Table + card layouts from one column definition; consumes `ApiListResponse::paginated()` |
| `components/Pagination.svelte` | Numbered, windowed, keyboard-reachable |
| `components/ConfirmDialog.svelte` | Focus trap, Escape, optional typed-phrase mode |
| `components/Field.svelte` | The control-per-type renderer described above |
| `lib/i18n.svelte.js` | `t()` / `tHtml()`, a client for the framework's own catalogue |

Each ships with its test, into the project's `__tests__/` — where the project's
Vitest runner will actually run them.

They stop being the framework's files the moment they exist, because the whole
value of shipping a `DataTable` is that a project extends it. To take a newer
version deliberately:

```bash
php bin/pramnos project:resync --spa-components          # report what differs
php bin/pramnos project:resync --spa-components --all    # overwrite local edits
```

It is **not** included in a plain `project:resync`, for the same reason.

### Generated View Examples

#### List View (user.html.php)

The DataTable is built and configured in the controller (`display()`); the view
just renders the widget shell inside the theme wrapper. The columns and AJAX
data source live in the controller, so the view stays thin (this is the
plain-css theme; bootstrap/tailwind use their own wrappers):
```php
<div class="page-section">
    <?php echo \Pramnos\Application\Application::getInstance()->renderBreadcrumbs(); ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2>Users</h2>
        <a href="<?php echo sURL; ?>User/edit/0" class="btn btn-primary">+ New User</a>
    </div>
    <?php echo $this->datatable->render(); ?>
</div>
```

#### Edit Form (edit.html.php)
```php
<div class="card">
    <div class="card-body">
        <form action="[sURL]User/save/<?php echo $this->model->userid; ?>" method="post" role="form">
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" value="<?php echo $this->model->username; ?>" 
                       id="username" name="username" class="form-control">
            </div>

            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" value="<?php echo $this->model->email; ?>" 
                       id="email" name="email" class="form-control">
            </div>

            <div class="form-group">
                <label for="firstname">First Name:</label>
                <input type="text" value="<?php echo $this->model->firstname; ?>" 
                       id="firstname" name="firstname" class="form-control">
            </div>

            <div class="form-group">
                <button type="submit" class="btn btn-primary"><?php l('Save'); ?></button>
            </div>
        </form>
    </div>
</div>
```

## API Generation

### Creating API Endpoints

```bash
php bin/pramnos create:api UserAPI
```

The target directory is created if it is not there, parents included — a project adding its
first API controller has no `Api/` yet. If it cannot be created the command **fails with the
path**, rather than reporting a file it did not write; three of these generators used to do the
latter, which sent people looking for a bug in generated code that was never on disk.

This generates a complete REST API controller with:
- GET endpoints for listing and individual records
- POST endpoints for creating records
- PUT endpoints for updating records
- DELETE endpoints for removing records
- Automatic API documentation (PHPDoc format)

### Generated API Controller

```php
<?php
namespace MyApp\Api\Controllers;

/**
 * UserAPI Controller
 * Auto generated at: 25/12/2024 10:30
 */
class UserAPI extends \Pramnos\Application\Controller
{
    /**
     * @api {get} 1.0/user List
     * @apiVersion 1.0.0
     * @apiGroup User
     * @apiName listUser
     * @apiDescription List of User objects with pagination, search, sorting and field selection
     *
     * @apiHeader {String} apiKey Application unique api key
     * @apiHeader {String} accessToken Authenticated user access token
     *
     * @apiParam  {Number} [page=0] Page number for pagination. Set to 0 to get all results
     * @apiParam  {Number} [limit=20] Limit number of results per page
     * @apiParam  {String} [sort] Sort by field. Syntax: [+-]fieldname,[+-]fieldname
     * @apiParam  {String} [search] Global search term or JSON object for field-specific search
     * @apiParam  {String} [fields] Specify which fields to return (comma-separated or JSON array)
     */
    public function display()
    {
        $user = \Pramnos\User\User::getCurrentUser();
        if (!is_object($user) || (int) $user->userid < 2) {
            return array('status' => 401);
        }
        if ($user->userid < 2) {
            return array('status' => 401);
        }
        
        $model = new \MyApp\Models\User($this);
        
        // Get parameters from request
        $fields = \Pramnos\Http\Request::staticGet('fields', array(), 'get');
        $search = \Pramnos\Http\Request::staticGet('search', '', 'get');
        $sort = \Pramnos\Http\Request::staticGet('sort', '', 'get');
        $page = (int) \Pramnos\Http\Request::staticGet('page', 0, 'get', 'int');
        $limit = (int) \Pramnos\Http\Request::staticGet('limit', 20, 'get', 'int');
        
        // Use the new getApiList method for enhanced pagination, search, and field selection
        return $model->getApiList(
            $fields, 
            $search, 
            $sort, 
            $page, 
            $limit,
            false, // debug
            false, // returnAsModels
            false   // useGetData
        );
    }

    /**
     * @api {get} 1.0/user/:userid Read
     * @apiVersion 1.0.0
     * @apiGroup User
     * @apiName readUser
     * @apiDescription Read a specific User object
     *
     * @apiHeader {String} apiKey Application unique api key
     * @apiHeader {String} accessToken Authenticated user access token
     * @apiParam  {Number} userid Id to load
     */
    public function readUser($userid)
    {
        $user = \Pramnos\User\User::getCurrentUser();
        if (!is_object($user) || (int) $user->userid < 2) {
            return array('status' => 401);
        }
        if ($user->userid < 2) {
            return array('status' => 401);
        }
        
        $model = new \MyApp\Models\User($this);
        $model->load((int) $userid);
        if ($model->userid == 0) {
            return array('status' => 404);
        }
        $data = $model->getData();
        return array('data' => $data);
    }

    /**
     * @api {post} 1.0/user Create
     * @apiVersion 1.0.0
     * @apiGroup User
     * @apiName createUser
     * @apiDescription Create a User
     *
     * @apiHeader {String} apiKey Application unique api key
     * @apiHeader {String} accessToken Authenticated user access token
     * 
     * @apiBody {String} username Username
     * @apiBody {String} email Email address
     * @apiBody {String} [firstname] First name
     */
    public function createUser()
    {
        $user = \Pramnos\User\User::getCurrentUser();
        if (!is_object($user) || (int) $user->userid < 2) {
            return array('status' => 401);
        }
        
        $model = new \MyApp\Models\User($this);

        $model->username = trim(strip_tags(\Pramnos\Http\Request::staticGet('username', '', 'post')));
        $model->email = trim(strip_tags(\Pramnos\Http\Request::staticGet('email', '', 'post')));
        $model->firstname = trim(strip_tags(\Pramnos\Http\Request::staticGet('firstname', '', 'post')));

        $model->save();
        
        return array(
            'status' => 201,
            'data' => $model->getData()
        );
    }

    /**
     * @api {put} 1.0/user/:userid Update
     * @apiVersion 1.0.0
     * @apiGroup User
     * @apiName updateUser
     * @apiDescription Update a specific User object
     *
     * @apiHeader {String} apiKey Application unique api key
     * @apiHeader {String} accessToken Authenticated user access token
     * @apiParam  {Number} userid Id to update
     */
    public function updateUser($userid)
    {
        $user = \Pramnos\User\User::getCurrentUser();
        if (!is_object($user) || (int) $user->userid < 2) {
            return array('status' => 401);
        }
        
        $model = new \MyApp\Models\User($this);
        $model->load((int) $userid);
        if ($model->userid == 0) {
            return array('status' => 404);
        }

        $model->username = trim(strip_tags(\Pramnos\Http\Request::staticGet('username', $model->username, 'put')));
        $model->email = trim(strip_tags(\Pramnos\Http\Request::staticGet('email', $model->email, 'put')));
        $model->firstname = trim(strip_tags(\Pramnos\Http\Request::staticGet('firstname', $model->firstname, 'put')));
        
        $model->save();
        return array(
            'status' => 202,
            'data' => $model->getData()
        );
    }

    /**
     * @api {delete} 1.0/user/:userid Delete
     * @apiVersion 1.0.0
     * @apiGroup User
     * @apiName deleteUser
     * @apiDescription Delete a User
     *
     * @apiHeader {String} apiKey Application unique api key
     * @apiHeader {String} accessToken Authenticated user access token
     * @apiParam  {Number} userid Id to delete
     */
    public function deleteUser($userid)
    {
        $model = new \MyApp\Models\User($this);
        $model->load((int) $userid);
        if ($model->userid == 0) {
            return array('status' => 404);
        }
        $model->delete($userid);
        return array('status' => 202);
    }
}
```

### API Route Registration

API routes are automatically added to `src/Api/routes.php`:

```php
$router->delete(
    '/user/{userid}',
    function ($userid) {
        $controller = $this->getController('UserAPI');
        return $controller->deleteUser($userid);
    }
);

$router->put(
    '/user/{userid}',
    function ($userid) {
        $controller = $this->getController('UserAPI');
        return $controller->updateUser($userid);
    }
);

$router->get(
    '/user/{userid}',
    function ($userid) {
        $controller = $this->getController('UserAPI');
        return $controller->readUser($userid);
    }
);

$router->get(
    '/user',
    function () {
        $controller = $this->getController('UserAPI');
        return $controller->display();
    }
);

$router->post(
    '/user',
    function () {
        $controller = $this->getController('UserAPI');
        return $controller->createUser();
    }
);
```

## CRUD Generation

### Complete CRUD System

```bash
php bin/pramnos create:crud User
```

This single command creates:
1. Model with database mapping
2. Controller with full CRUD operations
3. Views for list, create, edit, and detail pages

The output shows the status of each component:

```
Creating Model: OK
Creating Controller: OK
Creating View: OK
```

## Migration System

### Creating Migrations

```bash
php bin/pramnos create:migration CreateUsersTable
```

This generates a migration class:

```php
<?php
namespace MyApp\Migrations;

/**
 * CreateUsersTable migration
 * Auto generated at: 25/12/2024 10:30
 */
final class MigrationCreateUsersTable extends \Pramnos\Database\Migration
{
    /**
     * Version that this migration sets
     * @var string
     */
    public $version = 'CreateUsersTable';
    
    /**
     * Description of the migration
     * @var string
     */
    public $description = '';
    
    /**
     * Should the migration executed automatically
     * @var bool
     */
    public $autoExecute = true;

    /**
     * Run the migration
     * @return void
     */
    public function up() : void
    {
        // this up() migration is auto-generated, please modify it to your needs
    }

    /**
     * Undo the migration
     * @return void
     */
    public function down() : void
    {
        // this down() migration is auto-generated, please modify it to your needs
    }
}
```

### Migration Registry

Migrations are automatically registered in `app/migrations.php`:

```php
<?php
return [
    /*
    |--------------------------------------------------------------------------
    | Migrations List
    |--------------------------------------------------------------------------
    |
    | These migrations will be executed in order on application execution
    |
    */
    'CreateUsersTable' => 'MigrationCreateUsersTable'
];
```

## Development Server

### Starting the Server

```bash
# Start on default port (8000)
php bin/pramnos serve

# Start on custom port
php bin/pramnos serve --port=8080

# Start with custom host
php bin/pramnos serve --host=0.0.0.0 --port=8080
```

The development server provides:
- Hot reloading for PHP files
- Automatic routing
- Error display
- Access to framework features

## Log Migration

### Migrating Log Files

The framework includes a powerful log migration system to convert legacy log formats to structured JSON:

```bash
# Migrate all .log files in a directory
php bin/pramnos logs:convert /path/to/logs --all

# Migrate specific file
php bin/pramnos logs:convert /path/to/application.log

# Migrate without backup
php bin/pramnos logs:convert /path/to/application.log --no-backup
```

### Migration Features

- **Preserves timestamps** - Extracts timestamps from various log formats
- **Handles multiline logs** - Properly processes stack traces and error messages
- **Creates backups** - Original files are backed up with `.bak` extension
- **Progress tracking** - Shows progress bar for large files
- **Error handling** - Continues processing if individual lines fail

### Example Migration Output

```
Processing: application.log

 1000/1000 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%

Migration completed successfully:
Files processed: 1 (Failed: 0)
Lines processed: 1000 (Converted: 987)
Duration: 0.45 seconds
```

## Advanced Features

### Foreign Key Detection

The code generator automatically detects foreign key relationships and generates appropriate model methods:

```php
// If a 'user_id' field is detected, this method is auto-generated
public function getUser()
{
    if ($this->user_id > 0) {
        $user = new \MyApp\Models\User($this);
        $user->load($this->user_id);
        return $user;
    }
    return null;
}
```

### Data Type Handling

The generator creates type-safe field assignments based on database schema:

```php
// Integer fields
$model->count = \Pramnos\Http\Request::staticGet('count', 0, 'post', 'int');

// Boolean fields  
$tmpVar = \Pramnos\Http\Request::staticGet('active', '', 'post');
if ($tmpVar == 'true' || $tmpVar == 'on' || $tmpVar == "yes" || $tmpVar === '1' || $tmpVar === 1) {
    $tmpVar = true; 
} else { 
    $tmpVar = false; 
} 
$model->active = $tmpVar;

// Float fields
$model->price = (float) \Pramnos\Http\Request::staticGet('price', '', 'post');

// String fields (with sanitization)
$model->name = trim(strip_tags(\Pramnos\Http\Request::staticGet('name', '', 'post')));
```

### API Documentation Generation

Generated API controllers include comprehensive PHPDoc annotations compatible with API documentation tools:

```php
/**
 * @api {get} 1.0/user List
 * @apiVersion 1.0.0
 * @apiGroup User
 * @apiName listUser
 * @apiDescription List of User objects with pagination, search, sorting and field selection
 *
 * @apiHeader {String} apiKey Application unique api key
 * @apiHeader {String} accessToken Authenticated user access token
 *
 * @apiParam  {Number} [page=0] Page number for pagination
 * @apiParam  {Number} [limit=20] Limit number of results per page
 * @apiParam  {String} [sort] Sort by field. Syntax: [+-]fieldname,[+-]fieldname
 * @apiParam  {String} [search] Global search term or JSON field-specific search
 * @apiParam  {String} [fields] Comma-separated or JSON array of fields to return
 *
 * @apiSuccess {Array} data List of User objects
 * @apiSuccess {Object} [pagination] Pagination information (only when page > 0)
 * @apiSuccess {Number} pagination.currentpage Current page number
 * @apiSuccess {Number} pagination.itemsperpage Items per page
 * @apiSuccess {Number} pagination.totalitems Total number of items
 * @apiSuccess {Number} pagination.totalpages Total number of pages
 * @apiSuccess {Boolean} pagination.hasnext Whether there is a next page
 * @apiSuccess {Boolean} pagination.hasprevious Whether there is a previous page
 * @apiSuccess {Array} fields List of fields included in the response
 */
```

## Configuration

### Console Application Setup

The console application is configured in `src/Pramnos/Console/Application.php`:

```php
protected function registerCommands()
{
    $this->add(new \Pramnos\Console\Commands\Make\MakeController());
    $this->add(new \Pramnos\Console\Commands\Serve());
    $this->add(new \Pramnos\Console\Commands\MigrateLogs());
    
    // Add custom commands here
    // $this->add(new \MyApp\Console\CustomCommand());
}
```

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
    protected function configure()
    {
        $this->setName('custom:task');
        $this->setDescription('Execute custom task');
        $this->setHelp('This command performs a custom task');
        
        $this->addArgument('parameter', InputArgument::REQUIRED, 'Required parameter');
        $this->addOption('option', 'o', InputOption::VALUE_OPTIONAL, 'Optional parameter');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $parameter = $input->getArgument('parameter');
        $option = $input->getOption('option');
        
        $output->writeln("Executing custom task with parameter: $parameter");
        
        // Your custom logic here
        
        return Command::SUCCESS;
    }
}
```

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
# Ensure the web server has write permissions to:
# - app/ directory (for registry files)
# - includes/ directory (for generated files)
# - logs/ directory (for logging)

chmod -R 755 app/ includes/ logs/
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

## CommandBase

`Pramnos\Console\CommandBase` — abstract base class for lock-guarded and interactive console commands. All long-running or daemon-style commands should extend this instead of `Command` directly.

```php
abstract class CommandBase extends \Symfony\Component\Console\Command\Command
{
    abstract protected function getJobName(): string;
}
```

### Lock-file job guards

```php
class MyDaemon extends \Pramnos\Console\CommandBase
{
    protected function getJobName(): string { return 'my_daemon'; }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->beginJob($output)) {
            $output->writeln('<error>Already running.</error>');
            return Command::FAILURE;
        }

        while (true) {
            $this->heartbeat();  // touch lock file so orchestrator knows we're alive
            // ... do work ...
        }

        $this->endJob();
        return Command::SUCCESS;
    }
}
```

| Method | Description |
|---|---|
| `beginJob(OutputInterface, bool $registerShutdown=true): bool` | Returns `false` if already running; acquires the lock (+ SIGINT cleanup handler) |
| `endJob(): void` | Releases and removes the lock (also called by shutdown/signal handlers); notifies systemd `STOPPING` |
| `heartbeat(array $extra=[]): bool` | Refreshes the lock's heartbeat (+ records `$extra` state, pings the systemd watchdog); `false` once the lock was taken over |
| `checkIfRunning(): bool` | Checks lock file + PID liveness; treats stale locks (>2 h) as gone |
| `installStopSignals(?callable $onStop=null): void` | Trap SIGTERM/SIGINT for a **cooperative** stop (see below) |
| `shouldStop(): bool` | The single loop guard: a trapped signal, or the supervisor's `.stop` sentinel |

The lock lifecycle is delegated to `Pramnos\Console\WorkerLock` (see below): `startJob()`
acquires it (writing a JSON lock), `heartbeat()` refreshes it, `endJob()` releases and
removes it. `readPidFromLockFile()` uses `WorkerLock::pidFromFile()`, which reads the JSON
`pid` and falls back to a legacy plain-text lock — so the single-instance guard behaves
exactly as before across an upgrade.

**Two stop models — pick one per command.** A simple lock-guarded command uses
`beginJob()`, whose SIGINT handler cleans up and exits immediately (fine for one-shot
work). A **long-running worker** instead calls `installStopSignals()` and loops on
`while (!$this->shouldStop())`: SIGTERM (systemctl stop / deploy) and SIGINT then only
raise a flag, so the current job finishes before the process exits. `shouldStop()` also
honours the supervisor's `<lock>.stop` sentinel, and `heartbeat()` returns `false` when
another worker took the lock over — three stop sources behind one guard:

```php
$this->installStopSignals();
$this->systemd()->ready();                 // no-op off systemd Type=notify
while (!$this->shouldStop()) {
    $processed = $this->processBatch(...);
    if (!$this->heartbeat(['jobs_processed' => $processed])) {
        break;                             // lease lost — a replacement took over
    }
    sleep($idle);
}
$this->endJob();                           // releases the lock, notifies STOPPING
```

### Worker liveness primitives

> **See the [Workers & Daemons Guide](Pramnos_Workers_And_Daemons_Guide.md)** for the full
> mental model (CommandBase / ProcessQueue / DaemonOrchestrator + these primitives), a
> decision guide, and end-to-end examples including a standalone-script worker and systemd/
> cron recipes. The summary below is the primitive reference.

Four standalone `Pramnos\Console` primitives for long-running CLI workers, each usable
directly by a bespoke worker script and all composed by `CommandBase` (so console workers
get them for free): **`WorkerLock`** (single-instance lock + heartbeat), **`WorkerReloader`**
(code/settings reload), **`SignalStop`** (cooperative graceful stop), **`SystemdNotifier`**
(sd_notify watchdog).

**`Pramnos\Console\WorkerLock`** — a single-instance lock + heartbeat that works where
advisory `flock()` silently doesn't (Docker bind mounts on macOS). The lock is a JSON
file whose atomic *creation* (`fopen($path,'x')`) is the mutex, and which doubles as the
heartbeat.

```php
use Pramnos\Console\WorkerLock;

$lock = new WorkerLock('chat-worker', WorkerLock::defaultPath('chat-worker'));
if (!$lock->acquire($takenOverFrom)) {
    exit("another worker holds the lock\n");
}
while ($working) {
    /* ... one job ... */
    if (!$lock->heartbeat(['jobs_processed' => ++$n])) break; // taken over → stop
    if ($lock->stopRequested()) break;                        // <path>.stop sentinel
}
$lock->release();
```

| Method | Description |
|---|---|
| `acquire(?string &$takenOverFrom=null): bool` | Win the lock; take over a dead/wedged holder (describing it in `$takenOverFrom`), refuse a live+progressing one |
| `heartbeat(array $extra=[]): bool` | Refresh the heartbeat; `false` once another worker has taken over |
| `release(string $status='stopped'): void` | Mark stopped and drop the held flag (keeps the file for status reads) |
| `isHeldByAnother(): bool` / `holderIsWedged(): bool` | Foreign-holder liveness / alive-but-not-heartbeating |
| `readState(): ?array` / `heartbeatAge(?array): ?int` / `stopRequested(): bool` | State inspection |
| `static pidFromFile(string): int` | JSON `pid`, falling back to a legacy plain-text lock (0 if absent) |
| `static defaultPath(string $name, ?string $dir=null): string` | `<dir>/<name>.lock` (`ROOT/var` or temp) |

A holder is respected only when **both alive and progressing** (pid alive on the same host
*and* a fresh heartbeat); a crashed or wedged one is taken over. Scope the path/name per
install yourself when several installs share a host.

**`Pramnos\Console\WorkerReloader`** — keeps a daemon from running forever on stale code
and configuration. Both inputs are constructor parameters:

```php
use Pramnos\Console\WorkerReloader;

$reloader = new WorkerReloader(ROOT, ['src', 'worker.php', 'composer.lock'],
    fn () => MySettings::versionStamp());
$reloader->baseline();
// between jobs:
if ($reloader->settingsChanged()) { /* rebuild snapshot objects in place */ }
if ($reloader->codeChanged()) {
    $lock->release();
    WorkerReloader::isSupervised() ? exit(0) : /* respawn self */ ;
}
```

`codeChanged()` fingerprints watched files' size+mtime; `settingsChanged()` fires once per
stamp move (the resolver's return value); `isSupervised()` detects systemd/supervisord/
`WORKER_SUPERVISED` so the worker knows whether exiting reloads or just stops. With no
resolver, settings tracking is disabled.

**`Pramnos\Console\SignalStop`** — cooperative graceful stop: async SIGTERM/SIGINT handlers
that only raise a flag (or run an `$onStop` callback), so a loop finishes the job in hand
before exiting. No-op when `pcntl` is unavailable.

```php
use Pramnos\Console\SignalStop;

$stop = new SignalStop();          // defaults to SIGTERM + SIGINT
$stop->install();
while (!$stop->requested()) {
    /* ... one job ... */
}
// or, to break a blocking server loop, pass a callback:
$stop = new SignalStop([], fn () => $server->stop());
```

| Method | Description |
|---|---|
| `install(): void` | Install the async signal handlers (no-op without pcntl) |
| `requested(): bool` / `reset(): void` | Whether a stop was asked for / clear it |
| `stop(?int $signal=null): void` | Request a stop directly (runs `$onStop` once) |

**`Pramnos\Console\SystemdNotifier`** — the `sd_notify` protocol for units running as
`Type=notify`. Every method is a no-op when there is no `NOTIFY_SOCKET`, so a worker behaves
identically under cron, by hand, or supervised.

```php
use Pramnos\Console\SystemdNotifier;

$sd = new SystemdNotifier();       // reads NOTIFY_SOCKET
$sd->ready();                      // startup complete
$sd->watchdog();                   // liveness ping (with WatchdogSec set)
$sd->stopping();                   // deliberate shutdown
```

| Method | Description |
|---|---|
| `enabled(): bool` | Running under `Type=notify` (a reachable `NOTIFY_SOCKET`) |
| `ready()` / `watchdog()` / `stopping()` / `status(string)` | Send the corresponding sd_notify datagram |
| `notify(string): bool` | Send a raw state line (best-effort; `false` if disabled/failed) |

Via `CommandBase`, `installStopSignals()` wires `SignalStop`, `shouldStop()` folds it in with
the `.stop` sentinel, `heartbeat()` pings the `SystemdNotifier` watchdog, and `endJob()`
sends `STOPPING` — so a command-based worker gets all four primitives without wiring them by
hand. `ProcessQueue` and `broadcast:serve` use exactly this.

### Terminal control

| Method | Description |
|---|---|
| `clearScreen(OutputInterface)` | Clear terminal |
| `hideCursor(OutputInterface)` | Hide cursor during live dashboard |
| `showCursor(OutputInterface)` | Restore cursor |
| `detectTerminalSize(): array{int,int}` | Returns `[height, width]` via `stty size` |

### Progress bar

```php
$output->write("\r" . $this->buildProgressBar($current, $total));
// → " [████████████..........] 60 of 100 (60%)"
```

### Text utilities

| Method | Description |
|---|---|
| `formatBytes(int|float, int $precision=2): string` | `1024 → "1 KB"`, `1048576 → "1 MB"` |
| `formatTime(int $seconds): string` | `3723 → "01:02:03"` (HH:MM:SS) |
| `visibleLength(string): int` | Character count ignoring ANSI escape sequences |
| `truncateText(string, int $maxLen): string` | Adds `...` if visible length exceeds maxLen |

### Dashboard rendering

Used to build live bordered terminal dashboards:

```
┌──────────────────────────────────────┐
│           QUEUE PROCESSOR v2         │
├──────────────────────────────────────┤
│ Time: 2026-05-05 14:30:00 │ Uptime: 00:12:34 │ CPU: 2.1 │ Memory: 48 MB │
├──────────────────────────────────────┤
│ Mode: Normal │ State: Running │ Tasks today: 1024     │
└──────────────────────────────────────┘
```

| Method | Description |
|---|---|
| `buildDashboardHeader(string $title, int $borderLen): string` | Top border with centered title |
| `buildDashboardSectionSeparator(int $borderLen): string` | Section divider `├──┤` |
| `buildDashboardFooter(int $borderLen): string` | Bottom border `└──┘` |
| `padDashboardLine(string $content, int $borderLen): string` | Pad content with side borders `│ … │` |
| `buildDashboardRows(string[] $segments, int $borderLen): string` | Fit segments side-by-side with ` │ ` separator |
| `buildSystemStatusSegments(int $startTime, float $cpu, int|float $mem): string[]` | `['Time: …', 'Uptime: …', 'CPU: …', 'Memory: …']` |
| `renderDashboardFrame(OutputInterface, string $title, string[] $systemSegments, string[] $sections, int $terminalWidth): void` | Full frame render (cursor-home → write → erase-below) |
| `renderDashboardFrameAutoSystem(OutputInterface, string $title, string[] $sections, int $terminalWidth): void` | As above with auto-built system segments |

### Migrating the reference application commands

```php
// Before (the reference application-local):
use App\ConsoleCommands\CommandBase;

// After (framework-level — no code changes in the command):
use Pramnos\Console\CommandBase;
```

---

## DaemonOrchestrator

`Pramnos\Console\DaemonOrchestrator` — abstract generic process supervisor that reconciles desired-vs-actual daemon state. Handles crash detection, heartbeat monitoring, git-hash restart on deploy, pre-spawn dedup, graceful stop, and a live interactive dashboard.

### Extending

Override three abstract methods and optionally hook lifecycle checks:

```php
use Pramnos\Console\DaemonOrchestrator;

class MyOrchestrator extends DaemonOrchestrator
{
    protected function getJobName(): string        { return 'my_orchestrator'; }
    protected function getEntryPoint(): string     { return ROOT . '/bin/myapp'; }
    protected function getDashboardTitle(): string { return ' MY APP ORCHESTRATOR '; }

    protected function buildDesiredProcesses(): array
    {
        return [[
            'id'       => 'queue-1',
            'daemon'   => 'queue',
            'workerId' => 'worker-1',
            'lockFile' => ROOT . '/var/QUEUE_WORKER_1',
            'tokens'   => ['queue:process', '--worker-id', 'worker-1', '--daemon'],
        ]];
    }

    // Optional — read from application settings:
    protected function isOrchestratorEnabled(): bool
    {
        return \Pramnos\Application\Settings::getSetting('daemon_enabled', true);
    }
}
```

```bash
php bin/myapp daemons:start
php bin/myapp daemons:start --once           # single reconcile cycle
php bin/myapp daemons:start --interactive    # live terminal dashboard
php bin/myapp daemons:start --dry-run        # show planned actions, no changes
php bin/myapp daemons:start --interval=5     # reconcile every 5 seconds
```

### Process definition keys

| Key | Required | Description |
|---|---|---|
| `id` | yes | Unique slot identifier for state tracking |
| `daemon` | yes | Daemon type label (`'queue'`, `'kafka'`, etc.) |
| `workerId` | yes | `--worker-id` argument value |
| `lockFile` | yes | Absolute path to the worker's lock file |
| `tokens` | yes | CLI arguments passed to `getEntryPoint()` |
| `requireLockFile` | no | Whether a healthy lock file is required (default `true`) |
| `shellCommand` | no | Raw shell command — overrides `tokens` |

### Reading orchestrator health — `status()`

`status()` is a public, read-only health snapshot for status dashboards. It
reports the orchestrator's own liveness plus its managed daemons **without**
running a reconcile cycle, so an admin/API endpoint can answer "is the supervisor
up?" by constructing the orchestrator and calling it:

```php
$status = (new MyOrchestrator())->status();
// [
//   'running'               => bool,   // singleton-lock pid is alive
//   'pid'                   => ?int,
//   'heartbeat_age_seconds' => ?int,   // age of the state file (rewritten each cycle)
//   'daemons'               => [ ['id'=>.., 'pid'=>?int, 'running'=>bool, ...], ... ],
// ]
```

`running`/`pid` come from the singleton lock file; `heartbeat_age_seconds` is the
age of the state file (a fresh mtime means the loop is actively cycling); each
managed daemon from the last-persisted state is enriched with live process
status. Safe to call before the orchestrator has ever run (reports not-running).

---

## db:seed — Database Seeder Command

```bash
# Run all seeders in database/seeds/
php bin/pramnos db:seed

# Run a single seeder by class name
php bin/pramnos db:seed UsersSeeder

# Run seeders from a custom directory
php bin/pramnos db:seed --path=/custom/seeds/
```

Seeders must extend `Pramnos\Database\Seeder` and implement `run()`:

```php
use Pramnos\Database\Seeder;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->insert('users', [
                'name'  => 'User ' . $i,
                'email' => 'user' . $i . '@example.com',
            ]);
        }
    }
}
```

Default seeds path: `ROOT . '/database/seeds/'`. Exit codes: `0` = success, `1` = failure.

---

## Interactive Migration Wizard

`create:migration` (called without a name argument) launches an interactive wizard:

```bash
php bin/pramnos create:migration
```

The wizard collects the full schema definition — columns, types, nullable/default/unique flags, foreign keys — and writes a migration with a complete `up()` / `down()`. After generating the migration it prompts to optionally create a Model, Web Controller, API Controller, and Seeder from the same session.

```
 Migration description: create users table
 Table name: #PREFIX#users
 Add auto-increment primary key id? [yes]

 ── Columns ──────────────────────────────────────────────────────────
 Column name (Enter to finish): name
   Type [string (VARCHAR)]:
   Length [255]: 100
   Nullable? [no]:
   Default value (blank = none, '' = empty string):
   Unique? [no]:

 Column name (Enter to finish):              ← blank = done

 Add timestamps (created_at / updated_at)? [yes]
 Add another table to this migration? [no]:

 ✓ Migration created: app/migrations/2026_05_06_120000_create_users_table.php

 Run this migration now? [yes]

 ── Also create ───────────────────────────────────────────────────────
 Create Model (Users)? [yes]
 Create Web Controller (UsersController)? [yes]
 Create API Controller (UsersApiController)? [yes]
 Create Seeder (UsersSeeder with fake data)? [yes]
```

### UI-aware controller and view generation

The wizard detects the application's UI setup:

| Setup detected | Generated output |
|---|---|
| Bootstrap + DataTables + Select2 | ServerSide DataTable list, Select2 FK dropdowns |
| Bootstrap only | Plain `<table class="table">` list |
| Plain CSS | Minimal HTML table, no framework dependencies |

### Non-interactive usage (unchanged)

```bash
php bin/pramnos create:migration create_users_table   # blank stub, no wizard
```

### Seeder fake-data heuristics

| Column name contains | Generated fake value |
|---|---|
| `email` | `'user' . $i . '@example.com'` |
| `name` | `'Name ' . $i` |
| `status` | `['active','inactive','pending'][$i % 3]` |
| `password` | `password_hash('password' . $i, PASSWORD_DEFAULT)` |
| `token` | `bin2hex(random_bytes(16))` |
| `uuid` type | UUID v4 formatted string |
| `boolean` type | `($i % 2 === 0)` |
| `decimal/float` type | `round($i * 9.99, 2)` |
| fallback | `'value_' . $i` |

Auto-managed columns (`id`, `created_at`, `updated_at`, `deleted_at`) are never included in seed inserts.

---

## Related Documentation

- **[Framework Guide](Pramnos_Framework_Guide.md)** — MVC architecture for generated code
- **[Migration Guide](Pramnos_Migration_Guide.md)** — Migration system and MigrationRunner
- **[Database API Guide](Pramnos_Database_API_Guide.md)** — Database patterns used in generated models
- **[Authentication Guide](Pramnos_Authentication_Guide.md)** — Implementing authentication in generated controllers
- **[Routing Guide](Pramnos_Routing_Guide.md)** — Route registration for generated controllers
- **[Logging System Guide](Pramnos_Logging_Guide.md)** — Log migration tools and monitoring
