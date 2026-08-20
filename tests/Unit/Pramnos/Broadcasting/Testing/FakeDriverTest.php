<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting\Testing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\BroadcastingManager;
use Pramnos\Broadcasting\Testing\FakeDriver;

/**
 * The broadcasting test double.
 *
 * `NullDriver` discards silently and `LogDriver` writes a file a test then has to
 * parse, so a test asserting "this action broadcasts" either needed a real Redis or
 * asserted nothing — and the second kind keeps passing after the broadcast is
 * deleted. These tests check both that the recording is faithful and that the
 * assertions fail when they should, because an assertion helper that cannot fail is
 * the same problem one level up.
 */
#[CoversClass(FakeDriver::class)]
class FakeDriverTest extends TestCase
{
    protected function tearDown(): void
    {
        FakeDriver::restore();
    }

    /**
     * Run a callable and return the assertion failure it produced, or null.
     *
     * Used to prove the assertions fail: without this the suite would only ever
     * exercise their passing branch.
     */
    private function failureFrom(callable $assertion): ?string
    {
        try {
            $assertion();
        } catch (AssertionFailedError $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * swap() installs the fake as the process default, so code under test that
     * resolves the manager itself is captured without being handed a driver.
     */
    public function testSwapInstallsItselfAsTheProcessDefault(): void
    {
        // Arrange & Act
        $fake = FakeDriver::swap();

        BroadcastingManager::instance()->broadcast('chat', 'message.created', ['id' => 1]);

        // Assert
        $fake->assertBroadcast('chat', 'message.created');
        $this->assertCount(1, $fake->recorded());
    }

    /**
     * restore() puts the previous default back, and is safe when nothing was
     * swapped.
     *
     * A test that left a fake installed would silently swallow every later test's
     * broadcasts, and the failure would surface in an unrelated file.
     */
    public function testRestoreIsExactAndIdempotent(): void
    {
        // Arrange
        $sentinel = new BroadcastingManager();
        BroadcastingManager::setInstance($sentinel);

        // Act
        FakeDriver::swap();
        FakeDriver::restore();

        // Assert
        $this->assertSame($sentinel, BroadcastingManager::currentInstance());

        // A second restore must not throw or clear anything.
        FakeDriver::restore();
        $this->assertSame($sentinel, BroadcastingManager::currentInstance());

        BroadcastingManager::setInstance(null);
    }

    /**
     * swap() does not construct a manager to find out what to restore.
     *
     * `instance()` is a factory that would build a Redis-backed manager; asking
     * what is installed must not have that side effect. Proved by swapping from a
     * clean slate and checking the slate is clean again.
     */
    public function testSwapDoesNotConstructAManagerToRememberOne(): void
    {
        // Arrange
        BroadcastingManager::setInstance(null);

        // Act
        FakeDriver::swap();
        FakeDriver::restore();

        // Assert
        $this->assertNull(
            BroadcastingManager::currentInstance(),
            'restoring must not leave a manager that was never there'
        );
    }

    /**
     * Recording keeps channel, event, payload and exclusion.
     */
    public function testRecordsEveryField(): void
    {
        // Arrange
        $fake = new FakeDriver();

        // Act
        $fake->broadcast('a', 'e1', ['x' => 1]);
        $fake->broadcastExcept('b', 'e2', ['y' => 2], '12.34');

        // Assert
        $this->assertSame(
            [
                ['channel' => 'a', 'event' => 'e1', 'payload' => ['x' => 1], 'except' => null],
                ['channel' => 'b', 'event' => 'e2', 'payload' => ['y' => 2], 'except' => '12.34'],
            ],
            $fake->recorded()
        );
    }

    /**
     * matching() narrows by channel, event and a payload predicate.
     */
    public function testMatchingNarrowsByEveryCriterion(): void
    {
        // Arrange
        $fake = new FakeDriver();
        $fake->broadcast('orders', 'paid', ['id' => 1]);
        $fake->broadcast('orders', 'paid', ['id' => 2]);
        $fake->broadcast('orders', 'shipped', ['id' => 1]);

        // Act & Assert
        $this->assertCount(3, $fake->matching('orders'));
        $this->assertCount(2, $fake->matching('orders', 'paid'));
        $this->assertCount(
            1,
            $fake->matching('orders', 'paid', fn (array $p): bool => $p['id'] === 2)
        );
        $this->assertSame([], $fake->matching('invoices'));
    }

    /**
     * A payload predicate returning something other than true does not match.
     *
     * Strict, so a predicate that accidentally returns a truthy value is not read
     * as consent — the same rule the channel registry applies to its rules.
     */
    public function testPayloadPredicateMustReturnTrue(): void
    {
        // Arrange
        $fake = new FakeDriver();
        $fake->broadcast('a', 'e', ['x' => 1]);

        // Act & Assert
        $this->assertFalse($fake->hasBroadcast('a', 'e', fn (): mixed => 'yes'));
        $this->assertTrue($fake->hasBroadcast('a', 'e', fn (): bool => true));
    }

    /**
     * assertBroadcast passes on a match and fails otherwise, naming what *was*
     * broadcast.
     *
     * The listing is the part that matters in practice: without it a reader cannot
     * tell a missing broadcast from one on a channel whose name is built slightly
     * differently, which is the usual cause.
     */
    public function testAssertBroadcastFailsWithTheRecordedList(): void
    {
        // Arrange
        $fake = new FakeDriver();
        $fake->broadcast('private-order.42', 'order.paid', []);

        // Act & Assert — passes
        $fake->assertBroadcast('private-order.42', 'order.paid');

        // Act — fails, on a nearly-right channel name
        $message = $this->failureFrom(fn () => $fake->assertBroadcast('private-order-42', 'order.paid'));

        // Assert
        $this->assertNotNull($message, 'the assertion must fail');
        $this->assertStringContainsString('private-order-42', $message);
        $this->assertStringContainsString('private-order.42 / order.paid', $message, 'what was sent is listed');
    }

    /**
     * assertNotBroadcast is the inverse, and fails when something matched.
     */
    public function testAssertNotBroadcast(): void
    {
        // Arrange
        $fake = new FakeDriver();
        $fake->broadcast('a', 'e', []);

        // Act & Assert
        $fake->assertNotBroadcast('b');
        $this->assertNotNull($this->failureFrom(fn () => $fake->assertNotBroadcast('a')));
    }

    /**
     * assertBroadcastCount counts, optionally scoped to a channel or event.
     */
    public function testAssertBroadcastCount(): void
    {
        // Arrange
        $fake = new FakeDriver();
        $fake->broadcast('a', 'e', []);
        $fake->broadcast('a', 'e', []);
        $fake->broadcast('b', 'e', []);

        // Act & Assert
        $fake->assertBroadcastCount(3);
        $fake->assertBroadcastCount(2, 'a');

        $message = $this->failureFrom(fn () => $fake->assertBroadcastCount(1, 'a'));
        $this->assertNotNull($message);
        $this->assertStringContainsString('got 2', $message);
    }

    /**
     * assertNothingBroadcast is the guard for "this path must stay quiet".
     */
    public function testAssertNothingBroadcast(): void
    {
        // Arrange
        $fake = new FakeDriver();

        // Act & Assert
        $fake->assertNothingBroadcast();

        $fake->broadcast('a', 'e', []);
        $this->assertNotNull($this->failureFrom(fn () => $fake->assertNothingBroadcast()));
    }

    /**
     * assertBroadcastExcept proves toOthers() reached the driver.
     *
     * Worth its own assertion: the exclusion is easy to lose — a driver that does
     * not support it, a socket id that never left the request — and its only
     * production symptom is one user seeing a duplicate of their own action.
     */
    public function testAssertBroadcastExcept(): void
    {
        // Arrange
        $fake = new FakeDriver();
        $fake->broadcastExcept('chat', 'message.created', [], '12.34');
        $fake->broadcast('chat', 'other', []);

        // Act & Assert
        $fake->assertBroadcastExcept('12.34', 'chat', 'message.created');

        // A different socket, and an unexcluded broadcast, must both fail.
        $this->assertNotNull($this->failureFrom(fn () => $fake->assertBroadcastExcept('99.99', 'chat')));
        $this->assertNotNull($this->failureFrom(
            fn () => $fake->assertBroadcastExcept('12.34', 'chat', 'other')
        ));
    }

    /**
     * flush() forgets what was recorded, for a test with several phases.
     */
    public function testFlushForgetsRecordedBroadcasts(): void
    {
        // Arrange
        $fake = new FakeDriver();
        $fake->broadcast('a', 'e', []);

        // Act
        $fake->flush();

        // Assert
        $fake->assertNothingBroadcast();
    }

    /**
     * The failure message reads sensibly when nothing at all was recorded.
     */
    public function testFailureMessageWhenNothingWasRecorded(): void
    {
        // Arrange
        $fake = new FakeDriver();

        // Act
        $message = $this->failureFrom(fn () => $fake->assertBroadcast('a', 'e'));

        // Assert
        $this->assertStringContainsString('Recorded: nothing.', (string) $message);
    }

    /**
     * The driver names itself 'fake', which is what makes setDefault('fake') work.
     */
    public function testDriverName(): void
    {
        // Assert
        $this->assertSame('fake', (new FakeDriver())->name());
    }

    /**
     * A bare assertBroadcast() with no arguments asserts that *something* was
     * broadcast — useful when the channel name is built from data the test does not
     * want to reconstruct.
     */
    public function testBareAssertBroadcastChecksForAnything(): void
    {
        // Arrange
        $fake = new FakeDriver();

        // Act & Assert
        $this->assertNotNull($this->failureFrom(fn () => $fake->assertBroadcast()));

        $fake->broadcast('anything', 'at.all', []);
        $fake->assertBroadcast();
    }
}
