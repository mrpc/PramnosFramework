---
use_cases:
  - Putting a single-server application behind a load balancer
  - Working out why users are signed out at random after adding a second node
  - Deciding between a shared mount and object storage for uploads
  - Making sure a scheduled task does not run once per machine
---

# One server to several

An application on this framework runs on one machine by default, and that is not a
limitation to apologise for: one machine is simpler, cheaper and easier to reason about, and
most installations should stay there for as long as they can.

This page is for the day that stops being true. It is a checklist, in the order the failures
actually arrive, and every item ends with how to verify it rather than how to hope.

**Run `health:check` first and last.** Four of the six things below report themselves there,
and the whole point of those checks is that the failure they describe is invisible on one
node.

---

## What already works, and needs nothing

Worth knowing before you change anything, because it is most of the system:

| | Why it already holds |
|---|---|
| **The queue** | Jobs are claimed with `FOR UPDATE SKIP LOCKED` — one statement on PostgreSQL with `RETURNING`, a guarded `UPDATE` inside its own transaction on MySQL. Two workers on two machines never take the same job. |
| **WebSockets** | `Broadcasting\Cluster` is a Redis backplane with immediate deltas *and* a periodic full-state republish, so a node that missed a message corrects itself rather than staying wrong about who is present. |
| **Client IP** | `ClientIpResolver` reads the proxy chain, so a load balancer does not make every visitor share one address. |
| **Everything in the database** | Users, tokens, lockouts, the activity log, notifications, the migration ledger. |

---

## 1. Sessions — the one that looks like something else

**Symptom:** people are signed out at random. It reads as an expiry, a cookie problem, a
`SameSite` mistake — everything except a load balancer.

PHP keeps sessions in local files unless told otherwise, so a visitor whose next request
lands on the other node has no session at all.

```php
// app/config/app.php
'session' => ['handler' => 'redis'],
```

or `APP_SESSION_HANDLER=redis` in `.env`, which wins over it. With no `path` the **cache's
own host** is reused — an application that configured Redis for its cache has already said
where Redis is, and a second copy of a hostname is a second thing to get wrong.

Sticky sessions at the balancer are the other answer and are perfectly legitimate. The
framework cannot see them, so `health:check` stays yellow on that arrangement; that is the
check being honest rather than wrong.

**Verify:** `health:check` reports `session_storage`. It never fails a request — an
unregistered handler or a store that is down leaves sessions on files and logs why, because
a working single server is a better failure than a site that will not boot.

---

## 2. Cache — invalidation stops working before caching does

**Symptom:** a page you just edited is right on one refresh and stale on the next.

The default adapter is files. Both nodes cache happily; what breaks is **invalidation** —
the node that was not asked to clear still serves what it holds.

```php
'cache' => ['method' => 'redis', 'hostname' => 'redis', 'port' => 6379],
```

**Verify:** `health:check` reports `cache`, and reports `degraded` when the configured store
could not be reached and it fell back. That fallback is deliberate — a cache that cannot
connect must not take the site down — and it is exactly the state that looks fine from
outside. An application whose image lacks the `redis` extension runs on local disk with
`redis` in its settings, in its compose file and on its bill.

---

## 3. Scheduled tasks — they run once per machine

**Symptom:** two copies of the nightly email. Nothing fails, which is why this one can run
for months unnoticed.

`withoutOverlapping()` takes a lock **file**, and the class says so: it records the holder's
`host` and checks the holder's pid *on the same host*. Each machine has its own `var/` and
its own `sys_get_temp_dir()`, so each takes its own lock.

```php
Scheduler::command('reports:nightly')->dailyAt('02:00')->onOneServer();
Scheduler::call(fn() => …)->hourly()->onOneServer(name: 'metrics:rollup');
```

`onOneServer()` takes a row in `pramnos.locks`, whose primary key is the exclusion — the
database rather than a shared cache, because the default cache adapter is local files and a
lock that excludes nothing is worse than no lock, since it looks like one.

