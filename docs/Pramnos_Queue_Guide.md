---
use_cases:
  - Dispatching work to a queue, immediately or delayed
  - Choosing a queue driver
  - Running or supervising a queue worker
  - Finding out why a queue is falling behind
  - Recovering tasks stuck in `processing` after a worker died
---

# Pramnos Queue Guide

The framework ships **two sibling queue capabilities**, each fit to a different
shape of background work. Both live under `Pramnos\Queue`.

| Capability | Class | Semantics | Backend |
|---|---|---|---|
| **Durable work queue** | `QueueManager` + `Worker` + `QueueItem` | Auditable row per task with a `pending → processing → completed / failed / warning` lifecycle, priorities, retries, locking, admin datatable | Database (`queueitems`) |
| **Delayed queue** | `DelayedQueue` + `QueueDriverInterface` | Low-latency "run this later, don't lose it" dispatcher; claim-and-remove; linear-backoff retry | Pluggable driver — **Redis** (sorted set) or **Database** (`delayed_jobs`) |

They answer different needs. Reach for the **durable work queue** when you want a
persistent, inspectable record of every task and a rich status lifecycle (imports,
report generation, admin-visible jobs). Reach for the **delayed queue** when you
want a fast, ephemeral "do this in N seconds" primitive where the job's existence
*is* the state (bot replies, deferred deliveries, debounced work).

This guide documents the **delayed queue** capability, plus the durable queue's
operational side — [keeping the durable queue honest](#keeping-the-durable-queue-honest).
For the durable queue's API see `QueueManager` / `Worker` (feature entry #28 in
[1.2-new-features](1.2-new-features.md)).

---

## The delayed-queue capability

A delayed queue is a driver behind a capability — an application depends on
`DelayedQueue`, never on Redis directly. Two drivers ship — `RedisQueueDriver`
(the low-latency default) and `DatabaseQueueDriver` — and further drivers
implement `QueueDriverInterface` and drop in without touching application code.

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

## Choosing a driver

| | `RedisQueueDriver` | `DatabaseQueueDriver` |
|---|---|---|
| Latency | Lowest (in-memory sorted set) | Bounded by the poll interval |
| Durability across a Redis flush | No | Yes (rows survive) |
| Extra infrastructure | Requires Redis | Reuses the app database |
| Backing store | `<prefix><ns>:delayed` ZSET + `:data` hash | `delayed_jobs` table |

Default to Redis for latency-sensitive dispatch; use the database driver where
Redis is unavailable or the jobs must survive a cache flush. Switching driver is
a one-line change at construction — no application code changes:

```php
use Pramnos\Queue\Drivers\DatabaseQueueDriver;

$queue = new DelayedQueue(new DatabaseQueueDriver($app->database));
```

The database driver stores each job as a row in `delayed_jobs` (created by the
shipped migration), scored by an integer `run_at` Unix timestamp. Claiming is the
SQL analogue of Redis's claim-by-`ZREM`: for each due row it issues
`DELETE ... WHERE id = ?` and owns the job only when exactly one row was removed,
so competing pollers never double-process. Because the capability is defined by
`QueueDriverInterface`, further backends (a future message bus, etc.) are added
by implementing that one interface.

---

## Keeping the durable queue honest

Two things the durable queue needs from whoever runs it, and both are one scheduled
command. Neither is about throughput; both are about the queue's own account of itself
being true.

### Tasks whose worker died — `queue:reclaim`

A worker claims a task by setting `status = 'processing'` with a lock expiry. If it then
dies — a fatal, an OOM kill, a `SIGKILL`, a machine going away — nothing writes the end of
that story, because **a process that dies cannot record its own failure.**

`QueueManager::getNextTask()` picks up a stalled row on its own, and that covers the
ordinary case. It does not cover the two that hurt:

- **Nobody is polling that type.** The reclaim is a side effect of somebody asking for
  work, so a type whose workers are all dead reclaims nothing — which is exactly when it
  is needed.
- **`attempts` has reached `maxattempts`.** The stalled branch requires attempts
  remaining, so a row that has used them all stays `processing` for ever: never claimed,
  never failed, counted as *in progress* by `getStats()`, and invisible to `queue:cleanup`,
  which reads only terminal states.

```
php pramnos queue:reclaim                  # everything eligible
php pramnos queue:reclaim --grace=60       # allow for worker clocks disagreeing
php pramnos queue:reclaim --type=imports   # one queue at a time
php pramnos queue:reclaim --dry-run        # count without writing
```

A row with attempts left goes back to `pending` with its lock cleared; one with none left
is recorded as `failed` with a reason. `attempts` is never spent by a reclaim, so a task
whose worker keeps dying still walks up to `maxattempts` and stops.

**The lock expiry is the entire safety condition**, and that is what makes this safe
against a live queue: a worker that is alive holds a lock that has not expired, so nothing
running can have its task taken away. A row with no expiry at all is left alone too —
there is no evidence its holder is gone, and the expiry is the only evidence accepted.

