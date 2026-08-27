---
date: 2026-08-21
categories: [Changelog]
---

# Realtime, in both directions — and what building it turned up

Twenty-five commits over two days. The framework could **serve** a WebSocket and **publish** to
one; it could not listen to somebody else's, could not tell you who was in a room, could not
produce the token a client needs to join one, and could not be asked to stop. All of that changed,
and the work surfaced a run of faults that were worse than the gaps.

<!-- more -->

## Added — the realtime stack

### Consuming somebody else's socket

`\Pramnos\Http\WebSocketClient` — RFC 6455 transport, and nothing above it.

The case that found the gap: a directory of 2,251 radio stations polling each one's status page,
where two providers would rather push. One pushes over a Reverb socket — **nine track changes for
its entire network in ninety seconds, 350–430 bytes each, one connection**. The alternative is one
HTTP request per station per interval, permanently. The consuming application declined to build it
and said why: 250 lines of handshake, masking and length forms is exactly the code an application
should never contain, and it had already paid for the length forms once.

**The caller keeps its own loop**, which decides the API: shaped like `RedisSubscriberSocket`, not
like a client library, because a worker multiplexing sixty SSE reads, one WebSocket and a `.stop`
sentinel cannot use a client that owns the loop. Only `connect()` blocks. `read()` returns whole
messages. Pings are answered and not surfaced; a close surfaces as a closed socket. Client frames
are always masked. `permessage-deflate` is declined by never offering it. Two read ceilings, per
frame and per reassembled message — the second is the one that gets forgotten, because unlimited
fragments that never set FIN grow one buffer while no single frame looks suspicious.

The protocol above the transport stays out, the same split as `Client` and the APIs called over it.

### Channel authorization

`PusherAuthorizer` could **verify** a channel signature. Nothing could **produce** one, and nothing
said which user may join which channel — so every application wrote its own `/broadcasting/auth`
endpoint and its own HMAC. Production security code, rewritten per project, the same code every
time.

- **`ChannelRegistry`** — patterns without the protocol prefix, placeholders as callback arguments.
  An unmatched channel is **denied**: a missing rule must not be an open channel, or every
  misspelled pattern is a hole that still looks registered. A placeholder matches one segment and
  never a dot, so `order.{id}` does not quietly cover `order.42.items`.
- **`PusherAuthSigner`** — the other half of the verifier, over one string-to-sign definition, which
  is the only way the pair cannot drift. `channel_data` is signed as the exact JSON that is sent and
  never re-encoded.
- **`Broadcasting` controller** — `POST /broadcasting/auth`. 403 is deliberately one answer for four
  causes, so a caller cannot enumerate which channels have rules or which keys are real; a missing
  secret is **500**, because that is the operator's problem and reporting it as "forbidden" sends
  whoever debugs it to the wrong file. A malformed `socket_id` is refused before signing, since it
  is signed verbatim into a colon-delimited string.
- **App registries** — `broadcasting.apps.source` is `auto | config | authserver`. With the
  `authserver` feature on, realtime app keys are AuthServer `applications` rows; without it the old
  config path runs unchanged. Naming `authserver` explicitly while the feature is off **throws**,
  because a silent fallback would authorize channels against a different secret than the operator
  asked for.
- **`AppRegistryAuthorizer`** — resolves the signing secret per connection from the key in the
  token, which is what makes more than one app possible at the edge at all.

Source resolution is a pure function of the config and features arrays rather than a
`FeatureRegistry` lookup, so it cannot depend on which entry point asked.

### Presence, whisper, and not echoing yourself

`presence-` channels authenticated correctly and then dropped the member data:
`subscription_succeeded` carried `'{}'` and no `member_added` was ever sent, so
`here()`/`joining()`/`leaving()` could not have worked however they were written.

**Membership counts people, not sockets**, and that is the whole correctness of it. Three tabs are
one member; a second tab announces no arrival; only the last connection leaving is a departure.
Getting either direction wrong is visible — a room of one shown as three, or members flickering out
of a list.

Client events (`client-*`) relay between browsers, **off by default**. Until they existed a
`client-` frame was silently dropped, so no deployment has ever had a browser-to-browser write path
through this server; enabling it by default would open one on every installation that merely
updated the framework. Private and presence channels only, sender must be subscribed,
per-connection budget, every refusal silent.

`toOthers()` via `BroadcastingManager::except()`, which returns a clone, and
`LocalBroadcastServer::broadcastExcept()` beside `broadcast()`. **No method grew a parameter**: a
trailing optional parameter is source-compatible for callers and fatal for a subclass that
overrides it, and this framework's own test suite overrides `broadcast()` with its exact
three-argument signature. The exclusion travels in the driver envelope, because the publishing
process is not the one that fans out.

`pramnos-echo.js` gains `join()`/`here()`/`joining()`/`leaving()`,
`whisper()`/`listenForWhisper()`, and `socketId()`/`headers()`.

