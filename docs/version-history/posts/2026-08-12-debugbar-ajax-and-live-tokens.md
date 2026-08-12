---
date: 2026-08-12
categories:
  - Changelog
  - Added
tags:
  - debugbar
  - debugging
  - console
  - security
---

# The toolbar now sees what a page does after it renders, and can be opened on a live server

Two gaps, both about the requests that matter most: the ones a page makes after
it has loaded, and the ones happening on the server you cannot turn debugging on
for.

<!-- more -->

## The requests after the render

Every tab in the toolbar described a single request — the one that built the
page. But a page is rarely finished when it renders. A datatable pages and
sorts, a form saves, a widget polls, a single-page application does nothing else
at all. Those requests ran queries nobody was watching.

**Added: an `ajax` tab.** No setup. The toolbar wraps `fetch` and
`XMLHttpRequest`, and every call the page makes appears with its method, URL,
status, server time and query count. Click a row for the statements.

The data reaches it through two channels, because one is not enough:

- **`_debug` in the body**, for any JSON *object* response — the full payload,
  with the queries. This is now attached **centrally**, in the output-buffer
  callback, rather than only inside `Application\Api`. That is what makes it
  cover datatable endpoints and controllers that echo their own JSON.
- **`X-Pramnos-Debug` and `Server-Timing` headers**, for everything with nowhere
  to put a key: a `204`, a redirect, an HTML fragment, a top-level JSON array.
  They carry a summary — time, memory, query *count*, route.

The header never carries query text. A header is written to the web server's
access log and to every proxy in front of it, and statements there would put
customer data in files nobody treats as sensitive.

An annotated response also declares `Vary: Cookie`, and `Cache-Control:
no-store, private` when the grant came from a token. On a live server the
toolbar is open for one browser while everyone else gets the same URLs, and a
shared cache in front of the application cannot tell them apart — a cached body
with a `_debug` key would hand one browser's query log to the next visitor.

The wrapper obeys three rules, because it runs inside somebody else's
application: the original `fetch`/`XMLHttpRequest` is always called and its
result returned unchanged, bodies are only read through `clone()` so the
application still consumes them, and every part is wrapped in `try`/`catch`. A
toolbar that breaks the page it measures is worse than no toolbar.

## Opening it for one browser on a live server

The toolbar is off in production, and it should be. But the bugs that deserve a
toolbar are mostly the ones that only happen there.

**Added: `php pramnos debug:token`.**

```
$ php pramnos debug:token --ttl=2h
  https://example.com/?_debug=1786237200.9f86d081898637d1…
  Valid until 2026-08-12 16:40:00 (2h)
```

Open the link once; the toolbar then follows that browser — every page, and
every XHR those pages make — until the token expires. `?_debug=off` ends it.

The token is `<expiry>.<hmac>`: the expiry, and an HMAC-SHA256 of it under the
application key. No storage, nothing to clean up, and it stops working by
itself. The expiry is what is signed, so it cannot be extended by its holder;
rotating `APP_KEY` revokes every outstanding token; comparison is `hash_equals()`.

Twelve hours is the ceiling. A debug token that lasts a month is a backdoor with
a friendly name.

**With no application key, nothing is granted** — `debug:token` refuses and every
check returns false. There is deliberately no fallback secret: a predictable one
here would hand a live server's query log to anyone who read the source.

### Two decisions worth stating

**A cookie, not the session.** Service providers boot before
`Application::init()` starts the session, so at the moment the toolbar decides
whether to exist there is no session to ask — `$_COOKIE` is already populated.
It also means the grant travels with every later request on its own, including
the XHR calls, which is what makes the ajax tab work on a live server at all.

**A grant opens the toolbar, not debug mode.** The check sits next to
`isDebugMode()` rather than inside it, because that method also decides whether
errors are shown to the browser. One person gets to watch; nobody gets a stack
trace on a public page.

## Also

A statement served from cache is labelled `CACHE` in the ajax panel, as it
already was in the main queries panel — and in the text the copy button
produces. Showing it as `0ms` reads as "instant" rather than "did not run", and
the difference between those two is the reason for looking at the panel at all.
The per-request header says how many of its statements were live and how many
came from cache.

## Documentation

New guide: [Debugging](../../Pramnos_Debugging_Guide.md) — the ajax tab, both
data channels, the token mechanism, and a security checklist for using it in
production.
