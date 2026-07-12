<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\UnknownFeatureException;

/**
 * Unit tests for Pramnos\Application\UnknownFeatureException.
 *
 * This exception is thrown by FeatureRegistry when application code tries to
 * enable a feature key that has never been registered.  The message includes
 * the unknown key and — when available — the set of known keys so developers
 * can fix the typo or missing register() call immediately.
 */
#[CoversClass(UnknownFeatureException::class)]
class UnknownFeatureExceptionTest extends TestCase
{
    // =========================================================================
    // getFeatureKey
    // =========================================================================

    /**
     * getFeatureKey() returns the exact key that caused the exception, so
     * callers can log or display the problematic identifier without parsing
     * the exception message.
     */
    public function testGetFeatureKeyReturnsTheOffendingKey(): void
    {
        // Arrange / Act
        $e = new UnknownFeatureException('some-feature');

        // Assert
        $this->assertSame('some-feature', $e->getFeatureKey());
    }

    // =========================================================================
    // getMessage — with known keys
    // =========================================================================

    /**
     * When $knownKeys is provided, the message lists them so the developer can
     * spot the correct identifier without searching the codebase.
     */
    public function testMessageContainsKnownKeysWhenProvided(): void
    {
        // Arrange / Act
        $e = new UnknownFeatureException('typo-feature', ['foo', 'bar', 'baz']);

        $message = $e->getMessage();

        // Assert – unknown key appears in the message
        $this->assertStringContainsString('typo-feature', $message);
        // Assert – known keys are listed
        $this->assertStringContainsString('foo', $message);
        $this->assertStringContainsString('bar', $message);
        $this->assertStringContainsString('baz', $message);
    }

    // =========================================================================
    // getMessage — without known keys
    // =========================================================================

    /**
     * When $knownKeys is empty (nothing registered), the message says so
     * explicitly instead of listing keys — preventing a confusing empty list.
     */
    public function testMessageSaysNoFeaturesRegisteredWhenKnownKeysEmpty(): void
    {
        // Arrange / Act
        $e = new UnknownFeatureException('anything', []);

        $message = $e->getMessage();

        // Assert – empty-registry hint is present
        $this->assertStringContainsString('No features are currently registered', $message);
        // Assert – still mentions the offending key
        $this->assertStringContainsString('anything', $message);
    }

    /**
     * Without a second argument, the exception defaults to an empty $knownKeys
     * and produces the same empty-registry message as when an empty array is
     * explicitly passed.
     */
    public function testDefaultKnownKeysProducesEmptyRegistryMessage(): void
    {
        // Arrange / Act
        $withDefault   = new UnknownFeatureException('x');
        $withEmpty     = new UnknownFeatureException('x', []);

        // Assert – messages are identical
        $this->assertSame($withDefault->getMessage(), $withEmpty->getMessage());
    }

    // =========================================================================
    // Is a RuntimeException
    // =========================================================================

    /**
     * UnknownFeatureException extends RuntimeException so it propagates as
     * an unchecked exception and can be caught at the top-level handler.
     */
    public function testIsARuntimeException(): void
    {
        // Arrange / Act
        $e = new UnknownFeatureException('key');

        // Assert
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    /**
     * The exception includes a register() hint in the message so developers
     * know the next step to fix the issue.
     */
    public function testMessageContainsRegisterHint(): void
    {
        // Arrange / Act
        $e = new UnknownFeatureException('feature-x', ['known-a']);

        // Assert – actionable hint is in the message
        $this->assertStringContainsString('FeatureRegistry::register()', $e->getMessage());
    }
}
