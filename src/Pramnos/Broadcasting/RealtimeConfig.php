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

        $primary = match ($transport) {
            'websocket' => self::websocket($broadcasting['websocket'] ?? []),
            'pusher'    => self::pusher($broadcasting['pusher'] ?? []),
            default     => self::sse($broadcasting['sse'] ?? []),
        };

        // A socket transport advertises the SSE endpoint as a fallback the client
        // can degrade to when the socket cannot connect (worker down, a proxy that
        // refuses the Upgrade, a blocked port). SSE is the terminal transport, so
        // it carries no fallback of its own. Additive: existing keys are unchanged.
        if ($primary['transport'] !== 'sse') {
            $primary['fallback'] = self::sse($broadcasting['sse'] ?? []);
        }

        return $primary;
    }

    /** The channel a user's notifications are announced on, unless `notifications_channel` says otherwise. */
    public const USER_CHANNEL = 'private-users.{id}';

    /**
     * What `<meta name="pf-realtime">` carries for a page: forClient(), and the endpoint that signs
     * a private channel. Null unless `broadcasting.transport` is one the page's client speaks —
     * `websocket` (broadcast:serve) or `pusher` — so a page with none keeps polling.
     *
     * @param array<string,mixed> $broadcasting The app.php['broadcasting'] array.
     * @return array<string,mixed>|null
     */
    public static function forPage(array $broadcasting): ?array
    {
        if (!in_array($broadcasting['transport'] ?? null, ['websocket', 'pusher'], true)) {
            return null;
        }
        $endpoint = (string) ($broadcasting['auth_endpoint'] ?? '');

        return self::forClient($broadcasting) + [
            'authEndpoint' => $endpoint !== '' ? $endpoint : \Pramnos\Http\SiteUrl::to('broadcasting/auth'),
        ];
    }

    /**
     * The channel user `$id` hears their notifications on: `broadcasting.notifications_channel`
     * with `{id}` filled in, `private-users.{id}` by default.
     *
     * @param array<string,mixed> $broadcasting The app.php['broadcasting'] array.
     */
    public static function userChannel(int $id, array $broadcasting): string
    {
        $pattern = (string) ($broadcasting['notifications_channel'] ?? '');

        return str_replace('{id}', (string) $id, $pattern !== '' ? $pattern : self::USER_CHANNEL);
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
