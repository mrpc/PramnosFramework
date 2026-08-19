---
use_cases:
  - Caching a value, a query result or a rendered fragment
  - Choosing or configuring a cache backend
  - Invalidating cached data correctly
  - Diagnosing stale or missing cache entries
---

# Pramnos Cache System Guide

## Overview

The Pramnos Framework includes a comprehensive caching system that supports multiple backends and provides a unified interface for all caching operations. The cache system is designed to improve application performance by storing frequently accessed data in memory or on disk.

## Supported Cache Backends

### 1. Redis (Recommended)
- **Best for**: Production environments, distributed applications
- **Features**: Persistence, clustering, advanced data structures
- **Requirements**: PHP Redis extension, Redis server

### 2. Memcached
- **Best for**: High-performance distributed caching
- **Features**: Distributed memory caching, high throughput
- **Requirements**: PHP Memcached extension, Memcached server

### 3. Memcache (Legacy)
- **Best for**: Older systems requiring Memcache compatibility
- **Features**: Basic memory caching
- **Requirements**: PHP Memcache extension, Memcache server

### 4. File-based Cache
- **Best for**: Development, shared hosting, simple applications
- **Features**: No external dependencies, persistent storage
- **Requirements**: Writable cache directory

## Configuration

### Basic Configuration

```php
// app/config/cache.php
return [
    'method' => 'redis',        // redis, memcached, memcache, file
    'hostname' => 'localhost',  // Cache server hostname
    'port' => 6379,            // Cache server port
    'database' => 0,           // Redis database index
    'password' => null,        // Authentication password
    'prefix' => 'myapp_'       // Cache key prefix
];
```

### Application Settings Integration

The cache system automatically loads configuration from application settings:

```php
// In your application configuration
$settings = \Pramnos\Application\Settings::getInstance();
$cacheConfig = $settings->getSetting('cache');
```

## Basic Usage

### Creating Cache Instances

```php
// Get default cache instance — uses the configured backend
$cache = \Pramnos\Cache\Cache::getInstance();

// Get cache instance with specific category and extension
$cache = \Pramnos\Cache\Cache::getInstance('user_data', 'user', 'redis');

// Get cache instance with custom settings
$cache = \Pramnos\Cache\Cache::getInstance('sessions', 'session', 'file', [
    'cacheDir' => '/custom/cache/path',
    'prefix' => 'session_'
]);
```

**Omit the method unless you mean it.** The third argument overrides the
application's `cache.method` setting for that instance. Leave it out — or pass
`''` — and you get the store the application is configured to use, which is what
almost every caller wants. Name a backend only when this particular cache must
live somewhere other than the configured one (a file cache for something you
want to survive a Redis flush, for example).

> **Corrected 2026-08-20.** `getInstance()` declared `$method = 'memcached'`, which is
> not a default but an answer: the constructor reads the `cache` setting first and then
> lets a non-empty method argument overwrite it, so **every caller that did not name a
> backend asked for memcached** — the service provider, `Factory::getCache()`, the view
> cache, the SQL cache, the DevPanel cache screen. On an installation configured for
> Redis with no memcached to connect to, the fallback chain walked those callers down to
> the file adapter: the process ended up with a private on-disk cache sharing nothing
> with the store the rest of the application used, and the one screen that exists to show
> what the cache holds described that empty file store. Passing a method still wins, so
> nothing that named a backend changes.

### Basic Operations

```php
// Save data to cache
$cache->data = $userData;
$cache->timeout = 3600; // 1 hour
$success = $cache->save($userData, 'user_123');

// Load data from cache
$userData = $cache->load('user_123');

// Check if cache exists and is valid
if ($userData !== false) {
    // Use cached data
    echo "Welcome back, " . $userData['name'];
} else {
    // Cache miss - load from database
    $userData = $database->loadUser(123);
    $cache->save($userData, 'user_123');
}

// Delete specific cache entry
$cache->delete('user_123');

// Clear entire category
$cache->clear('user');
```

## Advanced Usage

### Categories and Organization

Categories help organize cache entries and enable bulk operations:

```php
// User-related cache
$userCache = \Pramnos\Cache\Cache::getInstance('users', 'user');
$userCache->save($userData, $userId);

// Session cache
$sessionCache = \Pramnos\Cache\Cache::getInstance('sessions', 'session');
$sessionCache->save($sessionData, $sessionId);

// Product cache
$productCache = \Pramnos\Cache\Cache::getInstance('products', 'product');
$productCache->save($productData, $productId);

// Clear all user cache
$userCache->clear('users');

// Clear all cache
$cache->clear('');
```

