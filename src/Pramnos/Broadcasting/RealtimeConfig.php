<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting;

/**
 * Builds the client-safe realtime configuration a browser needs to connect,
 * from the server-side app.php['broadcasting'] config.
 *
 * The whole point of the realtime stack is that a deployment chooses its
 * transport (`broadcasting.transport`) — 'sse' on shared hosting, 'websocket'
 * for the self-hosted server, or 'pusher' for a managed/Reverb server — and the
 * frontend adapts. This produces exactly the values pramnos-realtime.js reads,
 * and NEVER leaks a secret (no app_secret, no Redis password).
 */
final class RealtimeConfig
{
    /**
     * @param array<string,mixed> $broadcasting The app.php['broadcasting'] array.
     * @return array<string,mixed> Client-safe config keyed by transport.
     */
    public static function forClient(array $broadcasting): array
    {
        $transport = (string) ($broadcasting['transport'] ?? 'sse');

        return match ($transport) {
            'websocket' => self::websocket($broadcasting['websocket'] ?? []),
            'pusher'    => self::pusher($broadcasting['pusher'] ?? []),
            default     => self::sse($broadcasting['sse'] ?? []),
        };
    }

    /** @param array<string,mixed> $sse */
    private static function sse(array $sse): array
    {
        return [
            'transport' => 'sse',
            'url'       => (string) ($sse['url'] ?? '/api/stream'),
        ];
    }

    /** @param array<string,mixed> $ws */
    private static function websocket(array $ws): array
    {
        return [
            'transport' => 'websocket',
            'scheme'    => (string) ($ws['scheme'] ?? 'ws'),
            'host'      => (string) ($ws['host'] ?? 'localhost'),
            'port'      => (int) ($ws['port'] ?? 6001),
            'appKey'    => (string) ($ws['app_key'] ?? 'pramnos-local'),
        ];
    }

    /** @param array<string,mixed> $pusher */
    private static function pusher(array $pusher): array
    {
        // app_key is the PUBLIC key (safe to expose); app_secret is intentionally omitted.
        return [
            'transport' => 'pusher',
            'key'       => (string) ($pusher['app_key'] ?? ''),
            'cluster'   => isset($pusher['cluster']) ? (string) $pusher['cluster'] : null,
            'wsHost'    => isset($pusher['host']) ? (string) $pusher['host'] : null,
            'wsPort'    => isset($pusher['port']) ? (int) $pusher['port'] : null,
            'forceTLS'  => (string) ($pusher['scheme'] ?? '') === 'https',
        ];
    }
}
