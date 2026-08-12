---
date: 2026-08-12
categories:
  - Changelog
  - Fixed
tags:
  - debugbar
  - debugging
  - spa
---

# The toolbar's hide button now hides the toolbar

Reported from two applications at once: the `✕` did nothing. Not "hid the wrong
thing" — nothing at all, in the server-rendered toolbar and in the SPA panel
alike.

<!-- more -->

## What it was doing

Both handlers toggled the **panel's** inline `display`, not the bar's, starting
from `''`:

```js
d.style.display = d.style.display === 'none' ? '' : 'none';
```

The stylesheet already hides the panel (`#pdb-panels{…display:none}`). So the
first click set `display:none` on something invisible, and the second set `''`,
handing it back to the stylesheet — which hid it again. Two clicks, no visible
effect, forever. Closing an open panel is what clicking its own tab already does,
so even working as written the button had no job.

## What it does now

`✕` hides the whole bar and leaves a small `⚙` handle in the bottom-right corner
to bring it back. Both bars behave identically:

- The page's `padding-bottom` is released with the bar, so a hidden toolbar
  leaves no unexplained gap — and it is now set by the same code path that hides
  it, rather than by a separate inline script that could disagree.
- The choice is remembered in `localStorage` under `pramnos.debugbar.hidden`,
  shared between the toolbar and the SPA panel. A bar that came back on the next
  page would be the same complaint, one step later.
- Storage that throws — Safari's private mode, a blocked origin, where reading
  `localStorage` fails on access rather than on the call — costs the memory, not
  the button. It still hides; it just cannot remember that it did.
- The restore handle is rendered **outside** `#pramnos-debugbar`. Nested inside,
  hiding the bar would hide the only way back.

An existing SPA project picks the fix up with
`project:resync --debug-panel`.

## Tests

The behaviour is driven in JavaScript, because a PHP test can assert that a
button is emitted but not that clicking it does anything — which is exactly the
gap that let this ship.

New `tests/js/debugbar-hide.test.js` extracts `DebugBar::js()` from PHP, runs it
against a DOM stub and clicks the buttons: the bar hides and the padding is
freed, the handle restores both, a bar hidden on an earlier page loads hidden,
and throwing storage does not take the toolbar with it. `spa-debug-panel.test.js`
gains the same four for the scaffolded panel. `DebugBarTest` pins the markup
half — the button, the handle, and the handle being outside the bar.

## Also: `./testjs` ran nothing in the container

Two faults that hid each other. The runner passed a *container* path glob for the
**host** shell to expand, so node received the pattern literally and reported
`Could not find '/var/www/html/tests/js/*.test.js'` — and the JS tests that
extract PHP output called `ReflectionMethod::setAccessible()`, which has had no
effect since PHP 8.1 and is deprecated in 8.5, so the container's CLI printed
that deprecation **to stdout**, in front of the script, where it was parsed as
JavaScript.

Fixed both: the glob is expanded by the container's shell, and the no-op call is
gone. `./testjs` now runs all 74 JS tests inside the container, which is where it
was always meant to run them.