> **Corrected 2026-08-16.** The three instances above were, until this date, **the same
> object**. `getInstance()` held a single `static $instance` and returned it whatever
> category was asked for, so the first caller in the process decided the category for
> every later one — and in an application that boots providers, the first caller is
> `CacheServiceProvider`, which asks for none.
>
> Since `$this->category` is what goes into the cache key, and `save()` has no category
> parameter at all, the effect was that **categories were accepted and discarded**:
> `View::cache()` believed it wrote under `views`, so `cache:clear --category=views`
> never matched a view fragment, and two subsystems asking for different categories
> shared one namespace where a key collision is possible rather than prevented.
>
> There is now one instance per `(category, extension, method)`. Existing entries were
> written under the wrong key and will miss once, which for a cache is the correct
> outcome rather than a migration.

**Categories are a namespace, not a label.** Two entries with the same id in different
categories are different entries, and `clear($category)` removes one category without
touching the others. If you want a value shared between subsystems, give them the same
category deliberately rather than relying on them colliding.

### Cache with Timeouts

```php
// Short-term cache (5 minutes)
$cache->timeout = 300;
$cache->save($temporaryData, 'temp_data');

// Long-term cache (24 hours)
$cache->timeout = 86400;
$cache->save($staticData, 'static_data');

// Permanent cache (until manually cleared)
$cache->timeout = 0;
$cache->save($permanentData, 'permanent_data');
```

### Conditional Caching

```php
// Enable/disable caching dynamically
$cache->caching = env('CACHE_ENABLED', true);

if ($cache->caching) {
    $data = $cache->load($key);
    if ($data === false) {
        $data = $this->generateExpensiveData();
        $cache->save($data, $key);
    }
} else {
    $data = $this->generateExpensiveData();
}
```

## Cache Adapters

### Using Different Adapters

```php
// Redis adapter
$redisCache = new \Pramnos\Cache\Cache('category', 'extension', 'redis', [
    'hostname' => 'redis.example.com',
    'port' => 6379,
    'database' => 2,
    'password' => 'secret'
]);

// File adapter with custom directory
$fileCache = new \Pramnos\Cache\Cache('category', 'extension', 'file', [
    'cacheDir' => '/var/cache/myapp'
]);

// Memcached with persistent connection
$memcachedCache = new \Pramnos\Cache\Cache('category', 'extension', 'memcached', [
    'hostname' => 'memcached.example.com',
    'port' => 11211,
    'persistentId' => 'myapp'
]);
```

### Adapter-Specific Features

#### Redis Features

```php
$cache = new \Pramnos\Cache\Cache('data', 'app', 'redis');

// Access Redis connection directly
$redis = $cache->getAdapter()->getConnection();

// Use Redis-specific commands
$redis->expire('key', 3600);
$redis->exists('key');
```

#### File Cache Features

```php
$cache = new \Pramnos\Cache\Cache('data', 'app', 'file');

// Cleanup expired files
$cache->getAdapter()->cleanup();

// Get cache statistics
$stats = $cache->getStats();
echo "Cache entries: " . $stats['items'];
echo "Categories: " . $stats['categories'];
```

## Performance Optimization

### Fallback Strategy

The cache system automatically falls back to less optimal but available methods:

```
Redis → Memcached → Memcache → File
```

```php
// This will try Redis first, then fall back to Memcached, then File
$cache = \Pramnos\Cache\Cache::getInstance('data', 'app', 'redis');
```

An unrecognised method name — a typo in a settings file — lands on the file
adapter too, by the same route.

**Every downgrade is logged at `warning` level**, once per process per
transition:

```
Cache: falling back from "redis" to "memcached" - could not connect to 127.0.0.1:6379.
```

This matters more than it looks. A cache that silently changes store is a bug
with no symptom of its own: a value written to Redis and read back from a file
store is indistinguishable from an expiry, and the application keeps answering —
from a per-process cache it believes is shared. The log line is the only place
that difference is visible, so treat one in production as a broken cache rather
than as noise.

**Two properties, deliberately:**

| Property | Meaning |
|---|---|
| `$cache->method` | The store the instance **ended up with**. Follows the fallback chain, and always matches `getStats()['method']`. |
| `$cache->requestedMethod` | The store that was **asked for**, before any fallback. |

Read `->method` when you want to know where the data actually is — a diagnostic
screen printing the requested name over the numbers of a different store is
exactly the report that hides this problem. Compare the two when you want to know
whether a fallback happened at all:

```php
if ($cache->method !== $cache->requestedMethod) {
    // Asked for one store, running on another.
}
```

### Cache Key Management

```php
// Use descriptive, hierarchical keys
$cache->save($userData, 'user_profile_' . $userId);
$cache->save($userSettings, 'user_settings_' . $userId);
$cache->save($userPermissions, 'user_permissions_' . $userId);

// Group related data
$cache->category = 'user_' . $userId;
$cache->save($profileData, 'profile');
$cache->save($settingsData, 'settings');
$cache->save($permissionsData, 'permissions');
```