### The control plane

The only route in was the backplane and the only route out was a client's socket.

- **HTTP API** on the WebSocket port, opt-in: `/events`, `/batch_events`, `/channels`,
  `/channels/{name}`, `/channels/{name}/users`, `/metrics`. Pusher's REST signing unchanged, so
  every server SDK already speaks it. A body with no `body_md5` is **refused** — a signature over an
  unbound body authenticates the sender and says nothing about what they sent. A valid key may not
  act on another app's path, or the signature would verify and the *path* would choose the target.
- **Webhooks** — `channel_occupied`, `channel_vacated`, `member_added`, `member_removed`,
  `client_event`. `WebhookDispatcherInterface` exists because the daemon must not make the HTTP
  call: it is a single-threaded select loop, so an outbound request inside it stalls every connected
  client. `QueueWebhookDispatcher` pushes to Redis and returns; the job carries the signed body so
  the worker holds no secret.
- **Metrics** — levels and counters together, because neither is enough alone. `client_events_refused`
  is the one this was built for: refusals are silent on the wire by design, so without the counter a
  client throttled for an hour is indistinguishable from a quiet one.
- **Clustering** — presence membership and client events shared between nodes, in two message kinds
  whose split is the design: deltas for latency, and periodic **full state** for correctness, so no
  individual delta has to be reliable. That is what makes it safe on pub/sub. A node silent for three
  intervals is written off and its members dropped, or a killed node leaves a room full of people who
  are not there.

### The rest

- **`wss://` directly**, via `useTls()`. The handshake is synchronous and the loop single-threaded,
  so this is right for a small deployment and wrong for high connection churn — said plainly,
  because "the framework supports wss://" reads like a recommendation.
- **Encrypted channels** (`private-encrypted-`), decrypted natively by `pusher-js`. The channel
  *name* is never encrypted; only the payload.
- **`BroadcastableEvent`** — an event that knows its own channels, name and payload, so the three
  decisions stop being repeated and drifting at every call site. `QueuedBroadcastableEvent` defers
  one, and what is queued is the resolved payload rather than the object.
- **`FakeDriver`** — because `NullDriver` discards silently and `LogDriver` writes a file a test has
  to parse, so a test asserting "this action broadcasts" was either using a real Redis or asserting
  nothing. A consumer proved the point by measurement: both `now_playing` broadcasts deleted, 3,736
  tests, **not one behavioural failure**.

## Fixed — what building it turned up

Extracting the framing into `WebSocket\FrameCodec` and `MessageAssembler`, shared between the client
and the server, surfaced two silent faults in the server that had been there all along:

- **It never read the FIN bit.** A fragmented text message reached the Pusher handler as separate
  halves, each invalid JSON, from a client that had done nothing wrong.
- **Completing the handshake cleared the read buffer**, discarding any frame a client pipelined into
  the same segment as its request — a loss that depended on how the kernel split two writes.

And elsewhere:

- **`features` from `app.php` were off on the CLI.** `Application::init()` loaded the list and console
  commands do not call it, so every feature read as disabled inside every command. A daemon deciding
  anything from a flag reached the opposite conclusion from the web application reading the same file.
- **`redis-stream` was never registered.** The guide has listed it as a selectable driver for months,
  so `default => 'redis-stream'` threw, was caught by the fallback, and the application ran on the
  **null** driver — every broadcast discarded, with one log line. `broadcast:serve` also hard-coded
  pub/sub, so a deployment wanting SSE replay could not use the shipped command at all. One project
  wrote its own daemon for exactly that reason; its "deliberate divergence" was the only route there
  was.
- **A `{@see}` pointing at a class never written.** `PusherProtocolClient` was described in the
  guide, planned, and never built. `SeeReferencesResolveTest` now asserts every fully-qualified
  reference in `src/` names something real, because a consumer should not be the mechanism that
  discovers otherwise — and one was, at the cost of an afternoon.
- **A relayed typing cue with no age check.** Clustering relayed `client-*` events regardless of age,
  which is the one thing a transient cue cannot survive — and the identical case had already been
  found and filtered on the SSE path by a consuming project.

## Fixed — reported from deployments

Four rounds of consumer reports, and the ones that cost the most were the quiet ones.

- **The WebSocket daemon could not hear being asked to stop.** `DaemonOrchestrator` drops a `.stop`
  file and expects the worker to notice; this loop blocked in `stream_select()` and observed only
  signals, so it was structurally guaranteed to be reported `[stop-timeout]` on every deploy. On one
  installation the consequence was worse: **it served pre-deploy code across deploys, indefinitely.**
  `shouldStopUsing()` closes it.
- **The stop path could disagree with itself, undetectably.** The orchestrator wrote the sentinel
  beside the declared `lockFile` while the worker resolved its own path — two computations of one
  path, and *a sentinel read where nothing writes is indistinguishable from no sentinel*. The
  supervisor now exports the resolved path and the worker prefers it, in a `final` method. Adopting
  the stop seam had landed as a no-op in the project that filed it: the fix for a silent failure
  reproduced it.
