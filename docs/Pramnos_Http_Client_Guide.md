---
use_cases:
  - Calling a third-party REST API from a controller, model or worker
  - Checking whether a streaming or long-lived endpoint is alive
  - Reading only part of a large or endless HTTP response
  - Writing tests for code that makes outbound HTTP calls
  - Diagnosing a request that times out or exhausts memory
---

# Pramnos HTTP Client Guide

`Pramnos\Http\Client` is a fluent, zero-dependency HTTP client built on
`ext-curl`. It covers one-off calls, shared-configuration instances, retries
with exponential backoff, bounded reads of endless responses, and a fake system
so tests never touch the network.

| Class | What it is |
|---|---|
| `Pramnos\Http\Client` | The fluent builder and sender |
| `Pramnos\Http\ClientResponse` | Immutable response value object |
| `Pramnos\Http\ClientException` | Transport failure — *not* 4xx/5xx |

For an inbound WebSocket or SSE connection, see the
[Realtime Guide](Pramnos_Realtime_Guide.md); for an outbound WebSocket,
`Pramnos\Http\WebSocketClient`.

---

## Making a request

### One-off

```php
use Pramnos\Http\Client;

$response = Client::get('https://api.example.com/users')
    ->bearerToken($token)
    ->timeout(10)
    ->send();

if ($response->ok()) {
    $users = $response->json();
}
```

`get()`, `post()`, `put()`, `patch()`, `delete()` and `head()` all exist as
static factories.

### Shared configuration

When several calls share a base URL, credentials or default headers, build one
client and reuse it. Each `make()` returns a fresh request that inherits the
configuration but carries its own body.

```php
$api = (new Client('https://api.example.com'))->bearerToken($token);

$users  = $api->make('GET',  '/users')->send()->json();
$orders = $api->make('POST', '/orders')->json(['status' => 'open'])->send()->json();
```

An absolute URL passed to `make()` overrides the base URL.

### Bodies

```php
// JSON — sets Content-Type: application/json
Client::post($url)->json(['name' => 'Alice'])->send();

// URL-encoded form
Client::post($url)->form(['username' => 'alice', 'password' => 'secret'])->send();

// Anything else
Client::put($url)->body($xml, 'application/xml')->send();
```

### Headers and authentication

```php
Client::get($url)
    ->header('X-Request-Id', $id)
    ->headers(['Accept' => 'application/json', 'X-Trace' => $trace])
    ->bearerToken($token)          // Authorization: Bearer …
    ->basicAuth($user, $password)  // Authorization: Basic …
    ->userAgent('MyApp/2.0')
    ->send();
```

---

## Reading only part of the response

By default `send()` reads the response to completion. Against an endpoint that
never stops sending — an Icecast or Shoutcast mount, an SSE feed, a `tail -f`
over HTTP — "to completion" and "until the timeout" are the same thing, and
neither is useful. Two options say how much you actually want.

### `headersOnly()` — stop at the headers

```php
$response = Client::get($streamUrl)
    ->connectTimeout(2)->timeout(3)
    ->headersOnly()
    ->send();

$response->status();               // 200
$response->header('content-type'); // 'audio/mpeg'
$response->body();                 // '' — never read
$response->truncated();            // true
```

Redirects are still followed; "the headers" means the headers of the response
that ends the chain.

> **This is not `head()`.** `Client::head()` sends a *different request*, and
> a great many servers answer HEAD with 404 or 405 on a path they serve happily
> over GET — measured at 17 of 30 on one catalogue of streaming endpoints, so a
> prober built on HEAD reports live services as dead. `headersOnly()` sends the
> GET the server expects and stops listening once the headers arrive.

### `maxResponseBytes()` — read a bounded prefix

```php
// The first 16 kB of an endless stream: enough for the ICY metadata block.
$response = Client::get($url)
    ->header('Icy-MetaData', '1')
    ->maxResponseBytes(16 * 1024)
    ->send();

$response->truncated();       // true — there was more, we did not want it
strlen($response->body());    // 16384
```

Reading a prefix is its own use, not a way of approximating `headersOnly()`: a
caller that needs the headers *and* the first N bytes has no other way to say
so. If both are set, `headersOnly()` wins and no body is read.

### `truncated()` is a normal outcome, not an error

Reaching the ceiling does **not** throw. The response arrives with a complete
status and complete headers, `body()` holds exactly the bytes that were read,
and `truncated()` says whether anything is missing:

```php
$response = Client::get($url)->maxResponseBytes(1_000_000)->send();

if ($response->truncated()) {
    // The body is a prefix. Do not hand it to json().
}
```

`truncated()` answers *"is something missing"*, not *"was a limit set"* — a body
that fits under the ceiling, or a 204 with no body at all, comes back with
`truncated()` false.

### There is no default ceiling

Deliberately. A default would silently truncate every existing caller that
legitimately downloads something large, and a response that quietly loses its
tail is worse than one that fails loudly. Set a ceiling where you know what the
body should be — and do set one when you are calling something you do not
control, because without it a server that answers with a gigabyte will exhaust
`memory_limit` and take the worker down.

> **Added 2026-08-23.** Before this, a live streaming endpoint was reported
> unreachable: the server answered `200 audio/mpeg` in milliseconds and all of
> it was discarded, because the only way out of `send()` was a complete body or
> an exception. A faster endpoint did not even reach the timeout — three seconds
> of a fast stream measured a quarter of a gigabyte in `memory_limit`. Consuming
> applications dropped to raw cURL for both; they no longer need to.

