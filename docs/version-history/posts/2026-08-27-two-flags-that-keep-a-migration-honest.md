---
date: 2026-08-27
categories: [Changelog]
---

# Two flags that keep a migration honest

Both of these came from the same place: an application deleting a legacy class and finding
the replacement had made a decision for it.

<!-- more -->

## Added

### `Pagination::$displayEdgePages` — opt out of the pinned ends

```php
$pagination->displayEdgePages = false;   // … 8 9 [10] 11 12 …   — no 1, no 20
$pagination->displayFirstLast = true;    // « and » still reach both ends
```

`Html\Pagination` always showed the first and last page beside the ellipses, and the
changelog defended it: one click to either end is the point of a numbered pager over
previous/next alone. **The default has not changed.** What was missing was the opt-out.

The class it replaces had the equivalent flag, and the reporting application sets it off
in **12 of 13** places while leaving the first/last *buttons* on in 9 of them — the ends
stay reachable, and the number row keeps a constant width as the reader moves through it.
Without the flag, adopting the framework's pager would have altered twelve search pages:
a small change, but a product decision, and not one a library upgrade should make
silently. The alternative on the table was keeping 367 lines of the old class for two
links.

The ellipses stay honest either way, and that is the part that is not simply two `if`s.
With `1` on screen a gap exists from page 3, so `1 … 2` would be a lie about a page that
is right there; with `1` off screen a gap exists from page 2, and omitting the dots there
would hide that page 1 exists at all. The threshold follows the setting.

### `Input::$minlength`, `$title` and `$inputmode`

From an application replacing a JavaScript validation library with native constraints. Its
five validator types — none, email, integer, real, url — all already map onto types
`Input` has. Two attributes were what made the native path incomplete:

```php
$code->pattern   = '[0-9]{4}';
$code->title     = 'Four digits, e.g. 1234';
$code->minlength = 4;
$code->inputmode = 'numeric';
```

- **`minlength`** — `maxlength` was emitted and its sibling did not exist even as a
  property. The asymmetry was the finding: a field could declare a ceiling and not a
  floor, though both are halves of one HTML constraint. Emitted under the same rule, on
  the textual types only.
- **`title`** — `pattern` was emitted without it, so a failed pattern showed "Please match
  the requested format". That tells the user they are wrong and not what right looks like,
  which is validation that fails without explaining. Not restricted to textual types: it
  is also the only way `min`/`max` on a number explains itself.
- **`inputmode`** — not validation, the mobile counterpart of `pattern`. A field that
  accepts only digits should not open a QWERTY keyboard, and `type="text"` with a numeric
  pattern is exactly the combination that does.

`extraAttributes` could carry all three, but it escapes nothing, and a `title` is text
written for a person — precisely the kind of value that needs escaping.

Error message *text* stays the browser's, in the browser's language. That is more correct
than a framework shipping its own translated strings, and was not asked for.
