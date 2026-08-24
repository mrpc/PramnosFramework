<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Broadcasting;

use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\ChangeBroadcaster;
use Pramnos\Broadcasting\Testing\FakeDriver;
use Pramnos\Event\ChangeFeed;
use Pramnos\Event\Event;
use Pramnos\Event\ModelChange;

/**
 * The bridge from the local change feed to a channel a browser is listening on.
 *
 * Most of what is asserted here is about **what does not travel**. The default payload
 * carries the entity, the key and the operation, and a subscriber refetches through the
 * API — so no column can reach somebody the API would not have shown it to, and no
 * allow-list has to be maintained as columns are added.
 *
 * A test that only checked "the message went out" would pass on an implementation that
 * published the whole record, which is the failure worth preventing: it is silent, it
 * looks correct in every log, and it is discovered by somebody reading a WebSocket frame.
 */
class ChangeBroadcasterTest extends TestCase
{
    private FakeDriver $driver;

    protected function setUp(): void
    {
        Event::forget();
        ChangeFeed::reset();
        $this->driver = FakeDriver::swap();
    }

    protected function tearDown(): void
    {
        FakeDriver::restore();
        Event::forget();
        ChangeFeed::reset();
    }

    /**
     * @param list<string>      $channels
     * @param list<string>|null $broadcastFields
     */
    private function change(
        array $channels = ['private-wcm-device', 'private-wcm-device.42'],
        ?array $broadcastFields = null,
        string $op = ModelChange::UPDATED
    ): ModelChange {
        return new ModelChange(
            'wcm-device',
            42,
            $op,
            ['deviceid' => 42, 'status' => 3, 'secret' => 'do not publish'],
            ['status' => ['old' => 1, 'new' => 3], 'secret' => ['old' => 'a', 'new' => 'b']],
            $channels,
            $broadcastFields,
            7,
            ModelChange::SOURCE_WEB,
            1756000000,
            'App\\Models\\Device',
            'devices',
        );
    }

    // ── The default payload ─────────────────────────────────────────────────

    /**
     * By default a broadcast carries identifiers and nothing else.
     *
     * **The security property this whole design rests on.** Not the record, not the
     * changed values, and not even the names of the columns that moved — a half-rule
     * ("identifiers, plus which fields changed") is a thing to argue about later, and
     * field names alone are enough to map a schema the API never exposed.
     */
    public function testTheDefaultPayloadIsIdentifiersOnly(): void
    {
        // Arrange
        $broadcaster = new ChangeBroadcaster();

        // Act
        $broadcaster->handle($this->change());

        // Assert
        $sent = $this->driver->recorded()[0];
        $this->assertSame(
            ['entity' => 'wcm-device', 'key' => 42, 'op' => 'updated'],
            $sent['payload']
        );
        $this->assertArrayNotHasKey('data', $sent['payload']);
        $this->assertArrayNotHasKey('changes', $sent['payload']);
    }

    /**
     * The event name is the feed's own, so a client binds one name per channel.
     */
    public function testTheEventNameIsTheFeeds(): void
    {
        // Arrange & Act
        (new ChangeBroadcaster())->handle($this->change());

        // Assert
        $this->assertSame(ChangeFeed::EVENT, $this->driver->recorded()[0]['event']);
    }

    /**
     * Every channel the change named receives it.
     */
    public function testEveryNamedChannelReceivesIt(): void
    {
        // Arrange & Act
        (new ChangeBroadcaster())->handle($this->change());

        // Assert
        $this->assertSame(
            ['private-wcm-device', 'private-wcm-device.42'],
            array_column($this->driver->recorded(), 'channel')
        );
    }

    // ── The allow-list ──────────────────────────────────────────────────────

    /**
     * With an allow-list, only the named fields travel.
     *
     * `secret` is in the record and in the diff, and must appear in neither half of the
     * payload — filtering the data while leaking the change is the mistake that would
     * survive a test checking only one of them.
     */
    public function testAnAllowListFiltersBothTheRecordAndTheDiff(): void
    {
        // Arrange
        $change = $this->change(broadcastFields: ['deviceid', 'status']);

        // Act
        (new ChangeBroadcaster())->handle($change);

        // Assert
        $payload = $this->driver->recorded()[0]['payload'];
        $this->assertSame(['deviceid' => 42, 'status' => 3], $payload['data']);
        $this->assertSame(['status' => ['old' => 1, 'new' => 3]], $payload['changes']);
        $this->assertArrayNotHasKey('secret', $payload['data']);
        $this->assertArrayNotHasKey('secret', $payload['changes']);
    }

