<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Model;

/**
 * Stub subclass of Model with declared public properties so that get_object_vars()
 * can see them.  The constructor is empty to avoid needing a Controller or DB.
 */
class StubModelForGetData extends Model
{
    public $id    = null;
    public $name  = null;
    public $price = null;
    public $tags  = null; // will be used to verify array exclusion

    /**
     * Bypass the full Model constructor — we only need declared properties.
     */
    public function __construct()
    {
        // intentionally empty — no parent::__construct()
    }
}

/**
 * A minimal Database stub that overrides prepareInput() to use addslashes(),
 * avoiding any real connection.  Used by _buildSingleCondition() LIKE/ILIKE tests.
 */
class StubDatabaseForModel extends \Pramnos\Database\Database
{
    public function __construct()
    {
        // bypass real Database constructor
    }

    public function prepareInput($string): string
    {
        return addslashes((string)$string);
    }
}

/**
 * Unit tests for the branches of Model that are not yet exercised by the
 * existing integration tests (ModelTest) or pure-helper tests (ModelPureHelpersTest).
 *
 * All tests use ReflectionClass::newInstanceWithoutConstructor() to bypass the
 * constructor (which requires a live Controller and DB), then inject the
 * minimum state needed by each method under test via reflection.
 *
 * Covered areas:
 *   - __init(): #THISPREFIX# placeholder replacement
 *   - _fixDb(): cache-key derivation with/without prefix
 *   - _generateSpecificCacheKey(): primary-key-scoped cache key
 *   - fieldtype(): "double precision", "timestamp without/with time zone",
 *                  "time without/with time zone" edge-case strings
 *   - getData(): excludes internal properties; handles numeric/string values;
 *                skips non-scalar (array) values
 *   - getChanges(): numeric loose-comparison branch; mixed property/dynamic
 *                   field detection
 *   - getLastSaveChanges(): simple getter
 *   - _combineFilters(): all four branches (both empty, only search,
 *                        only filter, both non-empty; "where" keyword stripping)
 *   - _resolveFieldResultName(): AS with backtick-quoted alias,
 *                                 double-quoted alias
 *   - _buildSingleCondition(): null value → IS NULL/IS NOT NULL expansion,
 *                               int/float literals (no quoting),
 *                               unknown field → null,
 *                               invalid op → null,
 *                               missing 'value' key for scalar ops → null,
 *                               NOT IN with empty array → null,
 *                               LIKE/ILIKE normalisation on MySQL/PostgreSQL
 */
#[CoversClass(Model::class)]
class ModelUncoveredBranchesTest extends TestCase
{
    private Model $model;

    protected function setUp(): void
    {
        // Arrange — bypass constructor; inject only what helpers need
        $rc          = new \ReflectionClass(Model::class);
        $this->model = $rc->newInstanceWithoutConstructor();

        $this->setProp('_primaryKey', 'id');
        $this->setProp('_dbtable', 'products');
        $this->setProp('_isnew', true);
        $this->setProp('_initialData', []);
        $this->setProp('_lastChanges', []);
        $this->setProp('_jsonactions', []);
        $this->setProp('prefix', '');
        $this->setProp('_cacheKey', null);
        $this->setProp('_dbschema', null);
        $this->setProp('modelname', 'Product');
        $this->setProp('controller', null);

        // Initialise Base::_data so __get/__set work without a constructor
        $parentRef = new \ReflectionClass(\Pramnos\Framework\Base::class);
        $dataProp  = $parentRef->getProperty('_data');
        $dataProp->setValue($this->model, []);
    }

    protected function tearDown(): void
    {
        // Wipe any columnCache entries we may have injected
        Model::$columnCache = [];
    }

    // =========================================================================
    // __init() — #THISPREFIX# placeholder
    // =========================================================================

    /**
     * __init() replaces the #THISPREFIX# placeholder with "prefix_" so that
     * sub-modules can defer table-name resolution until after construction.
     *
     * This covers lines ~117-119 of Model.php.
     */
    public function testInitReplacesThisPrefixPlaceholder(): void
    {
        // Arrange — table uses the deferred placeholder
        $this->setProp('_dbtable', '#THISPREFIX#widgets');
        $this->setProp('prefix', 'shop');

        // Act
        $this->model->__init();

        // Assert — placeholder replaced with "shop_"
        $this->assertSame('shop_widgets', $this->getProp('_dbtable'));
    }