- **And then that export broke every spawn.** `nohup setsid VAR=value php …` puts a shell assignment
  where `setsid` expects a program, so every worker with a declared lock died with exit 127 —
  reporting success, because `echo $!` yields a pid whatever happened. Two projects reproduced it
  within the hour. It goes through `env` now.
- **The handshake buffer was the one unbounded read, and the one an unauthenticated peer controls.**
  Everything after the handshake had ceilings; `authorizeConnection()` runs after the headers are
  parsed, which cannot happen until the request is complete. Now 16 KiB before the terminator, 1 MiB
  of declared body, 10 s in the handshaking state. The body is **refused, never truncated**, because
  a truncated body fails `body_md5` and reads as tampering.
- **A policy check that had never once been taken.** `hasContinuousAggregatePolicy()` joined
  `timescaledb_information.jobs` on the materialization hypertable, and its docblock asserted the job
  "cannot be found by the view's name". True when written; false by TimescaleDB 2.26, which reports
  the view's own name. So the repair re-added a policy that already existed on every schedule cycle —
  three stack traces per cycle, 3,350 in one log. It accepts **both** pairings now, which was the
  reporter's instinct before it was measured: 2.19.3 does report the materialization hypertable, so
  swapping would have broken every 2.19-era install in the same silent way.
- **The descriptor ceiling, in three corrections.** `select(2)` is bounded by `FD_SETSIZE`, typically
  1024 — a hard per-node client ceiling the class documented as "~100 concurrent connections without
  tuning", which reads as a soft limit. Then: it bounds descriptor *numbers*, not how many you watch,
  so a warning computed from a socket count fires late by whatever else the process holds. Then: past
  it the call returns `false` **immediately** — the timeout is not applied to the error path — so the
  branch that noticed the failure spun about **1.4 million times a second** doing full housekeeping
  while serving nobody. And `false` shared a branch with `0`, so a node an hour past the cliff looked
  exactly like an idle one.
- **A spooled row reported written, and gone.** `WriteSpool::writeNow()` discarded its insert's return
  value, and `Database::execute()` has one documented path that fails without throwing — a prepare
  failure. So the row was counted as written and the spool file deleted. **Data loss, not a failed
  write**, measured twice on a live stack. The mechanism was documented two files away, in a comment
  naming this exact hazard and this exact class of caller.
- **Five minutes to reach the first error's conclusion.** Constraint violations were retried five
  times before parking, on a code path whose own comment says a deleted foreign key parent "cannot
  become writable by being tried again". Two attempts now — not one, because the spool has no
  dependency ordering and a child can legitimately fail while its parent is still queued. And
  `spool:drain` no longer fails for rows kept inside their budget, which had the scheduler recording a
  failure every minute while the spool worked as designed.

## Two rules this earned

Four tests in this batch had to be rewritten because they could not fail. A pacing assertion that
passed with the pause removed; a `{@see}` guard; a spawn guard asserting a string a shell rejects; a
spool double that reimplemented the contract it was meant to test. Each was written after the
conclusion, so it could only agree.

The consuming projects hit the same fault from the other side — three reports where a real count and
a plausible mechanism lent each other credibility, one of them where a probe's own typo corroborated
a misreading of a truncated log line.

Their formulation is the best one anybody produced: **the artefact was built after the conclusion, so
it could only agree.** From which:

1. **Construct the case where it must fail, and watch it fail, before believing it passes.** For a
   test that is the reversal; verified here by reverting each fix and confirming the guard goes red.
2. **A contradiction is not a finding either, until the probe that produced it has been read as
   carefully as the code it contradicts.**

Having both written down is not the same as applying them first — this changelog was recording the
first rule in the same commit as the next test that could not fail.

## Known limits, stated rather than discovered

- **Channels are process-global per app.** Multi-app resolves credentials per connection; it does not
  partition the channel namespace. Several daemons serving the *same* app are fully supported.
- **Presence under clustering is eventually consistent** — correct within one gossip interval, not
  instantly. `member_added`/`member_removed` webhooks are per-node, and so are
  `channel_occupied`/`channel_vacated`, so a receiver counting those across a cluster counts nodes.
- **A single daemon has a hard ceiling near a thousand concurrent clients**, and it is a cliff rather
  than a slope. Past it, more nodes is the answer; a bigger one does not exist.

## Documentation

`Pramnos_Realtime_Guide.md` now covers both directions and every feature above;
`Pramnos_Testing_Guide.md` gains *Asserting that something was broadcast*; `Pramnos_Console_Guide.md`
covers CLI features and the supervisor-owned lock path. Migration
`2026_08_20_000001_add_broadcast_secret_to_applications` is additive, nullable and idempotent, gated
on the `authserver` feature.
