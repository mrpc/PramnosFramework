---
use_cases:
  - Scaffolding a new application with init, with or without installing
  - Bringing a fresh clone up with project:setup
  - Changing an existing project's features, theme or web root afterwards
  - Seeing what init writes: controllers, tests, AI-assistant instructions, favicons
  - Refreshing framework-owned files in an existing project (project:resync)
---

# Starting a project: init, project:setup, reconfiguring

Part of the [console guide](Pramnos_Console_Guide.md).

## `init` will not scaffold over an existing application

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

## Scaffolding without installing — `--no-install`

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
- **tests that scaffold**, where a `composer update` per scaffolded project dominates
  the runtime and puts the network inside a unit test — see
  [Test suite performance](Pramnos_Test_Suite_Performance.md).

With Docker (`--docker=y`), `--no-install` also skips the framework migrations that
follow, since those run the new application's own CLI and need the autoloader that was
not generated.

## The dependency sync retries — and why

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

## `project:setup` — bringing a clone up

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
./pramnos project:setup --no-migrations    # skip the framework migrations
./pramnos project:setup --force-env        # rewrite an existing .env
```

**It writes no project file** — only `.env`. Not one of the steps above is a scaffolding
step: `project:reconfigure` and `project:resync` own that. A command that both set up an
environment *and* edited tracked files would be one nobody could safely run on a
checkout with local changes.

For a project scaffolded before the credentials moved there is no `.env.example`, and it
says so rather than writing an empty `.env`: those projects still keep their settings in
`app/config/settings.php`.

## `--service-worker` — caching assets in the browser

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

## Serving from a directory other than `www` — `--web-root`

The scaffold writes its document root as `www/` by convention. A project served from another
directory has to say so at scaffold time: a SPA build written into a directory nothing serves
produces **a blank page, not an error**, because the manifest is simply not where the shell looks
for it.

```bash
php bin/pramnos init --web-root=public
```

Everything under the document root follows it: the directory, the front controller, `.htaccess`,
assets, favicons, the API entry point, the SPA shell and build output, the `.gitignore` lines,
the Docker `DocumentRoot`, and the prose in generated files that names the path. A half-applied
option would be worse than none — a project that looks configured and is broken in a way the
configuration appears to explain — so there is a test that scaffolds with a non-default root and
asserts on the **tree**, including that nothing was left in `www/`.

The development server follows it too: `serve` runs PHP's built-in server with the web root's
`index.php` as its router.

Slashes and whitespace are trimmed, so `--web-root=/public/` works. An empty value falls back to
`www` rather than scaffolding a front controller into the repository root, which is easy to
produce from a shell variable that did not expand and difficult to undo.

## The web root config `init` writes

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

## Project Reconfiguration Commands (`project:`)

These commands change an **existing** project after it was scaffolded — no need to re-run
`init`. They are BC-safe and idempotent (re-running does nothing new).

!!! info "`features` from `app.php` are active on the CLI too"
    A command — and any daemon it runs — sees the same feature state as the web
    application: `FeatureRegistry::isEnabled('authserver')` answers from the
    `features` array in `app/app.php`, whichever entry point asked. The console
    `Application` applies the `features` list itself, because `Application::init()`
    — where the web lifecycle does it — is not run by a console command. Feature
    state has to mean one thing per installation, not one thing per entry point: a
    long-running daemon deciding anything from a feature flag must reach the same
    conclusion as the web application reading the same file.

    A `features` entry naming something unregistered raises
    `UnknownFeatureException` on the CLI as it does on the web. The message names
    the unknown key and lists the valid ones.

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

**Where the registration goes.** `project:install` writes each `registerScript()` /
`registerStyle()` line into `registerVendorLibraries()`, after the last registration already
there, or after its `$doc = …getDocument();` when there is none. Only code counts: the
example in that method's docblock is not a place to write, and a handle named only in a
comment is not registered. An `Application.php` without the method gets the lines after its
first `$doc = …getDocument();`; one without that either gets them printed for hand-insertion.

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

See [One palette, every UI system](Pramnos_Theme_UI_Systems_Guide.md#one-palette-every-ui-system).

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

### `project:resync` — refresh framework-owned files

Some scaffolded files stay the framework's: it keeps improving them, and an existing project
keeps its stale copy forever unless something re-copies it. `project:resync` is that
something.

```bash
php bin/pramnos project:resync                # refresh every framework-owned file present
php bin/pramnos project:resync --dry-run      # preview, write nothing
php bin/pramnos project:resync --all          # also copy files not in the project yet

php bin/pramnos project:resync --js           # only the pf-*.js CSP-safe UI hooks
php bin/pramnos project:resync --scripts      # only the API-docs tooling
php bin/pramnos project:resync --debug-panel  # only the framework-owned SPA modules (lib/debug.js, lib/webauthn.js)
php bin/pramnos project:resync --css          # only the theme's components.css
php bin/pramnos project:resync --pwa          # only the PWA icons (never overwritten) and manifest.json (merged)
php bin/pramnos project:resync --spa-components  # only the shared Svelte components and their tests
php bin/pramnos project:resync --rich-editor     # add the rich text editor to a Svelte SPA
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