    /**
     * __init() with an empty prefix replaces #THISPREFIX# with "_", which is
     * the documented behaviour when no sub-module prefix is set.
     */
    public function testInitWithEmptyPrefixReplacesWithUnderscore(): void
    {
        // Arrange
        $this->setProp('_dbtable', '#THISPREFIX#items');
        $this->setProp('prefix', '');

        // Act
        $this->model->__init();

        // Assert — empty prefix → single underscore separator
        $this->assertSame('_items', $this->getProp('_dbtable'));
    }

    // =========================================================================
    // _fixDb() — cache-key derivation
    // =========================================================================

    /**
     * _fixDb() must strip the DB prefix from the table name to derive a
     * stable cache key that does not change when the prefix changes.
     *
     * This covers lines ~944-962 of Model.php.
     */
    public function testFixDbDerivesCacheKeyByStrippingDbPrefix(): void
    {
        // Arrange — use an existing connected DB so we can read its prefix
        $db         = \Pramnos\Database\Database::getInstance();
        $origPrefix = $db->prefix;
        try {
            $db->prefix = 'pr_';
            $this->setProp('_dbtable', 'pr_products');
            $this->setProp('prefix', '');

            // Act
            $ref = new \ReflectionMethod($this->model, '_fixDb');
            $ref->invoke($this->model);

            // Assert — cache key must not contain the DB prefix
            $cacheKey = $this->getProp('_cacheKey');
            $this->assertStringNotContainsString('pr_', $cacheKey,
                '_fixDb() must strip the DB prefix from the cache key');
        } finally {
            $db->prefix = $origPrefix;
        }
    }

    /**
     * _fixDb() with a non-empty model prefix must also strip the model prefix
     * from the cache key so that cache keys are stable across prefix variations.
     *
     * This covers the `if ($this->prefix != "")` branch in _fixDb() (line ~951).
     */
    public function testFixDbStripsModelPrefixFromCacheKey(): void
    {
        // Arrange
        $db         = \Pramnos\Database\Database::getInstance();
        $origPrefix = $db->prefix;
        try {
            $db->prefix = '';
            $this->setProp('_dbtable', 'shop_products');
            $this->setProp('prefix', 'shop');

            // Act
            $ref = new \ReflectionMethod($this->model, '_fixDb');
            $ref->invoke($this->model);

            // Assert — "shop" prefix must be stripped
            $cacheKey = $this->getProp('_cacheKey');
            $this->assertStringNotContainsString('shop', $cacheKey,
                'Model prefix must be stripped from _cacheKey');
        } finally {
            $db->prefix = $origPrefix;
        }
    }

    // =========================================================================
    // _generateSpecificCacheKey()
    // =========================================================================

    /**
     * _generateSpecificCacheKey() must prepend the primary-key value to the
     * base cache key, scoping the entry to a single record.
     *
     * This covers lines ~969-975 of Model.php.
     */
    public function testGenerateSpecificCacheKeyPrependsPrimaryKeyValue(): void
    {
        // Arrange — inject a known cache key so _fixDb() is not called
        $this->setProp('_cacheKey', 'products');

        // Act
        $result = $this->callProtected('_generateSpecificCacheKey', 42);

        // Assert
        $this->assertSame('42-products', $result,
            'Specific cache key must be "primaryKeyValue-cacheKey"');
    }

    /**
     * _generateSpecificCacheKey() must call _fixDb() first when _cacheKey is
     * null, ensuring the cache key is always well-formed even before any save/load.
     */
    public function testGenerateSpecificCacheKeyCallsFixDbWhenCacheKeyIsNull(): void
    {
        // Arrange — _cacheKey is null (set in setUp); _dbtable is 'products'
        $this->setProp('_cacheKey', null);
        $db         = \Pramnos\Database\Database::getInstance();
        $origPrefix = $db->prefix;
        try {
            $db->prefix = '';

            // Act
            $result = $this->callProtected('_generateSpecificCacheKey', 7);

            // Assert — result must be "7-<something>", not "7-" nor null
            $this->assertStringStartsWith('7-', $result,
                'Cache key must be initialised by _fixDb() before use');
            $this->assertGreaterThan(2, strlen($result),
                'Cache key portion must not be empty');
        } finally {
            $db->prefix = $origPrefix;
        }
    }

