<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Auth;

/**
 * Permissive authorizer — accepts every connection and every channel.
 *
 * This is the default so local development and tests work with no configuration,
 * and it preserves the historical (unauthenticated) behaviour of the built-in
 * server. Do NOT use it in production for private/presence channels — configure
 * a {@see PusherAuthorizer} with your app key + secret instead.
 */
final class AllowAllAuthorizer implements PresenceAuthorizer
{
    public function authorizeConnection(string $appKey, array $params = []): bool
    {
        return true;
    }

    public function authorizeChannel(string $channel, string $socketId, string $auth, ?string $channelData = null): bool
    {
        return true;
    }

    /**
     * Take the client's word for who it is.
     *
     * Unsigned and therefore forgeable — which is exactly what this authorizer is
     * for. Without it, presence channels would not work at all in local
     * development, and a developer would be debugging an empty member list caused
     * by the dev default rather than by their code.
     */
    public function presenceMember(string $channel, string $socketId, ?string $channelData): ?array
    {
        if ($channelData === null || $channelData === '') {
            return null;
        }

        $decoded = json_decode($channelData, true);

        if (!is_array($decoded) || !isset($decoded['user_id']) || (string) $decoded['user_id'] === '') {
            return null;
        }

        $member = ['user_id' => (string) $decoded['user_id']];

        if (isset($decoded['user_info']) && is_array($decoded['user_info'])) {
            $member['user_info'] = $decoded['user_info'];
        }

        return $member;
    }
}