`queue:cleanup` runs the same reclaim before it purges, so an installation that already
schedules a cleanup gets this without a new crontab entry. `--no-reclaim` opts out;
`--reclaim-grace` sets the grace.

### Whether the queue is keeping up — `queue:health`

**A depth is a level; this is a rate.** A dashboard reading "a few thousand pending" can
describe a queue in perfect health or one losing 1.7 items a second — the level moves
slowly enough to look steady either way. Only arrivals against completions says which.

```
php pramnos queue:health --window=300              # every queue, last 5 minutes
php pramnos queue:health --type=imports --json     # one queue, for a monitor
php pramnos queue:health --per-type                # a line per type, and which one is losing
```

`net` below zero means the queue is losing, whatever the depth happens to be. **The exit
status is the point:** `1` when losing, so `cron` mails it and a monitor can page on it
with nothing parsing the output.

**Run it `--per-type`.** Totals hide the case that matters: nine queues draining and one
not sums to healthy, and an alert watching only the sum fires when the backlog is large
enough to move the sum — which is hours late. The types broken down are the ones the queue
has actually carried (`QueueManager::activeTaskTypes()`), read from the table rather than
from the directory of handler classes: a type with a handler and no traffic is noise, and a
type with traffic and **no** handler is the thing to see, because the worker fails every
one of those.

Completions count every terminal state, failures included — a task that failed is one the
queue is no longer carrying. A *retryable* failure is not a departure: `markTaskAsFailed()`
returns it to `pending` while attempts remain, and the queue is still carrying it.

The numbers are available directly, for a screen of your own:

```php
$numbers = $queueManager->throughput(300, 'imports');
// ['window' => 300, 'arrivals' => 1830, 'completions' => 1320, 'net' => -510,
//  'pending' => 175_000, 'processing' => 1, 'losing' => true]
```

### How many workers, and elasticity

**The framework ships the worker, not the pool.** `queue:process --daemon` is a complete
worker — signals, lock file, graceful stop on `SIGTERM` and `SIGINT` with the task in hand
finished first — and deciding how many of them run, noticing when one dies and restarting
it belongs to a supervisor: systemd, supervisord, or an application's own orchestrator.

If you build an autoscaler on top, three constraints are worth having from somebody who
did:

- **One step per reconcile cycle**, never a jump to a computed target. That is the whole
  load protection: an incremental policy only has to be right about the direction, where a
  leaping one has to be right about capacity in advance.
- **Load gates growth, never shrinking.** Past a load ceiling, add nothing however deep the
  queue — but never *shed* on load, because the load may not be the queue's, and shedding
  would fail to fix that while making the backlog worse.
- **Two thresholds with a gap between them.** One threshold oscillates by construction:
  scaling up drops the backlog below it, which scales down, which raises it again, every
  cycle.

And the order matters: **reclaim first, then the health signal, then elasticity.** Every
shrink stops a worker, so an autoscaler over a queue that cannot reclaim abandoned tasks is
a machine for losing work in proportion to how much it scales. Elasticity also *hides* what
it absorbs — a pool that quietly soaks up a misconfiguration is a pool nobody investigates.

### The index the backlog count needs

`count(*) WHERE status = ? AND type = ?` is what a backlog check runs, and what a pool
deciding how many workers a type needs runs every cycle, per profile. The table's
`(status, priority, createdat)` index leads with `status` but does not carry `type`, so
every matching row had to be fetched to be discarded — a sequential scan measured at 98 ms
and 50,365 buffers on a 175,000-row table, five times a minute, for a number that changes
slowly.

`idx_queueitems_status_type` answers it. Added by a framework migration; nothing to
configure.

## BC / additive notes

The delayed-queue capability is **purely additive**: `QueueDriverInterface`,
`ReservedJob`, `DelayedQueue`, and the drivers are all new types, and the
`delayed_jobs` table is a new migration (created only for apps that use the
database driver). No existing class (including `QueueManager`, `Worker`,
`QueueItem`) or table changed signature, schema, or behaviour, so existing
applications are unaffected.

The durable queue's operational additions are additive in the same way:
`reclaimAbandonedTasks()`, `throughput()` and `activeTaskTypes()` are **new methods** on
`QueueManager` rather than parameters on existing ones, `queue:reclaim` and `queue:health`
are new commands, and `idx_queueitems_status_type` is a new index. `queue:cleanup` gained
two options with defaults that preserve its behaviour except for also reclaiming — which
is the point, and `--no-reclaim` turns it off.

## Redis delayed queue from the ConnectionManager

`DelayedQueue::redis(string $namespace)` builds a Redis-backed delayed queue for a
namespace, bound to the shared `Pramnos\Redis\ConnectionManager` (its per-install
prefix + pooled connection), so an app gets the queue capability without wiring
the `RedisQueueDriver`:

```php
$queue = \Pramnos\Queue\DelayedQueue::redis('jobs'); // keyspace <prefix>jobs:delayed / :data
$queue->push('send-email', ['to' => $addr], 30);
```

A delayed queue is namespaced per use (not a process singleton), so this is a
factory — call it per namespace. Injecting a `DelayedQueue` into your own client
(constructor param) remains the test seam.
