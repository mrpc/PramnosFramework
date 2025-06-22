# Pramnos Database API Guide

This guide explains how to properly use the Pramnos database API for database operations in the Pramnos MVC framework.

## Overview

The Pramnos framework provides a database abstraction layer that must be used for all database operations. The key principle is to **always use `prepareQuery()` for parameter binding, then `query()` for execution**.

## Database Access in Controllers

In Pramnos controllers, the database connection is accessed through the application instance:

```php
// CORRECT: Access database through application
$sql = $this->application->database->prepareQuery("SELECT * FROM users WHERE id = %d", $userId);
$result = $this->application->database->query($sql);

// INCORRECT: Direct access to database property
$sql = $this->application->database->prepareQuery("..."); // This will cause "Call to a member function on null" error
```

**Important**: Always use `$this->application->database` in controllers, not `$this->application->database`.

## Core Pattern

### The Two-Step Process

1. **Prepare Query**: Use `prepareQuery()` with printf-style formatting
2. **Execute Query**: Use `query()` to execute the prepared query

```php
// CORRECT: Pramnos pattern with printf-style formatting
$sql = $this->application->database->prepareQuery("SELECT * FROM users WHERE email = %s AND status = %d", $email, $status);
$result = $this->application->database->query($sql);

// INCORRECT: Don't use ? placeholders
$this->application->database->prepareQuery("SELECT * FROM users WHERE id = ?", [$userId]); // Wrong syntax!
```

### Printf-Style Format Specifiers

- `%s` - String values
- `%d` - Integer values  
- `%%` - Literal % character

**Note**: Only `%s` and `%d` are commonly used in the Pramnos framework.

## Result Handling

**CRITICAL**: Pramnos query results use different patterns for single vs multiple records:

### Single Record Queries
For queries that return a single record (e.g., `SELECT * FROM users WHERE id = %d` or `SELECT COUNT(*) as total`):

```php
$sql = $database->prepareQuery("SELECT * FROM users WHERE id = %d", $userId);
$result = $database->query($sql);

// Single record - NO fetch() needed, NO [0] index
if ($result->numRows > 0) {
    $userData = $result->fields; // Direct associative array of column => value
    $userName = $result->fields['name'];
    $userEmail = $result->fields['email'];
}

// COUNT queries (single result)
$sql = $database->prepareQuery("SELECT COUNT(*) as total FROM users");
$result = $database->query($sql);
$count = $result->fields['total']; // Direct access, NOT $result->fields[0]['total']
```

### Multiple Record Queries
For queries that return multiple records (e.g., `SELECT * FROM applications ORDER BY created_at`):

```php
$sql = $database->prepareQuery("SELECT * FROM applications WHERE user_id = %d ORDER BY created_at DESC", $userId);
$result = $database->query($sql);

// Multiple records - USE fetch() to iterate
$applications = [];
while ($result->fetch()) {
    $applications[] = $result->fields; // Each row as associative array
}
```

### Common Mistakes to Avoid

❌ **WRONG** - Never use `[0]` index for single records:
```php
// These patterns are INCORRECT for Pramnos framework:
$user = $result->fields[0];           // Wrong!
$count = $result->fields[0]['total']; // Wrong!
```

✅ **CORRECT** - Direct access for single records:
```php
// These patterns are CORRECT for Pramnos framework:
$user = $result->fields;           // Correct!
$count = $result->fields['total']; // Correct!
```

### Key Points:
- **Single records**: Check `$result->numRows > 0`, then access `$result->fields` directly (no `[0]` index)
- **Multiple records**: Use `while ($result->fetch())` to iterate through rows
- **Never use** `$result->fields[0]` - this is from other frameworks, not Pramnos
- Access column data via `$result->fields` (associative array)

## Database Operations

### SELECT Operations

