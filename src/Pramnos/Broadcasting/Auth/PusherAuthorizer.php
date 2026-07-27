<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Auth;

/**
 * Pusher-compatible authorizer for the built-in broadcast server.
 *
 * Validates the connecting app key against the configured one, and validates
 * private-/presence- channel subscriptions using the standard Pusher HMAC-SHA256
 * signature so the framework's own pramnos-echo.js / pusher-js clients (which
 * fetch a signature from the app's /broadcasting/auth endpoint) work unchanged.
 *
 * Signature string:
 *   private-  channel: "<socketId>:<channel>"
 *   presence- channel: "<socketId>:<channel>:<channelData>"
 * Token: "<appKey>:" . hash_hmac('sha256', <string>, <appSecret>)
 */
final class PusherAuthorizer implements ConnectionAuthorizer
{
    public function __construct(
        private readonly string $appKey,
        private readonly string $appSecret,
    ) {
    }

    public function authorizeConnection(string $appKey, array $params = []): bool
    {
        return $this->appKey !== '' && hash_equals($this->appKey, $appKey);
    }

    public function authorizeChannel(string $channel, string $socketId, string $auth, ?string $channelData = null): bool
    {
        // Public channels need no authorization.
        if (!str_starts_with($channel, 'private-') && !str_starts_with($channel, 'presence-')) {
            return true;
        }
        if ($auth === '' || $this->appSecret === '') {
            return false;
        }

        $stringToSign = $socketId . ':' . $channel;
        if (str_starts_with($channel, 'presence-') && $channelData !== null && $channelData !== '') {
            $stringToSign .= ':' . $channelData;
        }

        $expected = $this->appKey . ':' . hash_hmac('sha256', $stringToSign, $this->appSecret);

        return hash_equals($expected, $auth);
    }
}
