---
use_cases:
  - Pointing an uptime monitor at an application
  - Writing a check for a dependency the framework knows nothing about
  - Working out why a health endpoint reports degraded or notice
  - Finding out why an authorization server answers pages but refuses tokens
  - Reading the health report from the command line or from CI
---

# Health checks

A health check answers one question: **is this dependency usable right now?** The
framework collects the answers, decides an overall status from the worst of them,
and exposes that over HTTP and on the command line.

**Classes:** `Pramnos\Health\HealthRegistry`, `Pramnos\Health\HealthCheck`,
`Pramnos\Health\HealthCheckResult`, `Pramnos\Health\HealthStatus`

---

## What you get without writing anything

Every application registers these during `init()`:

| Check | Reports |
|---|---|
| `database` | Reachable, with latency, driver and server version. Skipped entirely when the connection failed at boot, so you get one error rather than two. |
| `disk_space` | Free space and percentage used on the application root. |
| `memory_limit` | Peak usage against `memory_limit`. |
| `cache` | Whether the cache is on the store it was **configured** for. `degraded` when it fell back — the application works, on the wrong store. |
| `hypertables` | Whether a declared hypertable is one, and whether a continuous aggregate is one. `degraded` when the catalogue disagrees with the declaration. |
| `site_url` | The site's public root, and where it came from. `degraded` while it is only being inferred from the request. |
| `session_storage` | Which store PHP keeps sessions in. `notice` on `files`, which is local to one machine — correct on one server, and the quietest failure on two. |

With the `authserver` feature enabled, one more is registered by
`AuthServerServiceProvider`:

