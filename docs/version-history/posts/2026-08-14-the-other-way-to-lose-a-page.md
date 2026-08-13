---
date: 2026-08-14
categories:
  - Changelog
  - Fixed
tags:
  - debug
---

# The other way to lose a page

The empty-200 report came back after the first fix, with a sharper measurement: read
straight off the socket, the response was **523 header bytes and zero body bytes**.
Not a body mislabelled by a `Content-Length: 0` — a body that never left. And the
reporter was right that the guards added in `8c3c72ea` could not help: by the time
they run there is nothing left to guard.

<!-- more -->

## Reproduced, and it was not the decoration

A probe against a real server, with the provider booted by hand exactly as the
reporter's kernel does, and a plain `echo` of a page into the buffer:

| What the request does after the echo | Body delivered |
| --- | --- |
| nothing | 281,650 bytes ✅ |
| `while (ob_get_level()) { ob_end_clean(); }` | **0 bytes** ❌ |
| `ob_get_clean()` — one level, no matching `ob_start()` | **0 bytes** ❌ |
| its own `ob_start()`/`ob_end_flush()` pair | ✅ |
| a fatal error | ✅ |
| `zlib.output_compression` on | ✅ |

The probe also printed the buffer depth: **two levels** — php.ini's
`output_buffering`, plus the toolbar's. That is the whole mechanism. Code that clears
"its" buffer clears *ours*, and the page is inside it. Nothing errors. With
`APP_DEBUG=0` there is no second level, the `echo` goes straight to the socket, and
the same clean has nothing to destroy — which is exactly why the reporter's
`APP_DEBUG=0` measurement returned the full 37,353 bytes every time.

It also explains the asymmetry they noticed: a matched route survived because
`Response::send()` echoes its body, and by then the clean had already happened.

## What the framework does about it

It cannot refuse the clean — PHP has condemned the content before the handler is
called. So:

- **It says so.** The discard is logged with the byte count and both idioms named, so
  the cause is in the error log instead of nowhere. An invisible outage becomes a
  line somebody can search for.
- **It re-sends the response at shutdown**, once every buffer is out of the way,
  which is the only moment an `echo` reaches the client directly. Deliberately
  narrow: only when nothing was delivered through the buffer *and* what was dropped
  is a whole HTML document. A fragment, or a JSON body being replaced, is a discard
  that meant what it said.

The page comes back; the toolbar does not, because it was in the buffer that went.
Verified across all six shapes above: every one of them now delivers the document.

## Also fixed: the provider could boot twice

Constructing the provider by hand — the documented way to get collectors without
`Application::init()` — *and* calling `init()` booted it twice: two output-buffer
levels, and **the whole toolbar in the page twice**. Measured: a 9KB document came
back at 281KB. The second boot is now ignored.

Two levels also doubled the chance that a stray `ob_end_clean()` hit one of them, so
this is part of the same finding rather than a separate tidy-up.

## Documentation

- [Debugging Guide](../../Pramnos_Debugging_Guide.md) — "If your kernel clears output
  buffers", with the safe way to clear only what you opened.
