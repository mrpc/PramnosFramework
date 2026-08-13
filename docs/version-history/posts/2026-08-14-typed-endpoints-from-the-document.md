---
date: 2026-08-14
categories:
  - Changelog
  - Added
tags:
  - console
  - spa
  - openapi
---

# Typed endpoints, generated from the document

The last item of a consumer's review, and the one they expected to reduce the most
bugs: screens hand-write path strings and field names while the OpenAPI document in the
same repository knows both. A rename in the backend was therefore found in the browser,
one screen at a time.

<!-- more -->

```bash
php pramnos create:api-client     # → lib/endpoints.js + lib/endpoints.d.ts
```

One function per documented operation:

```js
import { listThings, readThing, createThing } from './lib/endpoints.js';

const page  = await listThings({ page: 2, search: 'ada' });
const thing = await readThing(42);
await createThing({ label: 'new' });
```

Path parameters become arguments and are `encodeURIComponent`-ed into the URL. Query
parameters arrive as one optional object, and **blank values are omitted** — `?status=`
and "no status filter" are different requests. A `204` is typed as returning `null`,
which is what the client returns for one.

## What it deliberately is not

**It is not a replacement for `lib/api.js`.** That file holds the `apiKey` header, the
bearer token, the session cookie, the `ApiError`, the two-factor flow and the debug-panel
recording — none of which a document describes. The generated functions delegate the
call, so there is one transport and one place to change it.

**It is not TypeScript.** A scaffolded project is plain JavaScript: Vite, Vitest,
`type: module`. Emitting TypeScript would buy the same editor checking at the cost of a
compiler in every project, so the types are declarations (`.d.ts`) that editors read and
the runtime ignores.

**It is not maintained by hand.** Both files are regenerated, and say so at the top.
Staying in step with the backend means being rewritten from the document — the opposite
of `scaffold:spa`, which never overwrites, for the opposite reason: that command adds
*your* files, this one owns its own.

**It does not guess.** Objects, arrays, primitives, enums and `$ref`s into
`components.schemas` are expanded; `oneOf`, `allOf` and a schema with no type become
`any`. A generated type that is confidently wrong is worse than one that admits it does
not know, because the first is trusted.

## Two things found by running it for real

Generating against a fixture proves less than generating against a document somebody's
API actually produced. Two defects came out of doing the second:

- **A POST with no documented request body** emitted `api.post(path, body)` while the
  signature took nothing. That is valid JavaScript, so `node --check` passed — and a
  `ReferenceError` the first time anybody called it. Every POST in the fixture happened
  to have a body.
- **An `operationId` that was already camelCase** came out lowercased: `listThings`
  became `listthings`, a name the API's author did not choose.

Both are covered by tests now, and the generated module is parsed by node in one of
them — because nothing else notices a syntax error before a build does.

## Documentation

- [Application Styles Guide](../../Pramnos_Application_Styles_Guide.md) — "Typed
  endpoints from the OpenAPI document".
