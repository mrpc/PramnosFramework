---
date: 2026-08-10
categories:
  - Changelog
  - Fixed
tags:
  - docker
  - scaffolding
---

# Files the container writes now belong to you, not to root

A scaffolded project's Docker image maps its `www-data` user to the host user's
ids, and every command `init` runs inside the container runs as that user — so
`vendor/`, `node_modules/` and `var/logs` stop landing as root-owned and deleting
a test project no longer needs `sudo`.

<!-- more -->

## The symptom

```bash
docker-compose down -v && cd .. && sudo rm -rf test-app
[sudo] password for mrpc:
```

Re-scaffolding a project meant typing a sudo password, because the working tree
was littered with files the developer could not delete.

## The cause

The project is bind-mounted into the container (`.:/var/www/html`), so file
ownership is shared with the host — by numeric id, not by name. Everything init
ran inside the container ran as **root** (uid 0):

```
docker-compose exec -T app composer update        # vendor/  → root
docker-compose exec -T app php app.php migrate    # var/logs → root
docker-compose exec -T app sh -lc "npm install"   # node_modules/ → root
```

Apache's workers made it worse in the other direction: they run as `www-data`,
whose uid inside a stock `php:apache` image (33) matches nobody on the host.

## The fix

Three parts, all needed — any one alone leaves a gap:

1. **The image adopts the host user's ids.** The generated `Dockerfile` takes
   `UID`/`GID` build args and remaps `www-data`:

   ```dockerfile
   ARG UID=1000
   ARG GID=1000
   RUN groupmod -o -g $GID www-data && usermod -o -u $UID -g $GID www-data
   ```

   `-o` allows a duplicate id, which some hosts already have in use.

2. **The ids reach the build.** `docker-compose.yml` passes them as build args,
   and `init` writes the host's actual ids into `.env`, which compose reads
   automatically. This matters more than it looks: a plain shell does **not**
   export `UID`, so `${UID}` taken from the environment would silently fall back
   to the default on every machine. An existing `.env` is preserved — only the
   missing keys are appended — and `/.env` is added to `.gitignore`, since it
   describes one machine.

3. **Commands run as that user.** Every `docker-compose exec` that can write into
   the mount now passes `-u www-data`, in `init` itself and in the generated
   helper scripts (`dockerbash`, `dockertest`, `dockernpm`, `testjs`, and the
   `./<app>` CLI wrapper). Composer and npm also get a writable `HOME`
   (`COMPOSER_HOME=/tmp/composer`, `HOME=/tmp`), because `www-data`'s home is not
   writable and the cache would otherwise fail.

### npm was the last root left

The API-docs generator (`scripts/doc.sh`) also installs node modules, and it still
ran as root — which created a root-owned `node_modules` that every later npm command
then crashed on:

```
npm ERR! Error: EACCES: permission denied, mkdir '/var/www/html/node_modules/@esbuild/aix-ppc64'
```

`doc.sh` now runs as the mapped user as well. Two repair paths handle whatever is
already root-owned: `init` chowns the tree once after the containers come up, and
`./dockernpm` hands back `node_modules` / `www/assets/spa` before running if they
are not owned by the web user — so the command that used to fail now fixes itself.

## Existing projects

Add the two lines to your `Dockerfile`, the `args:` block to `docker-compose.yml`
and a `.env` with your ids (`id -u`, `id -g`), then rebuild:

```bash
docker-compose build app && docker-compose up -d
docker-compose exec -u root app chown -R www-data:www-data /var/www/html
```

## Tests

`InitSpaScaffoldingTest` — the image declares and applies the args, compose passes
them, `.env` carries this host's real ids (via `Init::hostUserIds()`), an existing
`.env` survives, `/.env` is ignored, and every generated helper script runs as the
mapped user.
