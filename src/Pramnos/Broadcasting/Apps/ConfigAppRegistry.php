<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Apps;

/**
 * The single app configured in `broadcasting.pusher` (or `broadcasting.websocket`).
 *
 * This is the historical behaviour and stays the default: an installation that has
 * not enabled the authserver feature behaves exactly as it did before app
 * registries existed.
 */
final class ConfigAppRegistry implements AppRegistryInterface
{
    private ?BroadcastApp $app;

    /**
     * @param array<string,mixed> $broadcasting The `broadcasting` config array.
     */
    public function __construct(array $broadcasting)
    {
        $pusher = is_array($broadcasting['pusher'] ?? null) ? $broadcasting['pusher'] : [];
        $ws     = is_array($broadcasting['websocket'] ?? null) ? $broadcasting['websocket'] : [];

        // The key may be configured under either block: `pusher.app_key` for a
        // managed/Reverb edge, `websocket.app_key` for the built-in server. The
        // secret only ever lives under `pusher`, which is also where
        // broadcast:serve already reads it from.
        $key    = (string) ($pusher['app_key'] ?? $ws['app_key'] ?? '');
        $secret = (string) ($pusher['app_secret'] ?? '');
        $id     = (string) ($pusher['app_id'] ?? '');

        $this->app = $key === '' ? null : new BroadcastApp($key, $secret, $id, 'config');
    }

    public function findByKey(string $key): ?BroadcastApp
    {
        if ($this->app === null || $key === '') {
            return null;
        }

        // hash_equals rather than ===: the key is public, but comparing it in
        // constant time costs nothing and keeps one habit across both registries.
        return hash_equals($this->app->key, $key) ? $this->app : null;
    }

    public function defaultApp(): ?BroadcastApp
    {
        return $this->app;
    }
}
