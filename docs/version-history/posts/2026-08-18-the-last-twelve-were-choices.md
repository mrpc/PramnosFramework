---
date: 2026-08-18
categories: [Changelog]
---

# The last twelve were choices

`Application::getInstance()` is a factory: with no instance for the key it reads `app.php`,
defines constants and runs the whole constructor — database, language, session.
`currentInstance()` is the lookup.

Nine call sites in the authentication, identity and database layers were converted yesterday,
with a structural guard over those four directories. The changelog entry for that said
twenty-seven calls remained outside the guard and were **not audited one by one**. They have been
now.

<!-- more -->

## Fixed

Eleven converted, one deleted:

| Where | Why it was a lookup all along |
| --- | --- |
| `DevPanelController` (×2), `DocumentTypes\Html`, `DocumentTypes\Raw` | a CSP-nonce read during rendering, which happens inside a request. Three of the four already had `if ($app && …)` — a guard for a null the factory cannot return |
| `Broadcasting\Broadcastable` | building an application to ask whether broadcasting is configured, inside a `try` whose `catch` reports "not configured" |
| `Console\Commands\RouteList` | its third fallback strategy asks whether a global instance *exists*; constructing one to discover it has no router is the opposite of a fallback |
| `Testing\TestClient` | its `if ($appInstance === null)` branch was unreachable and carried a coverage-ignore explaining that. It is live now |
| `Init` — five generated templates | the nav features read, three footers and a page title, shipped into every new project. Now `currentInstance()?->…`, and the footers gained the escaping they were missing |
| `Addon\System\Session` | **deleted.** The line assigned `$app` and nothing ever read it, so a session-cleanup addon constructed an entire application for an unused variable |

A docblock in `NavRegistry` showed `getInstance()->applicationInfo['features']` as the way to read
features inside a theme header. Documentation that teaches the shape a guard forbids is worse than
no example.

## Kept, with the reason

Twelve, in two groups.

**Console bootstraps** — `Console\Application`, `TimescaleDrain`, `TimescaleEnsure`,
`PolicyEngine`, `BroadcastServe`, `BaseTestCase`, and the two bootstrap scripts the scaffolder
generates. Building an application is what they are for.

**Constructor fallbacks** in `Controller` and `Theme` — `__construct($application = null)`
resolving the current one when none was passed. In a real request both calls answer identically,
and `currentInstance()` would put **null** into `$this->application` for the standalone case, which
every unit test that builds a controller by hand relies on not happening. Every controller and
every theme, for no gain in production. That is a worse trade than the one it would fix.

The guard covers the directories where the factory is a hazard rather than a choice. These twelve
are choices, and they are now written down as such rather than remaining an unexamined number in a
changelog entry.
