---
use_cases:
  - Installing the framework for the first time
  - Setting up the Docker development environment
  - Generating a first model, controller and view
---

# Full Setup Guide

Everything the [Quickstart](index.md) leaves out: Docker, the `daemons` container,
configuration and secrets, project structure, `.gitignore`, and generating your first model
and controller.

## Installation

The recommended way to start a new project is to use the **Pramnos Application**. This provides a one-line setup experience that automatically handles the directory structure and framework installation.

1. From your projects root directory, run:
   ```bash
   composer create-project mrpc/pramnos-application my-app
   ```

Alternatively, if you prefer to manage the process manually or add the framework to an existing project:

1. Create your project directory:
   ```bash
   mkdir my-app && cd my-app
   ```
2. Install the framework:
   ```bash
   composer require mrpc/pramnosframework
   ```
3. Run the initialization command:
   ```bash
   php vendor/bin/pramnos init
   ```

The `init` command will guide you through the setup with smart defaults:
- **Application Name**: Defaults to the folder name (`my-app`).
- **Namespace**: Automatically converted to CamelCase (`MyApp`).
- **Database**: Suggested name (`my_app_db`) and user (`my_app_user`).
- **Docker**: Optional but highly recommended containerized setup.

## Using Docker

If you enabled Docker during initialization, first start your environment:

```bash
docker-compose up -d
```

*Note: Ensure Docker and Docker Compose are installed on your host machine.*

### Helper Scripts

- **`./dockerbash`**: Enter the application container's shell.
- **`./dockertest`**: Run your PHPUnit tests inside the Docker environment.

### The `daemons` container

If the features you enabled have background work — `queue`, `messaging`, `broadcasting`, or
the periodic jobs `auth` and `authserver` schedule — `init` also writes a `daemons` service and
`src/ConsoleCommands/Daemons.php`, the supervisor it runs.

It is there so that development runs the same background work production does. Without it a
fresh project's queue fills and nothing empties it, and the scheduled cleanups never run —
and neither of those announces itself. What you see is a screen with no rows on it, a job that
stays queued, a token that never expires, each of which reads as a bug in the code that would
have used them.

