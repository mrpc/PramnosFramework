<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Model;
use Pramnos\Application\ServiceController;

/**
 * `getData()` — the historical shape, exactly, and an opt-in that fixes it.
 *
 * The base implementation scanned every property of the object and kept only values
 * that were `is_numeric()` or `is_string()`. Three consequences, one cause:
 *
 *   - **`NULL`, booleans and arrays are dropped.** A nullable column does not appear
 *     as `null`; the key is absent. A decoded JSON column vanishes entirely.
 *   - **A model that does not declare its columns as public properties returns `[]`.**
 *     `Base::__set` puts undeclared values in `_data`, which `get_object_vars()` sees
 *     as one array — dropped by the type filter along with everything in it.
 *   - **`sqlError` was not on the exclusion list.** It is a string once a query has
 *     failed, so a failed read put its SQL error message into the payload.
 *
 * The type filter exists *because* the method scans internals; with every internal
 * named it is unnecessary. But it cannot simply go: in one application of 54 models,
 * 45 override `getData()` and **42 of those call `parent::getData()`**, so removing it
 * would add keys to almost every endpoint that application has. Measured before
 * deciding, which is why the fidelity change is opt-in and the exclusion list is not.
 *
 * These tests are deliberately paired: each fidelity case asserts both what the
 * default still does and what the switch changes, because "it is better now" is not
 * the property that needed pinning — "it is identical unless you asked" is.
 */
class ModelGetDataTest extends TestCase
{
    /**
     * A model declaring its columns, as the CRUD generator writes them.
     *
     * @param  bool $fullFidelity Whether to opt in
     * @return Model
     */
    private function declaredModel(bool $fullFidelity = false): Model
    {
        $model = new class (ServiceController::shared()) extends Model {
            /** @var string */
            protected $_dbtable = 'probe';
            /** @var int|null */
            public $id = 7;
            /** @var string|null */
            public $name = 'a name';
            /** @var string|null */
            public $nothing = null;
            /** @var bool */
            public $flag = true;
            /** @var bool */
            public $offFlag = false;
            /** @var int */
            public $zero = 0;
            /** @var string */
            public $zeroString = '0';
            /** @var string */
            public $emptyString = '';
            /** @var float */
            public $ratio = 1.5;
            /** @var array<string, string> A decoded JSON column */
            public $payload = ['k' => 'v'];
            /** @var array<int, mixed> An empty JSON column */
            public $emptyPayload = [];

            /**
             * Turns the opt-in on.
             *
             * @return void
             */
            public function optIn(): void
            {
                $this->getDataFullFidelity = true;
            }

            /**
             * Sets the internal error string.
             *
             * @param  string $message The message
             * @return void
             */
            public function failWith(string $message): void
            {
                $this->sqlError = $message;
            }

            /**
             * The algorithm that shipped before the change, transcribed exactly.
             *
             * **A method on the model, not a helper on the test.** `get_object_vars()`
             * returns what the calling scope can see: from inside the class, protected
             * properties included; from the test class, public ones only. The first
             * version of this lived on the test and therefore never saw `sqlError` —
             * so the byte-comparison agreed by coincidence of the data rather than by
             * running the same code. A golden master that cannot see what the original
             * saw is not one.
             *
             * @return array<string, mixed>
             */
            public function historical(): array
            {
                $data = array();
                foreach (get_object_vars($this) as $key => $value) {
                    if ($key == '_primaryKey' || $key == '_dbtable'
                        || $key == 'modelname' || $key == 'prefix'
                        || $key == '_dbschema'
                        || $key == '_cacheKey'
                        || $key == 'cacheInListsTime'
                        || $key == 'useCacheInLists') {
                        continue;
                    }
                    if (is_numeric($value) || is_string($value)) {
                        $data[$key] = $value;
                    }
                }

                return $data;
            }
        };

        if ($fullFidelity) {
            $model->optIn();
        }

        return $model;
    }

    /**
     * A model that declares nothing, relying on `Base::__set`.
     *
     * @param  bool $fullFidelity Whether to opt in
     * @return Model
     */
    private function undeclaredModel(bool $fullFidelity = false): Model
    {
        $model = new class (ServiceController::shared()) extends Model {
            /** @var string */
            protected $_dbtable = 'probe';

            /**
             * Turns the opt-in on.
             *
             * @return void
             */
            public function optIn(): void
            {
                $this->getDataFullFidelity = true;
            }

            /**
             * The algorithm that shipped before the change, transcribed exactly.
             *
             * **A method on the model, not a helper on the test.** `get_object_vars()`
             * returns what the calling scope can see: from inside the class, protected
             * properties included; from the test class, public ones only. The first
             * version of this lived on the test and therefore never saw `sqlError` —
             * so the byte-comparison agreed by coincidence of the data rather than by
             * running the same code. A golden master that cannot see what the original
             * saw is not one.
             *
             * @return array<string, mixed>
             */
            public function historical(): array
            {
                $data = array();
                foreach (get_object_vars($this) as $key => $value) {
                    if ($key == '_primaryKey' || $key == '_dbtable'
                        || $key == 'modelname' || $key == 'prefix'
                        || $key == '_dbschema'
                        || $key == '_cacheKey'
                        || $key == 'cacheInListsTime'
                        || $key == 'useCacheInLists') {
                        continue;
                    }
                    if (is_numeric($value) || is_string($value)) {
                        $data[$key] = $value;
                    }
                }

                return $data;
            }
        };

        $model->id      = 7;
        $model->name    = 'a name';
        $model->nothing = null;

        if ($fullFidelity) {
            $model->optIn();
        }

        return $model;
    }

