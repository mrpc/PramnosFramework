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
// Get default cache instance
$cache = \Pramnos\Cache\Cache::getInstance();

// Get cache instance with specific category and extension
$cache = \Pramnos\Cache\Cache::getInstance('user_data', 'user', 'redis');

// Get cache instance with custom settings
$cache = \Pramnos\Cache\Cache::getInstance('sessions', 'session', 'file', [
    'cacheDir' => '/custom/cache/path',
    'prefix' => 'session_'
]);
```

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
