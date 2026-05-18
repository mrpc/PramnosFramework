<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Model;

/**
 * Unit tests for the pure private/protected helper methods of Model.
 *
 * The Model constructor requires a Controller and a live DB connection.
 * We bypass the constructor via newInstanceWithoutConstructor() and set only
 * the minimal properties needed by each pure helper.
 *
 * Tests cover:
 *   - _resolveFieldResultName(): SQL alias/prefix stripping
 *   - _stripSqlKeyword(): leading SQL keyword removal
 *   - fieldtype(): DB type → framework type mapping
 *   - getLastSaveChanges(): simple getter for $_lastChanges
 *   - getChanges(): diff between $_initialData and current properties
 *   - getData(): filtered property export
 *   - addJsonAction(): JSON action registry
 */
#[CoversClass(Model::class)]
class ModelPureHelpersTest extends TestCase
{
    private Model $model;

    protected function setUp(): void
    {
        // Arrange – bypass constructor (needs Controller + live DB)
        $rc          = new \ReflectionClass(Model::class);
        $this->model = $rc->newInstanceWithoutConstructor();

        // Set minimal required properties so helpers work without a DB
        $this->setProp('_primaryKey', 'id');
        $this->setProp('_dbtable', 'items');
        $this->setProp('_isnew', true);
        $this->setProp('_initialData', []);
        $this->setProp('_lastChanges', []);
        $this->setProp('_jsonactions', []);
        $this->setProp('prefix', '');
        $this->setProp('_cacheKey', null);
        // Base::$_data is needed for getChanges() dynamic property access
        $parentRef = new \ReflectionClass(\Pramnos\Framework\Base::class);
        $dataProp  = $parentRef->getProperty('_data');
        $dataProp->setAccessible(true);
        $dataProp->setValue($this->model, []);
    }

    // ── _resolveFieldResultName() ─────────────────────────────────────────────

    /**
     * An "expr AS alias" expression must return only the alias.
     *
     * This covers the `preg_match('/\bAS\s+…/i', …)` branch in
     * _resolveFieldResultName() (line ~927).
     */
    public function testResolveFieldResultNameExtractsAlias(): void
    {
        // Act
        $result = $this->callPrivate('_resolveFieldResultName', 'COUNT(*) AS total');

        // Assert
        $this->assertSame('total', $result,
            '"expr AS alias" must resolve to "alias"');
    }

    /**
     * A field with a table prefix (e.g. "u.email") must strip the prefix.
     *
     * This covers the `strpos($field, '.')` branch (line ~931).
     */
    public function testResolveFieldResultNameStripsTablePrefix(): void
    {
        // Act
        $result = $this->callPrivate('_resolveFieldResultName', 'u.email');

        // Assert
        $this->assertSame('email', $result,
            '"table.column" must resolve to "column"');
    }

    /**
     * Backtick-quoted identifiers must have their quotes stripped.
     *
     * This covers the `trim($field, '"\`\'')` final step (line ~935).
     */
    public function testResolveFieldResultNameStripsBacktickQuotes(): void
    {
        // Act
        $result = $this->callPrivate('_resolveFieldResultName', '`username`');

        // Assert
        $this->assertSame('username', $result,
            'Backtick-quoted identifiers must be unquoted');
    }

    /**
     * A plain unqualified column name must be returned as-is (trimmed).
     *
     * This covers the default path when no alias and no table prefix exist.
     */
    public function testResolveFieldResultNameReturnsBareColumnName(): void
    {
        // Act
        $result = $this->callPrivate('_resolveFieldResultName', ' created_at ');

        // Assert
        $this->assertSame('created_at', $result);
    }

    // ── _stripSqlKeyword() ────────────────────────────────────────────────────

    /**
     * _stripSqlKeyword() must remove a leading keyword (case-insensitive) and
     * the following whitespace.
     *
     * This covers the preg_replace in _stripSqlKeyword() (line ~940).
     */
    public function testStripSqlKeywordRemovesLeadingKeyword(): void
    {
        // Act
        $result = $this->callPrivate('_stripSqlKeyword', 'ORDER BY created_at DESC', 'ORDER BY');

        // Assert
        $this->assertSame('created_at DESC', $result);
    }

