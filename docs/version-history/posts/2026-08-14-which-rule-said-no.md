---
date: 2026-08-14
categories:
  - Changelog
  - Added
tags:
  - debug
  - auth
---

# Which rule said no

The toolbar's **Auth** tab answers who the request is and what convinced the server of it. Nothing
answered the next question — was the action allowed, and **which rule decided** — and that was not
an oversight. It is a property of the feature.

<!-- more -->

## Why nothing could tell you

A gate's rule is a closure in a bootstrap file, so it appears in no stack trace. A
`Gate::before()` hook that returns `true` skips every step after it and leaves no mark. The SQL
panel cannot help, because a decision may touch no database at all. And a 403 tells you that
something refused, not which of six steps did:

| Step | Means |
| --- | --- |
| `before` | A global hook decided immediately — "an administrator may do anything" |
| `ability` | A named `Gate::define()` rule answered |
| `policy` | A policy method answered; the row names it |
| `store` | The permission store answered, via `fallbackToPermissions()` |
| `default` | **Nothing claimed this ability**, so it was refused |
| `after` | A rule answered and an `after` hook overrode it |

The [Authorization guide](../../Pramnos_Authorization_Guide.md) has said since the gate shipped
that this order *is* the contract — that every "why was this allowed" question is answered by
knowing which step decided. It was true and unobservable.

## The row that earns the tab

```
allowed   update-post        policy    PostPolicy::update    App\Models\Post
allowed   see-menu    ×40    before    a global before() hook decided     —
refused   updatePost         default   nothing claimed this ability       App\Models\Post
```

`fallbackToPermissions()` is off by default, so **an ability nobody defined is refused** — which
makes a typo in an ability name *indistinguishable* from a deliberate deny, because both produce
`false`. `updatePost` where the code defines `update-post` is a real afternoon.

`default` separates them, and the collector counts those rows separately so a badge says so
before the tab is opened. It is the same instinct as `WidgetRegistry::unresolved()`: the thing
that quietly did nothing should be findable, not merely survivable.

Identical checks collapse into `×N`, because rendering a permission-gated menu asks the same
question for every one of forty items and that should be one row rather than forty.

## What it deliberately does not carry

**The arguments.** A policy check receives whole models, and this payload is attached to the
response — it sits in a browser's network log. So a subject is reduced to its class name and a
user to an id; nothing that came out of a database travels. That is the rule `AuthCollector`
already applies to the credential it exists to explain, and the reason it exists there is the
reason it applies here.

**And it is not a permissions browser.** It shows what *this request* decided, not what a user
may do in general. The second question belongs to the permission store and a different tool; a
request-scoped panel that drifted into answering both would answer neither well.

## What it costs an application that never opens it

One boolean check per decision. `Gate::enableDecisionLog()` is opt-in and the debug provider
calls it, which is exactly the shape `Database::enableQueryLog()` has had all along — the query
panel does not exist unless something asked for it either. There is a test that switches
recording off and asserts nothing is recorded, because a cost guarantee nobody checks is a cost
guarantee that drifts.

The log is capped at 200 distinct decisions. A page checking hundreds of *different* abilities
has a different problem than this panel is for, and filling memory to describe it would be the
wrong trade.

## Added

- `Gate::enableDecisionLog()`, `Gate::decisionLog()`, `Gate::clearDecisionLog()`.
- `Pramnos\Debug\Collectors\GateCollector` and a **Gate** tab, beside Auth — the two halves of
  "why did this fail", in consecutive tabs.

## Documentation

- [Authorization guide → Seeing which step decided](../../Pramnos_Authorization_Guide.md)
- [Using the debug toolbar → It said I am not allowed](../../Pramnos_Debug_Toolbar_Usage.md)
