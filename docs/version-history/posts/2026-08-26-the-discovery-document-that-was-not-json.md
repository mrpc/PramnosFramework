---
title: The discovery document that was not JSON
date: 2026-08-26
categories:
  - Auth
  - Bugfix
---

# The discovery document that was not JSON

`/.well-known/openid-configuration` was 173 KB. A correct one is about four.

<!-- more -->

## What was happening

Every action on `Pramnos\Auth\Controllers\Discovery` ended the same way:

```php
echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
return;
```

`echo` writes to the output stream. Returning from the action does not end the
request — the framework goes on to render the page it was always going to render,
and appends it to what the action already wrote. So the response was the discovery
document, correct and complete, followed by the site's home page: navigation,
footer, inline scripts, the lot.

Six endpoints did this: `openid-configuration`, `jwks.json`,
`oauth-authorization-server`, `.well-known/health`, `/Discovery/serverConfig`, and
the `/config` alias projects put on top of it. Only `/status/check` was correct,
because the health controller returns a `Response` object rather than echoing.

## Why nobody noticed

The JSON is **first**.

Every way a person checks one of these looks perfect. `curl | head`. A browser's
raw view. The first screen of a log. The response opens with a well-formed
document, and the 170 KB of markup is past the bottom of the terminal.

The tests looked right for a sharper version of the same reason: they captured the
output stream around the call, which held exactly the JSON — the page was appended
*after* the action returned, outside the buffer they were reading. They were
asserting the part that worked, using the mechanism that broke it.

Only a client that parses the whole body ever saw it, and what it saw was
"malformed JSON from the identity provider" — which reads as a network problem, or
their own bug, on their side of the wire.

## The fix

Answer with the framework's `raw` document rather than the output stream:

```php
\Pramnos\Framework\Factory::getDocument('raw')->setContent(
    (string) json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);
```

`getDocument()` makes the type it is asked for the default, so this *is* the
document the framework renders. The body is the JSON, and nothing follows it.

The `OPTIONS` preflight gets the same treatment with an empty body: a 204 that
rendered a page was the same bug wearing a different status code.

## Tests

The new tests assert the shape of the whole response rather than the presence of
JSON inside it — parses as JSON, contains no markup, and echoes nothing. The last
one is the mechanism rather than the symptom: while a body is echoed, the framework
is still going to render a page after it, and the next endpoint added here would
have been written by copying the one above.

## Notes

- Client-side workarounds — truncating at the first `}` at column 0, or a regex —
  can be dropped, and should be: the shape they rely on is gone.
- Any project overriding one of these actions and echoing its own body has the same
  problem in the override.

## Documentation

- `Pramnos_AuthServer_Integration_Guide.md` — new "If a discovery response will not
  parse" section under Discovery, with the date, the symptom and what changed.
