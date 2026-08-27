---
date: 2026-08-27
categories: [Changelog]
---

# A flash that worked once per process

`Request::resetInstance()` cleared the request's derived state but not its captured flash bag,
so the second request served by a process got the first one's — already consumed — messages.

<!-- more -->

## Fixed

**`Request::resetInstance()` now clears the captured flash and validation state** —
`$flashMessages`, `$flashErrors`, `$validationErrors` and `$oldInput` — nulled rather than
emptied, so the next reader loads what is in the session at that moment.

The flash bag is captured once per request and the session keys are unset as they are read;
that is deliberate, and it is what lets a controller and a template both read a message
without one eating the other's. What was missing is that the capture is *per request*. Left
behind, it answered for the next request too, with contents that had already been consumed.

One process, one request hides this completely, which is why it lasted. Anything serving more
than one sees it: a queue worker, a daemon, and every test that makes two requests. What it
looks like from outside is a flash mechanism that works once and then goes quiet —
`addMessage()` writes to the session, the redirect lands, and the page renders with no
message. Indistinguishable from a save that silently did nothing.

It also made the flash untestable end to end, which is the second half: a test can only reach
the "after the redirect" state by making two requests, and the second one could never see what
the first one flashed.

## Documentation

- `Pramnos_Framework_Guide.md` — *Three things to know about the mechanism*, under
  **Flash messages**.
