---
date: 2026-08-25
categories: [Changelog]
---

# A stale stat should not cost you the admin user

`init` scaffolded a project, the dependency sync died on one arbitrary package, and
everything downstream — migrations, admin user, API application — was silently skipped.
The package was innocent.

<!-- more -->

## Fixed

- **The in-container `composer update` now retries up to three times.** Composer
  extracts every package into `vendor/`, which is a Docker bind mount of the project
  directory. `ArchiveDownloader::install()` creates the target directory, confirms it
  with `file_exists()`, then opens it with `Finder` — and Docker Desktop for macOS
  occasionally answers `ENOENT` for a directory it created a moment earlier:

  ```
  Install of phpunit/php-code-coverage failed
  In RecursiveDirectoryIterator.php line 48:
    RecursiveDirectoryIterator::__construct(/var/www/html/vendor/phpunit/php-code-coverage):
    Failed to open directory: No such file or directory
  ```

  Nothing is wrong with `phpunit/php-code-coverage` — it is whichever of the ~30
  packages lost the race. The retry is there because the consequence was so far out of
  proportion to the cause: a failed sync sets `autoloadSuccess = false`, so the
  framework migrations do not run, so the admin user is never created, and `init`
  reports success on a project that cannot boot. Three genuine failures still fail, and
  the closing summary still names the command to run by hand.

## Documentation

- [Console Guide](../../Pramnos_Console_Guide.md) gains **"The dependency sync retries
  — and why"**, next to the `--no-install` section, so the error above is searchable
  with its explanation attached instead of being read as a broken lockfile.
