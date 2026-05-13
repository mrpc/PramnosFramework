<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\General;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\General\Validator as LegacyValidator;

/**
 * Unit tests for Pramnos\General\Validator — the legacy compatibility shim
 * that wraps \Pramnos\Validation\Validator and emits a deprecation notice.
 *
 * These tests verify that:
 *  1. Each method still works correctly (delegates to the modern class).
 *  2. Each call triggers an E_USER_DEPRECATED notice.
 *
 * Functional correctness is tested in ValidatorTest.php for the modern class;
 * here we only need a representative call per method.
 */
#[CoversClass(LegacyValidator::class)]
class LegacyValidatorTest extends TestCase
{
    // =========================================================================
    // checkEmail — delegates + fires deprecation
    // =========================================================================

    /**
     * LegacyValidator::checkEmail() accepts a valid email address and returns
     * truthy, while emitting E_USER_DEPRECATED.
     */
    public function testCheckEmailValidAddressReturnsTruthyAndTriggersDeprecation(): void
    {
        // Arrange — capture the deprecation notice
        $triggered = false;
        set_error_handler(function (int $errno, string $errstr) use (&$triggered): bool {
            if ($errno === E_USER_DEPRECATED) {
                $triggered = true;
            }
            return true; // suppress the notice
        });

        // Act
        $result = LegacyValidator::checkEmail('user@example.com');

        // Restore handler
        restore_error_handler();

        // Assert – valid email accepted
        $this->assertTrue((bool) $result);
        // Assert – deprecation was triggered
        $this->assertTrue($triggered, 'Expected E_USER_DEPRECATED was not triggered');
    }

    /**
     * LegacyValidator::checkEmail() rejects an invalid email address.
     */
    public function testCheckEmailInvalidAddressReturnsFalsy(): void
    {
        // Arrange — suppress deprecation during this test
        set_error_handler(fn() => true);

        // Act
        $result = LegacyValidator::checkEmail('not-an-email');

        // Restore
        restore_error_handler();

        // Assert
        $this->assertFalse((bool) $result);
    }

    // =========================================================================
    // isJson — delegates + fires deprecation
    // =========================================================================

    /**
     * LegacyValidator::isJson() returns true for valid JSON and triggers a
     * deprecation notice.
     */
    public function testIsJsonValidJsonTriggersDeprecation(): void
    {
        // Arrange
        $triggered = false;
        set_error_handler(function (int $errno) use (&$triggered): bool {
            if ($errno === E_USER_DEPRECATED) {
                $triggered = true;
            }
            return true;
        });

        // Act
        $result = LegacyValidator::isJson('{"key":"value"}');

        restore_error_handler();

        // Assert
        $this->assertTrue($result);
        $this->assertTrue($triggered, 'Expected E_USER_DEPRECATED was not triggered');
    }

    /**
     * LegacyValidator::isJson() returns false for a non-JSON string.
     */
    public function testIsJsonInvalidJsonReturnsFalse(): void
    {
        // Arrange — suppress deprecation
        set_error_handler(fn() => true);

        // Act
        $result = LegacyValidator::isJson('not json at all');

        restore_error_handler();

        // Assert
        $this->assertFalse($result);
    }

    // =========================================================================
    // validate — delegates + fires deprecation
    // =========================================================================

    /**
     * LegacyValidator::validate() delegates to the modern Validator and returns
     * the validated (whitelisted) data array when all rules pass, while firing
     * deprecation.  Note: the return value is the validated data, not an error
     * array — the modern Validator::validate() returns data on success and throws
     * ValidationException on failure.
     */
    public function testValidatePassingDataReturnsValidatedData(): void
    {
        // Arrange
        $triggered = false;
        set_error_handler(function (int $errno) use (&$triggered): bool {
            if ($errno === E_USER_DEPRECATED) {
                $triggered = true;
            }
            return true;
        });

        $data  = ['name' => 'Alice'];
        $rules = ['name' => 'required|string|min:2'];

        // Act
        $result = LegacyValidator::validate($data, $rules);

        restore_error_handler();

        // Assert – validated data is returned (not an error array)
        $this->assertIsArray($result);
        $this->assertArrayHasKey('name', $result);
        $this->assertSame('Alice', $result['name']);
        $this->assertTrue($triggered, 'Expected E_USER_DEPRECATED was not triggered');
    }

    /**
     * LegacyValidator::validate() throws ValidationException when a required
     * field is missing.  The exception carries the per-field error details so
     * callers can display them.
     */
    public function testValidateFailingDataThrowsValidationException(): void
    {
        // Arrange — suppress deprecation
        set_error_handler(fn() => true);

        $data  = [];
        $rules = ['name' => 'required'];

        try {
            // Act
            LegacyValidator::validate($data, $rules);
            $this->fail('Expected ValidationException was not thrown');
        } catch (\Pramnos\Validation\ValidationException $e) {
            // Assert – exception carries per-field errors
            $errors = $e->errors();
            $this->assertNotEmpty($errors);
            $this->assertArrayHasKey('name', $errors);
        } finally {
            restore_error_handler();
        }
    }

    // =========================================================================
    // getInstance — returns a LegacyValidator instance
    // =========================================================================

    /**
     * LegacyValidator::getInstance() returns an instance of the legacy
     * validator class and triggers a deprecation notice.
     */
    public function testGetInstanceReturnsValidatorInstance(): void
    {
        // Arrange
        set_error_handler(fn() => true);

        // Act
        $instance = LegacyValidator::getInstance();

        restore_error_handler();

        // Assert
        $this->assertInstanceOf(LegacyValidator::class, $instance);
    }
}
