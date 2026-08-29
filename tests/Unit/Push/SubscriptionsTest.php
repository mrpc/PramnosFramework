<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Push;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Push\Subscriptions;

/**
 * What a subscription has to look like before it is allowed anywhere near the table.
 *
 * Everything asserted here is decided **before** a database is touched, which is the point: a
 * half-formed subscription stored successfully is a row that fails on every send for ever, and
 * nobody goes looking for it because storing it appeared to work.
 */
#[CoversClass(Subscriptions::class)]
class SubscriptionsTest extends TestCase
{
    /**
     * A subscription without its keys is refused.
     *
     * `p256dh` and `auth` are what RFC 8291 encrypts the payload against. Without them there is
     * no way to send anything to this endpoint — not a message that fails to arrive, a message
     * that cannot be composed.
     */
    public function testASubscriptionWithoutItsKeysIsRefused(): void
    {
        // Arrange
        $endpoint = 'https://fcm.googleapis.com/fcm/send/abc';

        // Assert
        $this->assertFalse(Subscriptions::store(1, ['endpoint' => $endpoint]));
        $this->assertFalse(Subscriptions::store(1, [
            'endpoint' => $endpoint,
            'keys'     => ['p256dh' => 'BKxAbc', 'auth' => ''],
        ]));
        $this->assertFalse(Subscriptions::store(1, [
            'endpoint' => $endpoint,
            'keys'     => ['p256dh' => '', 'auth' => 'xyz'],
        ]));
    }

    /**
     * An endpoint that is not an HTTPS URL is not a push service.
     *
     * The endpoint comes from the browser, but it reaches us through a request body that anybody
     * can post. `http://` and a bare string are both refused — and so is a `javascript:` URI,
     * which is what an endpoint is when somebody is probing rather than subscribing.
     */
    public function testAnEndpointThatIsNotHttpsIsRefused(): void
    {
        // Arrange
        $keys = ['p256dh' => 'BKxAbc', 'auth' => 'xyz'];

        // Assert
        foreach (['', 'not a url', 'http://push.example.com/x', 'javascript:alert(1)'] as $endpoint) {
            $this->assertFalse(
                Subscriptions::store(1, ['endpoint' => $endpoint, 'keys' => $keys]),
                $endpoint . ' is not a push endpoint'
            );
        }
    }

    /**
     * A subscription belongs to an account, so there has to be one.
     *
     * Stored against user 0 it would be delivered to nobody and revoked by nobody — a row only a
     * migration will ever remove.
     */
    public function testASubscriptionWithoutAnAccountIsRefused(): void
    {
        // Arrange
        $subscription = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
            'keys'     => ['p256dh' => 'BKxAbc', 'auth' => 'xyz'],
        ];

        // Assert
        $this->assertFalse(Subscriptions::store(0, $subscription));
        $this->assertFalse(Subscriptions::store(-1, $subscription));
    }

    /**
     * Nothing is looked up for an account that cannot exist.
     */
    public function testNothingIsListedForAnImpossibleAccount(): void
    {
        // Assert
        $this->assertSame([], Subscriptions::forUser(0));
    }

    /**
     * Forgetting nothing is not an error, but it is not a success either.
     */
    public function testForgettingAnEmptyEndpointDoesNothing(): void
    {
        // Assert
        $this->assertFalse(Subscriptions::forget('   '));
    }
}
