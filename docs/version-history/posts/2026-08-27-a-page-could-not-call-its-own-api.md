---
date: 2026-08-27
categories: [Changelog]
---

# A page could not call its own API

Every API request needed an `apikey` header. A browser page has none, and cannot be given
one. So a server-rendered screen could not call its own application's endpoint at all — the
framework's own search box being the case that found it.

<!-- more -->

## Fixed

**`ApiAuthMiddleware` accepts the application's own signed-in page in place of an API key.**

An API key names the *client*. For a same-origin request from our own document the client is
us, and there is no way to hand a page a secret anyway: anything the document can read, a
reader of the document can read. The middleware answered 403 `APIKeyMissing`, which is
correct for a third party and wrong for the page that rendered a moment earlier.

A request with no API key is now accepted when **both** of these hold:

- the session carries an active `web_session` token — which every web login already creates;
- the request carries `X-CSRF-Token` matching the session's own token.

Either half alone is refused. The cookie by itself is not an authentication signal, because
the browser attaches it to a cross-site request too; the CSRF token is the half that proves
the caller read our page. That is the same pair `UnifiedAuthMiddleware` has always accepted
for first-party route groups — and it is now literally the same code, moved to
`SameOriginSessionTrait` so the two middlewares cannot drift into two opinions about what a
same-origin session is. An anonymous session is refused with 401 rather than published as an
identity: user 1 is the anonymous account, and every `isAuthenticated()` check downstream
would read it as a signed-in user.

Applications served by `Pramnos\Application\Api` could not opt into `UnifiedAuthMiddleware`
even knowing all this: `Api::exec()` pipes the API-key middleware itself, before routing, so
there is no route group to configure.

**The document prints the token.** `<meta name="csrf" content="…">`, in the `<head>`, from
`Document::csrfHeadMarkup()` — so every theme in every project has it without editing a
template, existing projects included. For a **signed-in** page only, and only when a session
is already running: on an anonymous page the token authenticates nothing, and reading it
would start a session on every public URL, which is the difference between a page a shared
cache can hold and one it cannot.

**`pf-utils.js` sends it.** `window.pfApiHeaders(extra)` adds the header from that tag, and
both fetches in the file now go through it. Use it for your own calls rather than assembling
headers by hand.

## How three correct parts added up to a broken feature

`Html\SearchBox` renders a box. `ApiAdmin::search()` answers a term. The `data-pf-omnibox`
handler connects them. Each was tested, each worked, and the feature did nothing: the request
went out without a credential the endpoint would accept, the handler logged one line to the
console, and the box showed *No results* — which is indistinguishable from a term that
matched nothing. It was reported as "the search does nothing", and the parts each pointed at
the others.

Worth keeping in mind for anything assembled from three tested pieces across two processes.

## Documentation

- `Pramnos_API_Guide.md` — a new *Calling your own API from your own page*, with what is
  accepted, what is refused, and the `pfApiHeaders()` call.
- `Pramnos_Search_Guide.md` — *How the box authenticates*, and the two things a project
  adopting the omnibox has to have (a current `pf-utils.js`, nothing in the theme).
