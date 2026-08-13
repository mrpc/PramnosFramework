---
date: 2026-08-14
categories:
  - Changelog
  - Added
  - Fixed
tags:
  - debug
  - api
  - openapi
---

# An API playground in the toolbar — and a doubled slash it found

The toolbar's new **API** tab lists the endpoints in the project's own OpenAPI
document, calls one with the parameters you give it, and shows the answer. This is
the last item on the debug-toolbar roadmap.

<!-- more -->

## It is a real request

The call goes through the same server, the same middleware and the same
authentication as the application's own, and it is **recorded in the requests
list like any other** — so Time, SQL, Logs and Domain answer for the call you just
made. A playground that stubbed the request would answer a question nobody asked.

Recording is explicit rather than left to the transport wrapper, and the unwrapped
`fetch` is used to send: a SPA has no wrapper at all (its API client reports its
own calls), and on a server-rendered page this is what keeps the call from being
recorded twice.

## Nothing to maintain

The endpoint list is not a list — it is the OpenAPI document
(`www/api/openapi.json`, from `npm run docs:build`). An endpoint appears because it
is documented, and one that is missing is a documentation gap the tab has just
reported.

- **Where it sends:** the API prefix the shell injected. The document's own
  `servers` list is deliberately not preferred, and an absolute URL in it is
  ignored — a generated document names production URLs, and sending a development
  call there because a list was ordered that way is the one mistake this tab must
  not make.
- **Bodies** come pre-filled from the document's `example`, or from the schema's
  properties one level deep. A skeleton of a deeply nested schema is harder to
  correct than an empty object is to fill.
- **Credentials:** the `apiKey` from the injected configuration, cookies
  (`same-origin`, so a signed-in browser session authenticates the call), and a
  stored bearer token if the page has one — found by key name, with the panel
  naming **the key, never the value**. One click refuses it, which is how "is this
  endpoint actually public?" gets answered.

## Fixed: every documented path had a doubled slash

Building the playground surfaced a real bug in the generator every project copies
(`scripts/apidoc-to-openapi.cjs`): it prepended `/` to the path unconditionally, so
an endpoint documented the normal way —

```php
/** @api {get} /status Status */
```

— became `//status` in the OpenAPI document. A doubled slash is not the same path:
anything that sends it verbatim gets a 404 that reads as a routing bug in the
application. Every apidoc-derived path in every generated document was affected.

The fix adds the slash only when it is missing. The converter also stopped running
on load (`require.main === module`) so its parser can be driven by tests, which it
now is. Refresh the script in an existing project with
`project:resync --scripts`, then regenerate: `npm run docs:build`.

The playground normalises `//` on its own too — it has to work against documents
generated before the fix.

## Documentation

- [Debugging Guide](../../Pramnos_Debugging_Guide.md) — the API tab: where it
  sends, what it sends, and what it refuses to send.