```php
// Single record
$sql = $this->application->database->prepareQuery("SELECT * FROM users WHERE id = %d", $userId);
$result = $this->application->database->query($sql);
if ($result->numRows > 0) {
    $user = $result->fields; // Associative array of columns
}

// Multiple records
$sql = $this->application->database->prepareQuery("SELECT * FROM applications WHERE user_id = %d ORDER BY created_at DESC", $userId);
$result = $this->application->database->query($sql);
$applications = [];
while ($result->fetch()) {
    $applications[] = $result->fields;
}

// String and integer parameters
$sql = $this->application->database->prepareQuery("SELECT * FROM users WHERE email = %s AND status = %d", $email, $status);
$result = $this->application->database->query($sql);
```

### INSERT Operations

```php
$sql = $this->application->database->prepareQuery(
    "INSERT INTO applications (client_id, client_secret, name, redirect_uri, user_id, created_at) VALUES (%s, %s, %s, %s, %d, %s)",
    $clientId, $clientSecret, $name, $redirectUri, $userId, date('Y-m-d H:i:s')
);
$result = $this->application->database->query($sql);

// Get inserted ID
$insertId = $this->application->database->getLastInsertId();
```

### UPDATE Operations

```php
$sql = $this->application->database->prepareQuery(
    "UPDATE applications SET name = %s, redirect_uri = %s WHERE id = %d AND user_id = %d",
    $name, $redirectUri, $id, $userId
);
$result = $this->application->database->query($sql);
```

### DELETE Operations

```php
// Simple delete
$sql = $this->application->database->prepareQuery("DELETE FROM user_tokens WHERE expires_at < %s", date('Y-m-d H:i:s'));
$result = $this->application->database->query($sql);

// With parameters
$sql = $this->application->database->prepareQuery("DELETE FROM applications WHERE id = %d AND user_id = %d", $id, $userId);
$result = $this->application->database->query($sql);
```

### Queries Without Parameters

```php
// For queries without parameters, you can use query() directly
$result = $this->application->database->query("SELECT COUNT(*) as count FROM users");
```

## Model Integration

### In Model Classes

Models extend `Pramnos\Application\Model` and should use the database API through `$this->application->database` (passed in constructor):

```php
class Application extends \Pramnos\Application\Model
{
    public function loadByClientId($clientId)
    {
        $sql = $this->application->database->prepareQuery("SELECT * FROM applications WHERE client_id = %s", $clientId);
        $result = $this->application->database->query($sql);
        
        if ($result->numRows > 0) {
            foreach (array_keys($result->fields) as $key) {
                if (property_exists($this, $key)) {
                    $this->$key = $result->fields[$key];
                }
            }
            return true;
        }
        return false;
    }
    
    public function save()
    {
        if (isset($this->id) && $this->id > 0) {
            // Update existing record
            $sql = $this->application->database->prepareQuery(
                "UPDATE applications SET name = %s, client_secret = %s, redirect_uri = %s WHERE id = %d",
                $this->name, $this->client_secret, $this->redirect_uri, $this->id
            );
            $this->application->database->query($sql);
        } else {
            // Insert new record
            $sql = $this->application->database->prepareQuery(
                "INSERT INTO applications (name, client_id, client_secret, redirect_uri, created_at) VALUES (%s, %s, %s, %s, %s)",
                $this->name, $this->client_id, $this->client_secret, $this->redirect_uri, date('Y-m-d H:i:s')
            );
            $this->application->database->query($sql);
            $this->id = $this->application->database->getLastInsertId();
        }
    }
}
```

### Model Instantiation

Models should be instantiated with the controller:

```php
// In a Controller
$model = new \Model($this);
$model->load($id);

```


## Controllers and Database Access

### In Controller Classes

Controllers should use `$this->application->database` for database operations:

```php
class Token extends \Pramnos\Application\Controller
{
    public function revokeToken()
    {
        $token = $_POST['token'] ?? '';
        
        // Revoke access token
        $sql = $this->application->database->prepareQuery("UPDATE user_tokens SET revoked = 1 WHERE access_token = %s", $token);
        $this->application->database->query($sql);
        
        // Also revoke refresh token if it matches
        $sql = $this->application->database->prepareQuery("UPDATE user_tokens SET revoked = 1 WHERE refresh_token = %s", $token);
        $this->application->database->query($sql);
        
        $this->response(['revoked' => true]);
    }
}
```

## Common Patterns

### Checking if Record Exists

