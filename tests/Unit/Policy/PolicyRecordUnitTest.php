<?php

namespace Pramnos\Tests\Unit\Policy;

use PHPUnit\Framework\TestCase;
use Pramnos\Policy\PolicyRecord;

/**
 * Unit tests for PolicyRecord value object.
 *
 * PolicyRecord::fromRow() is the primary entry point — it converts raw
 * database rows into typed, immutable objects. These tests confirm that the
 * conversion handles all field types correctly, including JSON config decoding.
 */
class PolicyRecordUnitTest extends TestCase
{
    // =========================================================================
    // fromRow()
    // =========================================================================

    /**
     * fromRow() must map all fields from a complete database row to the correct
     * properties with correct types.
     */
    public function testFromRowMapsAllFields(): void
    {
        // Arrange
        $row = [
            'policyid'    => '42',
            'policy_type' => 'retention',
            'target'      => 'sensor_data',
            'config'      => '{"interval":"30 days","time_column":"recorded_at"}',
            'enabled'     => '1',
            'last_run'    => '2025-03-01 02:00:00',
            'next_run'    => '2025-04-01 02:00:00',
            'last_result' => 'ok',
            'last_error'  => null,
            'created_at'  => '2024-01-01 00:00:00',
        ];

        // Act
        $record = PolicyRecord::fromRow($row);

        // Assert — each field has the right type and value
        $this->assertSame(42,              $record->policyid);
        $this->assertSame('retention',     $record->policyType);
        $this->assertSame('sensor_data',   $record->target);
        $this->assertSame('30 days',       $record->config['interval']);
        $this->assertSame('recorded_at',   $record->config['time_column']);
        $this->assertTrue($record->enabled);
        $this->assertSame('2025-03-01 02:00:00', $record->lastRun);
        $this->assertSame('2025-04-01 02:00:00', $record->nextRun);
        $this->assertSame('ok',            $record->lastResult);
        $this->assertNull($record->lastError);
        $this->assertSame('2024-01-01 00:00:00', $record->createdAt);
    }

    /**
     * fromRow() must decode the JSON config string into a PHP array so callers
     * can access config values without additional JSON parsing.
     */
    public function testFromRowDecodesJsonConfig(): void
    {
        // Arrange
        $row = [
            'policyid'    => 1,
            'policy_type' => 'retention',
            'target'      => 'logs',
            'config'      => '{"interval":"7 days","time_column":"created_at"}',
            'enabled'     => true,
            'created_at'  => '2024-01-01 00:00:00',
        ];

        // Act
        $record = PolicyRecord::fromRow($row);

        // Assert
        $this->assertIsArray($record->config);
        $this->assertSame('7 days', $record->config['interval']);
    }

    /**
     * fromRow() must also accept an already-decoded config array (e.g. from
     * a database driver that auto-decodes JSON columns).
     */
    public function testFromRowAcceptsPreDecodedConfigArray(): void
    {
        // Arrange
        $row = [
            'policyid'    => 1,
            'policy_type' => 'retention',
            'target'      => 'logs',
            'config'      => ['interval' => '14 days'],
            'enabled'     => true,
            'created_at'  => '2024-01-01 00:00:00',
        ];

        // Act
        $record = PolicyRecord::fromRow($row);

        // Assert
        $this->assertSame('14 days', $record->config['interval']);
    }

    /**
     * Null or missing optional fields must be converted to null rather than
     * throwing, so code that reads e.g. last_run can always safely do a null
     * check.
     */
    public function testFromRowHandlesMissingOptionalFields(): void
    {
        // Arrange — minimal row with only required fields
        $row = [
            'policyid'    => 5,
            'policy_type' => 'compression',
            'target'      => 'metrics',
            'config'      => '{}',
            'enabled'     => true,
            'created_at'  => '2024-06-01 00:00:00',
        ];

        // Act
        $record = PolicyRecord::fromRow($row);

        // Assert — optional fields default to null
        $this->assertNull($record->lastRun);
        $this->assertNull($record->nextRun);
        $this->assertNull($record->lastResult);
        $this->assertNull($record->lastError);
    }

    /**
     * disabled policies (enabled = '0' / false) must be mapped correctly so
     * the engine can filter them out.
     */
    public function testFromRowHandlesDisabledPolicy(): void
    {
        // Arrange
        $row = [
            'policyid'    => 3,
            'policy_type' => 'retention',
            'target'      => 'audit_log',
            'config'      => '{}',
            'enabled'     => '0',
            'created_at'  => '2024-01-01 00:00:00',
        ];

        // Act
        $record = PolicyRecord::fromRow($row);

        // Assert
        $this->assertFalse($record->enabled);
    }

    // =========================================================================
    // Immutability
    // =========================================================================

    /**
     * All properties must be readonly — once constructed, a PolicyRecord cannot
     * be mutated, which ensures that cached records remain consistent.
     */
    public function testAllPropertiesAreReadonly(): void
    {
        // Arrange
        $ref = new \ReflectionClass(PolicyRecord::class);

        $expected = [
            'policyid', 'policyType', 'target', 'config', 'enabled',
            'lastRun', 'nextRun', 'lastResult', 'lastError', 'createdAt',
        ];

        // Assert
        foreach ($expected as $prop) {
            $this->assertTrue(
                $ref->getProperty($prop)->isReadOnly(),
                "Property '{$prop}' must be readonly"
            );
        }
    }
}