### Batch Operations

```php
// Cache multiple related items
$users = $this->database->getUsers();
foreach ($users as $user) {
    $cache->save($user, 'user_' . $user['id']);
}

// Clear related caches
$cache->clear('user_' . $userId); // Clear all user-related cache
```

## Flat-Key Caching (FlatCache)

`Cache` / `SimpleCache` are **category-based**: the key you pass is mangled by
`_generateCacheName()` (sanitised, with the prefix, category and extension folded
into the physical key), and PSR-16's `SimpleCache` additionally **rejects** keys
containing the reserved characters `{}()/\@:`.

When an application addresses the cache with its own **flat, explicit keys** —
especially colon-namespaced ones like `chat:messages:hash` or `radio:now_playing`
— use `Pramnos\Cache\FlatCache` instead. It is a PSR-16 cache that stores and
reads the key **verbatim** under a fixed prefix, and is **backend-agnostic**: it
works over any cache adapter, exactly like the category cache.

```php
use Pramnos\Cache\FlatCache;
use Pramnos\Cache\Adapter\RedisAdapter;
use Pramnos\Cache\Adapter\ArrayAdapter;

// Production: Redis-backed, keyed under "app:", colon keys kept verbatim.
$cache = new FlatCache(new RedisAdapter('127.0.0.1', 6379, 0, null, 'app:'), 'app:');

$cache->set('chat:messages:hash', $hash, 300);   // stored at app:chat:messages:hash
$hash = $cache->get('chat:messages:hash');        // arrays/objects round-trip
$cache->has('radio:now_playing');
$cache->delete('chat:messages:hash');

// Tests: swap in the in-memory adapter — same class, no live server.
$cache = new FlatCache(new ArrayAdapter('app:'), 'app:');
```

Choosing between them:

| Need | Use |
|------|-----|
| Cache a computed value under a logical id, grouped in categories | `Cache` / `SimpleCache` |
| Full control of the exact (possibly colon-namespaced) key | `FlatCache` |

`FlatCache` implements `Psr\SimpleCache\CacheInterface`, so it drops into any
PSR-16-aware library. Serialisation and TTL are delegated to the adapter. A
stored boolean `false` is reported as a miss (adapters signal "not found" with
`false`); wrap it in an array if you must distinguish it.

### Atomic counters (increment / decrement / counter)

Rate limits, failed-login trackers, spam-violation tallies and monotonic epoch
markers are **counters**, not cached values: they need atomic updates under
concurrency and a bare-integer representation. `FlatCache` exposes a dedicated
counter capability for them, separate from the value round-trip above:

```php
// Sliding-window rate limiter: +1 and (re)set a 900s TTL in one atomic step.
$attempts = $cache->increment("login_attempts:{$ip}", 1, 900); // returns the new total
if ($attempts > 5) { /* locked out */ }

$cache->counter("login_attempts:{$ip}"); // read current value (0 if absent, key NOT created)
$cache->decrement('slots_free');          // negative deltas via decrement()
$cache->delete("login_attempts:{$ip}");   // reset on success
```

Semantics:

- `increment(string $key, int $by = 1, null|int|\DateInterval $ttl = null): int`
  adds `$by` and returns the **new** total; when `$ttl` is given the expiry is
  reset on every call (a sliding window).
- `decrement(...)` is the mirror image.
- `counter(string $key): int` reads the current value — `0` when absent, and it
  does **not** create the key (unlike `increment($key, 0)` would).

A counter key is stored as a **bare integer**, distinct from the serialised
`{data,time}` envelope that `set()`/`get()` use — so **never** mix the two on the
same key (read a counter with `counter()`, never `get()`).

Backend support is layered so it is fully **backwards compatible**:

- `AdapterInterface` is **unchanged** — existing third-party adapters keep working.
- `AbstractAdapter` provides a concrete, non-atomic default (`counter()` +
  `save()`), so every adapter that extends it gains the capability for free.
- `RedisAdapter` overrides it with native `INCRBY` / `DECRBY` + `EXPIRE`, making
  it genuinely atomic across processes.
- If you inject a bare `AdapterInterface` without these methods, `FlatCache`
  transparently falls back to a get+set emulation.

#### The same capability on the classic `Cache` object

`FlatCache` is the PSR-16-shaped front end. Code that holds a classic
`\Pramnos\Cache\Cache` — the middleware pipeline, for one — reaches the same
counters through two additions:

```php
if ($cache->supportsAtomicCounter()) {
    $count = $cache->increment($key, $ttl);   // int, or false on failure
}
```

Two differences from `FlatCache::increment()` are deliberate:

- **The expiry is fixed, not sliding.** It is applied by whichever call creates
  the key and is not refreshed afterwards. A sliding expiry never lets a busy
  key die, so a rate-limit counter under sustained traffic would climb for ever
  and lock the client out permanently.
