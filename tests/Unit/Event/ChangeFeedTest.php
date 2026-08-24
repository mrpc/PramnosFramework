<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use Pramnos\Event\ChangeFeed;
use Pramnos\Event\Event;
use Pramnos\Event\ModelChange;

/**
 * A ChangeFeed whose idea of "in a transaction" is a switch rather than a database.
 *
 * The buffering rule is the whole point of the class and it cannot be exercised without
 * controlling that answer. Overriding the one seam is cheaper — and far less brittle —
 * than standing up a connection to test an if-statement.
 *
 * It redeclares no static property, so `$buffer` and `$booted` are ChangeFeed's own
 * storage rather than copies. That is deliberate: a change emitted through the fake is
 * drained by the real class, which is what lets the boot() wiring be tested end to end.
 */
class FakeTransactionChangeFeed extends ChangeFeed
{
    public static bool $open = false;

    protected static function inTransaction(): bool
    {
        return static::$open;
    }
}

/**
 * Unit tests for Pramnos\Event\ChangeFeed.
 *
 * The feed must:
 *  - deliver immediately when no transaction is open;
 *  - hold everything while one is, and deliver it in order on commit;
 *  - deliver nothing at all on rollback — the invariant the buffer exists for;
 *  - survive a listener that emits during a flush without duplicating or losing it;
 *  - never let a missing database turn into a change held for ever.
 */
class ChangeFeedTest extends TestCase
{
    protected function setUp(): void
    {
        // Arrange — a clean bus and a clean buffer, so nothing leaks between tests.
        Event::forget();
        ChangeFeed::reset();
        FakeTransactionChangeFeed::reset();
        FakeTransactionChangeFeed::$open = false;
    }

    protected function tearDown(): void
    {
        Event::forget();
        ChangeFeed::reset();
        FakeTransactionChangeFeed::$open = false;
        FakeTransactionChangeFeed::reset();
    }