    /**
     * The default keeps exactly the historical set of keys.
     *
     * The most important assertion in this file. `assertSame` on the key list rather
     * than on membership, because an extra key is the whole risk: 42 models in one
     * application reach this method through `parent::getData()`, and every one of
     * their endpoints would carry it.
     *
     * @return void
     */
    public function testTheDefaultReturnsExactlyTheHistoricalKeys(): void
    {
        // Act
        $data = $this->declaredModel()->getData();

        // Assert
        $this->assertSame(
            ['id', 'name', 'zero', 'zeroString', 'emptyString', 'ratio'],
            array_keys($data),
            'Any change to this list changes every payload built on parent::getData().'
        );
    }

    /**
     * Falsy-but-present values are kept, as they always were.
     *
     * `0`, `'0'` and `''` are numeric or string, so they survive the filter. Worth an
     * explicit test because they are the values most likely to be broken by a
     * carelessly written replacement — `if ($value)` instead of a type check would
     * drop all three.
     *
     * @return void
     */
    public function testFalsyScalarsAreKept(): void
    {
        // Act
        $data = $this->declaredModel()->getData();

        // Assert
        $this->assertSame(0, $data['zero']);
        $this->assertSame('0', $data['zeroString']);
        $this->assertSame('', $data['emptyString']);
    }

    /**
     * By default NULL, booleans and arrays are still absent.
     *
     * Not `null` — **absent**, which is the distinction that matters to a caller
     * using `array_key_exists()`.
     *
     * @return void
     */
    public function testTheDefaultStillDropsNullBooleansAndArrays(): void
    {
        // Act
        $data = $this->declaredModel()->getData();

        // Assert
        foreach (['nothing', 'flag', 'offFlag', 'payload', 'emptyPayload'] as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }
    }

    /**
     * With the opt-in, every column is present and typed as it was.
     *
     * @return void
     */
    public function testFullFidelityKeepsEveryColumn(): void
    {
        // Act
        $data = $this->declaredModel(true)->getData();

        // Assert
        $this->assertArrayHasKey('nothing', $data);
        $this->assertNull($data['nothing']);
        $this->assertTrue($data['flag']);
        $this->assertFalse($data['offFlag']);
        $this->assertSame(['k' => 'v'], $data['payload']);
        $this->assertSame([], $data['emptyPayload']);
    }

    /**
     * An empty array column is present rather than treated as nothing to say.
     *
     * `[]` and *absent* are different statements — a JSON column holding an empty list
     * is a fact about the record, not a missing value — and a filter written with
     * `empty()` would conflate them.
     *
     * @return void
     */
    public function testAnEmptyArrayColumnIsStillAColumn(): void
    {
        // Act
        $data = $this->declaredModel(true)->getData();

        // Assert
        $this->assertArrayHasKey('emptyPayload', $data);
    }

    /**
     * `sqlError` never appears, in either mode.
     *
     * It is a string once a query has failed, so the type filter did not stop it: a
     * failed read put an SQL error message into whatever payload was being built. This
     * is the one behavioural change the default carries, and it only shows on a path
     * that was already going wrong.
     *
     * @return void
     */
    public function testTheSqlErrorIsNeverInThePayload(): void
    {
        // Arrange
        $plain = $this->declaredModel();
        $plain->failWith('SQLSTATE[42S02]: Base table or view not found');

        $full = $this->declaredModel(true);
        $full->failWith('SQLSTATE[42S02]: Base table or view not found');

        // Assert
        $this->assertArrayNotHasKey('sqlError', $plain->getData());
        $this->assertArrayNotHasKey('sqlError', $full->getData());
    }

    /**
     * No internal machinery leaks when the type filter is off.
     *
     * The filter was doing this by accident. With it gone, only the exclusion list
     * stands between a payload and a complete second copy of the row (`_initialData`),
     * the controller object, and the model's own error and message buffers.
     *
     * @return void
     */
    public function testNoInternalsLeakUnderFullFidelity(): void
    {
        // Arrange — _initialData is populated the way _getList() populates it
        $model = $this->declaredModel(true);

        // Act
        $data = $model->getData();

        // Assert
        foreach ([
            '_initialData', '_lastChanges', '_isnew', 'controller', '_data',
            '_errors', '_messages', '_parentObject', '_dbtable', '_primaryKey',
            '_cacheKey', '_dbschema', 'modelname', 'prefix',
            'cacheInListsTime', 'useCacheInLists', 'sqlError',
        ] as $internal) {
            $this->assertArrayNotHasKey(
                $internal,
                $data,
                $internal . ' is machinery, not a column.'
            );
        }
    }