- **Failure is `false`, not a silent fallback.** `false` means "the counter did
  not work" — a dropped Redis connection, say — and is *not* zero. A caller
  doing security work must be able to tell the difference; reading a failure as
  an empty bucket opens the door at the moment the site is under strain.

`supportsAtomicCounter()` answers whether the backing adapter can do this at
all. Redis (`INCRBY`) and Memcached (`increment`, with creation through the
atomic `add`) can; Array and File cannot, and say so rather than pretending.

!!! danger "Do not probe with `method_exists()`"
    **Every** adapter has an `increment()` method — `AbstractAdapter` provides a
    working non-atomic default, so `method_exists($adapter, 'increment')` is
    true for the File adapter too. Asking that question is how the first version
    of this reported the File adapter as atomic and sent the rate limiter down
    the "exact under concurrency" path on a backend that loses increments.

    Ask `supportsAtomicCounter()`, which is `false` in `AbstractAdapter` and
    overridden to `true` only by the adapters that mean it.

A note on expiry, since the two entry points differ deliberately:
`FlatCache::increment()` keeps its documented **sliding** TTL, refreshed on
every call. The adapter's own default — and therefore `Cache::increment()` — is
the **fixed** window, because a sliding expiry on a rate-limit counter never
lets a busy key die: sustained traffic refreshes it on every hit, the count
climbs for ever, and the client is locked out permanently.

### Atomic swap (change detection / de-duplication)

`swap()` sets a key to a new value and returns the **previous** one in a single
atomic step — the classic "record only when it changed" primitive:

```php
// Record a play only when the now-playing track differs from the last one.
$previous = $cache->swap('radio:last_track', $display); // returns old value, sets new
if ($previous === $display) {
    return; // unchanged — skip
}
```

Like the counters, `swap()` is a **raw-key** operation: the value is stored
verbatim (not through the `{data,time}` envelope), so read it back with another
`swap()`, never `get()`. Backend support is layered identically —
`AbstractAdapter` provides a non-atomic read-then-write default, `RedisAdapter`
overrides it with native `GETSET` (genuinely atomic across processes), and
`AdapterInterface` is unchanged (fully backwards compatible).

### Structured operations (hash / list / enumeration)

Beyond opaque values, the flat cache exposes Redis-style structured operations —
for data that is a cache but needs a shape (a bounded recent-items list, a
field-addressed map) rather than one blob:

```php
// Hash (field-addressed map) — values may be any serialisable type.
$cache->hashSet('msg:hash', $id, ['user' => 'a', 'text' => 'hi'], ttl: 86400);
$cache->hashGet('msg:hash', $id);            // ['user' => 'a', 'text' => 'hi']
$cache->hashDelete('msg:hash', $id);
$cache->hashGetAll('msg:hash');              // [id => [...], ...]

// List (Redis LPUSH/LTRIM/LRANGE semantics) — a bounded recent-items cache.
$cache->listPush('recent', $item);           // prepend; returns new length
$cache->listTrim('recent', 0, 99);           // keep newest 100
$cache->listRange('recent', 0, -1);          // newest-first, decoded

$cache->expire('recent', 86400);             // (re)set TTL
$cache->keys('banned:*');                    // enumerate (logical keys)
```

Semantics:

- Field/element values are **serialised**, so arrays/objects round-trip (unlike
  the raw counters/swap). Read them back with the same structured methods.
- `listPush`/`listTrim`/`listRange` follow Redis LPUSH/LTRIM/LRANGE (newest-first,
  inclusive ranges, negative indices).
- `keys($pattern)` returns matching keys in the **logical** key-space (the cache
  prefix is stripped) and needs an enumeration-capable adapter; `RedisAdapter`
  uses a non-blocking `SCAN`, other adapters return an empty list.

Backend support is layered exactly like the counters: `AbstractAdapter` keeps the
whole structure under one key via load/save (non-atomic default), `RedisAdapter`
overrides with native `HSET`/`LPUSH`/`SCAN`, and `AdapterInterface` is unchanged.

## Integration Examples

### Model-Level Caching

```php
class UserModel extends \Pramnos\Application\Model
{
    private $cache;
    
    public function __construct($controller, $name = '')
    {
        parent::__construct($controller, $name);
        $this->cache = \Pramnos\Cache\Cache::getInstance('users', 'user');
    }
    
    public function load($userId)
    {
        // Try cache first
        $cacheKey = 'user_' . $userId;
        $userData = $this->cache->load($cacheKey);
        
        if ($userData === false) {
            // Cache miss - load from database
            $sql = $this->application->database->prepareQuery(
                "SELECT * FROM users WHERE id = %d", $userId
            );
            $result = $this->application->database->query($sql);
            
            if ($result->numRows > 0) {
                $userData = $result->fields;
                
                // Cache for 1 hour
                $this->cache->timeout = 3600;
                $this->cache->save($userData, $cacheKey);
            }
        }
        
        return $userData;
    }
    
    public function update($userId, $data)
    {
        // Update database
        $this->updateDatabase($userId, $data);
        
        // Invalidate cache
        $this->cache->delete('user_' . $userId);
    }
}
```