    private function change(string $entity = 'device', string|int|null $key = 1): ModelChange
    {
        return new ModelChange(
            $entity,
            $key,
            ModelChange::UPDATED,
            ['id' => $key],
            ['status' => ['old' => 1, 'new' => 2]],
            ['private-' . $entity],
            null,
            null,
            ModelChange::SOURCE_CLI,
            1756000000,
            'Some\\Model',
            'devices',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Delivery outside a transaction
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * With no transaction open, a change reaches listeners during emit().
     *
     * The default path: a save outside a transaction publishes before the request ends,
     * which is what makes a local Redis publish worth doing inline.
     */
    public function testEmitDeliversImmediatelyOutsideATransaction(): void
    {
        // Arrange
        $received = [];
        Event::listen(ChangeFeed::EVENT, function (ModelChange $c) use (&$received) {
            $received[] = $c;
        });

        // Act
        FakeTransactionChangeFeed::emit($this->change());

        // Assert
        $this->assertCount(1, $received);
        $this->assertSame(0, FakeTransactionChangeFeed::pending());
    }

    /**
     * A change emitted with no listeners registered is not an error.
     *
     * Emission is unconditional in the model; whether anything is listening is the
     * application's business, and a feature nobody has wired up must not throw.
     */
    public function testEmitWithNoListenersIsHarmless(): void
    {
        // Act
        FakeTransactionChangeFeed::emit($this->change());

        // Assert
        $this->assertSame(0, FakeTransactionChangeFeed::pending());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Buffering inside a transaction
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * While a transaction is open, nothing is delivered.
     *
     * Proves the hold: a listener that writes a changelog row must not see a change whose
     * transaction may still roll back.
     */
    public function testEmitBuffersWhileATransactionIsOpen(): void
    {
        // Arrange
        $received = [];
        Event::listen(ChangeFeed::EVENT, function (ModelChange $c) use (&$received) {
            $received[] = $c;
        });
        FakeTransactionChangeFeed::$open = true;

        // Act
        FakeTransactionChangeFeed::emit($this->change('device', 1));
        FakeTransactionChangeFeed::emit($this->change('device', 2));

        // Assert
        $this->assertSame([], $received);
        $this->assertSame(2, FakeTransactionChangeFeed::pending());
    }

    /**
     * flush() delivers held changes in the order they were emitted.
     *
     * Order matters to a changelog reader: two updates to one row are only meaningful
     * read in the sequence they happened.
     */
    public function testFlushDeliversInEmissionOrder(): void
    {
        // Arrange
        $keys = [];
        Event::listen(ChangeFeed::EVENT, function (ModelChange $c) use (&$keys) {
            $keys[] = $c->key;
        });
        FakeTransactionChangeFeed::$open = true;
        FakeTransactionChangeFeed::emit($this->change('device', 1));
        FakeTransactionChangeFeed::emit($this->change('device', 2));
        FakeTransactionChangeFeed::emit($this->change('device', 3));

        // Act
        FakeTransactionChangeFeed::$open = false;
        FakeTransactionChangeFeed::flush();

        // Assert
        $this->assertSame([1, 2, 3], $keys);
        $this->assertSame(0, FakeTransactionChangeFeed::pending());
    }

    /**
     * discard() delivers nothing and empties the buffer.
     *
     * **This is the invariant the buffer exists for.** A rolled-back transaction must
     * leave no changelog row behind, because an audit trail recording something that did
     * not happen is worse than no audit trail.
     */
    public function testDiscardDeliversNothing(): void
    {
        // Arrange
        $received = [];
        Event::listen(ChangeFeed::EVENT, function (ModelChange $c) use (&$received) {
            $received[] = $c;
        });
        FakeTransactionChangeFeed::$open = true;
        FakeTransactionChangeFeed::emit($this->change());

        // Act
        FakeTransactionChangeFeed::discard();

        // Assert
        $this->assertSame([], $received);
        $this->assertSame(0, FakeTransactionChangeFeed::pending());
    }

    /**
     * flush() with an empty buffer fires nothing.
     *
     * Every commit calls flush, and the overwhelming majority of commits carry no model
     * changes at all — so the empty case is the common one, not an edge.
     */
    public function testFlushOnAnEmptyBufferFiresNothing(): void
    {
        // Arrange
        $calls = 0;
        Event::listen(ChangeFeed::EVENT, function () use (&$calls) {
            $calls++;
        });

        // Act
        FakeTransactionChangeFeed::flush();

        // Assert
        $this->assertSame(0, $calls);
    }

    /**
     * A listener that emits during a flush does not have its change swallowed or doubled.
     *
     * The buffer is cleared before the first listener runs. Were it cleared afterwards,
     * a listener that saves a model — a changelog writer that is itself a model, say —
     * would emit into a buffer about to be wiped, and that change would vanish.
     */
    public function testAListenerEmittingDuringFlushIsNotLost(): void
    {
        // Arrange
        $keys = [];
        $reentered = false;
        Event::listen(ChangeFeed::EVENT, function (ModelChange $c) use (&$keys, &$reentered) {
            $keys[] = $c->key;
            if (!$reentered) {
                $reentered = true;
                FakeTransactionChangeFeed::emit($this->change('device', 99));
            }
        });
        FakeTransactionChangeFeed::$open = true;
        FakeTransactionChangeFeed::emit($this->change('device', 1));

        // Act
        FakeTransactionChangeFeed::$open = false;
        FakeTransactionChangeFeed::flush();

        // Assert — the re-entrant change was delivered exactly once, and nothing is stuck
        $this->assertSame([1, 99], $keys);
        $this->assertSame(0, FakeTransactionChangeFeed::pending());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Wiring
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The first emission wires the transaction listeners by itself.
     *
     * Nothing in the framework calls boot(), and nothing should have to. Without the
     * wiring a change emitted inside a transaction is buffered and never released — the
     * feed goes silent for precisely the code that wraps its writes in a transaction,
     * and only for that code, which is the hardest possible shape to notice.
     */
    public function testEmittingBootsTheTransactionWiring(): void
    {
        // Arrange — nothing booted
        $this->assertFalse(Event::hasListeners(ChangeFeed::EVENT_COMMITTED));

        // Act
        FakeTransactionChangeFeed::emit($this->change());

        // Assert
        $this->assertTrue(Event::hasListeners(ChangeFeed::EVENT_COMMITTED));
        $this->assertTrue(Event::hasListeners(ChangeFeed::EVENT_ROLLED_BACK));
    }

    /**
     * A change buffered by a transaction is released by the commit event alone.
     *
     * End to end through the automatic wiring: emit inside a transaction, fire nothing
     * but the commit, and the change arrives. This is the path that was broken while
     * boot() had no caller, and it passed every test that called boot() by hand.
     */
    public function testABufferedChangeIsReleasedByTheCommitEventAlone(): void
    {
        // Arrange
        $received = [];
        Event::listen(ChangeFeed::EVENT, function (ModelChange $c) use (&$received) {
            $received[] = $c;
        });
        FakeTransactionChangeFeed::$open = true;
        FakeTransactionChangeFeed::emit($this->change());
        $this->assertSame([], $received);

        // Act — no explicit flush anywhere
        FakeTransactionChangeFeed::$open = false;
        Event::fire(ChangeFeed::EVENT_COMMITTED);

        // Assert
        $this->assertCount(1, $received);
    }

    /**
     * boot() wires commit to flush and rollback to discard.
     *
     * Covers the seam between this class and Database: the two event names are the entire
     * contract, and a rename on either side would show up here.
     */
    public function testBootWiresTheTransactionEvents(): void
    {
        // Arrange
        ChangeFeed::boot();

        // Act & Assert
        $this->assertTrue(Event::hasListeners(ChangeFeed::EVENT_COMMITTED));
        $this->assertTrue(Event::hasListeners(ChangeFeed::EVENT_ROLLED_BACK));
    }

    /**
     * boot() twice registers one listener per event, not two.
     *
     * A service provider booted per request in a long-running worker would otherwise
     * accumulate listeners until every commit flushed the buffer N times.
     */
    public function testBootIsIdempotent(): void
    {
        // Arrange & Act
        ChangeFeed::boot();
        ChangeFeed::boot();

        // Assert
        $this->assertCount(1, Event::getListeners(ChangeFeed::EVENT_COMMITTED));
        $this->assertCount(1, Event::getListeners(ChangeFeed::EVENT_ROLLED_BACK));
    }

    /**
     * The commit event actually drains the buffer through the booted listener.
     *
     * The previous test proves the listeners exist; this proves they do the right thing,
     * which is the part a rename of flush()/discard() would break silently.
     */
    public function testFiringTheCommitEventFlushes(): void
    {
        // Arrange
        $received = [];
        Event::listen(ChangeFeed::EVENT, function (ModelChange $c) use (&$received) {
            $received[] = $c;
        });
        ChangeFeed::boot();
        FakeTransactionChangeFeed::$open = true;
        FakeTransactionChangeFeed::emit($this->change());

        // Act — nothing but the event; the booted listener has to do the rest.
        Event::fire(ChangeFeed::EVENT_COMMITTED);

        // Assert — delivered by the listener boot() registered, not by an explicit flush.
        // The fake redeclares no static property, so it buffers into ChangeFeed's own
        // storage and the base class's flush() drains what the subclass emitted.
        $this->assertCount(1, $received);
        $this->assertSame(0, ChangeFeed::pending());
    }

    /**
     * Without a reachable database, a change is delivered rather than held.
     *
     * ChangeFeed::inTransaction() swallows the failure and answers false. Answering true
     * would hold the change until a commit that is never coming — the feed would go
     * silent in exactly the situation somebody is trying to debug.
     */
    public function testAnUnreachableDatabaseDoesNotHoldChangesForEver(): void
    {
        // Arrange
        $received = [];
        Event::listen(ChangeFeed::EVENT, function (ModelChange $c) use (&$received) {
            $received[] = $c;
        });

        // Act — the real class, whose inTransaction() has to reach a database
        ChangeFeed::emit($this->change());

        // Assert
        $this->assertCount(1, $received);
        $this->assertSame(0, ChangeFeed::pending());
    }
}