    // =========================================================================
    // fieldtype() — edge-case DB type strings
    // =========================================================================

    /**
     * "double precision" (PostgreSQL numeric type) must map to "float".
     *
     * This covers the "double precision" case (line ~997) which is distinct from
     * plain "double" because it contains a space — the explode('(') normalisation
     * does NOT strip the space, so the full string must match explicitly.
     */
    public function testFieldtypeDoublePrecisionMapsToFloat(): void
    {
        // Act
        $result = $this->callPrivate('fieldtype', 'double precision');

        // Assert
        $this->assertSame('float', $result,
            '"double precision" must map to float');
    }

    /**
     * "timestamp without time zone" (PostgreSQL) must map to "timestamp".
     * This is a multi-word type string that must not fall through to "string".
     */
    public function testFieldtypeTimestampWithoutTimeZoneMapsToTimestamp(): void
    {
        $this->assertSame(
            'timestamp',
            $this->callPrivate('fieldtype', 'timestamp without time zone')
        );
    }

    /**
     * "timestamp with time zone" (PostgreSQL) must map to "timestamp".
     */
    public function testFieldtypeTimestampWithTimeZoneMapsToTimestamp(): void
    {
        $this->assertSame(
            'timestamp',
            $this->callPrivate('fieldtype', 'timestamp with time zone')
        );
    }

    /**
     * "time without time zone" (PostgreSQL) must map to "timestamp".
     * The framework unifies all time-related DB types under "timestamp".
     */
    public function testFieldtypeTimeWithoutTimeZoneMapsToTimestamp(): void
    {
        $this->assertSame(
            'timestamp',
            $this->callPrivate('fieldtype', 'time without time zone')
        );
    }

    /**
     * "time with time zone" (PostgreSQL) must map to "timestamp".
     */
    public function testFieldtypeTimeWithTimeZoneMapsToTimestamp(): void
    {
        $this->assertSame(
            'timestamp',
            $this->callPrivate('fieldtype', 'time with time zone')
        );
    }

    // =========================================================================
    // getData()
    // =========================================================================

    /**
     * getData() must return numeric and string public properties but exclude
     * all internal Model fields (_primaryKey, _dbtable, modelname, prefix,
     * _dbschema, _cacheKey, cacheInListsTime, useCacheInLists).
     *
     * Uses StubModelForGetData which has declared public properties so that
     * get_object_vars() (called inside getData()) can enumerate them.
     *
     * This covers lines ~1051-1065 of Model.php.
     */
    public function testGetDataExcludesInternalFields(): void
    {
        // Arrange — use the stub with declared public properties
        $stub       = new StubModelForGetData();
        $stub->id   = 10;
        $stub->name = 'Hello';

        // Act
        $data = $stub->getData();

        // Assert — public scalar fields present
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('name', $data);
        $this->assertSame(10,      $data['id']);
        $this->assertSame('Hello', $data['name']);

        // Assert — internal fields excluded
        foreach (['_primaryKey', '_dbtable', 'modelname', 'prefix',
                  '_dbschema', '_cacheKey', 'cacheInListsTime', 'useCacheInLists'] as $key) {
            $this->assertArrayNotHasKey($key, $data,
                "getData() must not expose '{$key}'");
        }
    }

    /**
     * getData() must skip non-scalar values (arrays/objects) so that the
     * returned array is always safe for json_encode without nesting surprises.
     *
     * This covers the `is_numeric($value) || is_string($value)` guard (line ~1061).
     */
    public function testGetDataSkipsNonScalarValues(): void
    {
        // Arrange — use stub; set array-valued property (must be skipped)
        $stub       = new StubModelForGetData();
        $stub->tags = ['a', 'b'];   // declared property — array, must be skipped
        $stub->name = 'Widget';     // declared property — string, must be included
        $stub->price = 9.99;        // declared property — numeric string/float, must be included

        // Act
        $data = $stub->getData();

        // Assert
        $this->assertArrayNotHasKey('tags', $data,
            'Array-valued declared properties must be excluded from getData()');
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('price', $data);
    }

    // =========================================================================
    // getChanges() — numeric loose-comparison branch
    // =========================================================================

