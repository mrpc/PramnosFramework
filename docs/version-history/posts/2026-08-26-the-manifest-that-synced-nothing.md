---
title: The manifest that synced nothing
date: 2026-08-26
categories:
  - Auth
  - Bugfix
---

# The manifest that synced nothing

An application pushes a capabilities manifest, the server answers
`200 {"status":"synced"}`, and stores nothing. The CI job goes green.

<!-- more -->

## What was happening

The integration guide publishes the manifest as a map keyed by name, which is the
natural JSON for it and what a client sends:

```json
{
  "resources": {
    "invoices": {
      "description": "Customer invoices",
      "scopes": { "read": "View invoices", "write": "Edit invoices" }
    }
  },
  "conditions": {
    "location_id": { "value_type": "int[]", "description": "Restrict to locations" }
  }
}
```

`CapabilitiesSyncService` normalised each section with `array_values()`. That
throws the keys away — so `resources` became one entry with a `description` and no
`name`, the loop's `if ($name === '') continue;` skipped it, and the response was:

```json
{"status":"synced","resources":0,"scopes":0,"conditions":0,"deactivated":0}
```

Which is a success, with a zero next to it that nobody reads.

**Scopes were worse than skipped.** `{"read": "View invoices"}` has no array to
lose a key from; `array_values()` left the bare string `"View invoices"`, and the
scope writer takes a string as the scope *name*. So a server that accepted the
manifest stored a scope called "View invoices" — and an application asking for
`read` matched nothing. A permission system keyed on prose.

## The second half: Basic auth was refused

Found while testing the first. RFC 6749 §2.3.1 lets a client authenticate with
HTTP Basic, which is what a CI pipeline does. Apache running as a module decodes
that header into `PHP_AUTH_USER` / `PHP_AUTH_PW` and does **not** pass the raw
`Authorization` header through — and the usual `E=HTTP_AUTHORIZATION` rewrite
cannot help, because there is nothing left to copy.

`extractClientCredentials()` read only the raw header. So a correctly
authenticated client was told:

```json
{"error":"invalid_client","error_description":"Client credentials required"}
```

"Client credentials required" when they were supplied. It reads as a wrong secret,
so the next thing anybody does is re-check the secret, and that never helps.

It now falls back to `PHP_AUTH_USER` / `PHP_AUTH_PW`, after the raw header so an
explicit one still wins. This affects **every** client-credentials endpoint, not
only the manifest one.

And the scaffolded `www/api/.htaccess` gets the Authorization passthrough of its
own: rewriting is per-directory, so the web root's copy of that rule does not
carry into a request rewritten under `/api/`.

## And nothing could read it back

The write side existed on its own. An application could push its resources, scopes
and condition keys, and no screen anywhere showed an operator what had arrived —
which makes "central permission control" a place where data goes in. A grant names
a resource, so the question "which names does this client publish" is the one this
has to answer, and answering it meant querying four tables by hand.

`CapabilitiesSyncService::describe()` reads it back, and the client's own page
renders it: resources, their scopes, the condition keys, the manifest hash and when
it last arrived. Deactivated rows are listed struck through rather than hidden —
a grant may still refer to one, and that is precisely what somebody is looking for
when a permission has quietly stopped working.

## What to check

If you have a pipeline pushing manifests, **read the counts in the response.** A
green job proves the request was accepted, not that anything was stored. A server
that has been syncing zero has no resources and no scopes recorded for that
client, and any permission grant referring to them was made against rows that were
never written.

## Documentation

- `Pramnos_AuthServer_Integration_Guide.md` — the accepted shapes, the two
  authentication forms, the response counts, and a dated note on what used to
  happen.