The two compose: `withoutOverlapping()` stops a slow run on *this* machine starting again,
`onOneServer()` keeps the other machines out.

Two things to know:

- **The lease has to outlast the work.** A holder that dies never releases, so the lock
  expires and the next node takes it. The default is an hour; a task that can run longer
  must say so, or two nodes overlap.
- **A closure has to be named.** Every closure describes itself as `Closure`, so two
  unrelated ones would share a lock and take turns not running. `onOneServer()` raises
  rather than letting that happen.

**Verify:** `schedule:list` reports `one_server` per task. And the simplest check of all —
run cron on one node only, which is the normal arrangement anyway and what you should do
while migrating.

---

## 4. `migrate` — run it from one place

`MigrationRunner` locks with the same file-based `WorkerLock`, so two deploys migrating at
once are not excluded from each other. Make migration a deploy step that runs on one node,
which is what a deploy script does anyway.

**Verify:** `migrate:status` before and after. A batch that ran anything also clears the
column cache on that node — and on the others the hour-long cache still holds the old
column list until `cache:clear` runs, which is one more reason for a shared cache.

---

## 5. Uploads — a mount, or object storage

**Symptom:** an image loads or 404s depending on which node served the page.

`www/uploads/` is local disk. There are two answers and the first is usually right.

### A shared mount

NFS, EFS, or whatever the host offers. **No code at all**: the web server keeps serving
`www/uploads/` directly, nothing is copied, and there is nothing to configure in the
application. If this is available, take it.

### Object storage

For what a mount cannot do — a CDN origin, a node that gets replaced, files that cost
nothing while nobody reads them.

```php
'storage' => [
    'disks' => [
        'media' => [
            'driver' => 's3',
            'bucket' => envvar('APP_S3_BUCKET'),
            'region' => envvar('APP_S3_REGION'),
            'key'    => envvar('APP_S3_KEY'),
            'secret' => envvar('APP_S3_SECRET'),
            'endpoint' => envvar('APP_S3_ENDPOINT', ''),   // MinIO, R2, Spaces
            'url'    => 'https://cdn.example.com',
        ],
    ],
],
```

A disk **named `media`** is the whole opt-in. With none, every path is a no-op and nothing
is copied — which is right on one server, where a publish step would be a copy from a
directory to itself.

The `s3` driver needs `aws/aws-sdk-php`: `composer require aws/aws-sdk-php`. It is the
application's dependency, not the framework's.

**In views, ask for the address:**

```php
<img src="<?php echo htmlspecialchars(MediaObject::urlFor($row['logo_mediaid'])); ?>">
<img src="<?php echo htmlspecialchars($media->publicUrl('thumb')); ?>">
```

`sURL . $media->url` is the line to remove, and it is wrong twice over: `sURL` is the
**script's** base — the same line inside an API request answers `/api/uploads/x.png`, a 404
that every "is this fetchable" check accepts — and once a disk is configured it keeps
pointing at the local copy. `create:crud` generates the right shape for a column whose
foreign key references `media`.

**Backfilling an existing library:** `republishToStorage()` puts what is missing and skips
what is there, so it is cheap to run repeatedly — which is what you want for ten thousand
pictures, and again after the disk was unreachable for an hour.

The local copies **stay**. They are the origin a later resize reads and, on a single server,
what is served; deleting them would make rendering a page depend on the disk answering.

**Verify:** upload on one node, then `ls` the other. Or read the row's `url` off the bucket.

---

## 6. `APP_URL` — the one cron cannot infer

**Symptom:** an email from a scheduled task contains `http:///uploads/x.jpg`.

Each node infers its own site root from the request it is serving. A scheduled task has no
request and no `Host` header, so it infers nothing.

```
APP_URL=https://example.com/
```

**Verify:** `health:check` reports `site_url`, and says whether the value was configured or
merely inferred — because inferred works on the web and not in the scheduler, which is
exactly the asymmetry that hides this.

---

## The order to do it in

