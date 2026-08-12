---
date: 2026-08-13
categories:
  - Changelog
  - Changed
tags:
  - debugbar
  - debugging
  - spa
---

# The toolbar finds the log endpoint itself

`GET /devpanel/logs?request=<id>` shipped yesterday with its URL advertised in
the data island `DebugBar::render()` emits — which a SPA never sees, since its
shell is a static file no middleware touches. The obvious fix was to put the URL
in `ApiDebugPayload::build()` so every response carried it. That was the wrong
fix, and it did not survive review.

<!-- more -->

The route is a framework constant: the same path in every installation. Putting
it in every debug payload means sending the client something it could already
work out, on every annotated response, for the lifetime of the page. A response
should carry what only it knows.

## Changed

Nothing about the endpoint travels in a payload. The toolbar knows the path and
resolves the base from what the page already knows about itself:

- `window.__PRAMNOS__.base` in a SPA — the one case that cannot be inferred,
  because the API need not live where the page does, and the SPA shell already
  publishes this for its own router;
- the document's own base URL otherwise, which is right for a server-rendered
  page including one served from a subdirectory.

Whether the route answers is settled by the answer: `404` (feature off), `403`
or `401` (grant expired) retire the offer for the rest of the page, with the
reason on the button. Feature detection by use rather than by advertisement —
and it covers the case an advertisement never could, a grant that expires while
the page is open.

`DebugBar::logsUrl()` is gone; it had no callers left.

## Added

`DevPanelController::logs()` now has the access-control tests it should have
shipped with — it is the one route in the framework that hands over log lines:
a grant without an admin user is allowed, an ordinary user without a grant is
refused, an admin without a grant is allowed, a malformed or missing id is
refused before anything is read, and with the DevPanel feature off the route is
a 404 whatever grant the caller holds.
