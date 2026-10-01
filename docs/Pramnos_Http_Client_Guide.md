---
use_cases:
  - Calling a third-party REST API from a controller, model or worker
  - Polling many endpoints at once instead of one at a time
  - Checking whether a streaming or long-lived endpoint is alive
  - Reading only part of a large or endless HTTP response
  - Fetching a page or feed that comes back compressed
  - Writing tests for code that makes outbound HTTP calls
  - Uploading a file with a message to a chat platform or media API
  - Fetching a URL that a user supplied, without opening an SSRF hole
  - Diagnosing a request that times out or exhausts memory
  - Downloading a large file, or a .gz one, straight to disk
  - Measuring outbound bandwidth or per-request timing
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

// Fields and files together — multipart/form-data
Client::post($webhook)->multipart([
    ['name' => 'payload_json', 'contents' => $json, 'type' => 'application/json'],
    ['name' => 'files[0]', 'contents' => $bytes, 'filename' => 'cover.jpg', 'type' => 'image/jpeg'],
])->send();

// Anything else
Client::put($url)->body($xml, 'application/xml')->send();
```

### Files with a message: `multipart()`

Each part is `name` and `contents`, plus `filename` for a file and `type` for the part's
own `Content-Type`. A part with a `filename` is a file; one without is a field. Contents
are bytes, so read a file with `file_get_contents()` first.

The body is built when `multipart()` is called, as a string. A retry therefore resends the
same bytes, and a fake receives them like any other body. The boundary is random and is
checked against the contents.

**A filename is often the user's**, so a line break in `name`, `filename` or `type` throws
`InvalidArgumentException`; it would otherwise be a header the caller chose. A double quote
is sent as `%22`, which is what browsers do.

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

## Several requests at once: `Client::pool()`

One request at a time is fine until you have a catalogue. Polling 200 status
endpoints at ~1.1 s each takes 218 seconds — and almost all of that second is
spent waiting on somebody else's server, which is exactly the wait that
overlaps.

```php
$responses = Client::pool([
    'aroma'  => 'https://one.example/status-json.xsl',
    'kosmos' => Client::get('https://two.example/stats?json=1')
        ->connectTimeout(2)->timeout(3)->maxResponseBytes(64 * 1024),
], concurrency: 8);

foreach ($responses as $station => $response) {
    if ($response instanceof \Pramnos\Http\ClientException) {
        $this->markUnreachable($station, $response->getMessage());
        continue;
    }
    $this->record($station, $response->json());
}
```

**Keyed in, keyed out.** The result array carries the keys you supplied, so you
never re-derive which answer belongs to whom. It is keyed, not ordered by
completion — read it by key.

**A failure is a value.** Any number of endpoints are down at any moment, and
one dead host must not abandon the other seven. An entry that fails at the
transport level gets a `ClientException` **in the array**; the pool itself never
raises. Check the type before using a result — that is the one thing a pool
caller must do that a `send()` caller does not.

**Per-request options** come from passing a configured `Client` instead of a
string. Anything the fluent builder can express works: headers, bodies,
timeouts, the body ceiling. A plain string is shorthand for a GET with the
defaults. `concurrency` is the only setting that belongs to the batch — it is
the number of requests in flight at once, and everything beyond it is started as
slots free up.

**`retry()` is honoured**, in rounds: entries that failed and have attempts left
are re-sent together after the longest backoff that round calls for. Entries
retry independently, so a neighbour's failure never costs a re-send.

**`throwOnError()` on an entry** becomes an exception *value* under that key,
not an exception out of `pool()`. The batch always completes.

**Fakes work.** A key whose URL matches a `fake()` pattern is answered from the
fake and never reaches the network, so a test of a batching caller does not
quietly become a live network test. Faked and live entries can mix in one batch.

Pooled requests go through the same handle configuration as a single `send()` —
the same TLS defaults, redirect handling and header normalisation. There is one
HTTP client here, not two.

---

## What a request cost

Every response carries what the transfer actually cost, taken from the handle
that made it:

```php
$response->transferredBytes();   // int|null — bytes over the wire
$response->elapsedMs();          // float|null — how long it took
```

**`transferredBytes()` is not `strlen(body())`.** It counts the response headers
as well as the body, so a `headersOnly()` probe reports a real figure rather than
zero; and it is the wire size, so a `maxResponseBytes()` ceiling or a compressed
response do not make it agree with the body length. A caller measuring bandwidth
wants the wire figure.

**Both are populated on failure.** A 404 with a page of HTML behind it is
bandwidth that was paid for, and a 500 with a stack trace is bandwidth *and* a
wrong address — a statistic only present on success would miss exactly the
requests worth finding.

**`null` means nobody measured, not zero.** A faked response and one built with
`ClientResponse::make()` have no transfer to report, and returning `0` for them
would quietly deflate any total they were added to.

**In a pool, each entry reports its own figures**, not a share of the batch's:

```php
$responses = Client::pool($urls, concurrency: 8);