```php
$sql = $this->application->database->prepareQuery("SELECT COUNT(*) as count FROM applications WHERE client_id = %s", $clientId);
$result = $this->application->database->query($sql);
$exists = false;
if ($result->numRows > 0) {
    $exists = $result->fields['count'] > 0;
}
```

### Getting Multiple Records

```php
$sql = $this->application->database->prepareQuery("SELECT * FROM user_tokens WHERE user_id = %d AND revoked = 0 ORDER BY created_at DESC", $userId);
$result = $this->application->database->query($sql);

$tokens = [];
while ($result->fetch()) {
    $tokens[] = $result->fields;
}
```

### Complex Queries with JOINs

```php
$sql = $this->application->database->prepareQuery(
    "SELECT ut.*, a.name as app_name 
     FROM user_tokens ut 
     JOIN applications a ON ut.client_id = a.client_id 
     WHERE ut.user_id = %d AND ut.expires_at > %s",
    $userId, date('Y-m-d H:i:s')
);
$result = $this->application->database->query($sql);
while ($result->fetch()) {
    // Process $result->fields
}
```

## Best Practices

### 1. Always Use Parameter Binding

**Never** concatenate user input directly into SQL queries:

```php
// WRONG - SQL injection risk
$result = $this->application->database->query("SELECT * FROM users WHERE email = '{$email}'");

// CORRECT - Use printf-style parameter binding
$sql = $this->application->database->prepareQuery("SELECT * FROM users WHERE email = %s", $email);
$result = $this->application->database->query($sql);
```

### 2. Handle Database Errors

Always check if query operations succeed:

```php
try {
    $sql = $this->application->database->prepareQuery("INSERT INTO users (email) VALUES (%s)", $email);
    $result = $this->application->database->query($sql);
    // Handle success
} catch (Exception $e) {
    // Handle database error
    error_log("Database error: " . $e->getMessage());
    return false;
}
```

### 3. Use Transactions for Multiple Operations

For operations that need to be atomic:

```php
try {
    $this->application->database->beginTransaction();
    
    // First operation
    $sql1 = $this->application->database->prepareQuery("INSERT INTO applications (name, client_id) VALUES (%s, %s)", $name, $clientId);
    $this->application->database->query($sql1);
    
    // Second operation
    $sql2 = $this->application->database->prepareQuery("INSERT INTO user_tokens (user_id, token) VALUES (%d, %s)", $userId, $token);
    $this->application->database->query($sql2);
    
    $this->application->database->commit();
} catch (Exception $e) {
    $this->application->database->rollback();
    throw $e;
}
```

### 4. Model Loading Patterns

Follow consistent patterns for loading data in models:

```php
// Single record by ID
public function loadById($id)
{
    $sql = $this->application->database->prepareQuery("SELECT * FROM table_name WHERE id = %d", $id);
    $result = $this->application->database->query($sql);
    
    if ($result->numRows > 0) {
        foreach (array_keys($result->fields) as $key) {
            if (property_exists($this, $key)) {
                $this->$key = $result->fields[$key];
            }
        }
        return true;
    }
    return false;
}

// Multiple records
public function loadByUserId($userId)
{
    $sql = $this->application->database->prepareQuery("SELECT * FROM table_name WHERE user_id = %d", $userId);
    $result = $this->application->database->query($sql);
    
    $records = [];
    while ($result->fetch()) {
        $records[] = $result->fields;
    }
    return $records;
}
```

### 5. Validate Input Before Database Operations

```php
public function updateApplication($id, $name, $redirectUri)
{
    // Validate input
    if (empty($name) || empty($redirectUri)) {
        throw new \InvalidArgumentException('Name and redirect URI are required');
    }
    
    if (!filter_var($redirectUri, FILTER_VALIDATE_URL)) {
        throw new \InvalidArgumentException('Invalid redirect URI');
    }
    
    // Proceed with database operation
    $sql = $this->application->database->prepareQuery("UPDATE applications SET name = %s, redirect_uri = %s WHERE id = %d", $name, $redirectUri, $id);
    $this->application->database->query($sql);
}
```

## Error Handling and Best Practices

### Proper Error Handling for Database Operations