### View-Level Caching

```php
class ProductView extends \Pramnos\Application\View
{
    public function display($template = 'default')
    {
        $cache = \Pramnos\Cache\Cache::getInstance('views', 'product');
        $cacheKey = 'product_list_' . $this->page . '_' . $this->category;
        
        $html = $cache->load($cacheKey);
        
        if ($html === false) {
            // Generate HTML
            $html = $this->renderTemplate($template);
            
            // Cache for 30 minutes
            $cache->timeout = 1800;
            $cache->save($html, $cacheKey);
        }
        
        return $html;
    }
}
```

### API Response Caching

```php
class ProductController extends \Pramnos\Application\Controller
{
    public function getProducts()
    {
        $cache = \Pramnos\Cache\Cache::getInstance('api', 'products');
        $cacheKey = 'products_' . md5(serialize($_GET));
        
        $response = $cache->load($cacheKey);
        
        if ($response === false) {
            $products = $this->getModel('Product')->getList($_GET);
            $response = [
                'products' => $products,
                'total' => count($products),
                'timestamp' => time()
            ];
            
            // Cache API response for 15 minutes
            $cache->timeout = 900;
            $cache->save($response, $cacheKey);
        }
        
        return $this->response($response);
    }
}
```

## Debugging and Monitoring

### Cache Statistics

```php
$cache = \Pramnos\Cache\Cache::getInstance();

// Get cache statistics
$stats = $cache->getStats();
print_r($stats);
/* Output:
Array(
    [method] => redis
    [categories] => 15
    [items] => 1247
)
*/
```

### Testing Cache Connection

```php
$cache = \Pramnos\Cache\Cache::getInstance();

// Test cache connectivity
if ($cache->testConnection()) {
    echo "Cache is working properly";
} else {
    echo "Cache connection failed";
}
```

### Debugging Cache Issues

```php
// Enable cache debugging
$cache = \Pramnos\Cache\Cache::getInstance('debug', 'test');

// Test save/load cycle
$testData = ['test' => 'data', 'timestamp' => time()];
$cache->save($testData, 'test_key');

$loadedData = $cache->load('test_key');
if ($loadedData === $testData) {
    echo "Cache working correctly";
} else {
    echo "Cache issue detected";
}

// Check adapter details
$adapter = $cache->getAdapter();
echo "Using adapter: " . get_class($adapter);
```

## Best Practices

### 1. Use Appropriate Cache Keys

```php
// Good: Descriptive and hierarchical
$cache->save($data, 'user_profile_' . $userId);
$cache->save($data, 'product_details_' . $productId);
$cache->save($data, 'api_search_' . md5($searchQuery));

// Bad: Generic or collision-prone
$cache->save($data, 'data');
$cache->save($data, $id);
```

### 2. Set Appropriate Timeouts

```php
// Frequently changing data - short timeout
$cache->timeout = 300; // 5 minutes
$cache->save($liveData, $key);

// Relatively stable data - medium timeout
$cache->timeout = 3600; // 1 hour
$cache->save($userData, $key);

// Static data - long timeout
$cache->timeout = 86400; // 24 hours
$cache->save($configData, $key);
```

### 3. Handle Cache Failures Gracefully

```php
try {
    $data = $cache->load($key);
    if ($data === false) {
        $data = $this->loadFromDatabase($key);
        $cache->save($data, $key);
    }
} catch (\Exception $e) {
    // Cache failed - continue without caching
    \Pramnos\Logs\Logger::log('Cache error: ' . $e->getMessage());
    $data = $this->loadFromDatabase($key);
}
```

### 4. Use Categories for Organization

```php
// Organize by feature
$userCache = \Pramnos\Cache\Cache::getInstance('users', 'user');
$productCache = \Pramnos\Cache\Cache::getInstance('products', 'product');
$sessionCache = \Pramnos\Cache\Cache::getInstance('sessions', 'session');

// Clear by category when needed
$userCache->clear('users'); // Clear only user-related cache
```

### 5. Cache Invalidation Strategy

