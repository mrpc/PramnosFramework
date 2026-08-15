---
date: 2026-08-16
categories:
  - Changelog
  - Fixed
tags:
  - cache
---

# The cache category was accepted and discarded

`Cache::getInstance('views')` returned an instance with whatever category the first
caller in the process had asked for. In any application that boots providers, that
first caller is `CacheServiceProvider`, which asks for none.

<!-- more -->

## Nine lines, one static

```php
public static function getInstance($category = NULL, $extension = NULL, ...)
{
    static $instance = null;
    if ($instance === null) {
        $instance = new Cache($category, $extension, $method, $settings);
    }
    return $instance;
}
```

One instance. Not one per category — one. Every argument after the first call was
ignored, and nothing said so.

That would be a curiosity if the category were decorative. It is not: `$this->category`
is what `_generateCacheName()` writes into the key, and `save()` **has no category
parameter at all**, so an instance's category is the only thing deciding where its
values go. `remember()` goes through both.

## What it cost, in two directions

- **`View::cache()` believed it was writing under `views`.** It was not, so
  `php pramnos cache:clear --category=views` never matched a single view fragment.
  The command ran, reported success, and cleared nothing — which is the failure mode
  this ledger has been collecting all week, in a new place.
- **Two subsystems asking for different categories shared one namespace.** A key
  collision between unrelated parts of an application was possible where the API said
  it was prevented.

And the guide documented the behaviour that did not exist, in detail — three instances
with three categories, each saving its own data. All three were the same object.

## The fix, and what it costs you

One instance per `(category, extension, method)`.

Existing entries were written under the wrong key, so they miss once. For a cache that
is the correct outcome rather than something to migrate: the values are recomputed and
written where they belong.

`$settings` is deliberately **not** part of the key. It is merged over the
application's cache settings, and a caller passing different settings for the same
category is asking for a different configuration of the same store — which is what
`new Cache()` is for.

## How it was found

Not by a report. It was found while planning something else entirely: a persistent
cache for database column metadata needed a category of its own, and the first question
was whether categories worked. They did not, and the thing that was going to use them
has not been built yet.

Four of the five tests fail when the fix is reverted. The fifth is the one asserting
that asking twice for the same category still gives you one instance — `getInstance()`
must not quietly become a factory that opens a connection per call.

## Fixed

- `Cache::getInstance()` keeps one instance per `(category, extension, method)`
  instead of one for the whole process.

## Documentation

- [Cache guide](../../Pramnos_Cache_Guide.md) — a correction on the *Categories and
  Organization* section, which described the intended behaviour accurately while the
  code did something else, and a sentence saying plainly that a category is a
  namespace rather than a label.
