---
date: 2026-08-14
categories:
  - Changelog
  - Added
  - Fixed
tags:
  - debugbar
  - debugging
  - authentication
---

# The toolbar answers "who am I, and until when"

"It worked and then it stopped" is almost always one of three things: the
credential expired, the client is sending a different one than it believes, or
it sent none and the server fell back to a session cookie that exists only on
the developer's own machine. Each is a different afternoon, and none of them was
visible.

<!-- more -->

## Added

An **Auth** tab, fed by a new `AuthCollector`:

- **who** — user id, username and type, or anonymous;
- **what** — `apiKey`, `accessToken`, the deprecated `userAuth` header, or a
  session cookie, reported in the order the middleware checks them so the answer
  is the credential that will actually be used;
- **where from** — `accessToken header` versus `Authorization: Bearer`. "The
  token" means a different header to different developers, and a client sending
  the one the server is not reading looks exactly like a client sending nothing;
- **until when** — a countdown, and the tab turns red once the expiry has passed,
  which explains every refusal above it in the list at once.

### The token never travels

Only identity claims do — `sub`, `iss`, `aud`, `iat`, `exp`, `nbf`, `jti`,
`userid`, `username` — and nothing else, because an application may put anything
in a token including data it would not want in a network log. This payload is
attached to responses, so a live credential in it would hand out the thing the
panel exists to explain.

The claims are read **without verifying the signature**, on purpose: this reports
what the client sent, and a token the server is about to reject is exactly the
one worth looking at. Whether it was accepted is the status of the request beside
it.

The expiry travels as the token's own absolute timestamp rather than as "seconds
remaining". The response may sit in a browser for a while before anybody opens
the tab, and a countdown that started when the request was made would be
reassuring and wrong.

## Fixed

**Signing in did not update the Auth tab** — reported from a real SPA, where it
still said "anonymous" until the page was refreshed. Auth is a *state*, not a
property of a request: the call made before signing in is over, and what a reader
wants is who they are now. It follows the most recent request unless one has been
picked, and says so when the state shown is newer than the request in view.

**A SPA no longer links to the DevPanel.** The DevPanel is a server-rendered page
behind MVC routing — a controller, a layout, an admin session — and a SPA
project's server answers JSON, so `/devpanel` is a 404 there. The link was drawn
in both deliveries on the assumption that a framework route exists wherever the
framework does. The data island is the exact test: it exists only for a page the
MVC middleware rendered, which is the pipeline the DevPanel lives in.
