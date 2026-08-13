---
date: 2026-08-14
categories:
  - Changelog
  - Added
tags:
  - debug
  - spa
---

# A Client tab: what the browser thinks the world is

Three questions that used to be answered by opening the browser's own devtools and
knowing where to look: what did the shell actually inject, where does the router
think it is, and what is in storage. The toolbar's new **Client** tab answers all
three, and it is the only tab present on every page — for every other tab no data
means no tab, but here *absence is the finding*.

<!-- more -->

## Runtime configuration

`window.__PRAMNOS__` as injected, with secrets masked by key name. A page with
none says so and says what that means: on a server-rendered page there is nothing
to inject, while in a SPA it means the shell did not run and the API client is
falling back to its built-in defaults — which is a different bug entirely.

## Router

The current URL, the router base, the resolved route and its params. The base is
printed next to the path because that pair *is* the deep-link failure: an
application served under `/app` whose router base is empty resolves every deep link
to its home screen and says nothing at all. When the path does not start with the
base, the panel says so in as many words rather than leaving two values side by
side to be compared.

The route name belongs to the application, so the router reports it — one line in
the scaffolded `lib/router.js`, on every navigation:

```js
import { reportRoute } from './debug.js';

reportRoute(name, { base: BASE });
```

Without it the URL and the injected base are still shown; only the route *name*
goes missing. The generated shell now publishes `routerBase` as well, so the
comparison works in a project whose `router.js` predates this.

## Storage

Every key in `localStorage` and `sessionStorage`, values masked by key name and
truncated when long. A masked value still reports its **length**, because "there is
a token and it is 900 characters long" is usually the whole finding: a stale token
survives a deploy, the server signs with a new key, and every call then fails in a
way that looks like a server problem.

An area that refuses to be read — private mode, a blocked origin — costs its own
section and nothing else. One that is not there at all is reported as *absent*
rather than as broken; those are different findings and they have different fixes.

## Documentation

- [Debugging Guide](../../Pramnos_Debugging_Guide.md) — the Client tab's three
  sections, and what each absence means.
