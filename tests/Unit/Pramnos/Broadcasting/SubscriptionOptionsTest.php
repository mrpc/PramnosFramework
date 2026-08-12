<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\SubscriptionOptions;

/**
 * The knobs a long-lived subscription is driven by.
 *
 * These are validated in the constructor rather than left to fail later, and
 * the reason is the shape of the failure: a subscription loop blocks. A zero
 * readTimeout is a busy loop nobody notices until a server is at 100% CPU, and
 * a maxRuntime of zero is a stream that ends before it begins. Both are caught
 * where the mistake was made.
 */
#[CoversClass(SubscriptionOptions::class)]
class SubscriptionOptionsTest extends TestCase
{
    /**
     * A read timeout under a second is refused.
     *
     * It is also the poll cadence and the idle-tick granularity, so zero is a
     * loop with no pause in it at all.
     */
    public function testAReadTimeoutBelowOneSecondIsRefused(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('readTimeout must be at least 1 second');

        // Act
        new SubscriptionOptions(readTimeout: 0);
    }

    /**
     * A max runtime of zero is refused rather than read as "no limit" — null is
     * how a caller says that, and the two must not be confusable.
     */
    public function testAZeroMaxRuntimeIsRefused(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxRuntime, when set, must be at least 1 second');

        // Act
        new SubscriptionOptions(maxRuntime: 0);
    }

    /**
     * A non-callable idle handler is refused at construction.
     *
     * Otherwise the failure surfaces on the first idle tick — inside a blocking
     * loop, in a long-lived process, minutes after the mistake.
     */
    public function testANonCallableIdleHandlerIsRefused(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('onIdle must be callable or null');

        // Act
        new SubscriptionOptions(onIdle: 'not a function at all');
    }

    /**
     * Same for the error handler.
     */
    public function testANonCallableErrorHandlerIsRefused(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('onError must be callable or null');

        // Act
        new SubscriptionOptions(onError: 42);
    }

    /**
     * With no idle handler, an idle tick means "carry on".
     *
     * The default has to be to continue: a driver asks this question on every
     * quiet period, and a caller that did not supply a handler has not asked to
     * stop.
     */
    public function testAnIdleTickWithoutAHandlerContinues(): void
    {
        // Arrange
        $options = new SubscriptionOptions();

        // Act & Assert
        $this->assertTrue($options->fireIdle());
    }

    /**
     * The handler's `false` is what ends a loop — anything else continues, so a
     * handler that forgets to return does not silently kill the stream.
     */
    public function testOnlyAnExplicitFalseStopsTheLoop(): void
    {
        // Arrange
        $stops    = new SubscriptionOptions(onIdle: static fn (): bool => false);
        $silent   = new SubscriptionOptions(onIdle: static function (): void {});

        // Act & Assert
        $this->assertFalse($stops->fireIdle());
        $this->assertTrue($silent->fireIdle(), 'a handler that returns nothing has not asked to stop');
    }

    /**
     * A swallowed transient error is reported to whoever wanted to know.
     *
     * The driver reconnects and carries on either way — this is the only channel
     * through which a reconnect loop is visible at all.
     */
    public function testASwallowedErrorIsReported(): void
    {
        // Arrange
        $seen = null;
        $options = new SubscriptionOptions(
            onError: static function (\Throwable $e) use (&$seen): void {
                $seen = $e->getMessage();
            }
        );

        // Act
        $options->reportError(new \RuntimeException('connection reset'));

        // Assert
        $this->assertSame('connection reset', $seen);
    }

    /**
     * With no handler, reporting an error is a no-op rather than a second
     * failure on top of the first.
     */
    public function testReportingWithoutAHandlerIsHarmless(): void
    {
        // Arrange
        $options = new SubscriptionOptions();

        // Act & Assert — reaching the end without throwing is the assertion
        $options->reportError(new \RuntimeException('nobody is listening'));
        $this->assertNull($options->sinceId, 'and the defaults are untouched');
    }
}