Done. 0 created, 0 updated, 0 unchanged, 0 kept, 0 skipped, 1 FAILED.
```

The failure count is printed only when there is one. Run it from CI and **check the exit
code**; a warning on stderr is what a CI job or a habitual `2>/dev/null` discards, and the
whole value of `project:resync` is being able to say a framework-owned file downstream
*is* the framework's current one.

The groups:

| Flag | What it owns |
|------|--------------|
| `--js` | `www/assets/js/pf-*.js` — the CSP-safe UI hook scripts |
| `--scripts` | `scripts/apidoc-to-openapi.cjs`, `scripts/doc.sh`, plus the API-docs npm scripts merged into `package.json` and the framework endpoints merged into `src/Api/openapi-overrides.json` |
| `--debug-panel` | `lib/debug.js` (the SPA debug panel) and `lib/webauthn.js`, in `frontend/lib/` or `www/assets/js/lib/` depending on this project's stack (read from `app_style`/`spa_stack`, or `spa_source_dir` when set) |
| `--css` | `www/assets/css/components.css`, the framework components' styles, from the theme `scaffold_theme` names. Kept when the project changed it (or when it predates the record `init` now keeps), unless `--overwrite` |
| `--pwa` | The PWA icons (added when missing, never overwritten) and `manifest.json` (merged) |
| `--spa-components` | The shared Svelte components and their tests — see [The shared components](Pramnos_Console_API_Generation_Guide.md#the-shared-components). Not part of a plain `project:resync` |
| `--rich-editor` | Adds the rich text editor to a Svelte SPA: `lib/rich-editor/` (the framework's core and stylesheet), `components/RichEditor.svelte` (the project's), and the TipTap packages in `package.json`. From then on a plain `project:resync` refreshes `lib/rich-editor/` — see the [Rich Text Editor guide](Pramnos_Rich_Editor_Guide.md#in-a-svelte-spa) |

`--overwrite` replaces shared components and `components.css` where the project changed them,
or where the framework has no record of writing them; without it they are reported `kept`.

Merges preserve what the project declared: user-added npm scripts, extra OpenAPI paths and
schemas survive. What is **not** rewritten is `lib/api.js` — it is the project's own file and
gets edited, so if it never calls `recordDebug` the command says so and prints the two lines
to add rather than regenerating it. See the
[Debugging guide](Pramnos_Debugging_Guide.md#in-a-single-page-application) for why the debug
panel is framework-owned.

## The controllers `init` writes, and the test that names them

Controllers land in two directories, and the split is by audience:

| Directory | Namespace | What is in it |
|---|---|---|
| `src/Controllers/` | `App\Controllers` | public-facing: `Home`, `Health`, `Login`, `Account`, `Register`, `Sso`, `Oauth`, `Webhook`, `Messages`, and the OAuth2 server's endpoints |
| `src/Admin/Controllers/` | `App\Admin\Controllers` | the administration screens: `Dashboard`, `Users`, `Settings`, `Logs`, `Services`, `Organizations`, `Emails`, `Tokens`, `Applications`, `Permissions`, `Roles`, `Queue`, and the rest |

Which of them appear depends on the features enabled: no `auth`, no `Login`; no
`queue`, no `Queue` screen.

`init` also writes `tests/Unit/Controllers/ControllersContractTest.php`, which asserts
that each one loads and extends the framework class it is meant to. **Its roster is read
off the project's own `src/` at scaffold time**, not from a list inside the generator —
so it names every controller the project has and nothing else, whatever combination of
features produced it. A hand-maintained list drifts in both directions at once, and the
half that names classes the project does not have turns the first command a developer
runs into a wall of errors.

The rows are the project's from then on. Adding a controller means adding a row; if you
re-parent one, update its second column — that column is the contract, and it is the
only thing that would notice.

## What `init` writes for AI assistants

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
  a guide is not to be reimplemented in the project — an assistant that cannot find a
  framework capability otherwise writes a second one beside the working one.

- **`.mcp.json`** — registers the framework's own MCP server (`mcp:serve`), which exposes this
  application's tables, schema, migrations, models and routes to an assistant instead of
  requiring a separate database MCP server.

  The server reports **`app/app.php`'s `name`** as its own, so a client that lists
  several projects can tell them apart. It reads the configuration file rather than a
  database-stored setting: the name is right there, no connection is involved, and a
  project whose settings load fails for any reason would otherwise have shown the
  framework's generic default for every one of its servers.

  **The tools, and what `route-list` can actually see.** `McpServiceProvider::registerDefaults()`
  serves:

  - always: `framework-docs`, `pramnos-check`, `find-symbol`, `console-commands`, `theme-info`,
    `api-docs`, `find-tests`, `coverage`, `changelog-add`, `log-analytics`, `log-errors`;
  - with the application booted: `migration-status`, `model-inspect`, `route-list`,
    `schema-drift`, `status`, `request-debug`, plus `list-tables`, `query-schema` and
    `db-inspect` when a database is configured;
  - the application's own tools, listed under `mcp.tools` (and the analyst's scoped
    tools under `llm.admin_tools`) in `app/app.php`.

  `mcp:call <tool>` runs one of them from the terminal and prints the result — omit the tool
  name to list them. See the [MCP guide](Pramnos_MCP_Guide.md).

  `route-list` runs under the **console** kernel, which builds no router — routing is an HTTP
  concern. So it builds one and discovers `#[Route]` attributes, using the PSR-4 map in the
  project's own `composer.json` rather than assuming `src/Controllers`. That means:

  - **attribute-routed controllers are listed**, with methods, URIs, actions and permissions;
  - **routes registered inside a `routes.php` that dispatches at the end cannot be** — including
    that file would serve a request rather than describe one, and the tool says so instead of
    returning nothing.

  Worth knowing as a shape: an MCP tool that returns an error *as a result* (inside
  `content[0].text` with `isError` false) is invisible to anything watching for failures.

## Favicons & branding

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
