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
final class AllowAllAuthorizer implements ConnectionAuthorizer
{
    public function authorizeConnection(string $appKey, array $params = []): bool
    {
        return true;
    }

    public function authorizeChannel(string $channel, string $socketId, string $auth, ?string $channelData = null): bool
    {
        return true;
    }
}
