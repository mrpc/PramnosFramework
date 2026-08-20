<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Apps;

/**
 * One application allowed to use the realtime edge: its public key, its signing
 * secret, and enough identity to log which app a connection belonged to.
 *
 * Immutable on purpose. It is handed to a long-running daemon that holds it for
 * the life of a connection, and a mutable credential passed around a select loop
 * is a credential nobody can reason about.
 */
final class BroadcastApp
{
    /**
     * @param string $key    Public app key — travels in the connection URL.
     * @param string $secret HMAC signing secret. Never leaves the server.
     * @param string $id     Stable identifier for logs and the HTTP API path.
     * @param string $name   Human-readable name, for diagnostics only.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $secret,
        public readonly string $id = '',
        public readonly string $name = '',
    ) {
    }

    /**
     * True when this app can actually sign — an app row with no secret cannot
     * authorize a private channel, and saying so here beats failing later inside
     * an HMAC comparison against an empty string.
     */
    public function canSign(): bool
    {
        return $this->key !== '' && $this->secret !== '';
    }

    /**
     * Redacted form, safe to log.
     *
     * @return array<string,string>
     */
    public function toLogContext(): array
    {
        return [
            'app_id'  => $this->id,
            'app_key' => $this->key,
            'name'    => $this->name,
            'secret'  => $this->secret === '' ? 'absent' : 'present',
        ];
    }
}
