---
date: 2026-08-14
categories:
  - Changelog
  - Added
  - Fixed
tags:
  - auth
  - documentation
---

# The guide described an API nobody had built

`Pramnos_Authorization_Guide.md` documented `Gate::define()`, policy classes, `auth()->can()`
and an `AuthorizationException`. **None of it existed.** A consumer found it by doing what the
documentation asks — picking a page by its `use_cases` and building on the first API it named.

The guide now describes what ships. And what ships now includes the gate, because the design
was right and the gap was real.

<!-- more -->

## How this one was worse than a normal doc bug

Sweeping every guide for `\Pramnos\…` names that resolve to no file finds nine pages. Eight
are **namespace slips** — the class exists, the guide spells its path wrong:

| Guide says | Actually |
| --- | --- |
| `Pramnos\Testing\TestCase` | `Pramnos\Framework\Testing\BaseTestCase` |
| `Pramnos\Application\Response` | `Pramnos\Http\Response` |
| `Pramnos\Database\Model` | `Pramnos\Database\OrmModel` and friends |

Annoying, and self-correcting: the reader greps the class name, finds it one namespace over,
moves on.

**The authorization guide was the only one where there was nothing to find.** No `Gate`, no
policy authorization, no `authorize()`, no exception — under any namespace. A reader who greps
`Gate` and gets nothing back cannot tell *"I searched wrong"* from *"this does not exist"*, and
that is the state in which somebody keeps looking for another hour.

There is even a near-miss to walk into: `Pramnos\Policy\PolicyEngine` and `PolicyRecord` exist,
and execute **data-retention** policies — retention windows, aggregate refresh, compression.
Same word, unrelated concept. Grep for "policy", find them, conclude the guide is implemented.

The filing put the cost precisely: the consumer's own `CLAUDE.md` says *"if something looks
missing, read the guide first — three times now the conclusion 'the framework does not do
this' was wrong."* That instruction is correct and has paid for itself. It also means a guide
describing an API that does not exist sends a reader the other way **with the same
confidence**, and it lands on whoever is doing authorization work — which is precisely where
people reach for a framework instead of inventing something.

## What was actually there

A permission **store**, and four places that ask it:

- `Pramnos\Auth\Permissions` — `allow()`, `deny()`, `isAllowed()` over
  `authserver.permissions`, with a genuinely well-judged three-valued answer: allow, deny, or
  **no rule at all**, which is not the same as a denial;
- `Controller::auth()` with `$actions_auth` and `$action_permissions`;
- `ApiCrudController::authorize()`;
- route permissions via `Router::hasPermissions()`;
- permission-gated navigation in `NavRegistry`.

All real, all documented now.

## And the gate, because the gap was real

The store records what an installation has **granted**. It cannot express a **rule**: "the
author, or a moderator" is not a row, and written as rows it becomes one row per article per
user.

```php
use Pramnos\Auth\Gate;

Gate::define('update-post', fn ($user, $post) => $user->userid === $post->userid);

Gate::policy(\App\Models\Post::class, \App\Policies\PostPolicy::class);

// "an administrator may do anything", once, instead of at the top of every rule
Gate::before(fn ($user) => $user->isAdmin() ? true : null);
```

```php
Gate::allows('update-post', $post);
Gate::authorize('update-post', $post);            // throws AuthorizationException
Gate::forUser($other)->check('update-post', $post);

$this->cannot('update-post', $post);              // in a controller
```

Rules return `true`, `false`, or **`null` for no opinion** — which falls through rather than
refusing, the same three-valued idea the store already used. Policies may carry their own
`before()`/`after()`, narrower than the global hooks.

### The bridge, which is the point

```php
Gate::fallbackToPermissions();   // off by default
```

With it on, an ability shaped `resource.privilege` that no gate or policy claims is answered
by the store. Rules that need reasoning live in code; everything else stays data an
administrator can edit without a deploy; and one `Gate::allows('reports.export')` asks
whichever layer owns the answer.

Off by default and deliberately explicit: a gate that silently consulted a database for names
nobody registered would be a gate whose answers cannot be read off the code.

### One failure shape instead of three

`Pramnos\Auth\AuthorizationException` — code **403**, carrying `getAbility()`. Before it, the
same answer had three shapes: `Controller::auth()` returned `false`, `ApiCrudController::
authorize()` returned `false`, and the router threw a plain `\Exception` with code 403. The
router now throws the typed one; since it extends `\Exception` with the same code, every
existing `catch (\Exception $e)` and `getCode() === 403` check keeps working.

### Three things differ from what that page described

| That page | Today | Why |
| --- | --- | --- |
| `$this->authorize('update', $post)` | `$this->can()` / `cannot()`, or `Gate::authorize()` | `ApiCrudController::authorize(string $action): bool` already exists with a different meaning — two `authorize()`s in one hierarchy is a trap even where PHP allows it |
| a global `auth()` helper | `Gate::` statics | the framework already has three unrelated `auth()` methods; a fourth spelling would have been the worst of them |
| policies only | policies **and** the store bridge | the store was already there and already used; a gate that ignored it would have split authorization in two |

### And a third isolation extension, written up front

`Gate` keeps abilities in statics. A `Gate::before(fn () => true)` left by one test would
allow everything for every test after it — and the failure would land in a test asserting that
an ordinary user is *refused*, which is the assertion nobody expects an unrelated file to
affect.

`Pramnos\Framework\Testing\GateIsolation` resets it between tests, and it is registered in
`phpunit.xml` and in what `pramnos init` generates. This is the first of the three that was
written **with** its feature rather than after the failures; the other two cost 135 and three.

## The check worth stealing

The filing ends with the method that found this, and it is the useful part:

> for each guide, take the first class or function it names and grep the source for it

It found this page by picking one from its `use_cases` frontmatter — the selection method the
consumer's own instructions prescribe — and then verifying the first API it named. **That last
step is the one nobody performs.**

## Added

- `Pramnos\Auth\Gate` — abilities, policies, `before`/`after` hooks, an optional bridge to the
  permission store.
- `Pramnos\Auth\AuthorizationException` — one failure shape, code 403, naming what was refused.
- `Controller::can()` / `Controller::cannot()`.
- `Pramnos\Framework\Testing\GateIsolation`, registered in the framework's `phpunit.xml` and in
  generated projects.

## Fixed

- [The Authorization guide](../../Pramnos_Authorization_Guide.md) describes what the framework
  actually does, including a note on `PolicyEngine` being a different thing entirely, and keeps
  a record of what the page used to claim.
- `Router` throws `AuthorizationException` instead of a bare `\Exception` — same class
  hierarchy, same code, now recognisable.
