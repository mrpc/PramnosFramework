---
date: 2026-08-16
categories:
  - Changelog
  - Added
tags:
  - model
  - orm
  - services
---

# The controller a model needs costs 1.5 microseconds

`Model::__construct()` requires a `Controller`. That reads as a hard dependency on the
MVC stack, and it has been quietly deciding architecture in projects that never
measured it.

<!-- more -->

## Five references, two of them real

Inside `Model`, `$this->controller` appears five times:

| Line | What it does |
| --- | --- |
| 99 | the assignment |
| 240 | `$this->controller->getModel($model)` — sibling lookup |
| 859 | the error path |
| 725, 880 | **passes itself to the next model being constructed** |

Three of the five exist only to carry the thing onwards. And `Orm\Relations\Relation`
explained the dependency with a reason that is false:

> *We pass the parent's controller so the model can reach the database.*

It does not. `Model::__construct()` calls `Database::getInstance()` on the line below.

A wrong reason in a comment is worse than no comment: it makes the dependency look
load-bearing, so somebody trying to use a model from a queue worker reads it and
concludes they need to fake a request.

## So it was measured

- `new Controller()` — **1.54 µs**
- the `Application::getInstance()` behind it — **1.3 ms cold, 0.002 ms warm**

The dependency costs nothing and looks like it costs a great deal. That gap is the
whole finding: it is exactly the shape that gets designed around rather than checked.

## `ServiceController`

```php
use Pramnos\Application\ServiceController;

$post = new \App\Models\Post(ServiceController::shared());
$post->load($id);
```

No framework change was needed for this to work — `new Controller()` was always safe to
call. What was missing was a **name**, so that every application working it out
independently stops inventing its own and rediscovering that fact.

`shared()` because a service building several models wants one controller: models from
the same controller resolve each other through `getModel()`, and a fresh one re-runs a
reflection and a permissions normalisation for nothing. `forget()` for tests, because
the instance holds the `Application` current when it was built and would otherwise leak
into whichever class runs next.

**It grants no permissions**, and there is a test pinning that. Code outside a request
has no user. A controller that quietly behaved as though it did would be a much worse
thing to put in a framework than the inconvenience it removes.

## What this does not settle

Whether a services-oriented application *should* introduce models is a separate
question with real arguments on both sides. This only removes one bad argument from it:
the `Controller` parameter is not a reason to decide either way, and it should never
have been read as one.

## Added

- `Pramnos\Application\ServiceController` — a `Controller` for code with no MVC request
  behind it, with `shared()` and `forget()`.

## Fixed

- `Orm\Relations\Relation::newRelatedInstance()` no longer documents a reason for the
  controller that was never true.

## Documentation

- [Application Styles guide](../../Pramnos_Application_Styles_Guide.md) — *Using a Model
  outside an MVC request*, with the measurements and the permissions caveat.