Always wrap database operations in try-catch blocks to handle potential errors gracefully:

```php
// Single record with error handling
private function getUserData(int $userId): ?array
{
    try {
        $sql = $this->application->database->prepareQuery("SELECT * FROM users WHERE id = %d", $userId);
        $result = $this->application->database->query($sql);
        
        if ($result && $result->numRows > 0) {
            return $result->fields;
        }
        return null;
    } catch (\Exception $e) {
        error_log("Error getting user data: " . $e->getMessage());
        return null;
    }
}

// Multiple records with error handling
private function getUserApplications(int $userId): array
{
    try {
        $sql = $this->application->database->prepareQuery("
            SELECT * FROM applications 
            WHERE user_id = %d 
            ORDER BY created_at DESC
        ", $userId);
        
        $result = $this->application->database->query($sql);
        $applications = [];
        while ($result->fetch()) {
            $applications[] = $result->fields;
        }
        return $applications;
    } catch (\Exception $e) {
        error_log("Error getting user applications: " . $e->getMessage());
        return [];
    }
}

// Insert/Update/Delete with error handling
private function createApplication(array $data): bool
{
    try {
        $sql = $this->application->database->prepareQuery("
            INSERT INTO applications (name, client_id, user_id, created_at) 
            VALUES (%s, %s, %d, NOW())
        ", $data['name'], $data['client_id'], $data['user_id']);
        
        $this->application->database->query($sql);
        return true;
    } catch (\Exception $e) {
        error_log("Error creating application: " . $e->getMessage());
        return false;
    }
}
```

### Common Validation Patterns

Always validate that database results exist before accessing them:

```php
// ✅ CORRECT - Validate result before access
$sql = $this->application->database->prepareQuery("SELECT name FROM users WHERE id = %d", $userId);
$result = $this->application->database->query($sql);

if ($result && $result->numRows > 0) {
    $name = $result->fields['name'];
} else {
    $name = 'Unknown User';
}

// ❌ WRONG - Direct access without validation
$name = $result->fields['name']; // Could cause errors if query fails
```

### Return Type Consistency

Methods should have consistent return types with proper defaults:

```php
// Return array for multiple records (always return array, never null)
private function getItems(): array
{
    try {
        // ... database query ...
        return $items;
    } catch (\Exception $e) {
        return []; // Always return empty array on error
    }
}

// Return nullable for single records
private function getItem(int $id): ?array
{
    try {
        // ... database query ...
        return $item;
    } catch (\Exception $e) {
        return null; // Return null on error for single records
    }
}
```

## Common Mistakes to Avoid

1. **Don't use PDO methods directly** - Always use the Pramnos database API
2. **Don't skip parameter binding** - Even for "safe" values, always use printf-style formatting  
3. **Don't use `?` placeholders** - Pramnos uses printf-style (`%s`, `%d`, etc.)
4. **Don't mix database access patterns** - Be consistent throughout your application

## Database Connection Access

In different contexts, access the database through:

- **Models**: `$this->application->database` (passed in constructor)
- **Controllers**: `$this->application->database` (available as property)
- **Repositories**: `$this->application->database` (injected via constructor)
- **Application**: `$this->application->database` (global application instance)

## Error Handling

### Check Query Results

```php
$sql = $this->application->database->prepareQuery("SELECT * FROM applications WHERE id = %d", $id);
$result = $this->application->database->query($sql);

if ($result->numRows == 0) {
    throw new \Exception('Application not found');
}

$application = $result->fields;
```

### Transaction Support

```php
try {
    $this->application->database->beginTransaction();
    
    // Multiple database operations
    $sql1 = $this->application->database->prepareQuery("INSERT INTO applications (client_id, name) VALUES (%s, %s)", $clientId, $name);
    $this->application->database->query($sql1);
    
    $sql2 = $this->application->database->prepareQuery("INSERT INTO application_permissions (app_id, permission) VALUES (%d, %s)", $appId, $permission);
    $this->application->database->query($sql2);
    
    $this->application->database->commit();
} catch (\Exception $e) {
    $this->application->database->rollback();
    throw $e;
}
```

## Summary

