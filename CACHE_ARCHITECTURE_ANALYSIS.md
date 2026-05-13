# Cache Architecture Deep Dive: Model Cache Key Issue

## Executive Summary

After fixing the UW-389 cache bug (key collision), the Model cache architecture is now **logically safe from collision**, but the design has several architectural gaps that could cause:
1. **Stale data** from related table updates (cascade invalidation gap)
2. **Confusion** between category (invalidation scope) and actual cache key
3. **Operational complexity** when managing cache across multiple models

---

## Issue #1: Cascade Invalidation Gap ⚠️

### Problem Scenario

```
Timeline:
T1: Supplygroup 7 is updated: name = "Water Group A" → "Water Group B"
    - Supplygroup cache invalidated ✓
    
T2: Watersupply 142970 loaded (still in cache):
    - Cache hit for: 142970-watersupplies_abc123...
    - Returns: {groupid: 7, group_name: "Water Group A"} ← STALE!
    
T3: Controller displays watersupply data
    - Shows old group name ("Water Group A")
    - Database has new name ("Water Group B")
```

### Why It Happens

- `Supplygroup.save()` calls `cacheflush("7-supplygroups")`
  - Pattern: `uw_7-supplygroups_*` → clears only Supplygroup 7 cache
  
- **Does NOT invalidate** related Watersupply caches
  - Pattern: `uw_142970-watersupplies_*` remains unchanged
  
- Foreign key reference is not tracked in cache layer

### Impact

- **Severity**: Medium (data consistency issue, not corruption)
- **When**: Any UPDATE to a table that other models reference
- **Frequency**: High (common in relational databases)

---

## Issue #2: Category vs. Actual Cache Key Confusion 🤔

### How It Works (Correctly)

```
Model Layer:
  _generateSpecificCacheKey(142970) → "142970-watersupplies"
  
QueryBuilder Layer:
  get($cache=false, $category="142970-watersupplies")
  
Database Layer:
  - $sql = "SELECT * FROM uw_watersupplies WHERE id = ?"
  - $bindings = [142970]
  - $actualCacheKey = md5(sql . serialize(bindings))
  - $actualCacheKey = "abc123def456..." (NOT same as category!)
  
Redis Layer:
  redis_key = "uw_" + "142970-watersupplies" + "_" + "abc123def456..."
  
Invalidation:
  cacheflush("142970-watersupplies")
  pattern = "uw_142970-watersupplies_*"
  Deletes: ALL keys matching pattern ✓
```

### Why It's Confusing

1. **Same method used for two different purposes**:
   - `_generateSpecificCacheKey()` → both a **category** (for invalidation) AND a logical identifier

2. **Not intuitive** that `QueryBuilder.get($category)` doesn't set the actual cache key
   - Developers might think: "If I pass category, that's the cache key"
   - Reality: Actual key is `md5(sql . serialize(bindings))`

3. **Two-level key system** is non-obvious:
   - Level 1: Category ("142970-watersupplies") → for pattern matching
   - Level 2: md5(sql+bindings) → for actual data lookup

### Risk

- **Severity**: Low (system works correctly, but hard to understand)
- **When**: During cache debugging or architecture changes
- **Frequency**: Affects maintenance and future development

---

## Issue #3: Read/Write Replica Consistency 📊

### Problem Scenario

```
With Read/Write Replicas (Primary → Read Replica):

T1: Request A: SELECT → read replica (lag = 500ms)
    Cache miss (stale data not yet on read replica)
    
T2: Request A: execute() → uses which connection?
    Currently: Goes to WRITE replica (wrong!)
    Should: Sense we're doing a cache miss and use write replica
    
T3: Data cached from write replica (fresh)
T4: Request B: Cache hit
    Returns: correct fresh data ✓
    
BUT if cache miss from read replica:
T5: Request C: SELECT → read replica (still stale)
    Cache stores: stale data from read replica
T6: All subsequent reads: stale data cached!
```

### Current Code

```php
// Database.php
public function cacheRead($query, $bindings = [], $category = "")
{
    $cachedData = $cache->load($cache_name);
    if ($cachedData !== false) {
        return $this->restoreDataFromCache($deserializedData);
    }
    
    // On cache miss, which connection is used?
    // Database::execute() always uses write connection (safe)
    // But this info is not visible to cache layer
}
```

### Impact

- **Severity**: Medium (only affects read-write replica setups)
- **When**: Cache miss happens + write replica has fresher data
- **Frequency**: Depends on replica lag