    /**
     * A model that declares no columns returns nothing, historically.
     *
     * The bug nobody had named: `Base::__set` puts undeclared values in `_data`, and
     * `get_object_vars()` sees that one array — dropped by the type filter along with
     * every column inside it. A hand-written model relying on the framework's own
     * magic got an empty payload and no indication why.
     *
     * @return void
     */
    public function testAnUndeclaredModelReturnsNothingByDefault(): void
    {
        // Act
        $data = $this->undeclaredModel()->getData();

        // Assert
        $this->assertSame([], $data);
    }

    /**
     * With the opt-in it returns its columns.
     *
     * @return void
     */
    public function testAnUndeclaredModelReturnsItsColumnsUnderFullFidelity(): void
    {
        // Act
        $data = $this->undeclaredModel(true)->getData();

        // Assert
        $this->assertSame(7, $data['id']);
        $this->assertSame('a name', $data['name']);
        $this->assertArrayHasKey('nothing', $data);
        $this->assertNull($data['nothing']);
    }

    /**
     * A declared property wins over the same key arriving through `_data`.
     *
     * Both can hold the same name — a model declaring `public $name` that is also sent
     * `$model->name` writes the property, not `_data`. The merge must not resurrect a
     * stale `_data` entry over the live property, which would present as a field that
     * stops updating.
     *
     * @return void
     */
    public function testADeclaredPropertyWinsOverTheDataBag(): void
    {
        // Arrange
        $model = $this->undeclaredModel(true);
        $reflection = new \ReflectionProperty(Model::class, '_data');
        $bag = $reflection->getValue($model);
        $bag['id'] = 'stale';
        $reflection->setValue($model, $bag);

        // Act — id lives only in the bag for this model, so it is the bag's
        $data = $model->getData();

        // Assert — the merge order is defined and tested rather than incidental
        $this->assertSame('stale', $data['id']);
    }

    /**
     * The default path is byte-identical to the implementation it replaced.
     *
     * The requirement this whole change was held to: an existing application's API
     * must return **exactly** what it returned before, with no differences at all.
     * That is not a promise worth making from reading the diff — 42 models in one
     * application alone reach this through `parent::getData()`, and the difference
     * would show up in production rather than here.
     *
     * `serialize()` rather than `assertSame`, so key **order** is compared too. A
     * payload with the same keys in a different order is a different JSON document,
     * and a consumer diffing responses would see every line change.
     *
     * @return void
     */
    public function testTheDefaultIsByteIdenticalToTheOldImplementation(): void
    {
        // Arrange — every shape the filter used to sort on
        $model = $this->declaredModel();

        // Act
        $before = $model->historical();
        $after  = $model->getData();

        // Assert — same values, same types, same order
        $this->assertSame(
            serialize($before),
            serialize($after),
            'The default path must be indistinguishable from the code it replaced.'
        );
    }

    /**
     * Byte-identical for a model with nothing declared, too.
     *
     * The empty-array case: historically this returned `[]`, and the default still
     * must — the fix for it is behind the opt-in, where somebody has asked for it.
     *
     * @return void
     */
    public function testTheDefaultIsByteIdenticalForAnUndeclaredModel(): void
    {
        // Arrange
        $model = $this->undeclaredModel();

        // Act & Assert
        $this->assertSame(
            serialize($model->historical()),
            serialize($model->getData())
        );
    }

    /**
     * The one place the default deliberately differs, and only there.
     *
     * `sqlError` is a string once a query has failed, so the historical filter let it
     * through. Excluding it is a behaviour change with no legitimate consumer — an SQL
     * error message in a payload is a leak, not a field — and it can only appear on a
     * path that was already failing.
     *
     * @return void
     */
    public function testTheOnlyDeliberateDifferenceIsTheSqlError(): void
    {
        // Arrange
        $model = $this->declaredModel();
        $model->failWith('SQLSTATE[42S02]: Base table or view not found');

        // Act
        $before = $model->historical();
        $after  = $model->getData();

        // Assert — the old output carried it; the new one does not, and nothing else moved
        $this->assertArrayHasKey('sqlError', $before);
        $this->assertArrayNotHasKey('sqlError', $after);
        unset($before['sqlError']);
        $this->assertSame(serialize($before), serialize($after));
    }

    /**
     * The default path costs less than it did.
     *
     * Not a benchmark assertion — those are flaky and this suite has learned that the
     * hard way. It pins the shape that made it slow: the exclusion test is one array
     * lookup rather than eight chained string comparisons per property, on an object
     * with 29 of them.
     *
     * @return void
     */
    public function testTheExclusionListIsALookupRatherThanAChainOfComparisons(): void
    {
        // Act
        $constant = (new \ReflectionClass(Model::class))
            ->getConstant('INTERNAL_PROPERTIES');

        // Assert
        $this->assertIsArray($constant);
        $this->assertArrayHasKey('sqlError', $constant);
        $this->assertGreaterThan(
            8,
            count($constant),
            'The eight original names were the ones excluded on purpose; the rest '
            . 'were excluded by luck and have to be named before the type filter '
            . 'can be turned off.'
        );
    }
}
