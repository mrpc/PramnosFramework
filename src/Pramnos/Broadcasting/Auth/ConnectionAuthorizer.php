<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Auth;

/**
 * Authorizes WebSocket connections and channel subscriptions for the built-in
 * broadcast server ({@see \Pramnos\Broadcasting\LocalBroadcastServer}).
 *
 * The server itself only speaks the wire protocol; whether a given app key may
 * connect, and whether a client may join a private-/presence- channel, is
 * delegated here so deployments can plug in their own policy (the default is
 * Pusher-compatible HMAC auth; local dev can allow everything).
 */
interface ConnectionAuthorizer
{
    /**
     * Decide whether a connection presenting $appKey (from the /app/<key> path)
     * may be established.
     *
     * @param array<string,string> $params Query parameters from the connection URL.
     */
    public function authorizeConnection(string $appKey, array $params = []): bool;

    /**
     * Decide whether a client may subscribe to $channel.
     *
     * Public channels (no private-/presence- prefix) are always allowed. For
     * private-/presence- channels the client must present a valid $auth token
     * bound to its $socketId (and, for presence, the $channelData it declared).
     */
    public function authorizeChannel(string $channel, string $socketId, string $auth, ?string $channelData = null): bool;
}