## Advanced Database Features

### Database Migrations

The Pramnos framework includes a migration system for version-controlled database schema changes:

#### Creating Migrations

```php
<?php
namespace MyApp\Migrations;

class CreateUsersTable extends \Pramnos\Database\Migration
{
    public $version = '1.0.1';
    public $description = 'Create users table with authentication fields';
    public $autoExecute = true;

    public function up(): void
    {
        $sql = "CREATE TABLE `#PREFIX#users` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `username` varchar(50) NOT NULL,
            `email` varchar(255) NOT NULL,
            `password_hash` varchar(255) NOT NULL,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `username` (`username`),
            UNIQUE KEY `email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $this->addQuery($sql);
        $this->executeQueries();
    }

    public function down(): void
    {
        $sql = "DROP TABLE IF EXISTS `#PREFIX#users`";
        $this->addQuery($sql);
        $this->executeQueries();
    }
}
```

#### Multi-Database Support

```php
// PostgreSQL-specific migrations
class CreateUsersTablePostgreSQL extends \Pramnos\Database\Migration
{
    public function up(): void
    {
        if ($this->application->database->type === 'postgresql') {
            $sql = 'CREATE TABLE "#PREFIX#users" (
                id SERIAL PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                email VARCHAR(255) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )';
            
            $this->addQuery($sql);
            $this->executeQueries();
        }
    }
}
```

### Advanced Query Patterns

#### Complex Joins and Subqueries

```php
// Complex join with subquery for analytics
$sql = $this->application->database->prepareQuery("
    SELECT 
        u.id,
        u.username,
        u.email,
        COUNT(o.id) as order_count,
        COALESCE(SUM(o.total), 0) as total_spent,
        recent_orders.last_order_date
    FROM users u
    LEFT JOIN orders o ON u.id = o.user_id
    LEFT JOIN (
        SELECT 
            user_id, 
            MAX(created_at) as last_order_date
        FROM orders 
        WHERE created_at > %s
        GROUP BY user_id
    ) recent_orders ON u.id = recent_orders.user_id
    WHERE u.created_at > %s
    GROUP BY u.id, u.username, u.email, recent_orders.last_order_date
    HAVING COUNT(o.id) > %d
    ORDER BY total_spent DESC
    LIMIT %d",
    date('Y-m-d', strtotime('-30 days')), // Recent orders filter
    date('Y-m-d', strtotime('-1 year')), // User creation filter
    5, // Minimum order count
    50  // Limit results
);

$result = $this->application->database->query($sql);
$analyticsData = [];
while ($result->fetch()) {
    $analyticsData[] = $result->fields;
}
```

#### Geospatial Queries (PostgreSQL)

```php
// PostGIS spatial queries
if ($this->application->database->type === 'postgresql') {
    $sql = $this->application->database->prepareQuery("
        SELECT 
            id,
            name,
            ST_AsText(location) as location_text,
            ST_Distance(
                location, 
                ST_SetSRID(ST_MakePoint(%s, %s), 4326)
            ) as distance_meters
        FROM stores 
        WHERE ST_DWithin(
            location, 
            ST_SetSRID(ST_MakePoint(%s, %s), 4326), 
            %d
        )
        ORDER BY distance_meters
        LIMIT %d",
        $longitude, $latitude, // Target point
        $longitude, $latitude, // Search center
        5000, // 5km radius in meters
        10 // Limit results
    );
    
    $result = $this->application->database->query($sql);
}
```

### Transaction Management

#### Advanced Transaction Patterns

```php
class OrderProcessor 
{
    private $database;
    
    public function __construct($application)
    {
        $this->database = $application->database;
    }
    