    /**
     * An empty allow-list publishes no values at all, but is not the default.
     *
     * `[]` and `null` mean different things and must not collapse: `null` is "this model
     * never opted in", `[]` is "it opted in and named nothing". Both are safe here, and
     * only the second should carry the keys — otherwise a subscriber cannot tell whether
     * a model has values to offer.
     */
    public function testAnEmptyAllowListPublishesTheKeysWithNoValues(): void
    {
        // Arrange
        $change = $this->change(broadcastFields: []);

        // Act
        (new ChangeBroadcaster())->handle($change);

        // Assert
        $payload = $this->driver->recorded()[0]['payload'];
        $this->assertSame([], $payload['data']);
        $this->assertSame([], $payload['changes']);
    }

    // ── Refusals ────────────────────────────────────────────────────────────

    /**
     * A change that named no channels publishes nothing.
     *
     * A model whose `changeChannels()` returns an empty list has said it has no audience.
     * Publishing to a default channel instead would be the framework inventing one.
     */
    public function testAChangeWithNoChannelsPublishesNothing(): void
    {
        // Arrange & Act
        (new ChangeBroadcaster())->handle($this->change(channels: []));

        // Assert
        $this->driver->assertNothingBroadcast();
    }

    /**
     * Anything that is not a ModelChange is ignored.
     *
     * The listener is registered on a shared bus. Another listener firing the same event
     * name with a different argument must not reach a `->channels` on a string.
     */
    public function testANonChangeArgumentIsIgnored(): void
    {
        // Arrange & Act
        (new ChangeBroadcaster())->handle('not a change');
        (new ChangeBroadcaster())->handle();

        // Assert
        $this->driver->assertNothingBroadcast();
    }

    /**
     * A driver that throws does not escape into the save that caused it.
     *
     * The write has already committed. An unreachable relay must not turn a successful
     * save into an exception the user sees, and there is nothing the caller could do
     * about it in any case.
     */
    public function testADriverThatThrowsDoesNotEscape(): void
    {
        // Arrange — a manager whose only driver fails
        FakeDriver::restore();
        $manager = new \Pramnos\Broadcasting\BroadcastingManager();
        $manager->addDriver(new ThrowingDriver())->setDefault('throwing');
        \Pramnos\Broadcasting\BroadcastingManager::setInstance($manager);

        // Act
        (new ChangeBroadcaster())->handle($this->change());

        // Assert — reaching this line is the assertion
        $this->addToAssertionCount(1);

        // Cleanup
        \Pramnos\Broadcasting\BroadcastingManager::setInstance(null);
    }

    /**
     * With no broadcasting configured, nothing happens and nothing is built.
     *
     * `currentInstance()` rather than `instance()`: asking whether broadcasting is
     * configured must not be the thing that configures it.
     */
    public function testWithNoManagerNothingHappens(): void
    {
        // Arrange
        FakeDriver::restore();
        \Pramnos\Broadcasting\BroadcastingManager::setInstance(null);

        // Act
        (new ChangeBroadcaster())->handle($this->change());

        // Assert
        $this->assertNull(\Pramnos\Broadcasting\BroadcastingManager::currentInstance());
    }

    // ── Registration ────────────────────────────────────────────────────────

    /**
     * listen() registers on the feed, and twice does not register twice.
     *
     * A provider booted per request in a long-running worker would otherwise accumulate
     * listeners, and every change would be published N times to the same channel.
     */
    public function testListenIsIdempotent(): void
    {
        // Arrange & Act
        ChangeBroadcaster::listen();
        ChangeBroadcaster::listen();

        // Assert
        $this->assertCount(1, Event::getListeners(ChangeFeed::EVENT));
    }

    /**
     * A change emitted on the feed reaches a channel end to end.
     *
     * The other tests drive handle() directly; this one proves the registration actually
     * connects the two halves.
     */
    public function testAnEmittedChangeReachesAChannel(): void
    {
        // Arrange
        ChangeBroadcaster::listen();

        // Act
        ChangeFeed::emit($this->change());

        // Assert
        $this->driver->assertBroadcast('private-wcm-device', ChangeFeed::EVENT);
    }
}

/**
 * A driver whose only behaviour is to fail.
 */
final class ThrowingDriver implements \Pramnos\Broadcasting\Drivers\DriverInterface
{
    public function broadcast(string $channel, string $event, array $payload): void
    {
        throw new \RuntimeException('the relay is down');
    }

    public function name(): string
    {
        return 'throwing';
    }
}
