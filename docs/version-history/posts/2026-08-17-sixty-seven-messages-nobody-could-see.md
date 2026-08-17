---
date: 2026-08-17
categories: [Changelog]
---

# Sixty-seven messages nobody could see

`pramnos-check` shipped this morning and reported **76 findings against the framework's own
`src/`**: 9 raw-SQL and 67 flash-query-parameter. All reviewed, all real. They are now all
fixed, and the flash half turned out to be a bigger job than "change 67 redirects".

<!-- more -->

## The flash had no display side *inside the framework*

`Base::addMessage()` and `addError()` write `$_SESSION['_messages']` and `$_SESSION['_errors']`,
and nothing in `src/` read them back: `_getMessages()` and `_getErrors()` are `protected` and are
called from nowhere in this repository.

**They are read by consuming applications**, though — which is the entire point of their living on
`Base`. A reference application calls `_getErrors()` in three API controllers to put the errors
into a JSON response. So the mechanism did have a display side; it was outside this repository and
invisible to a grep of it. An earlier draft of this post said "nothing ever read them", which was
wrong, and the correction mattered — see the regression below.

What was missing *here* is a path a framework view can use, which is why 67 controllers passed
`?error=…` in the redirect URL instead. Nothing read that either: there are no views in the
framework's own view directories at all. So converting the redirects without building the reading
half first would have been a lateral move — from nobody showing it to nobody showing it.

### So the reading half was built first

Into the seam that already existed rather than a new one. `Request` already owns this exact
pattern for validation errors: capture once per request, clear the session entry immediately, keep
the values available for the rest of the request. That is what makes a flash a flash — shown on
the page the redirect lands on, and **not again on a reload**.

- `Request::messages()` and `Request::flashErrors()`, captured and cleared by the same one-shot
  method as `errors()` and `old()`.
- `View::$messages` and `View::$flashErrors`, read once in the constructor beside the existing
  `$errors`.

`$errors` keeps its meaning — the per-field output of a validator — because a template that
iterates one expecting the other gets field names where it wanted a sentence.

## The 67 conversions

Each redirect now flashes a sentence instead of a code:

```php
// before
$this->redirect(sURL . 'organizations?error=not_found');

// after
$this->addError('That record no longer exists.');
$this->redirect(sURL . 'organizations');
```

Thirty-three distinct codes across ten controllers became thirty-three sentences. `?error=` and
`?message=` no longer appear in any controller.

**Sixty existing assertions pinned the old shape** —
`assertStringContainsString('error=not_found', $redirect)` — and they were the specification of
what changed. They now assert the user-visible outcome instead: that the sentence is in the flash
bag. That is a better test than the one it replaced; the URL parameter was never the point.

## The 9 raw-SQL findings

| Where | What |
| --- | --- |
| `ApiCrudController` | `SELECT 1 FROM $table LIMIT 1` → `->table($table)->exists()` |
| `ApiAdmin` | `SELECT COUNT(*) AS total FROM $table` → `->count()` |
| `DatabaseQueueDriver` ×2 | an interpolated row id, and a full-table delete → bound and built |
| `Init.php` ×3 | **generated** test teardown: the scaffolder was writing raw SQL into every new project, with a hand-rolled comment explaining how it avoided backticks. The builder does that properly |
| `PolicyEngine` ×2 | `INSERT … SELECT`, which the builder cannot express — suppressed **with the reason**, which is what rule 12 asks for |

`pramnos-check` now reports **no findings** across 463 files and 7 rules.

## The regression this shipped past the first fix

Twice, in fact. The first fix covered the destructive readers and missed the gates in front of
them, and the second miss was worse than the first.

### Round one: `_getErrors()` returned `false`

`Base::_getErrors()` reads `$_SESSION['_errors']` and, finding nothing, **returns `false`** — it
does not fall back to the instance bag. The new capture unsets that key, and
`View::__construct()` triggers the capture on essentially every request. So an application reading
its flash through `Base` would have received `false` for errors that were flashed perfectly well:
an API response that used to carry `errors` would have carried `false`, and nothing about it would
have looked wrong.

The claim that made this possible was mine: "nothing ever read them", from a grep of `src/`. But
`_getErrors()` is `protected` on `Base`, which **every application extends** — so its callers are
outside this repository and a grep here cannot see them. A `protected` member on a universally
inherited base class is a public API.

### Round two: the gates, which is where the real damage was