foreach ($responses as $key => $response) {
    if ($response instanceof \Pramnos\Http\ClientException) {
        $ledger->failure($key);
        continue;
    }
    $ledger->record($key, $response->transferredBytes(), $response->elapsedMs());
}
```

That is what the accessors are for. Together they answer whether an outbound cost
is **payload** or **waiting**, and those have different fixes — ask for less,
against ask less often.

> **Added 2026-08-24.** curl measured both already and the client discarded them.
> A consuming application keeping an outbound-traffic ledger therefore kept one
> service on a hand-rolled curl handle purely to read `curl_getinfo()`, and its
> pooled poller had to redefine its `millis` column as "share of the batch's
> elapsed time" because there was nothing else to divide.

---

## Compressed answers

Every request advertises the encodings this cURL was built with, and the body is **decoded
before you see it**. There is nothing to turn on and nothing to check.

Worth knowing because the alternative fails in a way that points at the wrong end. cURL only
inflates when it was told to advertise; without that it sends no `Accept-Encoding` **and
does not inflate** — and servers compress anyway. A feed that answered
`Content-Encoding: gzip` to a request that asked for nothing was handed to a parser as
compressed bytes, which recorded "the answer was not a feed we could read". True, and about
the parser. Nothing logs a decoding failure, because no decoding was attempted. CDNs and
caching plugins make it common enough to meet in any crawler.

**`maxResponseBytes()` counts the decoded size**, not the bytes off the wire — cURL inflates
before the ceiling sees anything. That is the size that matters for memory, and it is what
keeps a few compressed kilobytes that expand into gigabytes from being held whole.

## Downloading to a file: `sink()`

A download that is only going to be written to disk does not need to be held in memory
first — and a 60 MB `.gz` file that has to be inflated would be held twice. `sink()` writes
the body to a file as it arrives:

```php
$response = Client::get('https://download.example/city-lite.mmdb.gz')
    ->timeout(600)
    ->sink($target . '.part')
    ->decodeGzip()          // a .gz file, inflated on the way in
    ->send();

if ($response->successful() && self::looksLikeADatabase($target . '.part')) {
    rename($target . '.part', $target);
}
```

- **Only a 2xx body goes to the file.** A 404 page or a 500's HTML is kept as `body()`, so the
  file is never an error page that looks like the download; for a 2xx `body()` is empty.
- The file is written from the start on every attempt, so a retry does not append, and a
  successful answer with no body leaves it empty rather than stale.
- `decodeGzip()` is for a *file* that is gzip — `application/gzip`, a `.gz` name. A response
  sent with `Content-Encoding: gzip` is decoded anyway (see above). A body that turns out not to
  be gzip raises `ClientException`, and so does a file that cannot be opened or written.
- `maxResponseBytes()` still applies, to what is written after inflating; the response is
  `truncated()` when it cut the file short.
- Writing to a temporary name and renaming once the content is checked is yours to do, as
  above: only the caller knows what "checked" means.
- A faked response (`Client::fake()`) is written to the file the same way, inflated if asked,
  so a download command can be tested without the network.

## Redirects

Up to five are followed by default. `maxRedirects()` changes that and
`withoutRedirects()` hands the `30x` straight back:

```php
$response = Client::get($url)->withoutRedirects()->send();

if ($response->redirect()) {
    $where = $response->header('Location');
}
```

## Fetching a URL somebody else typed

`forUserSuppliedUrl()` is the guard for "verify your site", "import from a URL", a webhook
tester, an OG-preview fetcher, an RSS reader — anything where the address comes from
outside.

```php
$response = Client::get($url)
    ->forUserSuppliedUrl()     // https only, public addresses only, checked per hop
    ->maxResponseBytes(256 * 1024)
    ->timeout(10)
    ->send();
```

Without it, fetching a user-supplied URL is **server-side request forgery**, and the cost
of getting it wrong is cloud metadata credentials rather than a broken page.

**Why a pre-flight DNS check of your own is not enough.** The check happens before the
request; a perfectly public host that answers `302 http://169.254.169.254/` is fetched on
the *second* request, which nothing examined. Redirects are the hole, and they cannot be
closed from outside the client.