    /**
     * getChanges() must use loose numeric comparison so that a database-returned
     * string "100" is considered equal to the integer 100 — databases often
     * return integers as strings, and a false "changed" report would trigger
     * unnecessary UPDATE queries.
     *
     * This covers the `is_numeric($initialValue) && is_numeric($currentValue)`
     * branch (lines ~1111-1115).
     */
    public function testGetChangesNumericComparisonIgnoresStringVsIntDifference(): void
    {
        // Arrange — simulate DB returning "100" as string; current value is int 100
        $this->setProp('_isnew', false);
        $this->setProp('_initialData', ['price' => '100']);  // DB returned string
        // Set the property as integer via __set() (mimics code assignment)
        $this->model->price = 100;

        $parentRef = new \ReflectionClass(\Pramnos\Framework\Base::class);
        $dataProp  = $parentRef->getProperty('_data');
        $dataProp->setValue($this->model, ['price' => 100]);

        // Act
        $changes = $this->model->getChanges();

        // Assert — no change detected (100 == "100" numerically)
        $this->assertArrayNotHasKey('price', $changes,
            'getChanges() must not report a change when integer equals string-number');
    }

    /**
     * getChanges() must report a change when a numeric value genuinely differs,
     * even if both are expressed as strings.
     *
     * This covers the `(float)$initialValue !== (float)$currentValue` test (line ~1113).
     */
    public function testGetChangesDetectsNumericValueChange(): void
    {
        // Arrange — initial 100, current 200
        $this->setProp('_isnew', false);
        $this->setProp('_initialData', ['price' => '100']);
        $this->model->price = 200;

        $parentRef = new \ReflectionClass(\Pramnos\Framework\Base::class);
        $dataProp  = $parentRef->getProperty('_data');
        $dataProp->setValue($this->model, ['price' => 200]);

        // Act
        $changes = $this->model->getChanges();

        // Assert
        $this->assertArrayHasKey('price', $changes,
            'getChanges() must detect a genuine numeric value change');
        $this->assertSame('100', $changes['price']['old']);
        $this->assertSame(200,   $changes['price']['new']);
    }

    /**
     * getChanges() must detect a change for a string field that has been modified
     * (strict equality, not numeric comparison).
     *
     * This covers the `else { if ($currentValue !== $initialValue)` branch (line ~1121).
     */
    public function testGetChangesDetectsStringFieldChange(): void
    {
        // Arrange
        $this->setProp('_isnew', false);
        $this->setProp('_initialData', ['name' => 'Alice']);

        $parentRef = new \ReflectionClass(\Pramnos\Framework\Base::class);
        $dataProp  = $parentRef->getProperty('_data');
        $dataProp->setValue($this->model, ['name' => 'Bob']);

        // Act
        $changes = $this->model->getChanges();

        // Assert
        $this->assertArrayHasKey('name', $changes);
        $this->assertSame('Alice', $changes['name']['old']);
        $this->assertSame('Bob',   $changes['name']['new']);
    }

    /**
     * getChanges() must return an empty array when a string field is unchanged.
     */
    public function testGetChangesReturnsEmptyWhenStringFieldUnchanged(): void
    {
        // Arrange
        $this->setProp('_isnew', false);
        $this->setProp('_initialData', ['name' => 'Same']);

        $parentRef = new \ReflectionClass(\Pramnos\Framework\Base::class);
        $dataProp  = $parentRef->getProperty('_data');
        $dataProp->setValue($this->model, ['name' => 'Same']);

        // Act
        $changes = $this->model->getChanges();

        // Assert — no changes
        $this->assertEmpty($changes);
    }

    // =========================================================================
    // _combineFilters() — all four branches
    // =========================================================================

    /**
     * _combineFilters() with both inputs empty must return an empty string so
     * that no WHERE clause is appended to the query.
     *
     * This covers the `empty($baseFilter) && empty($searchConditions)` branch
     * (line ~2278).
     */
    public function testCombineFiltersBothEmptyReturnsEmpty(): void
    {
        // Act
        $result = $this->callPrivate('_combineFilters', '', '');

        // Assert
        $this->assertSame('', $result);
    }

    /**
     * _combineFilters() with only search conditions must return "where <conditions>".
     *
     * This covers the `elseif (empty($baseFilter))` branch (line ~2280).
     */
    public function testCombineFiltersOnlySearchConditionsReturnsWhereSearch(): void
    {
        // Act
        $result = $this->callPrivate('_combineFilters', '', 'name LIKE \'%foo%\'');

        // Assert
        $this->assertSame("where name LIKE '%foo%'", $result);
    }

