---
date: 2026-08-26
categories: [Changelog]
---

# The recovery path was the crash

`View::getTpl()` has an `else` branch for "I could not find that template". On PHP 8, with
`?format=json`, that branch was a fatal.

<!-- more -->

## Fixed

- **`View::getTpl()` no longer fatals when a template is missing and `?format=json` is
  asked for.** Two lines that did not agree with each other:

  ```php
  public $model = false;          // View.php:77
  if (isset($this->model)) {      // View.php:789
      if (method_exists($this->model, 'getJsonList')) {
  ```

  `isset()` answers *not null*, not *not empty*, so on a value of `false` it returns
  `true`. The guard therefore passed for every view with no model, and
  `method_exists(false, …)` is a `TypeError` on PHP 8:

  ```
  Uncaught TypeError: method_exists(): Argument #1 ($object_or_class)
  must be of type object|string, false given
  ```

  The branch it sat in exists to **recover**: a few lines further on it logs *"Cannot find
  view template"*. So the handler for a missing template was the thing taking the page
  down. Reported as FW-021 from a consuming application's home page, with the stack trace
  from its `php_error.log` — not a reading of the source.

  The guard is `is_object()` now. The default stays `false` rather than becoming `null`:
  `isset()` would then work, but anything comparing `=== false` would change meaning, and
  the question being asked is "have I got an object" either way.

- **The sibling sites the filing asked about were checked.** There are 105
  `isset($this->…)` in `src/`, and only two on a plain property rather than an array
  index: this one, and `Console\MakeCommandBase::$output`. The second is correct — a typed
  property with no default, where `isset()` genuinely answers "uninitialized". So this was
  the only occurrence.

## Tests

The regression test was verified backwards: the `isset()` guard was restored temporarily
and the test reproduced the reported `TypeError` at `View.php:804`. A regression test that
does not fail against the old code is worth nothing, and this one is a "does not throw"
assertion, where that is easy to get wrong without noticing.

The other two cover the reason the branch exists at all — a model that *does* expose
`getJsonList()` still gets to answer, and one that does not falls through — so the fix
cannot quietly become "skip the branch".

## Documentation

- [Framework Guide](../../Pramnos_Framework_Guide.md) gains **When a template is not
  found**, next to *Template Files*: what `getTpl()` does, and the `?format=json` model
  hand-off that is otherwise indistinguishable from magic.
