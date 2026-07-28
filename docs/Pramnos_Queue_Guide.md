# Pramnos Queue Guide

The framework ships **two sibling queue capabilities**, each fit to a different
shape of background work. Both live under `Pramnos\Queue`.

| Capability | Class | Semantics | Backend |
|---|---|---|---|
| **Durable work queue** | `QueueManager` + `Worker` + `QueueItem` | Auditable row per task with a `pending → processing → completed / failed / warning` lifecycle, priorities, retries, locking, admin datatable | Database (`queueitems`) |
| **Delayed queue** | `DelayedQueue` + `QueueDriverInterface` | Low-latency "run this later, don't lose it" dispatcher; claim-and-remove; linear-backoff retry | Pluggable driver — **Redis** (shipped); further drivers implement `QueueDriverInterface` |

They answer different needs. Reach for the **durable work queue** when you want a
persistent, inspectable record of every task and a rich status lifecycle (imports,
report generation, admin-visible jobs). Reach for the **delayed queue** when you
want a fast, ephemeral "do this in N seconds" primitive where the job's existence
*is* the state (bot replies, deferred deliveries, debounced work).

This guide documents the **delayed queue** capability; for the durable work queue
see `QueueManager` / `Worker` (feature entry #28 in
[1.2-new-features](1.2-new-features.md)).

---

## The delayed-queue capability

A delayed queue is a driver behind a capability — an application depends on
`DelayedQueue`, never on Redis directly. Redis is the shipped driver; further
drivers (e.g. a database-backed one) implement `QueueDriverInterface` and drop in
without touching application code.

### Contract

```php
namespace Pramnos\Queue\Contracts;

interface QueueDriverInterface
{
    public function name(): string;
    public function push(string $type, array $payload, int $delaySeconds = 0, int $attempts = 0): string;
    /** @return list<\Pramnos\Queue\ReservedJob> */
    public function claimDue(int $limit = 20): array;
    public function size(): int;
    public function secondsUntilNext(): ?int;
    public function flush(): int;
}
```

**Semantics: claim-and-remove.** `claimDue()` atomically removes each returned job
from the backend and hands it to the caller, so two competing workers never
process the same job. There is no in-place "processing" state to reconcile;
re-scheduling a failed job is a *fresh* `push()` with an incremented attempt
count (see `retry()` below), never a mutation of the claimed job.

A claimed job is delivered as an immutable `Pramnos\Queue\ReservedJob`:

```php
final class ReservedJob
{
    public readonly string $id;
    public readonly string $type;
    public readonly array  $payload;
    public readonly int    $attempts;
    public readonly int    $runAt;

    /** @return array{id:string,type:string,payload:array,attempts:int,run_at:int} */
    public function toArray(): array;
}
```

### The `DelayedQueue` facade

`DelayedQueue` is the application-facing accessor. Unlike `QueueManager` it is
**not** controller-coupled — it needs only its driver, so it can be constructed
in a bot, a CLI worker, or any service without an application `Controller`.

```php
use Pramnos\Queue\DelayedQueue;
use Pramnos\Queue\Drivers\RedisQueueDriver;

$queue = new DelayedQueue(new RedisQueueDriver([
    'host'      => '127.0.0.1',
    'port'      => 6379,
    'prefix'    => 'myapp_',   // applied verbatim in front of the namespace
    'namespace' => 'jobs',     // the logical queue name
]));

// Schedule work
$jobId = $queue->push('send_reply', ['to' => 42, 'text' => 'hi'], delaySeconds: 5);

// A worker loop
foreach ($queue->claimDue(limit: 20) as $job) {
    try {
        handle($job->type, $job->payload);
    } catch (\Throwable $e) {
        // Re-schedule with backoff, or drop after maxAttempts.
        $queue->retry($job, maxAttempts: 3, baseDelaySeconds: 10);
    }
}

$queue->size();             // jobs scheduled (due + future)
$queue->secondsUntilNext(); // 0 = work pending, null = empty, N = seconds to soonest
$queue->flush();            // remove all, returns count
```

#### `retry(ReservedJob $job, int $maxAttempts = 3, int $baseDelaySeconds = 10): ?string`

Re-schedules a failed job with **linear backoff**: the new job carries
`attempts + 1` and is delayed by `baseDelaySeconds × newAttempts` (10s, 20s, …).
Returns the new job id, or `null` when the job has reached `maxAttempts` and
should be dropped.

### Sharing an existing Redis connection

`RedisQueueDriver` either self-connects from its config, or reuses a connection
returned by an injected factory — the same pattern as the broadcasting
`RedisDriver`. Pass a factory to share the application's connection (and prefix)
rather than opening a second one:

```php
$driver = new RedisQueueDriver(
    ['prefix' => $app->redisPrefix(), 'namespace' => 'jobs'],
    static fn (): \Redis => $app->redis()   // reuse the app's connection
);
```

### Redis key layout

The Redis driver stores the queue as a sorted set scored by run-at time, plus a
companion hash for payloads. Keys are prefixed by the driver itself (it does
**not** rely on `\Redis::OPT_PREFIX`), so a migrating application that passes its
historical prefix keeps addressing byte-identical keys:

```
<prefix><namespace>:delayed   ZSET   jobId => runAt (unix seconds)
<prefix><namespace>:data      HASH   jobId => json payload
```

Claiming is atomic per job: a worker owns a job only when *its* `ZREM` removed the
id from the sorted set.

---

## Adding a driver

`RedisQueueDriver` is the shipped driver, chosen for the lowest dispatch latency
(an in-memory sorted set). Because the capability is defined by
`QueueDriverInterface`, an alternative backend — a database-backed driver where
Redis is unavailable or jobs must survive a cache flush, or a future message-bus
driver — is added by implementing that one interface and passing it to
`DelayedQueue`. Switching driver is then a one-line change at construction, with
no application code changes.

---

## BC / additive notes

The delayed-queue capability is **purely additive**: `QueueDriverInterface`,
`ReservedJob`, `DelayedQueue`, and the drivers are all new types. No existing
class (including `QueueManager`, `Worker`, `QueueItem`) changed signature or
behaviour, so existing applications are unaffected.
