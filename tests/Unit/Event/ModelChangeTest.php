<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use Pramnos\Event\ModelChange;

/**
 * Unit tests for Pramnos\Event\ModelChange.
 *
 * The value object is the contract between everything that emits a change and everything
 * that consumes one, so what is tested here is the shape rather than any behaviour:
 *  - the filters return what the caller asked for and nothing invented;
 *  - absent fields stay absent rather than becoming null;
 *  - toArray() carries every property, so a listener that serialises loses nothing.
 */
class ModelChangeTest extends TestCase
{
    /**
     * Build a change with sensible defaults, overriding only what a test cares about.
     *
     * @param array<string, mixed> $overrides
     */
    private function change(array $overrides = []): ModelChange
    {
        $defaults = [
            'entity'          => 'wcm-device',
            'key'             => 42,
            'op'              => ModelChange::UPDATED,
            'data'            => ['deviceid' => 42, 'status' => 3, 'eui' => 'AABB'],
            'changes'         => ['status' => ['old' => 1, 'new' => 3]],
            'channels'        => ['private-wcm-device', 'private-wcm-device.42'],
            'broadcastFields' => null,
            'userid'          => 7,
            'source'          => ModelChange::SOURCE_WEB,
            'at'              => 1756000000,
            'model'           => 'App\\Models\\Device',
            'table'           => 'devices',
        ];

        $args = array_merge($defaults, $overrides);

        return new ModelChange(
            $args['entity'],
            $args['key'],
            $args['op'],
            $args['data'],
            $args['changes'],
            $args['channels'],
            $args['broadcastFields'],
            $args['userid'],
            $args['source'],
            $args['at'],
            $args['model'],
            $args['table'],
        );
    }

    /**
     * has() reports a field that changed, and one that did not.
     *
     * This is what a listener uses to decide whether a change is interesting to it —
     * a significance test cheaper than reading the values.
     */
    public function testHasReportsChangedFields(): void
    {
        // Arrange
        $change = $this->change();

        // Act & Assert
        $this->assertTrue($change->has('status'));
        $this->assertFalse($change->has('eui'));
    }

    /**
     * has() is true for a field that changed *to* null.
     *
     * array_key_exists() rather than isset(): a column cleared to NULL is a change, and
     * isset() would report it as no change at all — the kind of miss that only shows up
     * as a stale row on somebody's screen.
     */
    public function testHasIsTrueForAFieldChangedToNull(): void
    {
        // Arrange
        $change = $this->change([
            'changes' => ['conditionid' => ['old' => 5, 'new' => null]],
        ]);

        // Act & Assert
        $this->assertTrue($change->has('conditionid'));
    }

    /**
     * only() returns the named fields from the record.
     */
    public function testOnlyReturnsNamedFields(): void
    {
        // Arrange
        $change = $this->change();

        // Act
        $result = $change->only(['deviceid', 'status']);

        // Assert
        $this->assertSame(['deviceid' => 42, 'status' => 3], $result);
    }

    /**
     * only() omits a requested field the record does not have.
     *
     * The alternative — present and null — would put keys on a broadcast wire that
     * nothing in the record put there, and a client cannot tell an invented null from a
     * real one.
     */
    public function testOnlyOmitsFieldsAbsentFromTheRecord(): void
    {
        // Arrange
        $change = $this->change();

        // Act
        $result = $change->only(['deviceid', 'nosuchfield']);

        // Assert
        $this->assertSame(['deviceid' => 42], $result);
        $this->assertArrayNotHasKey('nosuchfield', $result);
    }

    /**
     * except() returns the record without the named fields.
     *
     * This is the ignore-list path: a model that never wants `viewcache` in a payload
     * removes it here rather than at every listener.
     */
    public function testExceptRemovesNamedFields(): void
    {
        // Arrange
        $change = $this->change();

        // Act
        $result = $change->except(['eui']);

        // Assert
        $this->assertSame(['deviceid' => 42, 'status' => 3], $result);
    }

    /**
     * changesOnly() filters the diff, not the record.
     *
     * The two are different maps — `data` is field => value, `changes` is
     * field => {old,new} — and a broadcaster filtering one with the other's keys would
     * publish an empty diff beside a full payload.
     */
    public function testChangesOnlyFiltersTheDiff(): void
    {
        // Arrange
        $change = $this->change([
            'changes' => [
                'status' => ['old' => 1, 'new' => 3],
                'eui'    => ['old' => 'AAAA', 'new' => 'AABB'],
            ],
        ]);

        // Act
        $result = $change->changesOnly(['status']);

        // Assert
        $this->assertSame(['status' => ['old' => 1, 'new' => 3]], $result);
    }

    /**
     * toArray() carries every constructor property.
     *
     * Asserted against the constructor's own parameter list rather than a hand-written
     * key list, so adding a property without adding it to toArray() fails here instead
     * of silently dropping it from every serialised listener payload.
     */
    public function testToArrayCarriesEveryProperty(): void
    {
        // Arrange
        $change     = $this->change();
        $reflection = new \ReflectionClass(ModelChange::class);
        $expected   = array_map(
            static fn(\ReflectionParameter $p): string => $p->getName(),
            $reflection->getConstructor()->getParameters()
        );

        // Act
        $array = $change->toArray();

        // Assert
        sort($expected);
        $actual = array_keys($array);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    /**
     * A null key survives — a delete may not have one.
     *
     * _delete() can be called with a key the loaded model does not hold, so the feed has
     * to be able to say "something was deleted and I do not know which row".
     */
    public function testKeyMayBeNull(): void
    {
        // Arrange & Act
        $change = $this->change(['key' => null, 'op' => ModelChange::DELETED]);

        // Assert
        $this->assertNull($change->key);
        $this->assertSame(ModelChange::DELETED, $change->op);
    }
}