    /**
     * _combineFilters() with only a base filter must return "where <filter>".
     *
     * This covers the `elseif (empty($searchConditions))` branch (line ~2282).
     */
    public function testCombineFiltersOnlyBaseFilterReturnsWhereFilter(): void
    {
        // Act
        $result = $this->callPrivate('_combineFilters', 'is_active = 1', '');

        // Assert
        $this->assertSame('where is_active = 1', $result);
    }

    /**
     * _combineFilters() with both inputs non-empty must combine them with AND.
     *
     * This covers the final `else` branch (line ~2284).
     */
    public function testCombineFiltersBothNonEmptyReturnsCombinedFilter(): void
    {
        // Act
        $result = $this->callPrivate('_combineFilters', 'is_active = 1', 'name LIKE \'%foo%\'');

        // Assert
        $this->assertSame("where is_active = 1 AND name LIKE '%foo%'", $result);
    }

    /**
     * _combineFilters() must strip a leading "where" keyword from the base filter
     * so callers that pass raw WHERE clauses do not produce "WHERE where ..." SQL.
     *
     * This covers the `if (stripos($baseFilter, 'where') === 0)` branch (line ~2274).
     */
    public function testCombineFiltersStripsLeadingWhereKeyword(): void
    {
        // Act
        $result = $this->callPrivate('_combineFilters', 'WHERE is_active = 1', '');

        // Assert — duplicate WHERE stripped
        $this->assertSame('where is_active = 1', $result);
        $this->assertStringNotContainsString('WHERE where', $result);
    }

    /**
     * _combineFilters() case-insensitive stripping must also handle lowercase "where".
     */
    public function testCombineFiltersStripsLowercaseWhereKeyword(): void
    {
        // Act
        $result = $this->callPrivate('_combineFilters', 'where price > 10', '');

        // Assert
        $this->assertSame('where price > 10', $result);
    }

    // =========================================================================
    // _resolveFieldResultName() — additional alias quoting styles
    // =========================================================================

    /**
     * _resolveFieldResultName() must extract the alias from AS with backtick-quoted
     * identifier (MySQL style): "col AS `alias`" → "alias".
     *
     * This covers the backtick variant of the AS regex (line ~927).
     */
    public function testResolveFieldResultNameExtractBacktickQuotedAlias(): void
    {
        // Act
        $result = $this->callPrivate('_resolveFieldResultName', 'a.col AS `myalias`');

        // Assert
        $this->assertSame('myalias', $result);
    }

    /**
     * _resolveFieldResultName() must extract the alias from AS with double-quoted
     * identifier (PostgreSQL style): 'col AS "alias"' → 'alias'.
     */
    public function testResolveFieldResultNameExtractDoubleQuotedAlias(): void
    {
        // Act
        $result = $this->callPrivate('_resolveFieldResultName', 'a.col AS "myalias"');

        // Assert
        $this->assertSame('myalias', $result);
    }

    // =========================================================================
    // _buildSingleCondition() — edge cases
    // =========================================================================

    /**
     * _buildSingleCondition() with a null value and "=" operator must produce
     * "field IS NULL" — matching the SQL convention for null equality.
     *
     * This covers the `if (is_null($value)) { ... ($op === '=') ? 'IS NULL' : 'IS NOT NULL' }`
     * branch (line ~2253).
     */
    public function testBuildSingleConditionNullValueWithEqualsProducesIsNull(): void
    {
        // Arrange
        $db              = \Pramnos\Database\Database::getInstance();
        $availableFields = ['status'];
        $fieldMapping    = ['status' => 'status'];
        $condition       = ['field' => 'status', 'op' => '=', 'value' => null];

        // Act
        $result = $this->callPrivate(
            '_buildSingleCondition',
            $condition, $availableFields, $fieldMapping, false, $db
        );

        // Assert
        $this->assertSame('`status` IS NULL', $result);
    }

    /**
     * _buildSingleCondition() with a null value and "!=" operator must produce
     * "field IS NOT NULL".
     *
     * This covers the `else` branch of the null-value check (line ~2254).
     */
    public function testBuildSingleConditionNullValueWithNotEqualsProducesIsNotNull(): void
    {
        // Arrange
        $db              = \Pramnos\Database\Database::getInstance();
        $availableFields = ['status'];
        $fieldMapping    = ['status' => 'status'];
        $condition       = ['field' => 'status', 'op' => '!=', 'value' => null];

        // Act
        $result = $this->callPrivate(
            '_buildSingleCondition',
            $condition, $availableFields, $fieldMapping, false, $db
        );

        // Assert
        $this->assertSame('`status` IS NOT NULL', $result);
    }

