<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Http;

/**
 * The application's side of the Pusher HTTP API: who is in a presence channel now, asked of the
 * server that holds the connections — `broadcast:serve` with `http_api` enabled ({@see ServerApi}),
 * or Pusher and any server that speaks its API.
 *
 * ```php
 * $ids = ApiClient::fromConfig($app->applicationInfo['broadcasting'] ?? [])?->presenceUsers('presence-chat');
 * ```
 *
 * Where it asks: `broadcasting.http_api.url` when set — the daemon's address from the application's
 * host, which is often not the address browsers use; else `websocket.host`/`port` for the built-in
 * server, `pusher.host`/`port`/`scheme` or Pusher's own API host for `pusher`. Signed with
 * `pusher.app_key` (or `websocket.app_key`) and `pusher.app_secret`, as Pusher's SDKs sign.
 */
final class ApiClient
{
    /** How long a question may take: it is asked while a message is posted. */
    public const TIMEOUT = 2.0;

    /**
     * What sends a GET and returns the body, or null when it failed — `file_get_contents()` by
     * default; a test answers it with a {@see ServerApi} instead.
     *
     * @var (\Closure(string): ?string)|null
     */
    public static ?\Closure $fetch = null;

    public function __construct(
        private readonly string $url,
        private readonly string $appId,
        private readonly string $key,
        private readonly string $secret,
    ) {
    }

    /**
     * The client the `broadcasting` configuration describes, or null when it describes no API: a
     * transport without one, `websocket` without `http_api.enabled`, or no key and secret to sign with.
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): ?self
    {
        $pusher = is_array($config['pusher'] ?? null) ? $config['pusher'] : [];
        $ws     = is_array($config['websocket'] ?? null) ? $config['websocket'] : [];
        $api    = is_array($config['http_api'] ?? null) ? $config['http_api'] : [];
        $key    = (string) ($pusher['app_key'] ?? $ws['app_key'] ?? '');
        $secret = (string) ($pusher['app_secret'] ?? '');
        $url    = (string) ($api['url'] ?? '');
        if ($url === '') {
            $url = match ((string) ($config['transport'] ?? '')) {
                'websocket' => empty($api['enabled']) ? '' : (empty($ws['tls']['local_cert']) ? 'http' : 'https')
                    . '://' . ($ws['host'] ?? '127.0.0.1') . ':' . (int) ($ws['port'] ?? 6001),
                'pusher' => isset($pusher['host'])
                    ? ($pusher['scheme'] ?? 'https') . '://' . $pusher['host'] . (isset($pusher['port']) ? ':' . (int) $pusher['port'] : '')
                    : 'https://api-' . ($pusher['cluster'] ?? 'mt1') . '.pusher.com',
                default => '',
            };
        }
        if ($url === '' || $key === '' || $secret === '') {
            return null;
        }

        return new self(rtrim($url, '/'), (string) ($pusher['app_id'] ?? '') ?: 'app', $key, $secret);
    }

    /**
     * The ids of who is in a presence channel now, or null when the server could not be asked.
     *
     * @return list<string>|null
     */
    public function presenceUsers(string $channel): ?array
    {
        $body = $this->get('/apps/' . rawurlencode($this->appId) . '/channels/' . rawurlencode($channel) . '/users');
        if (!is_array($body['users'] ?? null)) {
            return null;
        }

        return array_values(array_map(static fn ($u): string => (string) ($u['id'] ?? ''), $body['users']));
    }

    /**
     * A signed GET, decoded; null when it failed or did not answer JSON.
     *
     * @return array<string, mixed>|null
     */
    private function get(string $path): ?array
    {
        $query = ['auth_key' => $this->key, 'auth_timestamp' => (string) time(), 'auth_version' => '1.0'];
        ksort($query);
        $pairs = [];
        foreach ($query as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $query['auth_signature'] = hash_hmac('sha256', "GET\n" . $path . "\n" . implode('&', $pairs), $this->secret);
        $url = $this->url . $path . '?' . http_build_query($query);

        $fetch = self::$fetch ?? static function (string $url): ?string {
            $context = stream_context_create(['http' => ['timeout' => self::TIMEOUT, 'ignore_errors' => false]]);
            $body    = @file_get_contents($url, false, $context);

            return $body === false ? null : $body;
        };
        $body    = $fetch($url);
        $decoded = $body === null ? null : json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
