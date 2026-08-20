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
final class PusherAuthorizer implements PresenceAuthorizer
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

    /**
     * Decode the member identity from the already-verified channel data.
     *
     * Safe to trust: this runs only after {@see authorizeChannel()} accepted the
     * subscription, and the signature covered `$channelData` byte-for-byte. That
     * is the whole reason presence data is signed rather than merely sent — a
     * client that could edit it could claim to be anyone in the room.
     */
    public function presenceMember(string $channel, string $socketId, ?string $channelData): ?array
    {
        if ($channelData === null || $channelData === '') {
            return null;
        }

        $decoded = json_decode($channelData, true);

        if (!is_array($decoded) || !isset($decoded['user_id'])) {
            return null;
        }

        $member = ['user_id' => (string) $decoded['user_id']];

        if ($member['user_id'] === '') {
            return null;
        }

        if (isset($decoded['user_info']) && is_array($decoded['user_info'])) {
            $member['user_info'] = $decoded['user_info'];
        }

        return $member;
    }
}