    /**
     * _buildSingleCondition() with an integer value must use unquoted numeric
     * literal in the SQL expression, not a quoted string.
     *
     * This covers the `elseif (is_int($value) || is_float($value))` branch (line ~2255).
     */
    public function testBuildSingleConditionIntValueIsNotQuoted(): void
    {
        // Arrange
        $db              = \Pramnos\Database\Database::getInstance();
        $availableFields = ['quantity'];
        $fieldMapping    = ['quantity' => 'quantity'];
        $condition       = ['field' => 'quantity', 'op' => '>=', 'value' => 5];

        // Act
        $result = $this->callPrivate(
            '_buildSingleCondition',
            $condition, $availableFields, $fieldMapping, false, $db
        );

        // Assert — integer must not be surrounded by quotes
        $this->assertSame('`quantity` >= 5', $result);
    }

    /**
     * _buildSingleCondition() with a float value must also produce an unquoted
     * numeric literal.
     */
    public function testBuildSingleConditionFloatValueIsNotQuoted(): void
    {
        // Arrange
        $db              = \Pramnos\Database\Database::getInstance();
        $availableFields = ['price'];
        $fieldMapping    = ['price' => 'price'];
        $condition       = ['field' => 'price', 'op' => '<', 'value' => 9.99];

        // Act
        $result = $this->callPrivate(
            '_buildSingleCondition',
            $condition, $availableFields, $fieldMapping, false, $db
        );

        // Assert
        $this->assertSame('`price` < 9.99', $result);
    }

    /**
     * _buildSingleCondition() with an unknown field must return null so that
     * the condition is silently skipped.
     *
     * This covers the `if ($targetField === null) { return null; }` branch (line ~2205).
     */
    public function testBuildSingleConditionUnknownFieldReturnsNull(): void
    {
        // Arrange
        $db              = \Pramnos\Database\Database::getInstance();
        $availableFields = ['id', 'name'];
        $fieldMapping    = ['id' => 'id', 'name' => 'name'];
        $condition       = ['field' => 'nonexistent', 'op' => '=', 'value' => 1];

        // Act
        $result = $this->callPrivate(
            '_buildSingleCondition',
            $condition, $availableFields, $fieldMapping, false, $db
        );

        // Assert — unknown field → silently skipped
        $this->assertNull($result);
    }

    /**
     * _buildSingleCondition() with an invalid/unsupported operator must return null
     * to prevent SQL injection via operator injection.
     *
     * This covers the `if (!in_array($op, $allowedOps, true)) { return null; }`
     * guard (lines ~2186-2188 area).
     */
    public function testBuildSingleConditionInvalidOpReturnsNull(): void
    {
        // Arrange
        $db              = \Pramnos\Database\Database::getInstance();
        $availableFields = ['price'];
        $fieldMapping    = ['price' => 'price'];
        $condition       = ['field' => 'price', 'op' => 'DROP TABLE', 'value' => 0];

        // Act
        $result = $this->callPrivate(
            '_buildSingleCondition',
            $condition, $availableFields, $fieldMapping, false, $db
        );

        // Assert
        $this->assertNull($result);
    }

    /**
     * _buildSingleCondition() for scalar ops (=, !=, <, etc.) must return null
     * when the 'value' key is absent from the condition array.
     *
     * This covers the `if (!array_key_exists('value', $condition)) { return null; }`
     * guard (line ~2229).
     */
    public function testBuildSingleConditionMissingValueKeyReturnsNull(): void
    {
        // Arrange — no 'value' key
        $db              = \Pramnos\Database\Database::getInstance();
        $availableFields = ['name'];
        $fieldMapping    = ['name' => 'name'];
        $condition       = ['field' => 'name', 'op' => '='];

        // Act
        $result = $this->callPrivate(
            '_buildSingleCondition',
            $condition, $availableFields, $fieldMapping, false, $db
        );

        // Assert
        $this->assertNull($result);
    }

