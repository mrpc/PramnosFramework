---
date: 2026-07-15
categories:
  - Changelog
  - Database
tags:
  - migrations
  - features
  - backward-compatibility
readtime: 2
---

# Framework auto-migrations are now gated by enabled features

Auto-run framework migrations are now scoped to the features an application has actually
enabled, so a project only ever provisions the schema for the subsystems it uses.

<!-- more -->

## Changed

- **Feature-gated auto-migrations.** `Application::runAutoMigrations()` (the boot-time runner
  that applies pending framework migrations automatically) now filters migration directories
  by feature activation. Each framework migration lives in a per-feature sub-directory
  (`database/migrations/framework/{feature}/`); a directory is now applied only when its
  feature is enabled in the application's `app.php` `features` array. The rule is
  **fail-open**: a directory whose name is not a registered framework feature — and the
  always-on `core` feature — still runs unconditionally, so nothing outside the known
  feature set is affected. Implemented as `Application::filterMigrationDirsByEnabledFeatures()`.

!!! warning "Upgrade note"

    If your application relies on a framework feature's tables being created automatically
    (`authserver`, `auth`, `queue`, `messaging`, …), make sure that feature is listed in your
    `app.php` `features` array. Without it, that feature's **new** migrations will no longer
    auto-run. Installations that already have the tables are unaffected for existing schema;
    this only governs whether *future* framework migrations for a feature are applied. The
    `core` feature always runs regardless of configuration.