Add a worker of your own in `buildDesiredProcesses()`; the framework's schedule worker is
supervised without being declared. `/admin/Services` shows what it is running, and
[Workers & Daemons](Pramnos_Workers_And_Daemons_Guide.md#creating-the-orchestrator-service) has
the systemd unit for a server.

## Project Structure

A typical Pramnos project following initialization looks like this:

- **`app/`**: Configuration and Migrations.
    - `config/settings.php`: Main environment configuration — **reads `.env`**, holds no
      credentials, identical in every checkout.
    - `config/testsettings.php`: The same, pointed at `APP_DB_TEST_NAME`.
- **`.env`**: this machine's values — credentials, database name, `APP_DEBUG`. **Not
  committed.**
- **`.env.example`**: the committed list of every key, secrets blank. Copy it to `.env`.
- **`src/`**: Your application logic (Controllers, Models, Views).
- **`www/`**: Public entry point (contains `index.php` and assets).
- **`var/`**: Logs, cache and migration state. Entirely machine-local — the whole
  directory is in `.gitignore`, and every writer creates what it needs, so a fresh
  clone with no `var/` at all is correct.
- **`tests/`**: Unit and Integration tests.

### Configuration and secrets

Nothing `init` writes into version control contains a credential. The values live in
`.env`, which is in `.gitignore`; `app/config/settings.php` reads them and is the same
file in every checkout:

```php
'database' => [
    'type'     => envvar('APP_DB_TYPE', 'postgresql'),
    'hostname' => envvar('APP_DB_HOST', 'db'),
    'database' => envvar('APP_DB_NAME', 'myapp_db'),
    'user'     => envvar('APP_DB_USER', 'myapp'),
    'password' => (string) envvar('APP_DB_PASSWORD', ''),
    ...
],
'development' => envvar('APP_DEBUG', false),
```

`docker-compose.yml` interpolates from the same file — `POSTGRES_PASSWORD:
${APP_DB_PASSWORD}` — so a credential has one home and one place it can leak from.

Four things are worth knowing:

- **`APP_DB_*`, not `DB_*`.** A real environment variable deliberately beats `.env`, so
  that a host which injects `APP_DB_PASSWORD` needs no file. The bare names are the
  convention and also the ones a hosting image, a CI runner or a sibling container is
  most likely to have set already — *for a different database*. This framework's
  own dev container exports `DB_HOST`, `DB_USER` and `DB_NAME`, for example; a
  project reading the bare names inside it would connect to the framework's test
  database.
- **The defaults in the file are the non-secret ones.** Host, database, user and prefix
  have one; `APP_DB_PASSWORD` does not. A clone with no `.env` fails to *authenticate*,
  which points at the missing file, rather than failing to find a database.
- **`APP_DEBUG` is `true` in the `.env` init writes and `false` in `.env.example`.**
  `init` is setting up a development machine; the next place that file is copied might
  not be one. The committed `settings.php` never fixes the value — it reads it.
- **`.env.example` lists every key** — the test suite asserts the two files have the
  same key set — so a diff of the two is exactly what a new checkout must be told.

After a fresh `git clone`, `cp .env.example .env` and fill in the blanks — or let
[`project:setup`](Pramnos_Console_Guide.md) do it and bring the environment up with you.

### What `init` puts in `.gitignore`, and why

| Entry | Why |
|---|---|
| `/vendor/` | Composer restores it from `composer.lock` |
| `/var/` | Everything under it is state this machine wrote: caches, logs, `var/migrations/*.verified` (a per-database verification timestamp) and `var/migrations-schemaversion.lock` (a worker lock carrying a pid, a hostname and a heartbeat) |
| `/.env` | The credentials, the database name, `APP_DEBUG` and this host's user ids. `.env.example` is the committed copy of its *shape* |
| `node_modules/` | npm restores it from `package-lock.json`. Ignored in **every** project, not only ones with a build stack: `npm install` runs at the project root for the OpenAPI/RapiDoc generator too, and `./dockernpm` is there for anyone to use |
| `/app/keys/*.key` | Every project. Each environment has its own key pairs (OAuth2 signing, encryption, web-push VAPID), so neither half of a pair is committed — a public key copied from development would stop production generating its own pair |
| `www/api/openapi*.json`, `www/api/docs/` | Only when API docs are on; regenerated by `./dockernpm run docs:build` |
| `<web-root>/<build-dir>/` | Only for a SPA with a build stack; produced by `npm run build` |

**`.mcp.json` and `CLAUDE.md` are committed on purpose.** They are project
configuration, and the point of them is that whoever clones the repository next gets
the MCP server and the assistant guidelines without being told they exist. Neither
holds a credential.

`www/assets/vendor/` is **committed** — the vendored front-end libraries are part of
what gets deployed, and a clone with no network still renders. `./<cli>
project:install` re-downloads them when you need it.

To run your tests, use the following command:

```bash
# Using Docker (Recommended)
./dockertest

# With coverage
./dockertest --coverage
```

The `./dockertest` command runs PHPUnit inside the Docker container (PHP 8.5), ensuring all dependencies and environmental requirements are met. It automatically handles container startup and composer installation if needed.

## Creating Entities

Once your project is initialized, use the `create:*` commands to scaffold new components.
The project's CLI is `<cli>.php` at the project root, where `<cli>` is the namespace in
lower case (`myapp.php` for `MyApp`); with Docker, `init` also writes a `./<cli>` wrapper
that runs it inside the container:

```bash
# Create a new controller
./<cli> create:controller MyNewController

# Create a new model
./<cli> create:model MyModel

# Without Docker
php <cli>.php create:controller MyNewController
```