    /**
     * _buildSingleCondition() with IN and an empty array value must return null
     * because an empty IN list is not valid SQL.
     *
     * This covers the `!is_array($value) || empty($value)` check in IN handling
     * (line ~2237).
     */
    public function testBuildSingleConditionInWithEmptyArrayReturnsNull(): void
    {
        // Arrange
        $db              = \Pramnos\Database\Database::getInstance();
        $availableFields = ['id'];
        $fieldMapping    = ['id' => 'id'];
        $condition       = ['field' => 'id', 'op' => 'IN', 'value' => []];

        // Act
        $result = $this->callPrivate(
            '_buildSingleCondition',
            $condition, $availableFields, $fieldMapping, false, $db
        );

        // Assert
        $this->assertNull($result);
    }

    /**
     * _buildSingleCondition() with NOT IN and a non-array value must return null.
     *
     * This covers the `!is_array($value)` path for NOT IN (line ~2237).
     */
    public function testBuildSingleConditionNotInWithNonArrayReturnsNull(): void
    {
        // Arrange
        $db              = \Pramnos\Database\Database::getInstance();
        $availableFields = ['id'];
        $fieldMapping    = ['id' => 'id'];
        $condition       = ['field' => 'id', 'op' => 'NOT IN', 'value' => 'not-an-array'];

        // Act
        $result = $this->callPrivate(
            '_buildSingleCondition',
            $condition, $availableFields, $fieldMapping, false, $db
        );

        // Assert
        $this->assertNull($result);
    }

    /**
     * _buildSingleCondition() with LIKE on MySQL must produce "LIKE" (not ILIKE).
     *
     * This covers the `($op === 'LIKE' || $op === 'ILIKE')` branch (line ~2247)
     * for a MySQL (non-postgresql) engine where ILIKE is normalised to LIKE.
     *
     * Uses StubDatabaseForModel to avoid needing a live connection for prepareInput().
     */
    public function testBuildSingleConditionLikeOnMySqlProducesLike(): void
    {
        // Arrange — stub DB with type=mysql (non-postgresql)
        $stubDb          = new StubDatabaseForModel();
        $stubDb->type    = 'mysql';
        $availableFields = ['name'];
        $fieldMapping    = ['name' => 'name'];
        $condition       = ['field' => 'name', 'op' => 'ILIKE', 'value' => '%foo%'];

        // Act
        $result = $this->callPrivate(
            '_buildSingleCondition',
            $condition, $availableFields, $fieldMapping, false, $stubDb
        );

        // Assert — ILIKE must be normalised to LIKE on MySQL
        $this->assertStringContainsString('LIKE', $result);
        $this->assertStringNotContainsString('ILIKE', $result);
    }

    /**
     * _buildSingleCondition() with LIKE on PostgreSQL must produce "ILIKE".
     *
     * This covers the `($database->type === 'postgresql') ? 'ILIKE' : 'LIKE'`
     * selection (line ~2248).
     *
     * Uses StubDatabaseForModel to avoid needing a live PostgreSQL connection.
     */
    public function testBuildSingleConditionLikeOnPostgresProducesIlike(): void
    {
        // Arrange — stub DB with type=postgresql
        $stubDb          = new StubDatabaseForModel();
        $stubDb->type    = 'postgresql';
        $availableFields = ['name'];
        $fieldMapping    = ['name' => 'name'];
        $condition       = ['field' => 'name', 'op' => 'LIKE', 'value' => '%foo%'];

        // Act
        $result = $this->callPrivate(
            '_buildSingleCondition',
            $condition, $availableFields, $fieldMapping, false, $stubDb
        );

        // Assert — LIKE upgraded to ILIKE on PostgreSQL
        $this->assertStringContainsString('ILIKE', $result);
    }

    /**
     * _buildSingleCondition() must resolve a field that exists in the
     * fieldMapping but NOT directly in availableFields.
     *
     * This covers the `elseif (isset($fieldMapping[$fieldName]))` branch (line ~2201).
     *
     * Uses StubDatabaseForModel to avoid needing a live DB connection for prepareInput().
     */
    public function testBuildSingleConditionResolvesFieldViaMapping(): void
    {
        // Arrange — field is in mapping but not directly in availableFields
        $stubDb          = new StubDatabaseForModel();
        $stubDb->type    = 'mysql';
        $availableFields = ['a.name'];                    // prefixed in available
        $fieldMapping    = ['name' => 'a.name'];          // resolved via mapping
        $condition       = ['field' => 'name', 'op' => '=', 'value' => 'Alice'];

        // Act
        $result = $this->callPrivate(
            '_buildSingleCondition',
            $condition, $availableFields, $fieldMapping, false, $stubDb
        );

        // Assert — must use the resolved field reference
        $this->assertNotNull($result);
        $this->assertStringContainsString('a.name', $result);
    }