---

## Issue #4: Query Executor Pattern Gap 🔧

### Current Model Pattern

```php
// Model._load() - NO executor callback
$result = $database->queryBuilder()
    ->from($this->getFullTableName())
    ->where($this->_primaryKey, $primaryKey)
    ->limit(1)
    ->get(false, 600, $specificCacheKey);
    
// Problem: No atomic get-or-set
// If multiple requests miss cache simultaneously:
//   Both execute query
//   Both try to write to cache
//   Last write wins (data loss potential)
```

### Why It Matters

With our new `cacheGetOrSet()` helper:

```php
// Better pattern (not yet adopted):
$result = $database->cacheGetOrSet(
    $sql,
    $bindings,
    $specificCacheKey,
    600,
    function($sql, $bindings) {
        return $this->queryBuilder()
            ->from(...)
            ->where(...)
            ->execute();
    }
);
```

This prevents:
- Duplicate queries during cache miss storm
- Wasted database resources
- Potential stale data caching

---

## Solutions & Recommendations

### 🔴 CRITICAL: Implement Cascade Invalidation

**File**: Model.php

```php
protected function _cascadeInvalidateCache($primaryKeyValue, $relatedModels = [])
{
    $database = \Pramnos\Database\Database::getInstance();
    $cacheKey = $this->_generateSpecificCacheKey($primaryKeyValue);
    
    // Invalidate this model
    $database->cacheflush($cacheKey);
    
    // Invalidate related models
    foreach ($relatedModels as $relatedModelName => $relatedTable) {
        $database->cacheflush($relatedTable);
    }
}
```

**Usage in Watersupply model**:

```php
public function save()
{
    parent::_save();
    
    // After successful save, invalidate related caches
    $this->_cascadeInvalidateCache($this->supplyid, [
        'locations' => 'locations',
        'zones' => 'zones',
        'supplygroups' => 'supplygroups'
    ]);
}
```

### 🟡 IMPORTANT: Add Replica Awareness

**File**: Database.php (already added helper)

```php
public function getActiveConnectionInfo($isWriteQuery = false)
{
    // Returns which connection was used
    // Allows cache layer to make decisions
}
```

**Usage in cacheRead()** (future enhancement):

```php
public function cacheRead($query, $bindings = [], $category = "")
{
    $connectionInfo = $this->getActiveConnectionInfo(false);
    
    if ($connectionInfo['type'] === 'read' && /* cache miss */) {
        // Log warning: cache miss from read replica might return stale data
        \Pramnos\Logs\Logger::warn(
            "Cache miss from read replica, ensuring write replica used for cache population"
        );
    }
    
    // ... rest of method
}
```

### 🟢 NICE-TO-HAVE: Adopt Atomic Executor Pattern

**File**: Model.php _load()

```php
$result = $database->cacheGetOrSet(
    "SELECT * FROM " . $this->getFullTableName() . " WHERE " . $this->_primaryKey . " = ?",
    [$primaryKey],
    $specificCacheKey,
    600,
    function($sql, $bindings) {
        return $this->queryBuilder()
            ->from($this->getFullTableName())
            ->where($this->_primaryKey, $primaryKey)
            ->limit(1)
            ->execute();
    }
);
```

---

## Key Takeaways

| Issue | Severity | Root Cause | Fix Effort |
|-------|----------|-----------|-----------|
| Cascade Invalidation | Medium | No relationship tracking in cache | **Medium** |
| Category vs Key Confusion | Low | Design documentation gap | **Low** (docs) |
| Replica Consistency | Medium | No replica awareness | **Medium** |
| Executor Pattern | Low | Not adopted yet | **Low** |

---

## Current Status After UW-389 Fix

✅ **Fixed**: Cache key collision (md5 of SQL only)
✅ **Fixed**: Type restoration bugs  
✅ **Fixed**: Singleton state mutation
✅ **Fixed**: Controller default value propagation

⚠️ **NOT Fixed**: Cascade invalidation gap
⚠️ **NOT Fixed**: Replica consistency gap
⚠️ **NOT Fixed**: Query executor race condition

---

## Next Steps

1. **Immediate** (this sprint):
   - Add cascade invalidation helpers
   - Update Model documentation
   
2. **Short-term** (next sprint):
   - Add replica awareness checks
   - Create integration tests for cascade scenarios
   
3. **Long-term** (future):
   - Consider implementing cascade invalidation pattern library
   - Add cache monitoring dashboard