---

## Timeouts and retries

```php
Client::get($url)
    ->connectTimeout(2)   // seconds to establish the TCP connection (default 10)
    ->timeout(15)         // seconds for the whole request (default 30)
    ->retry(3, 200)       // up to 3 further attempts, first delay 200 ms
    ->send();
```

Retries fire on **connection errors** and **5xx** responses. A **4xx is never
retried** — it describes the request, and sending it again cannot change the
answer. The delay grows exponentially: `delayMs × 2^(attempt−1)`, so
200 ms → 400 ms → 800 ms.

---

## Reading the response

```php
$response->status();          // int
$response->ok();              // 2xx  (successful() is an alias)
$response->failed();          // 4xx or 5xx
$response->clientError();     // 4xx
$response->serverError();     // 5xx
$response->redirect();        // 3xx

$response->body();            // raw string
$response->truncated();       // did we stop reading early?
$response->json();            // decoded JSON, or null if undecodable
$response->json('user.email'); // dot-notation pluck

$response->header('content-type'); // case-insensitive; '' when absent
$response->headers();              // all headers, lowercase-keyed
```

Response headers are always lowercase-keyed, whatever case the server used. When
a request is redirected, `headers()` holds the **final** response's headers
only — the hops' headers do not accumulate into it.

### Failure

`ClientException` is thrown for transport failures — connection refused, DNS
failure, timeout, SSL error — and never for a 4xx or 5xx, which are answers.

```php
try {
    $response = Client::get($url)->send();
} catch (\Pramnos\Http\ClientException $e) {
    $e->getCurlErrno();  // libcurl CURLE_* value, or 0
    $e->getMessage();
}
```

To treat a failing status as an exception too:

```php
// Both of these throw on 4xx/5xx:
Client::get($url)->throwOnError()->send();
Client::get($url)->send()->throw();
```

---

## Testing without the network

Register fake responses before exercising the code under test, and clear them
afterwards.

```php
use Pramnos\Http\Client;
use Pramnos\Http\ClientResponse;

Client::fake([
    'https://api.example.com/users'  => ClientResponse::make(['id' => 1], 200),
    'https://api.example.com/errors' => ClientResponse::make('Internal error', 500),
    'https://api.example.com/*'      => ClientResponse::make(['error' => 'not found'], 404),
]);

// ... run the code under test ...

Client::resetFakes();   // always, in tearDown()
```

Patterns are matched with `fnmatch()`, so `*` is a wildcard and `'*'` alone
matches everything. Patterns are tried in the order they were registered, so put
the specific ones first.

A callable fake is invoked once per attempt, which is what lets you simulate a
transient failure and assert that the retry policy recovers from it:

```php
$attempt = 0;
Client::fake([
    'https://api.example.com/flaky' => function (Client $req) use (&$attempt): ClientResponse {
        $attempt++;
        return $attempt < 3
            ? ClientResponse::make('error', 503)
            : ClientResponse::make(['ok' => true], 200);
    },
]);
```

`ClientResponse::make()` builds a response by hand — an array body is
JSON-encoded and given `content-type: application/json` automatically:

```php
ClientResponse::make('Hello world', 200);
ClientResponse::make(['id' => 1], 200);
ClientResponse::make('', 204, ['x-request-id' => 'abc123']);
```

**Fakes bypass the network entirely**, so `headersOnly()` and
`maxResponseBytes()` have no effect on one — a faked body arrives whole and
`truncated()` is false. To test what a real ceiling does, test against a real
server; the framework's own suite forks one
(`tests/Integration/Http/ClientBodyCeilingTest.php`).

---

## SSL

Certificate verification is on. `withoutSslVerification()` turns it off and is
for development only — it makes the connection trivially interceptable.

---

## API summary

| Method | Description |
|---|---|
| `Client::get\|post\|put\|patch\|delete\|head(string $url): static` | One-off request |
| `(new Client($baseUrl))->make(string $method, string $path): static` | Request sharing this client's configuration |
| `->header(string $name, string $value): static` | Set one request header |
| `->headers(array $headers): static` | Merge several |
| `->bearerToken(string $token): static` | `Authorization: Bearer` |
| `->basicAuth(string $user, string $pass): static` | `Authorization: Basic` |
| `->json(array\|object $data): static` | JSON body + content type |
| `->form(array $data): static` | URL-encoded form body |
| `->body(string $body, string $contentType): static` | Raw body |
| `->timeout(int $seconds): static` | Whole-request timeout (default 30) |
| `->connectTimeout(int $seconds): static` | TCP connect timeout (default 10) |
| `->retry(int $times, int $delayMs = 100): static` | Retry on transport error / 5xx |
| `->headersOnly(): static` | Stop reading once the final headers arrive |
| `->maxResponseBytes(int $bytes): static` | Keep at most this much body |
| `->withoutSslVerification(): static` | Disable certificate checks (dev only) |
| `->userAgent(string $agent): static` | Override the User-Agent |
| `->throwOnError(): static` | Throw on 4xx/5xx instead of returning |
| `->send(): ClientResponse` | Execute |
| `Client::fake(array $responses): void` | Register test fakes |
| `Client::resetFakes(): void` | Clear them |