    public function processOrder($orderData, $orderItems)
    {
        try {
            // Start transaction
            $this->database->startTransaction();
            
            // Create order record
            $sql = $this->database->prepareQuery("
                INSERT INTO orders (user_id, total, status, created_at) 
                VALUES (%d, %s, %s, %s)",
                $orderData['user_id'],
                $orderData['total'],
                'pending',
                date('Y-m-d H:i:s')
            );
            $this->database->query($sql);
            $orderId = $this->database->getLastInsertId();
            
            // Add order items
            foreach ($orderItems as $item) {
                $sql = $this->database->prepareQuery("
                    INSERT INTO order_items (order_id, product_id, quantity, price) 
                    VALUES (%d, %d, %d, %s)",
                    $orderId,
                    $item['product_id'],
                    $item['quantity'],
                    $item['price']
                );
                $this->database->query($sql);
                
                // Update product inventory
                $sql = $this->database->prepareQuery("
                    UPDATE products 
                    SET stock_quantity = stock_quantity - %d 
                    WHERE id = %d AND stock_quantity >= %d",
                    $item['quantity'],
                    $item['product_id'],
                    $item['quantity']
                );
                $result = $this->database->query($sql);
                
                // Check if inventory update affected any rows
                if ($result->getAffectedRows() === 0) {
                    throw new \Exception("Insufficient inventory for product " . $item['product_id']);
                }
            }
            
            // Update user's order history
            $sql = $this->database->prepareQuery("
                UPDATE users 
                SET total_orders = total_orders + 1, last_order_date = %s 
                WHERE id = %d",
                date('Y-m-d H:i:s'),
                $orderData['user_id']
            );
            $this->database->query($sql);
            
            // Commit transaction
            $this->database->commitTransaction();
            
            return ['success' => true, 'order_id' => $orderId];
            
        } catch (\Exception $e) {
            // Rollback on any error
            $this->database->rollbackTransaction();
            \Pramnos\Logs\Logger::logError("Order processing failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
```

#### Nested Transactions and Savepoints

```php
class AdvancedTransactionManager 
{
    private $database;
    private $savepointCounter = 0;
    
    public function __construct($application)
    {
        $this->database = $application->database;
    }
    
    public function createSavepoint($name = null)
    {
        if ($name === null) {
            $name = 'sp_' . (++$this->savepointCounter);
        }
        
        if ($this->database->type === 'postgresql') {
            $this->database->query("SAVEPOINT {$name}");
        } else {
            $this->database->query("SAVEPOINT {$name}");
        }
        
        return $name;
    }
    
    public function rollbackToSavepoint($name)
    {
        if ($this->database->type === 'postgresql') {
            $this->database->query("ROLLBACK TO SAVEPOINT {$name}");
        } else {
            $this->database->query("ROLLBACK TO SAVEPOINT {$name}");
        }
    }
    
    public function releaseSavepoint($name)
    {
        if ($this->database->type === 'postgresql') {
            $this->database->query("RELEASE SAVEPOINT {$name}");
        } else {
            $this->database->query("RELEASE SAVEPOINT {$name}");
        }
    }
}
```

### Performance Optimization

#### Query Optimization and Analysis

```php
class QueryOptimizer 
{
    private $database;
    
    public function __construct($application)
    {
        $this->database = $application->database;
    }
    
    public function analyzeQuery($sql)
    {
        $explainSql = "EXPLAIN " . $sql;
        $result = $this->database->query($explainSql);
        
        $analysis = [];
        while ($result->fetch()) {
            $analysis[] = $result->fields;
        }
        
        return $analysis;
    }
    
    public function getSlowQueries($limit = 10)
    {
        if ($this->database->type === 'mysql') {
            $sql = "
                SELECT 
                    sql_text,
                    exec_count,
                    avg_timer_wait/1000000000000 as avg_time_seconds,
                    sum_timer_wait/1000000000000 as total_time_seconds
                FROM performance_schema.events_statements_summary_by_digest 
                ORDER BY avg_timer_wait DESC 
                LIMIT " . (int)$limit;
            
            $result = $this->database->query($sql);
            $slowQueries = [];
            while ($result->fetch()) {
                $slowQueries[] = $result->fields;
            }
            return $slowQueries;
        }
        
        return [];
    }
}
```

#### Connection Pool Management

```php
class ConnectionPoolManager 
{
    private static $connections = [];
    private static $maxConnections = 10;
    private static $currentConnections = 0;
    
    public static function getConnection($config)
    {
        $key = md5(serialize($config));
        
        if (isset(self::$connections[$key]) && self::$connections[$key]->connected) {
            return self::$connections[$key];
        }
        
        if (self::$currentConnections >= self::$maxConnections) {
            throw new \Exception("Connection pool exhausted");
        }
        
        $database = new \Pramnos\Database\Database($config);
        $database->connect();
        
        self::$connections[$key] = $database;
        self::$currentConnections++;
        
        return $database;
    }
    
    public static function releaseConnection($database)
    {
        // In a real implementation, you might pool connections
        // rather than closing them immediately
        $database->close();
        self::$currentConnections--;
    }
}
```

### Adjacency List Implementation

The framework includes specialized support for hierarchical data:

```php
$database = \Pramnos\Framework\Factory::getDatabase();
$categoryTree = new \Pramnos\Database\Adjacencylist(
    $database,
    'categories',     // table name
    'id',            // id field
    'parent_id',     // parent field
    'name'           // title field
);

// Get all categories as hierarchical array
$categories = $categoryTree->getArray();

// Get path to specific category
$categoryPath = $categoryTree->getPathAsArray(15); // category ID 15

// Set custom separator for path display
$categoryTree->separator = ' > ';
$fullPath = $categoryTree->getArray(null, 15);
```

### Advanced Model Patterns

#### Model Caching and Column Information

```php
class AdvancedModel extends \Pramnos\Application\Model
{
    // Column cache for performance
    private static $columnCache = [];
    
    public function getAvailableFields()
    {
        $database = \Pramnos\Database\Database::getInstance();
        $tableName = $this->getFullTableName();
        
        if (isset(self::$columnCache[$tableName])) {
            return array_column(self::$columnCache[$tableName], 'Field');
        }
        
        $fields = [];
        if ($database->type === 'postgresql') {
            $schema = $this->_dbschema ?? $database->schema;
            $sql = "SELECT column_name as \"Field\", data_type as \"Type\"
                    FROM information_schema.columns 
                    WHERE table_schema = '{$schema}' 
                    AND table_name = '" . str_replace('#PREFIX#', $database->prefix, $this->_dbtable) . "'";
        } else {
            $sql = "SHOW COLUMNS FROM `{$tableName}`";
        }
        
        $result = $database->query($sql);
        while ($result->fetch()) {
            $fields[] = $result->fields['Field'];
            if (!isset(self::$columnCache[$tableName])) {
                self::$columnCache[$tableName] = [];
            }
            self::$columnCache[$tableName][] = $result->fields;
        }
        
        return $fields;
    }
    
    public function getDynamicList($filter = '', $order = '', $page = 0, $itemsPerPage = 50, 
                                  $globalSearch = '', $fieldSearches = [])
    {
        $database = \Pramnos\Database\Database::getInstance();
        $availableFields = $this->getAvailableFields();
        
        // Build dynamic search conditions
        $searchConditions = $this->buildSearchConditions($availableFields, $globalSearch, $fieldSearches);
        
        // Combine with existing filter
        $finalFilter = $this->combineFilters($filter, $searchConditions);
        
        // Validate order fields against available fields
        $validatedOrder = $this->validateOrderFields($order, $availableFields);
        
        return $this->getList($finalFilter, $validatedOrder, $page, $itemsPerPage);
    }
}
```

### Error Handling and Resilience

#### Comprehensive Error Handling

```php
class ResilientDatabaseOperation 
{
    private $database;
    private $maxRetries = 3;
    private $retryDelay = 1; // seconds
    
    public function __construct($application)
    {
        $this->database = $application->database;
    }
    
    public function executeWithRetry($sql, $params = [])
    {
        $attempt = 0;
        $lastException = null;
        
        while ($attempt < $this->maxRetries) {
            try {
                if (!$this->database->connected) {
                    $this->database->connect();
                }
                
                if (!empty($params)) {
                    $preparedSql = $this->database->prepareQuery($sql, ...$params);
                } else {
                    $preparedSql = $sql;
                }
                
                return $this->database->query($preparedSql);
                
            } catch (\Exception $e) {
                $lastException = $e;
                $attempt++;
                
                \Pramnos\Logs\Logger::logError(
                    "Database query attempt {$attempt} failed: " . $e->getMessage()
                );
                
                if ($attempt < $this->maxRetries) {
                    sleep($this->retryDelay * $attempt); // Exponential backoff
                    $this->database->refresh(); // Reconnect
                }
            }
        }
        
        throw new \Exception(
            "Database operation failed after {$this->maxRetries} attempts. Last error: " . 
            $lastException->getMessage()
        );
    }
    
    public function executeInTransaction($operations)
    {
        try {
            $this->database->startTransaction();
            
            foreach ($operations as $operation) {
                if (is_callable($operation)) {
                    $operation($this->database);
                } elseif (is_array($operation) && isset($operation['sql'])) {
                    $this->executeWithRetry($operation['sql'], $operation['params'] ?? []);
                }
            }
            
            $this->database->commitTransaction();
            return true;
            
        } catch (\Exception $e) {
            $this->database->rollbackTransaction();
            \Pramnos\Logs\Logger::logError("Transaction failed: " . $e->getMessage());
            throw $e;
        }
    }
}
```

### Database-Specific Features

#### PostgreSQL Advanced Features

```php
class PostgreSQLFeatures 
{
    private $database;
    
    public function __construct($application)
    {
        $this->database = $application->database;
    }
    
    public function useJSONQueries($table, $jsonColumn, $jsonPath, $value)
    {
        if ($this->database->type !== 'postgresql') {
            throw new \Exception("JSON queries are only supported in PostgreSQL");
        }
        
        $sql = $this->database->prepareQuery("
            SELECT * FROM \"{$table}\" 
            WHERE \"{$jsonColumn}\" ->> %s = %s",
            $jsonPath,
            $value
        );
        
        return $this->database->query($sql);
    }
    
    public function useArrayColumns($table, $arrayColumn, $searchValue)
    {
        $sql = $this->database->prepareQuery("
            SELECT * FROM \"{$table}\" 
            WHERE %s = ANY(\"{$arrayColumn}\")",
            $searchValue
        );
        
        return $this->database->query($sql);
    }
    
    public function useFullTextSearch($table, $textColumn, $searchTerm)
    {
        $sql = $this->database->prepareQuery("
            SELECT *, ts_rank(to_tsvector('english', \"{$textColumn}\"), plainto_tsquery('english', %s)) as rank
            FROM \"{$table}\" 
            WHERE to_tsvector('english', \"{$textColumn}\") @@ plainto_tsquery('english', %s)
            ORDER BY rank DESC",
            $searchTerm,
            $searchTerm
        );
        
        return $this->database->query($sql);
    }
}
```

## Database Security Best Practices

### SQL Injection Prevention

Always use the framework's parameter binding - never concatenate user input:

```php
// ✅ SECURE - Using parameter binding
$sql = $this->application->database->prepareQuery(
    "SELECT * FROM users WHERE email = %s AND role = %s AND active = %d",
    $userEmail,
    $userRole,
    1
);

// ❌ VULNERABLE - Direct concatenation
$sql = "SELECT * FROM users WHERE email = '" . $userEmail . "'"; // NEVER DO THIS!
```

### Sensitive Data Handling

```php
class SecureDataHandler 
{
    public function hashSensitiveData($data, $salt = null)
    {
        if ($salt === null) {
            $salt = bin2hex(random_bytes(32));
        }
        
        return [
            'hash' => hash_pbkdf2('sha256', $data, $salt, 10000),
            'salt' => $salt
        ];
    }
    
    public function insertUserWithHashedPassword($userData)
    {
        $passwordData = $this->hashSensitiveData($userData['password']);
        
        $sql = $this->application->database->prepareQuery("
            INSERT INTO users (username, email, password_hash, password_salt, created_at) 
            VALUES (%s, %s, %s, %s, %s)",
            $userData['username'],
            $userData['email'],
            $passwordData['hash'],
            $passwordData['salt'],
            date('Y-m-d H:i:s')
        );
        
        return $this->application->database->query($sql);
    }
}
```

The Pramnos Database API provides a comprehensive, secure, and flexible foundation for all database operations, supporting both MySQL and PostgreSQL with advanced features for modern web applications.
