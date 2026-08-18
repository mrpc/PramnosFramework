---
date: 2026-08-18
categories: [Changelog]
---

# A JSON reply that did not stop

Reported from a project consuming the framework: `/devpanel/logs?request=…` returned its JSON,
and then this, in the same response body:

```
{"request":"c6264dcd8e596cac","count":0,"lines":[]}
Deprecated: stripos(): Passing null to parameter #1 ($haystack) …
Fatal error: Uncaught TypeError: Application::renderThroughTheme(): Argument #1 ($content)
must be of type string, null given
```

The toolbar showed *"The server did not answer — try again"*. The server had answered perfectly
well and then kept talking.

<!-- more -->

## What was wrong

`DevPanelController` writes its own responses. `renderLayout()` echoes the panel and calls
`terminate()`; `renderError()` echoes the error page and calls `terminate()`. `sendJson()` echoed
the JSON and **returned `null`** — the same contract with the ending left off, and the only
outlier in the file.

A `null` return tells a dispatcher that the action produced nothing. So the application carried on
and rendered a page on top of a response that was already complete.

The rest of the failure is worth writing down, because it is a good example of two reasonable
decisions meeting badly:

- the application's `$output` property is **magic** — `Base::__get()` answers `null` for anything
  never assigned;
- its guard for "did a controller produce output?" is `if ($this->output !== '')`.

`null !== ''` is true. So the guard passed holding a null, `stripos()` was called on it twice, and
`renderThroughTheme()` fatalled on a `string` parameter. Its dispatcher was careful — it handles
`Response`, a string, and any object with `send()` — and `null` fell through every branch.

## The fix, and the fix that was rejected

`sendJson()` calls `terminate()` after writing. One line, and it makes the outlier consistent with
the two paths beside it.

Returning a `Response` was the other candidate and reads better in the abstract: it is the
framework's own "I am the whole response" object, and both `Application::exec()` and that
application's router handle it. It was rejected on a detail that matters more than the shape —
that application routes a non-API, non-HTML body through its theme, so a JSON body returned this
way would have come back wrapped in a page. A JSON reply to an XHR is finished when it has been
written.

## The test that would have caught it

Not one asserting the JSON. **The JSON was correct**; every existing test that looked at this
endpoint passed, before and after.

```php
$this->assertSame(1, $this->controller->terminated);
```

The testable subclass already stubbed `terminate()` as a no-op so the suite would not exit; it now
counts instead, and two tests assert that both the success and the 400 path declare the request
finished. Verified by removing the `terminate()` again: both redden.

That is the shape to remember — when a bug is *"the right output, followed by more"*, the
assertion has to be about the ending, not the content.