```php
class UserController extends \Pramnos\Application\Controller
{
    private function invalidateUserCache($userId)
    {
        $cache = \Pramnos\Cache\Cache::getInstance('users', 'user');
        
        // Clear specific user cache
        $cache->delete('user_profile_' . $userId);
        $cache->delete('user_settings_' . $userId);
        $cache->delete('user_permissions_' . $userId);
        
        // Clear related caches
        $cache->clear('user_' . $userId);
    }
    
    public function updateUser($userId, $data)
    {
        // Update database
        $this->updateUserInDatabase($userId, $data);
        
        // Invalidate cache
        $this->invalidateUserCache($userId);
    }
}
```

## Troubleshooting

### Common Issues

1. **Cache Not Working**
   - Check if the cache backend is running
   - Verify connection credentials
   - Ensure proper file permissions for file cache

2. **Performance Issues**
   - Monitor cache hit rates
   - Optimize cache key strategies
   - Consider cache distribution across servers

3. **Memory Issues**
   - Set appropriate timeouts
   - Implement cache size limits
   - Regular cache cleanup

### Error Handling

```php
$cache = \Pramnos\Cache\Cache::getInstance();

// Graceful degradation
if (!$cache->caching) {
    // Cache is disabled - work without cache
    $data = $this->loadFromSource();
} else {
    try {
        $data = $cache->load($key);
        if ($data === false) {
            $data = $this->loadFromSource();
            $cache->save($data, $key);
        }
    } catch (\Exception $e) {
        // Log error and continue
        \Pramnos\Logs\Logger::log('Cache error: ' . $e->getMessage());
        $data = $this->loadFromSource();
    }
}
```

## Advanced Cache Strategies

### Cache Invalidation Patterns

The Pramnos Cache system provides sophisticated invalidation strategies to ensure data consistency:

#### Tag-based Cache Invalidation

```php
// Cache with category tags for bulk invalidation
$userCache = \Pramnos\Cache\Cache::getInstance('users', 'user');
$productCache = \Pramnos\Cache\Cache::getInstance('products', 'product');

// Save related data
$userCache->save($userData, 'user_' . $userId);
$userCache->save($userProfile, 'profile_' . $userId);
$userCache->save($userSettings, 'settings_' . $userId);

// Invalidate all user-related cache at once
$userCache->clear('users'); // Clears all cache entries in 'users' category
```

#### Hierarchical Cache Keys

```php
// Organize cache keys hierarchically for precise invalidation
class OrderCache 
{
    private $cache;
    
    public function __construct()
    {
        $this->cache = \Pramnos\Cache\Cache::getInstance('orders', 'order');
    }
    
    public function cacheOrderData($userId, $orderId, $data)
    {
        // Cache at multiple levels for different access patterns
        $this->cache->save($data, "user_{$userId}_order_{$orderId}");
        $this->cache->save($data, "order_details_{$orderId}");
        
        // Cache order list for user
        $userOrders = $this->getUserOrders($userId);
        $userOrders[] = $data;
        $this->cache->save($userOrders, "user_{$userId}_orders_list");
    }
    
    public function invalidateUserOrders($userId)
    {
        // Clear specific user's order cache
        $this->cache->delete("user_{$userId}_orders_list");
        
        // Could also clear all user-specific order entries
        // This would require maintaining a list of order IDs per user
    }
}
```

### Advanced Backend Features

#### Redis-Specific Features

```php
$redisCache = \Pramnos\Cache\Cache::getInstance('advanced', 'redis', 'redis');

// Access Redis connection directly for advanced operations
if ($redisCache->getAdapter() instanceof \Pramnos\Cache\Adapter\RedisAdapter) {
    $redis = $redisCache->getAdapter()->getConnection();
    
    // Use Redis sets for complex data relationships
    $redis->sadd('user_sessions:' . $userId, $sessionId);
    $redis->expire('user_sessions:' . $userId, 3600);
    
    // Use Redis lists for queues
    $redis->lpush('notification_queue', json_encode($notificationData));
    
    // Use Redis sorted sets for leaderboards
    $redis->zadd('user_scores', $score, $userId);
}
```

#### Memcached Connection Pooling

```php
// Use persistent connections for better performance
$memcachedCache = new \Pramnos\Cache\Cache('sessions', 'session', 'memcached', [
    'hostname' => 'memcached.example.com',
    'port' => 11211,
    'persistentId' => 'app_persistent_pool'
]);
```

### Performance Monitoring and Statistics

#### Cache Performance Metrics

```php
class CacheMonitor 
{
    public function getCacheStatistics()
    {
        $caches = [
            'users' => \Pramnos\Cache\Cache::getInstance('users', 'user'),
            'products' => \Pramnos\Cache\Cache::getInstance('products', 'product'),
            'sessions' => \Pramnos\Cache\Cache::getInstance('sessions', 'session')
        ];
        
        $stats = [];
        foreach ($caches as $name => $cache) {
            $stats[$name] = $cache->getStats();
        }
        
        return $stats;
    }
    
    public function monitorCacheHealth()
    {
        $cache = \Pramnos\Cache\Cache::getInstance('health_check', 'monitor');
        
        $startTime = microtime(true);
        $testSuccess = $cache->testConnection();
        $responseTime = (microtime(true) - $startTime) * 1000; // ms
        
        return [
            'status' => $testSuccess ? 'healthy' : 'failed',
            'response_time_ms' => round($responseTime, 2),
            'timestamp' => time()
        ];
    }
}
```

