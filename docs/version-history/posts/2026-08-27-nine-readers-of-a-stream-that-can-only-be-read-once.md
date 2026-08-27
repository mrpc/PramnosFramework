---
date: 2026-08-27
categories: [Changelog]
---

# Nine readers of a stream that can only be read once

`php://input` is a stream. Nine places in the framework opened it independently, and
whichever of them ran second saw a request with no body.

<!-- more -->

## Fixed

**`Pramnos\Http\Request::rawBody()` is now the one place the raw request body is read**,
and it reads once and keeps the result.

The nine readers were `Auth\Controllers\Capabilities`, `Passkey`, `Account`, `ApiAccount`,
`Gdpr` and `Oauth`, plus `User\Token`, `Application\Api` and `Webhook\WebhookHandler`. Each
called `file_get_contents('php://input')` for itself. That works when it happens once. It
does not survive a second reader:

- for `multipart/form-data`, PHP has already consumed the stream — the read returns an
  empty string under every SAPI;
- with `enable_post_data_reading` off, the first read drains it and every later one is
  empty;
- `Oauth` was worse than the rest: it handed League an *open handle* to the stream
  (`createStreamFromResource(fopen('php://input', 'r'))`), so anything that had read the
  body first left the token endpoint with a body positioned at EOF.

The failure shape is the same in each case, and it is the misleading kind — the request
arrives complete and the handler reports the payload as missing. A capabilities manifest
refused as `Malformed or missing JSON manifest`. A token request refused as
`invalid_request` with `client_id`, `grant_type` and `client_secret` all present. Nothing in
either response points at the body having been read somewhere else first.

`Request::rawBody()` also returns a `string`, never `false`. A `false` from
`file_get_contents()` fails an `if ($raw === '')` test for "no payload" and goes on to
`json_decode()`, which turns what should be a 400 into a 500.

The cache is per request: `Request::resetInstance()` clears it, so a worker serving several
requests in one process does not answer with the previous one's body.

## The second half: those paths were untestable

`Request::setRawInput()` has always been the documented way to supply a body in a test. A
handler reading `php://input` for itself cannot see it — so the body-reading branch of all
nine could not be exercised by a test at all, which is why the bug above lasted. Routing
them through `rawBody()` makes `setRawInput()` reach them, and the branch testable:

```php
Request::setRawInput('{"resources":[{"name":"invoices"}]}');
// the capabilities endpoint can now be called end to end in a test
```

`ApiAccount::rawBody()` lost its `@codeCoverageIgnore` for the same reason: it is now
reachable.

## Documentation

- `Pramnos_Framework_Guide.md` — "The raw body, when you need the bytes", under *Reading the
  request body*.