| Check | Reports |
|---|---|
| `signing_keys` | The RSA pair can be read, parsed, and actually signs and verifies. See [Signing keys](#signing-keys) below. |

Redis has a check too — `Pramnos\Health\Checks\RedisConnectivityCheck` — which is
not registered by default because not every application uses Redis. Register it
yourself if yours does.

!!! note "Why `cache` is degraded rather than down"

    `Cache` walks down to the next adapter when the configured one cannot be reached,
    which is correct: a cache that cannot connect must not take the site down. What
    was missing was anybody being told. An application whose PHP image lacks the
    `redis` extension runs on local disk with `redis` in its settings, `redis` in its
    compose file and Redis on its bill — and passes every test. Invalidation is then
    per-server, so a two-node deployment serves whatever the node that was not asked
    still holds.

    The check names both stores (`Running on file, configured for redis`) and hints at
    the missing extension, because the container is almost always up and the extension
    is almost always the answer. It reports `degraded`: the site is working, and a
    check that pages somebody for a working site is a check that gets muted.

!!! note "Why `session_storage` is a `notice` on `files`"

    `files` is PHP's default and is right on one machine: free, no dependency, nothing to
    configure. On two it is the quietest failure in a deployment — a visitor whose next
    request lands on the other node has no session, so they are signed out at random on a
    site that is otherwise working, and it reads as an expiry, a cookie problem, a
    `SameSite` mistake. Everything except a load balancer.

    Nothing else reports it, which is the argument for a check: the application works,
    every test passes, and the failure exists only in a topology the developer's machine
    does not have.

    `APP_SESSION_HANDLER=redis` in `.env` (or `'session' => ['handler' => 'redis']` in
    `app/config/app.php`) turns it green — pointed at **a Redis this application
    controls**. With no path it reuses the cache's host, and on a shared server, where one
    Redis serves every vhost, that puts session ids somewhere every other site on the
    machine can read. A session id is an account. Use a dedicated instance, or at least a
    separate database with its own credentials.

    Sticky sessions at the load balancer are the other answer, and this check cannot see
    them — if that is your arrangement, this one stays a notice deliberately.

    **It is a `notice`, not `degraded`, and that matters**: a single-server installation is
    correct, and answering 503 for it would page somebody for a working site for ever.

    The check names the store either way, because the common way to get this wrong is not
    leaving it on files but pointing it at a Redis the other nodes do not use.

!!! note "Why `site_url` is degraded when nothing is configured"

    A web request almost always infers a usable root, so the site works and nothing
    reports a problem. The process that cannot infer one is the scheduler: cron has no
    `Host` header, so a task putting a public URL into an email, a webhook payload or a
    feed builds `http:///uploads/x.jpg` — a string that reads as a bug in the caller and
    is a missing setting.

    Set `APP_URL` in `.env` (or `'site_url'` in `app/config/app.php`) and the check goes
    green. It reports the resolved address either way, because the common mistake is not
    leaving it unset but setting it to the wrong thing — a staging host copied into
    production, or `http` on a site behind a TLS-terminating proxy. One line of output
    showing the address the application believes in is what makes that visible.

    See [`SiteUrl`](Pramnos_Routing_Guide.md#surl-is-the-scripts-base-not-the-site-root)
    for why `sURL` cannot answer this.

---

## The endpoints

```
GET /health          HTML dashboard          (sign-in required)
GET /health/check    Full JSON report        (public)
GET /health/status   Flattened verdict       (public — the safe monitor URL)
GET /health/phpinfo  phpinfo()               (usertype >= 90)
```

Both JSON endpoints are deliberately public, because a monitor calls them with no
credentials, and both answer with the status in the body **and** in the HTTP code.

`/health/check` is the full report:

```json
{
  "status": "ok",
  "checks": {
    "database": { "status": "ok", "name": "database", "message": "Reachable", "details": { "latency_ms": 4.7 } }
  }
}
```

| Overall status | HTTP |
|---|---|
| `ok` | 200 |
| `notice` | 200 |
| `degraded` | 503 |
| `down` | 503 |

`degraded` answering 503 is a decision worth knowing about: a monitor that only
looks at the status code treats reduced capacity as an outage. That is the safer
default — a cache that has stopped working is not something to discover a week
later — but if you want the two distinguished, read `status` from the body.

**`notice` exists because that default was wrong for one category.** "Correct here,
and would not be everywhere" is a real answer — sessions on local files are right on
one machine — and it had to borrow `degraded`, which pages. A correct single-server
installation therefore answered 503 to the uptime monitor this endpoint exists for.
`notice` appears in the JSON, in the table and on the badge, and does not change the
status code.

### `/health/status` — when the full report says too much

```json
{ "status": "healthy", "timestamp": "2026-08-25T14:46:08+00:00", "service": "My App" }
```

Same verdict, three keys. When something is wrong it adds the **names** of the
failing checks and nothing else:

```json
{ "status": "unhealthy", "timestamp": "…", "service": "My App", "errors": ["redis", "disk_space"] }
```

Reach for it in two situations.

**The probe cannot read a nested document.** A load balancer health probe, a
status page widget or a shell script wants one field. Asking it to walk
`checks.*.status` is how a probe ends up parsing JSON with `grep`.

**The endpoint is reachable from the internet.** `/health/check` publishes
versions, drivers, paths and latencies in `details`. That is a fair trade on a
private network and not one to make publicly — a database version and a driver
name are a starting point for somebody who is looking for one. `/health/status`
gives away whether the application is well and where to look, which is what an
operator needs and all an attacker gets.

It does not re-probe anything: both endpoints read the same
`HealthRegistry::runAll()`. Two endpoints answering the same question from two
sets of probes is how they come to disagree.

Whichever you expose, keep detail out of what you add. If a check's `details`
would carry a hostname or a fragment of a credential, that is worth a second
thought even on `/health/check`.

---

## From the command line

```bash
php pramnos health:check                        # a table
php pramnos health:check --json                 # the same report as JSON
php pramnos health:check --only=database,redis  # just these
```

Exit codes make it usable in CI or a deploy gate: `0` all ok, `1` something
degraded, `2` something down. A `notice` is a success.

The command sees every check the application registered, not only the built-in
three — it boots the application first, so feature providers and your own
`Application::init()` registrations are all present.

---

## Writing a check

Implement `HealthCheck`. Two methods, and one rule.

```php
use Pramnos\Health\HealthCheck;
use Pramnos\Health\HealthCheckResult;

class PaymentGatewayCheck implements HealthCheck
{
    public function getName(): string
    {
        return 'payment_gateway';
    }

    public function run(): HealthCheckResult
    {
        try {
            $latency = $this->ping();
        } catch (\Throwable $e) {
            return HealthCheckResult::down($this->getName(), 'Unreachable: ' . $e->getMessage());
        }

        if ($latency > 2.0) {
            return HealthCheckResult::degraded(
                $this->getName(),
                'Responding slowly',
                ['latency_s' => $latency]
            );
        }

        return HealthCheckResult::ok($this->getName(), 'Reachable', ['latency_s' => $latency]);
    }
}
```

**The rule: `run()` must not throw.** A check that raises takes the whole report
with it, so the one dependency you were worried about brings down the endpoint
you were going to use to find out about it. Catch everything and return `down()`.

**The name is a contract.** The JSON report is keyed by it, so a monitor or
dashboard reading `checks.payment_gateway` breaks if you rename it. Use a stable
`snake_case` identifier.

### Registering it

```php
// In Application::init(), after parent::init()
\Pramnos\Health\HealthRegistry::register(new PaymentGatewayCheck());
```

Or, if the check belongs to a feature, in that feature's service provider
`boot()` — which is how `signing_keys` arrives. `register()` is keyed by name and
idempotent, so registering the same name twice replaces rather than duplicates;
that is also how an application overrides a built-in check with its own.

### Choosing a status

- **`down`** — the application cannot do its job. Somebody should be woken up.
- **`degraded`** — it works, at reduced capacity or with a problem that will
  become an outage if ignored. A cache that is unreachable, a disk at 95%, a key
  that is smaller than it should be.
- **`notice`** — **correct here, and would not be everywhere.** Sessions on local
  files; a single-node cache on a single node. It does not change the HTTP code and
  does not fail `health:check`.
- **`ok`** — with `details` carrying whatever an operator would want from a green
  result. A latency figure or a key size in a passing check is what tells someone
  the trend before it crosses a threshold.

**The test for `notice`** is whether there is an installation where this exact answer
is the *correct* one. Sessions on files passes it. An unset `APP_URL` does not — every
URL built outside a request is wrong on one server exactly as much as on five, so
`site_url` stays `degraded`.

Getting this wrong in the direction of severity is not a harmless over-report. A check
that pages for a correct installation is a check somebody mutes, and a muted check is
the one that was going to tell you about the real thing.

---

## Signing keys

This one deserves its own section, because it is the check that exists for a
failure the others cannot see.

An authorization server whose private key has gone missing answers every page
normally. The database is fine, the disk is fine, memory is fine, and every
`/oauth/token` request returns a 500. `/health/check` reported `ok` on exactly
that server.

So `signing_keys` does not ask whether the files exist. It walks the states in
which they exist and the server still cannot issue a usable token:

| State | Result |
|---|---|
| `openssl` not loaded, or the OAuth2 library missing | down |
| A key file missing, unreadable, or a directory | down — and it says *which* half |
| A key file present but unparseable (truncated write, mangled PEM) | down |
| Both keys valid, but from **different pairs** | down |
| A matching pair below 2048 bits | degraded |
| A matching pair, 2048 bits or more | ok, with `bits` in the details |

The mismatched-pair case is why the check signs and verifies a constant rather
than stopping at file tests. Both files parse, both are real keys, every file
test passes — and no token this server issues can be verified by anybody, so the
failure appears in *somebody else's* application. One small signature rules it
out.

The paths come from `OAuth2ServerFactory::defaultPrivateKeyPath()` and
`defaultPublicKeyPath()`, so the check always reports on the keys the server
actually signs with. Pass explicit paths to the constructor if yours live
elsewhere:

```php
HealthRegistry::register(new \Pramnos\Auth\Health\SigningKeysCheck(
    '/etc/keys/oauth-private.pem',
    '/etc/keys/oauth-public.pem'
));
```

---

## Related

- [Console](Pramnos_Console_Guide.md) — the `health:check` command among the rest
- `/.well-known/health` — an authserver-only endpoint that reads the same
  registry and answers in `ok` / `error` per component; see
  [Third-Party Integration](Pramnos_AuthServer_Integration_Guide.md)
- [Third-Party Integration](Pramnos_AuthServer_Integration_Guide.md) — what the
  signing keys are used for
- [Redis](Pramnos_Redis_Guide.md) — registering the Redis connectivity check