With the guard on:

| | |
|---|---|
| **Scheme** | `https` only. `forUserSuppliedUrl(true)` also allows `http`, for sites that have not got a certificate yet. |
| **Address** | Every address the host resolves to must be public. Loopback, private ranges, link-local (**including `169.254.169.254`**), carrier-grade NAT, the IETF and benchmarking blocks, multicast and reserved space are all refused — and one non-public address among several refuses the whole host, because "whichever record the fetch happened to use" is not a security property. |
| **Rebinding** | The resolved address is pinned for the connection, so the name cannot answer differently between the check and the fetch. |
| **Redirects** | Followed by the client, one checked hop at a time, bounded by `maxRedirects()`. A chain that exceeds it returns the `30x` rather than raising. |

A refusal is a `ClientException` naming the address, not a `false` — a URL rejected for
being internal must not be indistinguishable from one that was merely down, because the
caller is usually showing one of the two to a user.

**What it does not do**, stated rather than implied: it cannot see a public host proxying
into its own private network, and it does not limit the response. Pair it with
`maxResponseBytes()` and `timeout()`, as above.

### Letting it reach your own network

Some addresses somebody else chose legitimately live on the operator's network — a partner
service reached over the VPN, a receiver on the same LAN. `allowAddresses()` names the
ranges the guard lets through although they are not public:

```php
use Pramnos\Security\OutboundUrl;

Client::post($url)
    ->forUserSuppliedUrl()
    ->allowAddresses(OutboundUrl::PRIVATE_NETWORK_RANGES)   // or ['10.8.0.0/24']
    ->send();
```

Everything else about the guard still holds — the scheme, the check on every hop, the
pinned address — and one address outside the ranges still refuses the whole host.

There are two lists and they answer different questions. `OutboundUrl::NEVER_PUBLIC_RANGES`
is what PHP's address filters miss — carrier-grade NAT, the IETF protocol block, the
benchmarking block, multicast — and it makes `isPublicAddress()` mean what it says.
`OutboundUrl::PRIVATE_NETWORK_RANGES` is RFC 1918, carrier-grade NAT and IPv6 unique-local:
an organisation's network. It deliberately leaves out loopback and link-local, where this
machine's own ports and the cloud metadata address answer; a caller that really needs one
names it (`'127.0.0.1/32'`). The ranges must come from the operator's configuration, never
from the request — a list the requester supplies allows whatever the requester wants.
`OutboundUrl::inRanges($address, $ranges)` is the matcher, for IPv4 and IPv6; a range that
does not parse matches nothing.

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

A multipart request's parts are readable without parsing the body:

```php
Client::fake([
    'https://discord.com/api/*' => function (Client $req) use (&$parts): ClientResponse {
        $parts = $req->multipartParts();   // the list given to multipart(), [] for any other body
        return ClientResponse::make(['id' => '1'], 200);
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
| `->multipart(array $parts): static` | `multipart/form-data` body of fields and files |
| `->multipartParts(): array` | The parts given to `multipart()`, for a fake |
| `->body(string $body, string $contentType): static` | Raw body |
| `->timeout(int $seconds): static` | Whole-request timeout (default 30) |
| `->connectTimeout(int $seconds): static` | TCP connect timeout (default 10) |
| `->retry(int $times, int $delayMs = 100): static` | Retry on transport error / 5xx |
| `->headersOnly(): static` | Stop reading once the final headers arrive |
| `->maxResponseBytes(int $bytes): static` | Keep at most this much body |
| `->sink(string $path): static` | Write a 2xx body to this file instead of memory |
| `->decodeGzip(bool $decode = true): static` | Inflate a gzip file on its way into the sink |
| `->withoutSslVerification(): static` | Disable certificate checks (dev only) |
| `->userAgent(string $agent): static` | Override the User-Agent |
| `->throwOnError(): static` | Throw on 4xx/5xx instead of returning |
| `->send(): ClientResponse` | Execute |
| `$response->transferredBytes(): ?int` | Bytes over the wire, headers included |
| `$response->elapsedMs(): ?float` | How long the request took |
| `Client::pool(array $requests, int $concurrency = 8): array` | Send many at once; returns `ClientResponse\|ClientException` per key |
| `Client::fake(array $responses): void` | Register test fakes |
| `Client::resetFakes(): void` | Clear them |