    /**
     * _stripSqlKeyword() must be case-insensitive.
     */
    public function testStripSqlKeywordIsCaseInsensitive(): void
    {
        // Act
        $result = $this->callPrivate('_stripSqlKeyword', 'where id = 1', 'WHERE');

        // Assert
        $this->assertSame('id = 1', $result);
    }

    /**
     * _stripSqlKeyword() must return the original string when the keyword
     * is not at the start.
     */
    public function testStripSqlKeywordDoesNotRemoveMidStringKeyword(): void
    {
        // Arrange — keyword appears in the middle, not at the start
        $sql = 'id = 1 ORDER BY created_at';

        // Act
        $result = $this->callPrivate('_stripSqlKeyword', $sql, 'ORDER BY');

        // Assert — unchanged (regex only matches at the start)
        $this->assertSame($sql, $result);
    }

    // ── fieldtype() ───────────────────────────────────────────────────────────

    /**
     * Integer DB types must map to "integer".
     *
     * This covers the int/tinyint/integer/smallint/bigint cases (lines ~991-996).
     */
    public function testFieldtypeMapsIntegerTypesToInteger(): void
    {
        foreach (['int', 'tinyint', 'integer', 'smallint', 'bigint'] as $type) {
            $result = $this->callPrivate('fieldtype', $type);
            $this->assertSame('integer', $result,
                "DB type '{$type}' must map to 'integer'");
        }
    }

    /**
     * Float DB types must map to "float".
     *
     * This covers double/float/real/numeric/decimal/money (lines ~997-1004).
     */
    public function testFieldtypeMapsFloatTypesToFloat(): void
    {
        foreach (['double', 'float', 'real', 'numeric', 'decimal', 'money'] as $type) {
            $result = $this->callPrivate('fieldtype', $type);
            $this->assertSame('float', $result,
                "DB type '{$type}' must map to 'float'");
        }
    }

    /**
     * Boolean DB types must map to "boolean".
     *
     * This covers the boolean/bool cases (lines ~1008-1009).
     */
    public function testFieldtypeMapsBooleanToBoolean(): void
    {
        foreach (['boolean', 'bool'] as $type) {
            $result = $this->callPrivate('fieldtype', $type);
            $this->assertSame('boolean', $result,
                "DB type '{$type}' must map to 'boolean'");
        }
    }

    /**
     * JSON DB types must map to "json".
     *
     * This covers the json/jsonb cases (lines ~1011-1012).
     */
    public function testFieldtypeMapsJsonToJson(): void
    {
        foreach (['json', 'jsonb'] as $type) {
            $result = $this->callPrivate('fieldtype', $type);
            $this->assertSame('json', $result);
        }
    }

    /**
     * Timestamp DB types must map to "timestamp".
     *
     * This covers the timestamp/time variants (lines ~1014-1021).
     */
    public function testFieldtypeMapsTimestampToTimestamp(): void
    {
        foreach (['timestamp', 'timestamptz', 'time', 'timetz'] as $type) {
            $result = $this->callPrivate('fieldtype', $type);
            $this->assertSame('timestamp', $result,
                "DB type '{$type}' must map to 'timestamp'");
        }
    }

    /**
     * Geometry DB type must map to "geometry".
     *
     * This covers the geometry case (line ~1006).
     */
    public function testFieldtypeMapsGeometryToGeometry(): void
    {
        $result = $this->callPrivate('fieldtype', 'geometry');
        $this->assertSame('geometry', $result);
    }

    /**
     * Unknown / text DB types must fall through to the default "string" mapping.
     *
     * This covers the default case (line ~1023) for types like varchar, text, etc.
     */
    public function testFieldtypeMapsUnknownTypesToString(): void
    {
        foreach (['varchar', 'text', 'char', 'blob', 'bytea', 'unknown_type'] as $type) {
            $result = $this->callPrivate('fieldtype', $type);
            $this->assertSame('string', $result,
                "Unknown DB type '{$type}' must fall back to 'string'");
        }
    }

    /**
     * fieldtype() must strip size qualifiers like "varchar(255)" → "varchar"
     * before the switch.
     *
     * This covers the `explode("(", $type)` stripping at line ~987.
     */
    public function testFieldtypeStripsParenthesizedSizeQualifier(): void
    {
        // Act — "int(11)" should map to integer just like plain "int"
        $result = $this->callPrivate('fieldtype', 'int(11)');
        $this->assertSame('integer', $result);

        // Act — "varchar(255)" should map to string
        $result2 = $this->callPrivate('fieldtype', 'varchar(255)');
        $this->assertSame('string', $result2);
    }

