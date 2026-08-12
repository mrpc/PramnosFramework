---
date: 2026-08-12
categories:
  - Changelog
  - Added
  - Fixed
tags:
  - console
  - spa
  - mcp
  - broadcasting
---

# `spa:dev` / `spa:build`, and a service container that actually exists

Two things reported from a real project on the same afternoon: the front-end
workflow had no place in the CLI, and `mcp:serve` died before printing anything.

<!-- more -->

## Added: the front end has CLI commands

Building and serving a SPA were `./dockernpm run build` and `./dockernpm run
dev` — correct, and absent from `pramnos list`, so they had to be remembered
from the docs rather than found in the CLI everything else in the project uses.

```bash
php bin/pramnos spa:dev              # dev server with HMR (alias: spa:serve)
php bin/pramnos spa:build            # production build → www/assets/spa/
php bin/pramnos spa:build --watch    # rebuild on change, no dev server
```

Both wrap npm; `./dockernpm run <script>` still covers everything else in
`package.json`. The `init` summary now points at these instead.

**Where npm comes from is worked out rather than assumed.** The scaffolded CLI
wrapper is `docker-compose exec -u www-data app php <cli>.php`, so the console is
normally *already inside* the container — and the first version of this command
delegated to `./dockernpm` from there, which is Docker asking to exec into
Docker. It failed with "The app container is not running", printed from inside
the container it was talking about. Inside, npm now runs directly (with
`HOME=/tmp`, because www-data's home is not writable and npm wants a cache); from
the host, `./dockernpm` is used, so build output is never left owned by root.

Missing `node_modules` is installed rather than reported: npm's own error names a
missing binary several lines down and says nothing about what to do.

Both commands refuse where they cannot apply, with the reason that fits — an MVC
project has no front end, and the build-less stack serves `www/assets/js/` exactly
as written, so there is nothing to build and nothing for a dev server to supply.
`spa:dev` prints the application's URL, because the one unguessable thing about
this workflow is that the Vite port serves no HTML.

## Fixed: `$app->container` was always null

```
Fatal error: Uncaught Error: Call to a member function has() on null
  in McpServe.php:71
```

`container` is a magic property on `Application`, and **nothing ever assigned
it**. Every call site read null:

- `mcp:serve` could not start at all — the reported crash;
- `McpServiceProvider::register()` and `WebhookServiceProvider::register()` would
  have killed `init()` outright, so enabling either feature broke the
  application;
- `Broadcastable::resolveBroadcastingManager()` threw into its own `try`/`catch`,
  which quietly reported the wiring bug as "broadcasting is not configured";
- `BroadcastServe` called `$app->getContainer()`, a method that did not exist, and
  its own catch swallowed that too.

**Added `Application::getContainer()`**, which creates the container on first use
and stores it back on `$this->container`, so existing `$app->container->…` code
keeps working. Lazily rather than in `init()`, because the console reaches the
application *without* initialising it — which is precisely the path that crashed.
An application that assigns its own container keeps it.

Two test doubles had been quietly papering over this: one hand-rolled a
container-shaped object because there was no `getContainer()` to satisfy, and
another overrode the missing method. Both now use the real `Container`, which is
what let this bug live in the first place.

## Added: `mcp:serve` says what it is doing

It printed nothing and blocked on STDIN, which is indistinguishable from a hang.
It now announces the server, its tools and its resources — **on STDERR**. STDOUT
is the JSON-RPC channel: a greeting there is not cosmetic damage, the client
fails on the first line and reports the server as broken. MCP clients route
stderr to a log and ignore it, so it is the only place a human-facing word can go.

```
MCP server ready on stdio.
  5 tools: list-tables, query-schema, migration-status, model-inspect, route-list
  3 resources: Claude Code guide, Project README, App config
  Waiting for JSON-RPC on stdin — this is normally launched by an
  MCP client (see .mcp.json), not run by hand. Ctrl-C to stop.
```

## Tests

`SpaCommandsTest` — 16 cases over the three decisions that produced wrong
answers in practice: which npm to use (inside the container, on the host, neither
available, a container built without node), whether the project can be built at
all (MVC, build-less, no project), and what happens around dependencies (install
first, abort on a failed install, propagate the build's exit code) — plus
`--watch`, and the URL hint in its four states. `McpServeTest` gains the
uninitialised-application case that reproduces the crash, and asserts the banner
reaches stderr while stdout stays empty. `ApplicationTest` covers `getContainer()`
creating one, caching it, and never replacing an existing one.
