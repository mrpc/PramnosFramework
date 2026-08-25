---
use_cases:
  - Pointing an uptime monitor at an application
  - Writing a check for a dependency the framework knows nothing about
  - Working out why a health endpoint reports degraded
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

With the `authserver` feature enabled, one more is registered by
`AuthServerServiceProvider`:

| Check | Reports |
|---|---|
| `signing_keys` | The RSA pair can be read, parsed, and actually signs and verifies. See [Signing keys](#signing-keys) below. |

Redis has a check too — `Pramnos\Health\Checks\RedisConnectivityCheck` — which is
not registered by default because not every application uses Redis. Register it
yourself if yours does.

---

## The endpoints

```
GET /health          HTML dashboard          (sign-in required)
GET /health/check    JSON report             (public — this is the monitor URL)
GET /health/phpinfo  phpinfo()               (usertype >= 90)
```

`/health/check` is the one to give an uptime monitor. It is deliberately public,
because a monitor calls it with no credentials, and it answers with the status in
both the body and the HTTP code:

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
| `degraded` | 503 |
| `down` | 503 |

Degraded answering 503 is a decision worth knowing about: a monitor that only
looks at the status code treats reduced capacity as an outage. That is the safer
default — a cache that has stopped working is not something to discover a week
later — but if you want the two distinguished, read `status` from the body.

Because the endpoint is public, keep detail out of anything you add to it. The
built-in checks put versions and paths in `details`, which is visible on
`/health/check`; that is a deliberate trade for a monitoring endpoint on a
private network, and it is worth a second thought before you put a hostname or a
credential fragment there.

---

## From the command line

```bash
php pramnos health:check                        # a table
php pramnos health:check --json                 # the same report as JSON
php pramnos health:check --only=database,redis  # just these
```

Exit codes make it usable in CI or a deploy gate: `0` all ok, `1` something
degraded, `2` something down.

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

### Choosing between degraded and down

- **`down`** — the application cannot do its job. Somebody should be woken up.
- **`degraded`** — it works, at reduced capacity or with a problem that will
  become an outage if ignored. A cache that is unreachable, a disk at 95%, a key
  that is smaller than it should be.
- **`ok`** — with `details` carrying whatever an operator would want from a green
  result. A latency figure or a key size in a passing check is what tells someone
  the trend before it crosses a threshold.

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
- [Third-Party Integration](Pramnos_AuthServer_Integration_Guide.md) — what the
  signing keys are used for
- [Redis](Pramnos_Redis_Guide.md) — registering the Redis connectivity check