1. **Set `APP_URL`, move the cache to Redis, move sessions to Redis.** All three are single
   settings, all three are safe on one server, and all three can be done and verified before
   a second machine exists. `health:check` should be green afterwards.
2. **Mark the scheduled tasks that must not double up** with `onOneServer()`, and keep cron
   on one node until you trust it.
3. **Decide uploads** — mount if you can, object storage if you must, and backfill before
   the second node serves traffic.
4. **Add the node.** Then re-run `health:check` on *each* one: they have different
   filesystems, and a check that is green on the node you deployed from says nothing about
   the other.

---

## The three that look like gaps and are not

Each of these is per-node by default and shared by configuration. None of them needs code.

### Logs

`var/logs/` is per node, and reading five machines to follow one request is not a plan. The
logger writes to a **stream** as well as, or instead of, the file:

```bash
PRAMNOS_LOG_MODE=both    # files keep the LogViewer working, STDERR feeds the collector
PRAMNOS_LOG_MODE=stream  # STDERR only
```

`both` is the usual choice on a container: the file stays where `LogViewer` expects it, and
the same line reaches `docker logs` — which is to say whatever ships it to Loki, CloudWatch
or journald. The env var is read per process, so a node can differ from its neighbours
without a deployment. A `LOG_MODE` constant does the same for an installation that would
rather set it in configuration, and `Logger::setOutputMode()` overrides both.

### `var/cache/` and the write spool

Both default to files, and a file cache on two nodes is two caches: one clears, the other
serves yesterday. Both have a Redis driver, and neither announces that it is not using it —
which is the actual failure mode, since every page still works.

- **Cache** — configure the `redis` adapter. `Cache` falls back down the adapter list when
  the one it was asked for cannot be reached, so the settings can say `redis` while the
  cache is on local disk. That is what `CacheBackendCheck` is for: it reports **degraded**
  when the running backend is not the configured one, and it is the check to watch before
  the second node goes live.
- **Write spool** — set the driver to `redis`. It falls back to the file on a Redis that
  will not take the row, because making the request wait for the database is worse than a
  local file — so the same caveat applies: verify the driver in use rather than the one in
  settings.

### Database

Read/write splitting is built in and automatic. Declare the two roles in the `database`
settings block:

```php
'database' => [
    'write' => ['hostname' => 'db-primary', 'user' => 'app', 'password' => '…', 'database' => 'myapp'],
    'read'  => ['hostname' => 'db-replica', 'user' => 'app', 'password' => '…', 'database' => 'myapp'],
],
```

Every statement is then routed by its first keyword — `SELECT`, `SHOW`, `EXPLAIN`, `DESC`
and `DESCRIBE` to the read link, everything else to the write link — so application code
does not choose, and nothing that already works has to change. Each link is a separate
session: the configured time zone and collation are applied to both, and a link a failed
statement has shown to be gone is replaced on its own without taking the other one with it.

What this does not give you is **failover**: the two hostnames are configuration, not a
cluster, and a primary that is down is an outage rather than a promotion. That belongs to
whatever is in front of the database — a proxy, a managed service, a virtual IP — and the
framework is content to be pointed at it. Replica lag is the other thing to know about:
a write followed immediately by its own `SELECT` can read the state before the write, so
anything that must read its own write needs to stay on one connection.

See [Database API](Pramnos_Database_API_Guide.md#readwrite-replicas) for the full
configuration.

## What this framework does not solve for you

One thing, stated plainly, because a guide that only lists what works is not a guide:

- **Deploy consistency.** Nothing here ensures two nodes are running the same commit. That
  is the deployment tool's job, and the symptom of getting it wrong — half the requests
  behaving like last week — is one nothing in the application can report, because each node
  answers correctly for the code it is running.

## Related

- [Workers & Daemons](Pramnos_Workers_And_Daemons_Guide.md) — the same ground for background
  work, with the deployment recipes
- [Health checks](Pramnos_Health_Guide.md) — every check named above
- [Media](Pramnos_Media_Guide.md) — storage disks in detail