Fixing the destructive readers was not enough, and the search that found them was aimed wrong: it
looked for `_getErrors`, and a reference application's actual readers are **`hasErrors()` and
`_printErrors()`**. It gates every flash it displays:

```php
if ($this->hasErrors()) { echo $this->_printErrors(); }
```

in its theme header and in five views. `hasErrors()` had no fallback, so it answered `false`, the
printer was never reached, and the **entire flash UI went silent** — invalid-login messages,
lockout notices, CSRF errors, and around sixty `addError()`/`addMessage()` calls across its admin
controllers. Nothing failed anywhere.

**How it was actually found**, because this is the part worth copying: the application's `vendor/`
copy was swapped for a symlink to this repository, and three real HTTP requests were driven through
both versions with one cookie jar — `GET login`, `POST` with a bad CSRF token, `GET login` — while
counting how many times the message appeared.

| Framework | Appearances of "Security token invalid or expired" |
| --- | --- |
| `vendor/` (1108 commits behind) | 1 |
| this branch | **0** |

Its own 5497-test suite passed identically on both, with the same 15624 assertions. So did this
framework's 9795. **The regression was invisible to both suites** and visible in one byte count.

All four members now consult the capture. The gates stay non-destructive — a gate that consumed
would leave the printer with nothing, the same silence by the opposite route — and the printers
consume, so a message is shown exactly once. Verified by driving the same three requests again: 1
appearance, not 2.

### And two loose ends the same investigation surfaced

`ApplicationsController` read `$_GET['message']` into a view, and the scaffolder generated an
account-profile view that mapped `?message=profile_saved` to a sentence — **with nothing anywhere
emitting those parameters**. The same half-wired shape as the 67 redirects, generated into every
new project. Both now read the flash. No `$_GET['error']`, `['message']` or `['msg']` reader is
left in `src/`.

## What was originally written up as the near-miss

Draining the session for the new path nearly broke the old one, and it would have been silent.

`Base::_getErrors()` reads `$_SESSION['_errors']` and, finding nothing, **returns `false`** — it
does not fall back to the instance bag. The new capture unsets that key, and
`View::__construct()` triggers the capture on essentially every request. So an application reading
its flash through `Base` would have received `false` for errors that were flashed perfectly well:
an API response that used to carry `errors` would have carried `false`, and nothing about it would
have looked wrong.

Three lines were enough to reproduce it once the possibility was pointed out — flash an error, let
something read the request as the view does, read through `Base`, get `false`.

`_getErrors()` and `_getMessages()` now fall back to the same per-request capture, so both paths
work and the session is drained once. One behavioural difference, documented rather than hidden:
within a single request they can now return the same errors twice, where the second call
previously answered `false`.

The lesson is not "add a fallback". It is that a `protected` method on a base class **every
application extends** is a public API, and a grep of this repository cannot see its callers.

## Three mistakes made along the way

Recorded because each was caught by something specific, and the something is the point.

**The queue would have claimed nothing.** Converting `DELETE FROM … WHERE id = …` to the builder,
the atomic claim became `(int) $deleted !== 1` — but `delete()` returns a `Result`, not a row
count, so that comparison would have been false for every job and the queue would have processed
nothing at all. Caught by reading the builder's return type rather than assuming it.

**A suppression that suppressed nothing.** The `PolicyEngine` comment was four lines above the
statement; the check accepts the same line or the one immediately above. The tool reported its own
suppression as unsuppressed, which is the correct behaviour and exactly what the feature is for.

**A regex that was far too greedy.** The last pass over the test assertions matched a bare code
string anywhere and changed **74 assertions across 17 files** when 11 needed changing — including
files that had never failed, where `assertStringContainsString('deleted', …)` was about a response
body rather than a redirect. The suite went green, which is worse than red: passing tests that
assert the wrong thing. Twelve files were reverted and the remaining conversions checked one by
one, confirming every one referenced `redirectedTo`, `lastRedirect` or the echoed redirect.

## Also fixed

`ViewTest::testGetTplWithActualFile` asserted the debug comment in a rendered template and passed
only because some *other* test in the suite set `APP_DEBUG` first. Gating that comment behind
debug mode — a path-disclosure fix from earlier today — is what made it order-dependent. It now
sets the variable itself and restores it.

## Documentation

The Framework Guide's error-handling section documented `addError()` without saying how anything
displays it, because nothing did. It is now a flash-messages section: how to write one, how to
read it in a view, why not to use a query parameter, and the three things about the mechanism
worth knowing — that `messages` is not `errors`, that reading consumes, and that it needs a
session.
