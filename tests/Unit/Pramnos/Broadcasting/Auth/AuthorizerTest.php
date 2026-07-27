<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Broadcasting\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Auth\AllowAllAuthorizer;
use Pramnos\Broadcasting\Auth\PusherAuthorizer;

/**
 * Unit tests for the broadcast connection authorizers.
 *
 * PusherAuthorizer must accept only the configured app key and validate
 * private-/presence- channel subscriptions with the standard Pusher HMAC-SHA256
 * signature (public channels always pass). AllowAllAuthorizer must accept
 * everything (the permissive local-dev default).
 */
#[CoversClass(PusherAuthorizer::class)]
#[CoversClass(AllowAllAuthorizer::class)]
class AuthorizerTest extends TestCase
{
    private function sign(string $socketId, string $channel, string $secret, string $key, ?string $channelData = null): string
    {
        $stringToSign = $socketId . ':' . $channel;
        if (str_starts_with($channel, 'presence-') && $channelData !== null && $channelData !== '') {
            $stringToSign .= ':' . $channelData;
        }
        return $key . ':' . hash_hmac('sha256', $stringToSign, $secret);
    }

    /**
     * Only the exact configured app key is accepted for connection.
     */
    public function testConnectionAcceptsOnlyMatchingAppKey(): void
    {
        $auth = new PusherAuthorizer('app-key-123', 'secret');
        $this->assertTrue($auth->authorizeConnection('app-key-123'));
        $this->assertFalse($auth->authorizeConnection('wrong-key'));
        $this->assertFalse($auth->authorizeConnection(''));
    }

    /**
     * Public channels need no signature; private channels need a valid one.
     */
    public function testPrivateChannelRequiresValidSignature(): void
    {
        $auth   = new PusherAuthorizer('key', 'secret');
        $socket = '123.456';

        $this->assertTrue($auth->authorizeChannel('room.public', $socket, ''), 'public channel needs no auth');

        $good = $this->sign($socket, 'private-room.7', 'secret', 'key');
        $this->assertTrue($auth->authorizeChannel('private-room.7', $socket, $good));

        $this->assertFalse($auth->authorizeChannel('private-room.7', $socket, 'key:deadbeef'), 'bad signature rejected');
        $this->assertFalse($auth->authorizeChannel('private-room.7', $socket, ''), 'missing signature rejected');
    }

    /**
     * Presence channels fold channel_data into the signed string.
     */
    public function testPresenceChannelSignsChannelData(): void
    {
        $auth   = new PusherAuthorizer('key', 'secret');
        $socket = '9.9';
        $data   = '{"user_id":"7"}';

        $good = $this->sign($socket, 'presence-room', 'secret', 'key', $data);
        $this->assertTrue($auth->authorizeChannel('presence-room', $socket, $good, $data));

        // Same signature without the channel_data must fail (different signed string).
        $this->assertFalse($auth->authorizeChannel('presence-room', $socket, $good, null));
    }

    /**
     * AllowAllAuthorizer accepts every connection and channel.
     */
    public function testAllowAllAcceptsEverything(): void
    {
        $auth = new AllowAllAuthorizer();
        $this->assertTrue($auth->authorizeConnection('anything'));
        $this->assertTrue($auth->authorizeChannel('private-secret', 'sock', ''));
    }
}
