<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\WebhookEvents;
use Pramnos\Auth\WebhookService;

/**
 * The webhook event registry: the framework's types plus an application's.
 *
 * What an endpoint may subscribe to used to be a CHECK constraint over the framework's eight.
 * The registry replaces it, so it has to keep the constraint's one property — an unknown name
 * is refused — while letting an application name its own events.
 */
#[CoversClass(WebhookEvents::class)]
class WebhookEventsTest extends TestCase
{
    protected function tearDown(): void
    {
        WebhookEvents::reset();
    }

    /**
     * The built-in list and `WebhookService::EVENT_TYPES` are the same eight.
     *
     * Two spellings of one list, kept for callers that read the constant; this is what keeps
     * them from drifting apart.
     */
    public function testTheBuiltInTypesAreTheServiceConstant(): void
    {
        // Act
        $builtIn = array_keys(WebhookEvents::builtIn());

        // Assert
        $this->assertSame(WebhookService::EVENT_TYPES, $builtIn);
    }

    /**
     * A registered type is known, listed after the built-ins, and described.
     */
    public function testARegisteredTypeIsKnownAndListed(): void
    {
        // Arrange
        WebhookEvents::register([
            'station.live' => ['title' => 'A station went on the air', 'payload' => ['station_id', 'slug']],
        ]);

        // Act
        $names = WebhookEvents::names();

        // Assert
        $this->assertTrue(WebhookEvents::isKnown('station.live'));
        $this->assertSame('station.live', end($names), 'registered types follow the built-ins');
        $this->assertSame(['station_id', 'slug'], WebhookEvents::all()['station.live']['payload']);
    }

    /**
     * A type nobody registered is not known.
     *
     * The property the database constraint was for.
     */
    public function testAnUnregisteredTypeIsNotKnown(): void
    {
        // Assert
        $this->assertFalse(WebhookEvents::isKnown('station.live'));
        $this->assertTrue(WebhookEvents::isKnown('token_revoked'));
    }

    /**
     * The framework's own win a collision, and are listed once.
     *
     * Their payloads are the framework's to write; a registration redescribing one would
     * advertise fields nobody sends.
     */
    public function testABuiltInCannotBeRedefined(): void
    {
        // Arrange
        $original = WebhookEvents::builtIn()['token_revoked'];

        // Act
        WebhookEvents::register(['token_revoked' => ['title' => 'Mine now', 'payload' => ['x']]]);

        // Assert
        $this->assertSame($original, WebhookEvents::all()['token_revoked']);
        $this->assertSame(1, array_count_values(WebhookEvents::names())['token_revoked']);
    }

    /**
     * A title defaults to the name, and reset() forgets every registration.
     */
    public function testDefaultsAndReset(): void
    {
        // Arrange
        WebhookEvents::register(['track.changed' => []]);

        // Act
        $title = WebhookEvents::all()['track.changed']['title'];
        WebhookEvents::reset();

        // Assert
        $this->assertSame('track.changed', $title);
        $this->assertFalse(WebhookEvents::isKnown('track.changed'), 'reset() kept a registration');
    }

    /**
     * Names the column cannot hold, or that are not plain identifiers.
     *
     * @return array<string, array{0: string}>
     */
    public static function invalidNames(): array
    {
        return [
            'empty'           => [''],
            'too long'        => [str_repeat('a', 51)],
            'a space'         => ['station live'],
            'a quote'         => ["station'live"],
        ];
    }

    /**
     * An invalid name is refused at registration, not at the first insert.
     */
    #[DataProvider('invalidNames')]
    public function testAnInvalidNameIsRefused(string $name): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        WebhookEvents::register([$name => []]);
    }
}