    // =========================================================================
    // _buildFilterFromConditions() — OR group and raw fragment integration
    // =========================================================================

    /**
     * _buildFilterFromConditions() must handle an empty conditions array and
     * return an empty string.
     *
     * This is a boundary case that covers the empty-iteration path (line ~2135).
     */
    public function testBuildFilterFromConditionsEmptyArrayReturnsEmpty(): void
    {
        // Act
        $result = $this->callPrivate('_buildFilterFromConditions', [], ['id', 'name']);

        // Assert
        $this->assertSame('', $result);
    }

    /**
     * _buildFilterFromConditions() must silently skip an OR group that contains
     * no valid conditions and produce an empty result rather than "()" or an error.
     *
     * This covers the `if (!empty($orParts))` guard (line ~2145).
     */
    public function testBuildFilterFromConditionsOrGroupWithAllInvalidConditionsSkipped(): void
    {
        // Arrange — OR group whose conditions all reference unknown fields
        $conditions = [[
            'or' => [
                ['field' => 'ghost', 'op' => '=', 'value' => 1],
                ['field' => 'phantom', 'op' => '=', 'value' => 2],
            ]
        ]];

        // Act
        $result = $this->callPrivate('_buildFilterFromConditions', $conditions, ['id']);

        // Assert — empty OR group must produce empty string
        $this->assertSame('', $result);
    }

    /**
     * _buildFilterFromConditions() must handle a raw fragment entry with an
     * empty string and not include it in the output.
     *
     * This covers the `if ($raw !== '')` guard (line ~2155).
     */
    public function testBuildFilterFromConditionsEmptyRawFragmentSkipped(): void
    {
        // Arrange
        $conditions = [['raw' => '   ']];

        // Act
        $result = $this->callPrivate('_buildFilterFromConditions', $conditions, ['id']);

        // Assert
        $this->assertSame('', $result);
    }

    /**
     * _buildFilterFromConditions() must handle a condition entry that has
     * neither 'field' nor 'or' nor 'raw' keys, and return null from
     * _buildSingleCondition — resulting in no output for that entry.
     */
    public function testBuildFilterFromConditionsIgnoresEntriesWithoutField(): void
    {
        // Arrange — malformed condition with neither field/or/raw
        $conditions = [
            ['something_else' => 'value'],
            ['field' => 'id', 'op' => '=', 'value' => 1],
        ];

        // Act
        $result = $this->callPrivate('_buildFilterFromConditions', $conditions, ['id']);

        // Assert — only the valid condition appears
        $this->assertStringContainsString('`id` = 1', $result);
        // Malformed entry silently skipped
        $this->assertStringNotContainsString('something_else', $result);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** Call a private method via reflection. */
    private function callPrivate(string $method, mixed ...$args): mixed
    {
        $rm = new \ReflectionMethod(Model::class, $method);
        return $rm->invoke($this->model, ...$args);
    }

    /** Call a protected method via reflection. */
    private function callProtected(string $method, mixed ...$args): mixed
    {
        $rm = new \ReflectionMethod(Model::class, $method);
        return $rm->invoke($this->model, ...$args);
    }

    /** Set a property value via reflection (walks the class hierarchy). */
    private function setProp(string $prop, mixed $value): void
    {
        $class = new \ReflectionClass($this->model);
        while ($class) {
            if ($class->hasProperty($prop)) {
                $rp = $class->getProperty($prop);
                $rp->setValue($this->model, $value);
                return;
            }
            $class = $class->getParentClass();
        }
        throw new \RuntimeException("Property {$prop} not found in class hierarchy");
    }

    /** Get a property value via reflection (walks the class hierarchy). */
    private function getProp(string $prop): mixed
    {
        $class = new \ReflectionClass($this->model);
        while ($class) {
            if ($class->hasProperty($prop)) {
                $rp = $class->getProperty($prop);
                return $rp->getValue($this->model);
            }
            $class = $class->getParentClass();
        }
        throw new \RuntimeException("Property {$prop} not found in class hierarchy");
    }
}
