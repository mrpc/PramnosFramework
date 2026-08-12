---
date: 2026-08-13
categories:
  - Changelog
  - Added
tags:
  - debugbar
  - debugging
---

# The request body is back in the toolbar

The old ajax panel kept what the page had sent: captured from `fetch` and
`XMLHttpRequest`, form-urlencoded bodies decoded into the structure they encode,
secrets masked, the lot collapsed behind a `<details>`. Unifying the two
renderers lost it — `entry.body` was still recorded and drawn nowhere.

<!-- more -->

"What did I send" is the first question when a call comes back wrong, and it was
answerable only in the browser's own network panel, which does not know which
request the toolbar is showing.

## Added

The body of the selected request, above whichever tab is open — it belongs to
the request rather than to any collector.

- **Captured from both transports**, synchronously and without consuming
  anything: a string is already text, `URLSearchParams` and `FormData` are
  walked, and a `Blob` or an `ArrayBuffer` is described (`[binary, 4096 bytes]`)
  rather than decoded, because reading one is asynchronous and the object
  belongs to the application. Only `fetch`'s init body — a `Request` instance
  owns its own stream, and reading it would consume what is about to be sent.
- **Form bodies decoded.** `columns%5B0%5D%5Bdata%5D=userid` is nested data
  written flat and then escaped; a datatables request is fifty of those, and as
  raw text it is unreadable, which is the same as not being shown.
- **Collapsed, with its size**, because two kilobytes of column metadata
  expanded would push everything worth reading off the screen.
- **Masked.** The body never leaves the browser — nothing is added to the
  request and nothing is transmitted to produce it — but the panel gets
  screenshotted and shared, and a password in a bug report is a password that
  has to be changed.
## Limits, so it cannot become the problem

- **8KB per body**, and the panel says when it cut one — a body that looks
  complete and is not is worse than one that admits where it stops. Fifty file
  uploads in history is how a debugging aid runs a tab out of memory.
- **No layout above 2KB.** Pretty-printing means parsing, re-serialising and
  walking every key to mask it: nothing for two kilobytes, real work for eight,
  and it would run on the render path that must never be why a page feels slow.
  The browser's own network panel draws this line somewhere too. Masking is not
  part of the trade — it applies at every size.
- **Computed once.** `render()` runs for every recorded request, so a polling
  page would otherwise re-lay-out the same body every few seconds. The result is
  kept on the entry.
