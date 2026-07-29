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
of connect/auth/select. Each still honours its own configuration, and each still
accepts an injected connection factory for full control — so this is backwards
compatible.

To make the whole stack share a single connection, inject the same factory (or
point them all at the shared instance) from your bootstrap.

## Health check

`Pramnos\Health\Checks\RedisConnectivityCheck` pings Redis through the manager and
reports up/down, alongside the built-in database / disk / memory checks. Register
it with the `HealthRegistry` (or it is available to `health:check`).

## Prefixing

The manager does **not** apply `OPT_PREFIX`; it exposes `prefix()` so callers
prefix keys explicitly and keys stay byte-predictable across installs.

## Capabilities derive from the ConnectionManager

The ConnectionManager is the single Redis connection source, and the Redis-backed
capabilities can now be obtained pre-wired from it — configure the manager once in
bootstrap and use the capabilities anywhere:

- Cache: `FlatCache::default()` (see the Cache guide)
- Broadcast: `BroadcastingManager::instance()` (see the Realtime guide)
- Queue: `DelayedQueue::redis($namespace)` (see the Queue guide)

Each resolves `ConnectionManager::getInstance()` lazily, so an app that binds the
manager via `setInstance()` during bootstrap is always in effect. The cache adapter
keeps its own dedicated connection; broadcast/queue publish over the shared
`connection()`.
