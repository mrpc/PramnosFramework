---
date: 2026-08-20
categories: [Changelog]
---

# Features that were off on the command line

`app.php` declares which framework features an installation runs. Every console command read
that list as empty — so a daemon and the web application, reading the same file, could reach
opposite conclusions about the same feature.

<!-- more -->

## Fixed

`Application::getInstance()` reads `app.php` into `applicationInfo`; the call that turns its
`features` array into `FeatureRegistry` state lived in `Application::init()`. The web
lifecycle runs `init()`. A console command does not — `Console\Application` calls
`getInstance()` and stops there.

So `FeatureRegistry::isEnabled()` answered `false` for every feature inside every command,
regardless of `app.php`. Nothing failed, because nothing on the CLI happened to ask yet. The
gap was found while designing something that would: an authorizer choosing where application
keys come from, needed identically by the web request that signs a token and by the daemon
that verifies it. Two halves of one security decision, disagreeing on a config file they both
read, with no error on either side — the same shape as this framework's own documented trap
where a subscriber and a publisher pick different Redis primitives and the subscription simply
never receives anything.

`Console\Application::__construct()` now loads the list, so feature state means one thing per
installation rather than one thing per entry point.

**One behaviour change to know about:** a `features` entry naming something unregistered now
raises `UnknownFeatureException` on the CLI, as it already did on the web, instead of being
silently ignored. The message names the unknown key and lists the valid ones.

## Documentation

`Pramnos_Console_Guide.md` states that `features` are active on the CLI, and what it means
when one is misspelled, with a `use_cases:` entry for the question that leads there.
