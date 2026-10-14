---
use_cases:
  - Configuring the Redis connection manager
  - Sharing one Redis connection across cache, queue and broadcasting
  - Checking Redis health or key prefixing
---

# Pramnos Redis Guide

Redis is used across the framework as the backend of several capabilities — the
**cache** (`RedisAdapter`), the **broadcasting** backplane (`RedisDriver`) and the
**delayed queue** (`RedisQueueDriver`). Rather than each of these opening and
configuring its own connection, they all go through one place:

## `Pramnos\Redis\ConnectionManager`

The single source of Redis connections. It centralises `connect` / `auth` /
`select` (with fail-fast errors) and exposes:

- **`connection()`** — a shared, lazily-opened connection for ordinary commands
  (get/set, publish, sorted sets, hashes).
- **`newConnection()`** — a fresh, dedicated connection, required for a blocking
  `SUBSCRIBE` (a subscribed connection cannot be used for anything else).
- **`prefix()`** and config getters (`host()`/`port()`/`database()`/`password()`).
- **`ConnectionManager::forConfig($config)`** — the pooled manager for one endpoint
  (keyed on host, port, database and a hash of the password), so every caller naming
  the same server shares one manager and one socket. `resetPool()` forgets the pool
  (tests, or a process rebuilding its world).

`new ConnectionManager($config)` gives an object with a connection of its own, so two
callers naming the same server get two sockets. In a request that is invisible; in a
daemon it is one socket per caller for the life of the worker, and a worker that runs
out of file descriptors cannot autoload a class either. Long-running code that needs a
connection for an explicit endpoint should go through `forConfig()`.

Config keys: `host` (default `127.0.0.1`), `port` (6379), `database` (0), `password`,
`prefix` (`''`), `timeout` (connect timeout in seconds; 0 = phpredis default) and
`read_timeout` (`OPT_READ_TIMEOUT` in seconds).

```php
use Pramnos\Redis\ConnectionManager;

// Explicit config (e.g. from an application bootstrap):
$redis = new ConnectionManager([
    'host' => '127.0.0.1', 'port' => 6379, 'database' => 0,
    'password' => null, 'prefix' => 'myapp_',
]);
$redis->connection()->set('k', 'v');

// The shared default instance — resolved from native env vars
// (REDIS_HOST/PORT/DATABASE/PASSWORD/PREFIX) or a `redis` settings section:
ConnectionManager::getInstance()->connection();

// Applications that own their configuration set it once in bootstrap:
ConnectionManager::setInstance(new ConnectionManager($appRedisConfig));
```

A connection **factory** is injectable (second constructor argument) for tests
(no live server) or to reuse an existing connection.

## Drivers fall onto the manager

The cache, broadcasting and queue Redis drivers create their connections through
a `ConnectionManager` built from their own config, so there is one implementation
of connect/auth/select. Each honours its own configuration. The broadcasting
`RedisDriver` and the `RedisQueueDriver` also accept an injected connection factory
(second constructor argument) for full control; the cache `RedisAdapter` takes
host, port, database, password and prefix only.

To make broadcasting and the queue share a single connection, inject the same
factory (or point them at the shared instance) from your bootstrap.

## Health check

`Pramnos\Health\Checks\RedisConnectivityCheck` pings Redis through the manager
(the shared `getInstance()` by default, or one passed to its constructor) and reports
`ok` with the latency, or `down`. It is not registered by default, because not every
application uses Redis; register it in `Application::init()`:

```php
\Pramnos\Health\HealthRegistry::register(new \Pramnos\Health\Checks\RedisConnectivityCheck());
```

Its name in the report, and for `health:check --only`, is `redis`.

## Prefixing

The manager does **not** apply `OPT_PREFIX`; it exposes `prefix()` so callers
prefix keys explicitly and keys stay byte-predictable across installs.

## Capabilities derive from the ConnectionManager

The ConnectionManager is the single Redis connection source, and the Redis-backed
capabilities can be obtained pre-wired from it — configure the manager once in
bootstrap and use the capabilities anywhere:

- Cache: `FlatCache::default()` (see the Cache guide)
- Broadcast: `BroadcastingManager::instance()` (see the Realtime guide)
- Queue: `DelayedQueue::redis($namespace)` (see the Queue guide)

Each resolves `ConnectionManager::getInstance()` lazily, so an app that binds the
manager via `setInstance()` during bootstrap is always in effect. Broadcast and the
delayed queue publish over that instance's shared `connection()`. The cache adapter
(`RedisAdapter`) and a self-connecting `RedisQueueDriver` take the shared connection
of the pooled manager for their endpoint (`forConfig()`), so all cache categories
pointed at one server use one socket.
