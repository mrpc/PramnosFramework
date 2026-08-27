---
date: 2026-08-27
categories: [Changelog]
---

# An API endpoint's status code was untestable

The body of an API response has always been assertable. The status was not, and the status
is the half a client acts on.

<!-- more -->

## Added

**`Pramnos\Application\Api::$lastStatusCode`** — the HTTP status the last dispatched request
answered with, set for every dispatch whatever the SAPI.

Under CLI the kernel deliberately does not emit the status: `http_response_code()` has
nowhere to put it, and calling it there is noise. So a test dispatching a request through
`Api` could read the body and nothing else — and 400 "you sent no credentials", 401 "they
were wrong" and 405 "wrong verb" are three different instructions to a client that can all
carry a body of the same shape. A test asserting only on the body cannot tell them apart,
which means an endpoint whose status changed silently kept passing.

```php
$api = new \Pramnos\Application\Api();
$api->init();
$api->exec();

$status = $api->lastStatusCode;   // null before the first dispatch
```

Set for both response kinds — a `Response` object's own status, and the status inside the
legacy array/string envelope. A middleware short-circuit (missing or invalid API key) never
reaches the dispatch and puts its status in the body instead, which is the documented
fallback.

## Documentation

- `Pramnos_API_Guide.md` — "Testing an endpoint's status code".