### Cache Warming Strategies

#### Preemptive Cache Population

```php
class CacheWarmup 
{
    public function warmupUserCache($userId)
    {
        $cache = \Pramnos\Cache\Cache::getInstance('users', 'user');
        
        // Load and cache frequently accessed user data
        $userData = $this->loadUserFromDatabase($userId);
        $cache->timeout = 3600; // 1 hour
        $cache->save($userData, 'user_' . $userId);
        
        // Warm up related data
        $userSettings = $this->loadUserSettingsFromDatabase($userId);
        $cache->save($userSettings, 'settings_' . $userId);
        
        $userPermissions = $this->loadUserPermissionsFromDatabase($userId);
        $cache->timeout = 1800; // 30 minutes for permissions
        $cache->save($userPermissions, 'permissions_' . $userId);
    }
    
    public function warmupPopularProducts()
    {
        $cache = \Pramnos\Cache\Cache::getInstance('products', 'product');
        
        $popularProducts = $this->getPopularProductIds();
        foreach ($popularProducts as $productId) {
            $productData = $this->loadProductFromDatabase($productId);
            $cache->timeout = 7200; // 2 hours for popular products
            $cache->save($productData, 'product_' . $productId);
        }
    }
}
```

### Multi-Layer Caching

#### Implementing Cache Layers

```php
class LayeredCache 
{
    private $l1Cache; // Fast, small cache (Redis)
    private $l2Cache; // Larger, slower cache (File)
    
    public function __construct()
    {
        $this->l1Cache = \Pramnos\Cache\Cache::getInstance('l1', 'memory', 'redis');
        $this->l2Cache = \Pramnos\Cache\Cache::getInstance('l2', 'disk', 'file');
    }
    
    public function get($key)
    {
        // Try L1 cache first
        $data = $this->l1Cache->load($key);
        if ($data !== false) {
            return $data;
        }
        
        // Fall back to L2 cache
        $data = $this->l2Cache->load($key);
        if ($data !== false) {
            // Promote to L1 cache
            $this->l1Cache->timeout = 300; // 5 minutes in L1
            $this->l1Cache->save($data, $key);
            return $data;
        }
        
        return false;
    }
    
    public function set($key, $data, $timeout = 3600)
    {
        // Save to both layers
        $this->l1Cache->timeout = min(300, $timeout); // Max 5 min in L1
        $this->l1Cache->save($data, $key);
        
        $this->l2Cache->timeout = $timeout;
        $this->l2Cache->save($data, $key);
    }
}
```

### Error Recovery and Fallback

#### Graceful Degradation Patterns

```php
class RobustCache 
{
    private $primaryCache;
    private $fallbackCache;
    private $logger;
    
    public function __construct()
    {
        $this->primaryCache = \Pramnos\Cache\Cache::getInstance('primary', 'app', 'redis');
        $this->fallbackCache = \Pramnos\Cache\Cache::getInstance('fallback', 'app', 'file');
        $this->logger = \Pramnos\Logs\Logger::getInstance();
    }
    
    public function getWithFallback($key, $dataLoader = null)
    {
        try {
            $data = $this->primaryCache->load($key);
            if ($data !== false) {
                return $data;
            }
        } catch (\Exception $e) {
            $this->logger->logError('Primary cache failed: ' . $e->getMessage());
        }
        
        try {
            $data = $this->fallbackCache->load($key);
            if ($data !== false) {
                return $data;
            }
        } catch (\Exception $e) {
            $this->logger->logError('Fallback cache failed: ' . $e->getMessage());
        }
        
        // No cache available, load fresh data
        if ($dataLoader && is_callable($dataLoader)) {
            $data = $dataLoader();
            $this->setWithFallback($key, $data);
            return $data;
        }
        
        return false;
    }
    
    private function setWithFallback($key, $data, $timeout = 3600)
    {
        try {
            $this->primaryCache->timeout = $timeout;
            $this->primaryCache->save($data, $key);
        } catch (\Exception $e) {
            $this->logger->logError('Primary cache save failed: ' . $e->getMessage());
        }
        
        try {
            $this->fallbackCache->timeout = $timeout;
            $this->fallbackCache->save($data, $key);
        } catch (\Exception $e) {
            $this->logger->logError('Fallback cache save failed: ' . $e->getMessage());
        }
    }
}
```

### Development and Debugging Tools

#### Cache Inspector

