---
use_cases:
  - Dispatching work to a queue, immediately or delayed
  - Splitting a scheduled pass into one queued task per item, for a pool of workers
  - Choosing a queue driver
  - Running or supervising a queue worker
  - Finding out why a queue is falling behind
  - Recovering tasks stuck in `processing` after a worker died
  - Reading the queue's execution and wait times over days rather than minutes
  - Moving an event's side effects (emails, syncs) out of the request into the queue
  - Syncing a record once after many changes, or sending a welcome email only once
  - Writing an application's events as classes that listeners in the request and in the queue both take
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

This guide documents both: [the durable queue](#the-durable-work-queue) and its
operational side — [keeping the durable queue honest](#keeping-the-durable-queue-honest) —
and [the delayed queue](#the-delayed-queue-capability).

---

## The durable work queue

A task is a row in `queueitems`; a handler is a class implementing
`Pramnos\Queue\TaskInterface` (or extending `AbstractTask`); a worker claims rows and runs
the handler for their type.

```php
use Pramnos\Queue\QueueManager;

$queue = new QueueManager($controller);

// addTask(string $taskType, mixed $data, int $priority = 10, int $maxAttempts = 3, bool $unique = false): ?int
$taskId = $queue->addTask('send_email', ['to' => 'a@example.com'], priority: 5, unique: true);
// null when unique and an identical task is still pending or processing
```

A handler:

```php
class SendEmailTask extends \Pramnos\Queue\AbstractTask
{
    public function execute(\Pramnos\Queue\QueueItem $queueItem): mixed
    {
        $payload = $this->getPayload($queueItem);
        // … do the work; throw to fail the attempt
        return true;
    }

    public function getDescription(\Pramnos\Queue\QueueItem $queueItem): string
    {
        return 'Send an email';
    }
}
```

`TaskInterface` also declares `validate()` and `handleFailure()`; `AbstractTask` provides
both. Handlers are named once, in `app.php`, for every worker:

```php
// app/app.php
'queue' => ['handlers' => [
    'send_email' => \App\Tasks\SendEmailTask::class,
    'rsvp'       => \App\Tasks\RsvpTask::class,
]],
```

`queue:process` reads them with no subclass, which is what the orchestrator's queue pools need:
they start `queue:process` by the framework's name. A worker can also be given a handler in code,
`$worker->registerTaskHandler('send_email', SendEmailTask::class)`, in `ProcessQueue::createWorker()`
for example. The order is: a handler registered in code, then one named in `app.php`, then the
framework's own task types (`Worker::FRAMEWORK_HANDLERS`), which need no registration.

**A worker takes only the task types it can run.** Not told which (`queue:process` without
`--type`), it claims every type it has a handler for and no other. A task of a type this worker
cannot run stays pending, attempts untouched, for the worker that can. `--type` narrows that list
further. A worker whose handlers are found some other way — by naming convention, in an
overridden `getTaskHandler()` — overrides `handledTaskTypes()` too: with its types, or with `null`
to claim every type.

### Columns of the application's own

A queue table with columns of its own — a `meta_data`, a tenant — has them filled for every task,
from `addTask()` and `addMany()` alike, by a manager that names them:

```php
final class AppQueueManager extends \Pramnos\Queue\QueueManager
{
    protected function extraColumns(string $taskType, mixed $payload): array
    {
        return ['meta_data' => json_encode(['source' => 'web'])];
    }
}
```

A worker uses it through `Worker::createQueueManager()`. A column of the framework's with the same
name is written as the framework writes it.

Other `QueueManager` methods: `addMany()` and the batch methods ([below](#spreading-a-pass-over-the-worker-pool-addmany)),
`getNextTask()`, `markTaskAsCompleted()` / `markTaskAsWarning()` / `markTaskAsFailed()`,
`retryTask(int $taskId)`, `getStats()`, `reclaimAbandonedTasks()`, `throughput()`,
`activeTaskTypes()`, `history()` and `purgeOldTasks()`. Running and supervising workers is
covered in [Workers & Daemons](Pramnos_Workers_And_Daemons_Guide.md).

### The queue screen

The administration's **Queue** (`src/Admin/Controllers/Queue.php`, the framework's
`QueueController`) opens on the queue's state, then its tasks:

- **A count per status** — pending, processing, completed (with its share of all tasks), warning,
  failed, stopped, deleted — and the total, counted on each request.
- **Per task type, whether it is keeping up**, over the last ten minutes: running now, pending,
  completions a minute, net a minute, and when the backlog clears. A type is *keeping up* when
  arrivals match completions and the backlog is under a minute of its own output; *growing — needs
  workers* is the row worth reading, and it is drawn in the error colour. The figures come from
  `QueueManager::throughput()`, the same numbers `queue:health` prints.
- **The tasks**, a DataTable fed by `Queue/data`: status and type filters under their columns,
  search over the type, the error and the payload, a page size, and sorting by id, type and status,
  the indexed columns. Priority, attempts, created, started, completed and execution time are
  columns too. A table of tens of thousands of tasks is read a page at a time.

The id opens **the task** (`Queue/show/<id>`): every field it has a value for — lock, batch, task
hash, CPU time, the error and the result in full, the application's columns — and its payload key
by key, each value as readable JSON, a value that is itself JSON text decoded first. A queued
listener's task (`event.listener`, [below](#events-that-run-in-the-queue)) also shows the event that
brought it — name and id, when, by whom, from where, the event that caused it and the first of its
chain — the key it was folded by and how many events it absorbed; the list shows the event's name
beside the type. A task whose work was already done reads *Skipped: already done (key)* as its result.

**Statistics — last 48 hours** (`Queue/history`) is the hourly roll-up per task type from
`QueueManager::history()`: tasks, failed, retried, execution mean and max, CPU mean, wait mean and
max. It reads `queuestats`, which `queue:cleanup` fills as it removes finished tasks, so it answers
for longer than the table keeps them. `Queue/stats` stays as JSON for a monitor; no page links to
it.

Each row, and the task page, carries what its status allows: **retry** a failed task, **stop** a
pending or processing one, **duplicate** any (a new pending copy: same type, payload, priority and
attempts allowed, and the application's columns below), **delete** one not already deleted. All
four are `POST`s with the form token. A stopped task has status `stopped`: no worker claims it, a
worker already running it finishes that run, `addTask(unique: true)` does not count it, and
`queue:cleanup` removes it with the completed ones. Above the table, **Retry all failed** and
**Clear** (failed, completed, stopped or deleted tasks, marked deleted).

There is no form to create or edit a task: duplicate re-runs one, and a payload edited from the
browser is a write path into whatever the handler does with it.

Every label goes through `t()`, and the framework's Greek catalogue carries them.

Columns of the application's own appear in the list when the wrapper names them, as
`[label => column]`. They are shown escaped and searched, and not sorted, since the framework
knows no index on them:

```php
final class Queue extends \Pramnos\Queue\Controllers\QueueController
{
    protected function extraColumns(): array
    {
        return ['Sent by' => 'meta_data'];
    }
}
```

---

## Events that run in the queue

A listener that extends `Pramnos\Event\QueuedListener` is not called by `Event::fire()`: a task is
written for it, and a worker runs it with the event's arguments. The request writes the fact; the
emails, the notifications and the provider syncs happen in the worker.

```php
use Pramnos\Event\Event;
use Pramnos\Event\QueuedListener;

final class SyncMoosend extends QueuedListener
{
    public function handle(mixed ...$args): mixed
    {
        [$userId] = $args;
        // … call the provider …
        return true;
    }

    /** Six saves in a minute are one sync, with the last of them. */
    public function debounceKey(mixed ...$args): ?string
    {
        return 'moosend:' . $args[0];
    }
}

Event::listen('user.saved', SyncMoosend::class);      // in init(), like any listener
Event::fire('user.saved', $userId);                    // writes a task; returns [taskId]
Event::fireAs(null, 'subscription.expired', $userId);  // a cron: fired by the system
```

The task's type is `event.listener`, registered by the framework, so every worker runs it — no
handler to name in `app.php`. Retries, attempts and the Queue screen are the queue's own.

**Arguments travel as JSON** — ids and plain values. An object that is not `JsonSerializable`, a
resource or invalid UTF-8 cannot: in development (`DEVELOPMENT`) and under tests `fire()` throws; in
production it logs to the `events` log, skips that task and goes on with the other listeners, so an
event fired in a payment's path never loses the payment over a listener. A queued listener's place
in `fire()`'s results is its task id, or null where nothing was queued, and it never stops the
listeners after it — it has not run yet.

**The task is written in the caller's transaction, on the application's connection.** Inside
`startTransaction()` a rollback takes the task with the change and a commit releases both; without a
transaction it is written at once. A transaction on another connection — a second handle kept for
older code — does not cover it, and neither does the Redis driver.

Each listener answers, from the event's arguments, what it needs — every method has a default:

| Method | Default | What it does |
|---|---|---|
| `debounceKey(...$args)` | null | Events with the same key fold into one waiting task: the last event's arguments win, `absorbed` counts the others, and the task runs `debounceWindow()` after the last |
| `debounceWindow()` | 60 | Seconds the folded task waits after the last event |
| `debounceCeiling()` | 600 | Seconds after the first event it runs at the latest, however many keep coming |
| `idempotencyKey(...$args)` | null | Work done once per key: an event whose key was used queues nothing, and a task that finds its key taken completes as *skipped*. A failed run gives the key back for the retry |
| `idempotencyTtl()` | 30 days | How long a used key stays used |
| `priority()`, `maxAttempts()` | 10, 3 | The task's |

A task already running folds nothing — the change it did not see gets a task of its own. Folding
keeps the last payload; there is no hook to merge two yet (a `mergePayload()` would go here). Nor is
there an ordering key: two tasks for the same account can run out of order when the first is
retried, so a listener that cares reads the current state rather than trusting the event's.

**Every task carries the event that brought it**, in the payload's `meta`: `eventId`, `event`,
`firedAt`, `userid` (the signed-in account, the one `fireAs()` names, or null for the system),
`ip` for a web request, `source` (`web`, `cli`, or `queue` when a worker fired it), and the
causation chain — `causedBy`, the event whose queued listener fired this one; `rootId`, the first of
the chain; `depth`. A chain deeper than ten is refused and logged: a rule that triggers itself.

Two migrations add what folding and idempotency need — `add_event_columns_to_queueitems`
(`dedupekey`, `absorbed`) and `create_queue_idempotency_table`. Until they have run, queued
listeners still queue, unfolded and without keys, and the `events` log says so once.

`QueueManager::addKeyed($type, $data, $key, $delaySeconds, $ceilingSeconds)` is the folding on its
own, for a task that is not an event's.

### Domain events as classes

An application's own events — a job post created, an account deactivated — are classes in
`App\Events`, fired with `Event::dispatch()`:

```php
namespace App\Events;

use Pramnos\Event\QueueableEvent;

final class JobPostCreated implements QueueableEvent
{
    public const EVENT = 'jobpost.created';

    public function __construct(public readonly JobPost $post, public readonly User $parent) {}

    public function jsonSerialize(): array
    {
        return ['post' => (int) $this->post->id, 'parent' => (int) $this->parent->userid];
    }

    public static function fromQueue(array $data): static
    {
        return new self(new JobPost($data['post']), new User($data['parent']));
    }
}

Event::dispatch(new JobPostCreated($post, $user));   // = Event::fire('jobpost.created', $event)
```

`dispatch()` fires the class's `EVENT` — a dotted name, so `Event::listen('jobpost.created', …)`
and the Queue screen read as they always did — with the object as the one argument. A class
without `EVENT` is fired under its class name.

Listeners live in `App\Listeners` and take the event's class:

```php
final class CompleteRegistration                       // in the request
{
    public function handle(JobPostCreated $event): mixed
    {
        $event->parent->regcompletion = 1;              // the caller's object — saved by the caller
        return null;
    }
}

final class NotifyNearbyProfessionals extends QueuedListener   // in the worker
{
    public function handle(JobPostCreated $event): mixed { /* … */ return true; }

    public function idempotencyKey(mixed ...$args): ?string
    {
        return 'notified:' . $args[0]->post->id;        // asked with the event object
    }
}

Event::listen(JobPostCreated::EVENT, CompleteRegistration::class);
Event::listen(JobPostCreated::EVENT, NotifyNearbyProfessionals::class);
```

**A listener in the request receives the dispatched instance itself**, models included. That is
the point of the object: a listener that changes the record the caller is holding changes it on the
caller's object, and the caller's next `save()` writes it. One that loaded its own copy and saved it
would be overwritten by the caller's stale object a moment later.

**A queued listener receives the same class, built again in the worker.** The task carries what
`jsonSerialize()` returns — ids and plain values — and the class; the worker calls `fromQueue()`
and hands the result to `handle()`, so one `handle(JobPostCreated $event)` serves both places.
`fromQueue()` loads what it needs, so the worker reads the records as they are now. The key methods
— `debounceKey()`, `idempotencyKey()` — are asked at dispatch, with the object.

Which goes where: a listener that adjusts the caller's own records — flags, counters, a completion
step — runs in the request, on the caller's object. One that changes somebody else's data, calls a
provider or sends anything goes to the queue, where it cannot slow the request or lose its work
over the request's rollback.

An object that is not a `QueueableEvent` reaches a queued listener only if it is `JsonSerializable`,
and then as the array it encodes to; one that is neither is refused as any argument that cannot
travel is.

A listener class typed with its event — `handle(JobPostCreated $event)` — does not implement
`ListenerInterface`, whose `handle(mixed ...$args)` no typed parameter can satisfy; it is
registered by class name, and `fire()` calls its `handle()`. `QueuedListener` does not implement it
either, for the same reason.

**When a bare name is enough.** `Event::fire('cache.cleared')` with plain arguments stays right for
an event with nothing to carry, or one only the framework fires. A class earns its place when the
event carries records, has more than one listener, or reaches the queue — the class is then the one
place that says what the event is and how it travels.

`create:event JobPostCreated` writes the class with its `EVENT`, a readonly payload and the two
queue methods; `create:listener NotifyNearby --event=JobPostCreated --queued` writes the listener
(see the Console guide).

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
use Pramnos\Redis\ConnectionManager;

$driver = new RedisQueueDriver(
    ['prefix' => ConnectionManager::getInstance()->prefix(), 'namespace' => 'jobs'],
    static fn (): object => ConnectionManager::getInstance()->connection()   // reuse the shared connection
);
```

That is exactly what [`DelayedQueue::redis('jobs')`](#redis-delayed-queue-from-the-connectionmanager)
builds for you. Without a factory the driver connects through the pooled
`ConnectionManager::forConfig()` for its host, port and database.

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

## Spreading a pass over the worker pool — `addMany()`

A scheduled pass that loops over every channel, feed or site inside `schedule:run` takes
longer with every customer. Queue one task per thing instead, and whichever worker is free
claims the next one; N workers do N at once.

```php
$batch = $queue->addMany('pass_unit', array_map(
    fn (int $id) => ['pass' => 'channels.collect', 'id' => $id],
    $dueChannelIds
), unique: true, batch: 'channels.collect');

$batch->queued();   // tasks created
$batch->skipped;    // payloads whose identical task was still waiting
$batch->id;         // written to every task's batchid
$batch->taskIds;    // in the order given
```

| Argument | |
|---|---|
| `$taskType` | the handler that runs each unit |
| `$payloads` | any iterable, one payload per task |
| `priority`, `maxAttempts` | as for `addTask()` |
| `unique` | skip a payload whose identical task is `pending` or `processing` |
| `batch` | what the batch is for; stored as `batchname` |

**`unique` is atomic.** Duplicates are looked for and the rows written under a
`SharedLock` on the task type, so two producers queueing the same pass at once cannot both
insert a unit. A unit whose earlier copy has finished, in any way, is queued again. The same
payload twice in one call is queued once. A producer that waits 30 seconds for the lock gets
a `RuntimeException` and queues nothing.

**A batch is queued whole or not at all.** The rows are written in one transaction.

The handler maps a unit back to the work, and that mapping is the application's: a
`pass_unit` task whose `execute()` looks up `pass` and calls the method for `id`.

### Knowing when a pass is done

```php
$status = $queue->batchStatus($batch->id);
$status->finished();     // nothing pending or processing
$status->toArray();      // id, pending, processing, completed, warning, failed, total, finished

$queue->batchInProgress('channels.collect');   // any unit of a batch by this name still waiting or running
```

`withoutOverlapping()` guards the run that *queues* a pass, and that run ends in a second.
The units go on for as long as they take, so check `batchInProgress()` before queueing the
next pass, or a slow one gets a second copy of itself queued on top:

```php
Scheduler::call(function () use ($queue, $store) {
    if ($queue->batchInProgress('channels.collect')) {
        return;
    }
    $queue->addMany('pass_unit', $store->dueUnits(), unique: true, batch: 'channels.collect');
})->everyFiveMinutes();
```

An id nobody queued reads as an empty batch that is finished.

### A failed task waits before it is tried again

`markTaskAsFailed()` on a task with attempts left puts it back to `pending` with
`availableat` in the future: **30 seconds per attempt so far, at most ten minutes**. No
worker claims it before then, on any claim path (both `SKIP LOCKED` shapes and the
fallback).

The reason is a deploy. A unit queued while a deploy lands can be claimed by a worker still
running the old code and fail there; retried at once, it goes straight back to the same
worker. The orchestrator restarts workers once it sees the new deploy, within a minute, and
the delay outlasts that window.

To change the curve, override `retryDelaySeconds()`:

```php
class Queue extends \Pramnos\Queue\QueueManager
{
    protected function retryDelaySeconds(\Pramnos\Queue\QueueItem $task): int
    {
        return 5;   // a queue of cheap, idempotent units
    }
}
```

`retryTask()`, an operator's retry of a task that failed for good, is available at once. On a
database where `migrate` has not yet added the `availableat` column, retries are immediate and
nothing fails.

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

!!! warning "`--window` cannot reach past the purge"
    `throughput()` counts among rows that are **still there**, and `queue:cleanup` deletes
    them. On an installation running it hourly, `--window=86400` can only count the ninety
    minutes that survived, and the rate would look like the answer for the whole day.

    So when the oldest surviving completion is newer than the window, the output carries a
    line saying so. Detected rather than configured, because the
    retention is an argument on somebody else's crontab and the oldest surviving row is a
    fact this connection has.

    For a window that long, read the roll-up instead — `--history`, below.

### History that outlives the purge — `queue:cleanup` and `--history`

`execution_time` and `cpu_time` are recorded per task, and wait time exists only as
`startedat - createdat` — on rows that `queue:cleanup` deletes an hour later. To answer
*is this task type slower than last week*, or to see a nightly burst where queue latency
goes far above normal, the measurements have to survive the purge.

`QueueManager::purgeOldTasks()` summarises the rows it is about to delete into
`queuestats`, one row per task type per hour, in the **same transaction** as the `DELETE`.
That equivalence — a counted row is a deleted row — is the whole correctness argument: no
row can be summarised twice and none deleted without being summarised.

```
php pramnos queue:health --history            # the last 168 hours, from the buckets
php pramnos queue:health --history=48         # two days
php pramnos queue:health --history --json     # for a collector
```

| | |
|---|---|
| Where the roll-up lives | `purgeOldTasks()`, not `queue:cleanup` — the method is what destroys the data, so every caller is covered: the framework's command, an application's own cron, a manual console call |
| Cost | ~104 ms over a purge set of 40,840 rows producing 12 buckets, once an hour, on the connection that was about to scan them anyway |
| Volume | one row per type per hour — three types is ~72 rows a day, 26k a year. **No hypertable, no compression, no retention policy** |
| Stored | sums, counts and maxima. **Never averages** |
| Read side | `QueueManager::history()`, which derives the means |

**Sums and maxima, never averages**, and that is what makes the upsert additive: a second
cleanup inside the same hour adds to the bucket instead of overwriting it. An average cannot
be added to another average.

**Percentiles are deliberately absent.** `percentile_cont` does not merge across two inserts
into one bucket, so storing p95 would mean either a wrong number or a second pass over rows
that no longer exist. `max` and the derived mean are enough to find a burst — it shows in
both.

How long finished tasks are kept is `queue.retention_hours` in `app.php`, 24 when unset. The
framework's daily `queue:cleanup` uses it, so an installation that keeps a month sets it once
rather than replacing the scheduled task; `--hours` given on the command line still wins.

```php
'queue' => ['retention_hours' => 720],
```

**Statuses are broken out**, and the framework's own retention is why: `queue:cleanup` keeps
warnings for `$hours * 10`, which can make `warning` the *dominant* population of the
table. A roll-up that counted only "tasks" could describe the small half.

!!! note "A `--limit` purge is not summarised"
    `DELETE … LIMIT` removes an arbitrary subset of what the predicate matches, so an
    aggregate over the predicate would count rows that are still there — and the next call
    would count them again. The limit exists to keep one `DELETE` off a lock on a table left
    for months; it is not the normal path. It logs that it skipped the roll-up.

### Waiting is not working — `cpu_time` beside `execution_time`

**`execution_time` is wall clock around the handler, and on its own it is misleading.** A task
taking 0.9 seconds reads the same whether it is computing or waiting, and every theory of a
slow queue — lock contention, a missing index, a CPU-bound handler — fits the wall clock. A
handler at 1.6% CPU is waiting on something, and only the CPU figure says so.

So the worker records `cpu_time` — user plus system CPU across the handler — beside it, and
`throughput()` reports the ratio:

```php
$numbers = $queueManager->throughput(300, 'imports');
// ['window' => 300, 'arrivals' => 1830, 'completions' => 1320, 'net' => -510,
//  'pending' => 175_000, 'processing' => 1, 'losing' => true,
//  'wall' => 1194.2, 'cpu' => 19.1, 'computing' => 0.016, 'clears_in' => null]
```

`computing` is the diagnosis in one figure, and the two answers point in **opposite**
directions:

| | |
|---|---|
| near 1 | the handlers are working. Faster code, or more cores. |
| near 0 | they are waiting — a database, redis, an HTTP call. **More workers will not help**, because whatever they wait for is already the limit. |

`queue:health` prints it, and says the second one in words rather than leaving it to be read
off a decimal.

`null` where nothing recorded CPU — a row from before the migration, or a platform without
`getrusage()`. Reported as unknown rather than as zero, because *nobody measured* and *did no
work* are opposite conclusions and the second sends somebody hunting a phantom.

### `clears_in` — and why it is often null

`null` is the useful answer, and an estimate usually refuses to give it. Dividing the backlog
by the completion rate produces a figure that recedes on every refresh, so a queue reads as
nearly finished right up until it obviously is not. If arrivals are winning there is **no**
completion time; saying so is more informative than any number.

The rate is the **net** rate for the same reason: work arriving during the drain has to be
drained too.

And read it per type. Aggregated over one queue draining in nine hours and another that never
clears, the estimate describes neither and points at nothing.

### One claim, one worker

The ordinary claim is **one statement, and it cannot collide**:

```sql
UPDATE queueitems
   SET status = 'processing', attempts = attempts + 1, lockedby = …, lockexpires = …
 WHERE taskid = (SELECT taskid FROM queueitems WHERE status = 'pending'
                  ORDER BY priority ASC, createdat ASC
                  FOR UPDATE SKIP LOCKED LIMIT 1)
RETURNING taskid
```

`FOR UPDATE SKIP LOCKED` removes the collision rather than recovering from it: the subquery
locks the row it picks, and a worker arriving while that lock is held is neither queued behind
it nor a loser — it is offered the next unlocked row. Twelve workers take twelve different
rows on the first attempt, one round trip each.

Which matters more than it sounds, because select-then-claim is *correct* and, under
contention, slow: more workers mean more lost races and retries, and none of it shows in
`execution_time`, which times only the handler.

**Engine differences.** PostgreSQL takes the statement above. MySQL and MariaDB reject it —
error 1093, *you can't specify target table for update in FROM clause* — and have no
`UPDATE … RETURNING`, so there it is a locking `SELECT` and a guarded `UPDATE` inside a
transaction of the queue's own. That transaction is why the fast path steps aside when the
**caller** already has one open: committing inside somebody else's transaction would commit
their work. A server without `SKIP LOCKED` (MariaDB before 10.6, MySQL before 8.0) refuses
once, the refusal is remembered for the process, and everything falls back to the loop below.

Do **not** write the MySQL form as a derived table (`… FROM (SELECT … FOR UPDATE SKIP LOCKED)
x`). It gets past error 1093 and is wrong: the subquery is materialised, so the lock is taken
on a copy and `SKIP LOCKED` means nothing.

### When the fast path does not apply

`--reverse`, the recent-high-priority window and stalled rows keep select-then-claim: they are
rare, and the last of them needs an `attempts < maxattempts` comparison against the row it is
also updating. There the claim is a **guarded `UPDATE`** — the state the row was read in
becomes the `WHERE` clause, and the database decides who got it.

For a stalled row the lock we read is part of the condition, not just the status: status is
`processing` either way, and only the lock says whose the row is. It is compared **by value**
against the snapshot rather than re-tested against `NOW()`, because the question is *is this
still the claim I read?*, not *is some claim expired?*

**A lost race is not an empty queue**, and spelling them the same way is expensive. Selection
is deterministic, so an atomic claim turns "everybody wins" into ten losers per eleven asks —
and a loser that answers `false` reaches `processBatch()`, which reads `false` as *nothing
left*, breaks out of the batch and sleeps five seconds — most of the pool would spend its
time losing races and sleeping. So a pass that was offered rows and won none is retried, up
to ten passes: the winner has already marked its row `processing`, so the next pass is offered
the one below and the pool converges. A pass offered **nothing** answers `false` immediately —
an idle poll must not cost ten selections.

`attempts` is incremented in the database (`attempts + 1`), never read-and-written in PHP —
two workers both writing `read + 1` lose one of the increments, and `markTaskAsFailed()`
compares `attempts` against `maxattempts` to choose between a retry and a permanent failure.

### Stopping a worker

`queue:process --daemon` stops **cooperatively**. `SIGTERM` (a `systemctl stop`, a deploy)
and `SIGINT` raise a flag; nothing is torn down inside the handler. A supervisor can also
drop a `.stop` sentinel beside the lock file.

A stop can be asked for three ways, and a supervisor uses all of them: a signal, a `.stop`
sentinel, or **the lock file being removed** — which is how an orchestrator reclaims a slot.
All three are answered the same way, and the third only counts for a worker that actually
took a lock: a one-shot CLI run never writes a job file, and reading that absence as *stop*
would make it process one task and report the queue empty.

The flag is checked in two places, and the second is the one that matters under systemd:
the daemon loop on each pass, **and between tasks inside a batch**. With the default
`--batch=20`, checking only at the batch boundary means up to twenty more tasks claimed
after the signal — long enough for `TimeoutStopSec` (90 seconds by default) to expire and
`SIGKILL` the worker mid-task, leaving the row it held `processing` behind a lock nobody
holds. Which is the leak `queue:reclaim` exists to clean up, produced by the code whose job
is to stop cleanly.

The check is before each claim rather than after each task, so **the task in hand always
finishes and nothing new is taken**.

### A task sees the settings as they are now

A worker in `--daemon` runs for hours, and an administrator changes settings meanwhile — a
language-model provider, an API key. Before each task it claims, the worker calls
`Settings::refresh()` and drops the language-model connections built from the old values, so
the task runs against what was saved last. Only when there is a task: an idle worker polling
the queue reads nothing.

### How many workers, and elasticity

`queue:process --daemon` is a complete worker: signals, lock file, cooperative stop as above.
How many of them run is the supervisor's decision. The framework's orchestrator makes it with
[`queuePool()`](Pramnos_Workers_And_Daemons_Guide.md#a-pool-that-follows-the-backlog-queuepool),
which sizes a pool from the backlog of its task types. systemd or supervisord can run a fixed
number instead.

The pool follows three constraints, and they apply to any autoscaler built on this queue:

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
deciding how many workers a type needs runs every cycle, per profile. The
`(status, priority, createdat)` index leads with `status` but does not carry `type`, so on
its own it would fetch every matching row to discard most of them.

`idx_queueitems_status_type` answers it. Created by a framework migration; nothing to
configure.

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