    // ── getLastSaveChanges() ──────────────────────────────────────────────────

    /**
     * getLastSaveChanges() must return the current value of $_lastChanges.
     *
     * This covers the simple getter at line ~1084.
     */
    public function testGetLastSaveChangesReturnsLastChangesArray(): void
    {
        // Arrange — inject known changes
        $changes = ['name' => ['old' => 'Alice', 'new' => 'Bob']];
        $this->setProp('_lastChanges', $changes);

        // Act
        $result = $this->model->getLastSaveChanges();

        // Assert
        $this->assertSame($changes, $result);
    }

    // ── getChanges() ──────────────────────────────────────────────────────────

    /**
     * getChanges() must return an empty array when the model is new (_isnew=true).
     *
     * This covers the `if ($this->_isnew || empty($this->_initialData))` branch
     * (line ~1102).
     */
    public function testGetChangesReturnsEmptyWhenModelIsNew(): void
    {
        // Arrange — model is new (set in setUp)
        $this->setProp('_isnew', true);
        $this->setProp('_initialData', ['name' => 'Alice']);

        // Act
        $result = $this->model->getChanges();

        // Assert
        $this->assertSame([], $result,
            'New model must always report no changes');
    }

    /**
     * getChanges() must return an empty array when _initialData is empty.
     *
     * This covers the `empty($this->_initialData)` path (line ~1102).
     */
    public function testGetChangesReturnsEmptyWhenNoInitialData(): void
    {
        // Arrange — not new but no initial data
        $this->setProp('_isnew', false);
        $this->setProp('_initialData', []);

        // Act
        $result = $this->model->getChanges();

        // Assert
        $this->assertSame([], $result);
    }

    // ── addJsonAction() ───────────────────────────────────────────────────────

    /**
     * addJsonAction() must register an action in the internal _jsonactions array.
     *
     * This covers addJsonAction() (lines ~1035-1045): the action, field, column,
     * title, and confirm are stored keyed by action name.
     */
    public function testAddJsonActionRegistersAction(): void
    {
        // Act
        $this->model->addJsonAction('edit', 'id', 'actions', 'Edit', false);

        // Assert – action stored in the private array
        $actions = $this->getProp('_jsonactions');
        $this->assertArrayHasKey('edit', $actions);
        $this->assertSame('edit', $actions['edit']['action']);
        $this->assertSame('id',   $actions['edit']['field']);
        $this->assertFalse($actions['edit']['confirm']);
    }

    /**
     * addJsonAction() with confirm=true must store confirm as true.
     */
    public function testAddJsonActionStoresConfirmTrue(): void
    {
        // Act
        $this->model->addJsonAction('delete', 'id', 'actions', 'Delete', true);

        // Assert
        $actions = $this->getProp('_jsonactions');
        $this->assertTrue($actions['delete']['confirm']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Call a private/protected method via reflection. */
    private function callPrivate(string $method, mixed ...$args): mixed
    {
        $rm = new \ReflectionMethod(Model::class, $method);
        $rm->setAccessible(true);
        return $rm->invoke($this->model, ...$args);
    }

    /** Set a property value via reflection. */
    private function setProp(string $prop, mixed $value): void
    {
        // Walk the class hierarchy to find the property
        $class = new \ReflectionClass($this->model);
        while ($class) {
            if ($class->hasProperty($prop)) {
                $rp = $class->getProperty($prop);
                $rp->setAccessible(true);
                $rp->setValue($this->model, $value);
                return;
            }
            $class = $class->getParentClass();
        }
        throw new \RuntimeException("Property {$prop} not found");
    }

    /** Get a property value via reflection. */
    private function getProp(string $prop): mixed
    {
        $class = new \ReflectionClass($this->model);
        while ($class) {
            if ($class->hasProperty($prop)) {
                $rp = $class->getProperty($prop);
                $rp->setAccessible(true);
                return $rp->getValue($this->model);
            }
            $class = $class->getParentClass();
        }
        throw new \RuntimeException("Property {$prop} not found");
    }
}
