---
date: 2026-08-11
categories:
  - Changelog
  - Fixed
tags:
  - model
  - orm
---

# A model may give `load()` whatever parameters it needs

The listing helpers used to find a model's table by calling `$this->load(0)` —
not to load anything, but hoping the subclass would set `$_dbtable` as a side
effect. That quietly required every model's `load()` to accept exactly one
argument.

<!-- more -->

## Fixed

Three places in `Model` did the same thing: when `$_dbtable` was unset, call
`load(0)` and hope. It coupled table discovery to record loading, issued a
pointless lookup for id 0, and assumed a signature the base has no business
assuming. A model written as `load($username, $type)` got an
`ArgumentCountError` raised from inside a framework method, about a call its
author never made.

The assumption could not simply be enforced, because enforcing it would cost
more than it saved. PHP only lets a child **add optional** parameters, so any
declaration in the base rejects a child that needs its own:

```
abstract load($id)   → child load($id, $x = null)     allowed
abstract load($id)   → child load($user, $type)       Fatal: must be compatible
abstract load(...$a) → child load($id)                Fatal: must be compatible
load($id = null)     → child load($user, $type)       Fatal: must be compatible
```

Not even a variadic declaration is neutral. `Pramnos\Auth\Application` already
loads by id plus two options, and models are expected to keep that freedom.

So `load()` stays undeclared and the base gets a hook of its own:

```php
/** Set $_dbtable here when the model works its name out at runtime. */
protected function initTable(): void
{
    $this->_dbtable = 'readings_' . $this->tenant;
}
```

Models that declare `protected $_dbtable` — nearly all of them — need nothing.

Models still written the old way keep working: when neither a property nor
`initTable()` produced a name, the base falls back to the historical `load(0)`
call. It first checks, by reflection, that `load()` can accept one argument. A
model whose `load()` needs more gets a `LogicException` naming the class and the
fix, instead of an `ArgumentCountError` from a call that should never have been
attempted — and the call is not attempted.

What each caller does with an unresolved table is unchanged: `_getJsonList()`
still returns an empty list, the others still skip the query. Turning that into
an exception would have changed three public methods for no gain.
