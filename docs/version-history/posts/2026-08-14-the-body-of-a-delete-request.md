---
date: 2026-08-14
categories:
  - Changelog
  - Added
  - Fixed
tags:
  - http
  - request
---

# The body of a DELETE request

`$_POST` is filled by PHP for POST only. A handler reading it under DELETE finds
nothing, and nothing about the code says it will — which shipped as **three
separate bugs in one application**: banning worked and unbanning was impossible; an
endpoint worked over POST and failed over DELETE on the same route; a third
accepted JSON and refused the form-encoded body every other endpoint used.

<!-- more -->

All three passed their unit tests. That is the part worth keeping: **a test that
seeds `$_POST` for a DELETE proves nothing**, because it constructs a state no real
request can produce. They were found with `curl`.

## `body()` and `bodyValue()`

```php
$request = new \Pramnos\Http\Request();

$fields = $request->body();                  // whatever this request carried
$id     = $request->bodyValue('id');
$reason = $request->bodyValue('reason', ''); // with a default
```

| Method | What comes back |
| --- | --- |
| `POST` | `$_POST`, or the decoded JSON body |
| `PUT` / `DELETE` / `PATCH` | the decoded body — PHP fills nothing for these |
| `GET` / `HEAD` | `$_GET`, because on a GET the query *is* the input |
| anything else | the decoded body |

Two differences from `allCurrent()`, and both were needed:

- **The method is read live.** `allCurrent()` answers from the method captured when
  the singleton was built — correct in production, stale anywhere the method is set
  afterwards, which is every test. A fix built on it passes over HTTP and fails
  under PHPUnit, and that happened.
- **The body is decoded on demand**, so the accessor works even when the object was
  constructed under a different method.

**PATCH now has a store at all** (`Request::$patchData`), and `all('PATCH')`
returns it instead of falling through to an empty `$_REQUEST`.

## Fixed: JSON bodies were decoded one level deep

`(array) json_decode($raw)` casts only the top level, so every nested value stayed
an `stdClass`. A handler iterating a nested list and checking `is_array($row)`
rejected the whole payload — one import endpoint answered
`200 {"success":true,"imported":0,"invalid":1,"reason":"Entry is not an object"}`:
a success status, nothing imported, and the only evidence a counter nobody reads.

It is a regression rather than a feature that never worked: the endpoint had been a
standalone script calling `json_decode($raw, true)` itself, and moving it onto the
framework's parsing is what broke it. All three sites (POST, PUT, and the
JSON-in-a-GET-key path) now decode associatively.

## Fixed: a DELETE body is no longer run through `parse_str` regardless

`parse_str('{"id":7}', $out)` produces `['{"id":7}' => '']` — **non-empty, so an
`empty()` fallback never fires, and nonsense, so nothing reads correctly either**.
That garbled-but-plausible array broke every JSON caller of an endpoint inside the
hour its form-encoded case was fixed.

JSON is now detected from the content type, or from a body starting `{`/`[` when the
header is absent (a hand-written `curl` and more than one HTTP client omit it). A
body that declares or looks like JSON and is not valid JSON yields an **empty**
array rather than one invented key.

## Documentation

- [Framework Guide](../../Pramnos_Framework_Guide.md) — "Reading the request body",
  with the per-method table and the JSON rules.
- `Request::$putData`, `$deleteData` and `$patchData` now carry docblocks saying why
  they exist. One sentence there would have been enough for any of the three bugs.
