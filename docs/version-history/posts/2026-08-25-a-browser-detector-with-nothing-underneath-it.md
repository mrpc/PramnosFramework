---
date: 2026-08-25
categories: [Changelog]
---

# A browser detector with nothing underneath it

`Helpers::getBrowser()` has always had the right signature. On a default PHP installation
it filled one of its six fields, and said so to nobody.

<!-- more -->

## Added

- **`matomo/device-detector` support in `Helpers::getBrowser()`**, as a `suggest`. With it
  installed, `browser`, `version`, `majorver`, `platform`, `os_number` and `engine` are all
  populated — including `os_number`, which was hard-coded empty on the browscap path and
  empty on the fallback, so nothing had ever filled it.

  Without it, behaviour is exactly what it was. A framework should not put a user-agent
  parser into every project that installs it.

- **`detector`** on the returned object: `device-detector`, `browscap` or `sniff`. An empty
  `version` used to mean either *this agent is not identifiable* or *there was no parser
  running*, and those call for opposite responses — a fact about the visitor, or a missing
  package. The field is how a caller tells them apart, and it is what the filing behind
  this asked for.

## Why

Filed as FW-017 from a consuming application, with numbers. `get_browser()` needs the
`browscap` ini directive pointing at a browscap.ini, and that directive is unset on a
default installation — so the real engine was the fallback, a six-branch regex returning a
name and nothing else. The method answered with a perfectly valid object every time, which
is what made it invisible: 3,040 visits recorded with a browser name, 771 with an operating
system or an engine.

`matomo/device-detector` rather than `browscap/browscap-php` for one decisive reason: its
regexes ship **inside the package**. No data file to provision, no monthly refresh, and no
way for a missing download to degrade it silently — which is the same failure mode as the
one being fixed. Staleness becomes `composer update`.

Two mappings are deliberate. **`platform` stays the operating system**, because
device-detector's own `platform` is the CPU architecture and passing it through would have
quietly changed what a public field means for every existing caller. And **a crawler gets a
name and nothing else** — `Googlebot` with an empty version — because this object is
written into statistics tables a row at a time, where an invented number is worse than an
empty one.

## Fixed

- `getBrowser()` reaches its helpers through `static::` rather than `self::`, so the parser
  is a real seam an application can substitute. With `self::` an override bound to the base
  class and was ignored — which the tests found immediately, since the fallback path can
  only be exercised on a machine where the package *is* installed.

## Documentation

- [Framework Guide](../../Pramnos_Framework_Guide.md) gains **Reading a user agent**: the
  three engines, what each one fills, when to install the package, and what `detector` is
  for.