```php
class CacheInspector 
{
    public function dumpCacheContents($category = '')
    {
        $cache = \Pramnos\Cache\Cache::getInstance($category, 'debug');
        
        $stats = $cache->getStats();
        echo "<h3>Cache Statistics</h3>\n";
        echo "<pre>" . print_r($stats, true) . "</pre>\n";
        
        $categories = $cache->getAdapter()->getCategories();
        echo "<h3>Available Categories</h3>\n";
        echo "<pre>" . print_r($categories, true) . "</pre>\n";
    }
    
    public function validateCacheIntegrity()
    {
        $cache = \Pramnos\Cache\Cache::getInstance('integrity_test', 'test');
        
        $testCases = [
            'string_data' => 'Hello World',
            'array_data' => ['key1' => 'value1', 'key2' => 'value2'],
            'object_data' => (object)['property' => 'value'],
            'numeric_data' => 12345,
            'boolean_data' => true
        ];
        
        $results = [];
        foreach ($testCases as $key => $testData) {
            $cache->save($testData, $key);
            $retrieved = $cache->load($key);
            $results[$key] = [
                'original' => $testData,
                'retrieved' => $retrieved,
                'match' => $testData === $retrieved
            ];
            $cache->delete($key);
        }
        
        return $results;
    }
}
```

## Production Optimization

### High-Performance Configuration

#### Redis Production Setup

```php
// Production Redis configuration
$productionCache = new \Pramnos\Cache\Cache('production', 'app', 'redis', [
    'hostname' => 'redis-cluster.example.com',
    'port' => 6379,
    'database' => 0,
    'password' => 'secure_redis_password',
    'prefix' => 'prod_app_'
]);

// Use appropriate timeouts for different data types
$productionCache->timeout = 86400; // 24 hours for static data
$productionCache->save($configData, 'app_config');

$productionCache->timeout = 300; // 5 minutes for dynamic data
$productionCache->save($userSession, 'session_' . $sessionId);
```

#### Memory Management

```php
class CacheMemoryManager 
{
    public function cleanupExpiredEntries()
    {
        $fileCache = \Pramnos\Cache\Cache::getInstance('cleanup', 'app', 'file');
        
        if ($fileCache->getAdapter() instanceof \Pramnos\Cache\Adapter\FileAdapter) {
            // File adapter has built-in cleanup method
            $fileCache->getAdapter()->cleanup();
        }
    }
    
    public function monitorMemoryUsage()
    {
        $cache = \Pramnos\Cache\Cache::getInstance('memory', 'monitor');
        $stats = $cache->getStats();
        
        $memoryUsage = [
            'cache_items' => $stats['items'],
            'cache_categories' => $stats['categories'],
            'php_memory_usage' => memory_get_usage(true),
            'php_memory_peak' => memory_get_peak_usage(true)
        ];
        
        return $memoryUsage;
    }
}
```

The Pramnos Cache system provides a robust, flexible foundation for application performance optimization while maintaining simplicity and reliability across different deployment environments. With these advanced patterns and strategies, you can build highly scalable and performant caching solutions that gracefully handle failures and provide optimal user experiences.

---

## Related Documentation

- **[Framework Guide](Pramnos_Framework_Guide.md)** - Core framework patterns and MVC architecture
- **[Database API Guide](Pramnos_Database_API_Guide.md)** - Database operations and query optimization
- **[Authentication Guide](Pramnos_Authentication_Guide.md)** - Caching user sessions and permissions
- **[Console Commands Guide](Pramnos_Console_Guide.md)** - CLI tools for cache management
- **[Logging System Guide](Pramnos_Logging_Guide.md)** - Cache performance monitoring and debugging
- **[Media System Guide](Pramnos_Media_Guide.md)** - Caching processed images and media files
- **[Internationalization Guide](Pramnos_Internationalization_Guide.md)** - Caching translated content and language data

---

For implementation examples and integration patterns, see the [Framework Guide](Pramnos_Framework_Guide.md) for guidance on using caching in controllers and models.

## Default flat cache from the ConnectionManager

`FlatCache::default()` returns a lazy, process-wide flat cache backed by a
`RedisAdapter` bound to the shared `Pramnos\Redis\ConnectionManager`
(host/port/database/password + per-install prefix). Configure the manager once in
bootstrap (`ConnectionManager::setInstance(...)`) and read the cache anywhere,
without wiring the adapter yourself:

```php
$cache = \Pramnos\Cache\FlatCache::default();
$cache->set('radio:now_playing', $data, 300);
```

`FlatCache::setDefault(?FlatCache)` overrides it (bootstrap wiring) or resets it
to rebuild (`setDefault(null)` — the test seam). Colon-namespaced keys are stored
verbatim under the install prefix, and the atomic counter + structured (hash/list/
expire/keys) operations are available on the returned instance.
