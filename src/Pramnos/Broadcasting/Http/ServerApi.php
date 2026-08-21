<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Http;

use Pramnos\Broadcasting\Apps\AppRegistryInterface;
use Pramnos\Broadcasting\Apps\BroadcastApp;
use Pramnos\Broadcasting\LocalBroadcastServer;

/**
 * The Pusher-compatible HTTP API of the built-in WebSocket server.
 *
 *     POST /apps/{appId}/events                     publish one event
 *     POST /apps/{appId}/batch_events               publish several
 *     GET  /apps/{appId}/channels                   which channels are occupied
 *     GET  /apps/{appId}/channels/{name}            one channel's occupancy
 *     GET  /apps/{appId}/channels/{name}/users      who is in a presence channel
 *     GET  /apps/{appId}/metrics                    counters and levels
 *
 * ## Why this exists at all
 *
 * Until now the only way into the server was the backplane: an event had to be
 * published to Redis (or a log file) and ingested. That is right for the
 * application, and wrong for everything else. A deploy script wanting to announce
 * a release, a service in another language, a monitoring check asking "is anybody
 * in room 12" — all of them had to speak Redis and know the envelope format, or
 * do nothing. Occupancy in particular was unobservable from outside the process:
 * `subscribedChannels()` is in-memory and the daemon had no way to answer a
 * question about it.
 *
 * ## Signing, and why the shape is Pusher's
 *
 * Requests are authenticated with Pusher's REST scheme — `auth_key`,
 * `auth_timestamp`, `auth_version`, `body_md5`, `auth_signature` as query
 * parameters — because that is what every Pusher server SDK already sends. A
 * bespoke scheme would mean a bespoke client in every language that wants in,
 * which is the problem this is meant to remove.
 *
 * The signature covers the method, the path and every query parameter except
 * itself, sorted; `body_md5` is what binds the body to it. Omitting `body_md5` on
 * a request that has one is refused rather than tolerated: a signature over an
 * unbound body authenticates the sender and says nothing about what they sent.
 */
final class ServerApi
{
    /** How far a request's timestamp may be from ours, in seconds. */
    public const MAX_CLOCK_SKEW = 600;

    public function __construct(
        private readonly LocalBroadcastServer $server,
        private readonly AppRegistryInterface $registry,
    ) {
    }

    /**
     * Handle one API request.
     *
     * @param string               $method HTTP verb.
     * @param string               $path   Path, no query string.
     * @param array<string,string> $query  Decoded query parameters.
     * @param string               $body   Raw request body.
     * @return array{status:int, body:array<string,mixed>}
     */
    public function handle(string $method, string $path, array $query, string $body = ''): array
    {
        if (!preg_match('#^/apps/([^/]+)/(.+)$#', $path, $matches)) {
            return $this->error(404, 'Not found');
        }

        [$appId, $resource] = [$matches[1], $matches[2]];

        $app = $this->authenticate($method, $path, $query, $body);
        if ($app === null) {
            // One answer for an unknown key, a stale timestamp, a wrong signature
            // and an unbound body: a caller has no use for the difference, and
            // distinguishing them helps somebody probing keys more than it helps
            // anybody debugging.
            return $this->error(401, 'Unauthorized');
        }

        // The app in the path must be the app that signed. Without this check any
        // valid key could act on any other app's channels — the signature would
        // verify and the path would decide the target.
        if ($app->id !== '' && $app->id !== $appId) {
            return $this->error(401, 'Unauthorized');
        }

        return match (true) {
            $method === 'POST' && $resource === 'events'
                => $this->publish($body),
            $method === 'POST' && $resource === 'batch_events'
                => $this->publishBatch($body),
            $method === 'GET' && $resource === 'channels'
                => $this->channels($query),
            $method === 'GET' && $resource === 'metrics'
                => ['status' => 200, 'body' => $this->server->stats()],
            $method === 'GET' && preg_match('#^channels/([^/]+)/users$#', $resource, $m) === 1
                => $this->channelUsers(urldecode($m[1])),
            $method === 'GET' && preg_match('#^channels/([^/]+)$#', $resource, $m) === 1
                => $this->channel(urldecode($m[1]), $query),
            default => $this->error(404, 'Not found'),
        };
    }

    // -------------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------------

    /**
     * The app that signed this request, or null.
     *
     * @param array<string,string> $query
     */
    private function authenticate(string $method, string $path, array $query, string $body): ?BroadcastApp
    {
        $key       = (string) ($query['auth_key'] ?? '');
        $signature = (string) ($query['auth_signature'] ?? '');
        $timestamp = (int) ($query['auth_timestamp'] ?? 0);

        if ($key === '' || $signature === '') {
            return null;
        }

        // A replay window rather than a nonce store. A daemon has nowhere durable
        // to remember nonces, and a ten-minute window is what every Pusher SDK
        // already assumes — a shorter one turns ordinary clock drift into
        // intermittent 401s that look like a signing bug.
        if ($timestamp === 0 || abs(time() - $timestamp) > self::MAX_CLOCK_SKEW) {
            return null;
        }

        $app = $this->registry->findByKey($key);
        if ($app === null || !$app->canSign()) {
            return null;
        }

        // A body must be bound to the signature by body_md5. A signature over an
        // unbound body authenticates who sent the request and says nothing about
        // what it contained, which is not what a signature is for.
        if ($body !== '') {
            $declared = (string) ($query['body_md5'] ?? '');
            if ($declared === '' || !hash_equals(md5($body), $declared)) {
                return null;
            }
        }

        $signed = $query;
        unset($signed['auth_signature']);
        ksort($signed);

        $pairs = [];
        foreach ($signed as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        $stringToSign = strtoupper($method) . "\n" . $path . "\n" . implode('&', $pairs);

        return hash_equals(hash_hmac('sha256', $stringToSign, $app->secret), $signature)
            ? $app
            : null;
    }

    // -------------------------------------------------------------------------
    // Endpoints
    // -------------------------------------------------------------------------

    /**
     * POST /events — `{name, channel|channels, data, socket_id?}`
     */
    private function publish(string $body): array
    {
        $payload = json_decode($body, true);

        if (!is_array($payload)) {
            return $this->error(400, 'Body must be a JSON object');
        }

        $name = (string) ($payload['name'] ?? '');
        if ($name === '') {
            return $this->error(400, 'Event name is required');
        }

        $channels = $this->channelList($payload);
        if ($channels === []) {
            return $this->error(400, 'At least one channel is required');
        }

        $socketId = isset($payload['socket_id']) ? (string) $payload['socket_id'] : null;

        foreach ($channels as $channel) {
            // Through broadcastExcept so socket_id is honoured — the events API is
            // the other half of toOthers() for a caller that is not a PHP request.
            $this->server->broadcastExcept($channel, $name, $payload['data'] ?? [], $socketId);
        }

        return ['status' => 200, 'body' => []];
    }

    /**
     * POST /batch_events — `{batch: [{name, channel, data, socket_id?}, ...]}`
     */
    private function publishBatch(string $body): array
    {
        $payload = json_decode($body, true);
        $batch   = is_array($payload) && is_array($payload['batch'] ?? null) ? $payload['batch'] : null;

        if ($batch === null) {
            return $this->error(400, 'Body must be a JSON object with a "batch" array');
        }

        // Validated in full before anything is published: a batch that fails
        // half-way has delivered some of its events and reported an error, which
        // leaves the caller unable to retry safely.
        foreach ($batch as $index => $item) {
            if (!is_array($item) || (string) ($item['name'] ?? '') === '') {
                return $this->error(400, 'batch[' . $index . '] needs an event name');
            }
            if ($this->channelList($item) === []) {
                return $this->error(400, 'batch[' . $index . '] needs at least one channel');
            }
        }

        foreach ($batch as $item) {
            $socketId = isset($item['socket_id']) ? (string) $item['socket_id'] : null;

            foreach ($this->channelList($item) as $channel) {
                $this->server->broadcastExcept(
                    $channel,
                    (string) $item['name'],
                    $item['data'] ?? [],
                    $socketId
                );
            }
        }

        return ['status' => 200, 'body' => []];
    }

    /**
     * GET /channels — occupied channels, optionally with presence user counts.
     *
     * @param array<string,string> $query
     */
    private function channels(array $query): array
    {
        $prefix = (string) ($query['filter_by_prefix'] ?? '');
        $wantsUserCount = str_contains((string) ($query['info'] ?? ''), 'user_count');

        $out = [];

        foreach ($this->server->subscribedChannels() as $channel) {
            if ($prefix !== '' && !str_starts_with($channel, $prefix)) {
                continue;
            }

            $info = [];
            if ($wantsUserCount && str_starts_with($channel, 'presence-')) {
                $info['user_count'] = count($this->server->presenceMembers($channel));
            }

            $out[$channel] = $info;
        }

        return ['status' => 200, 'body' => ['channels' => $out]];
    }

    /**
     * GET /channels/{name}
     *
     * @param array<string,string> $query
     */
    private function channel(string $channel, array $query): array
    {
        $subscribers = $this->server->subscriberCount($channel);
        $requested   = explode(',', (string) ($query['info'] ?? ''));

        $body = ['occupied' => $subscribers > 0];

        if (in_array('subscription_count', $requested, true)) {
            $body['subscription_count'] = $subscribers;
        }

        if (in_array('user_count', $requested, true)) {
            if (!str_starts_with($channel, 'presence-')) {
                // Refused rather than answered with the subscription count: a
                // caller reading "user_count" off a private channel would believe
                // it had deduplicated people, and it would not have.
                return $this->error(400, 'user_count is only available on presence channels');
            }
            $body['user_count'] = count($this->server->presenceMembers($channel));
        }

        return ['status' => 200, 'body' => $body];
    }

    /**
     * GET /channels/{name}/users
     */
    private function channelUsers(string $channel): array
    {
        if (!str_starts_with($channel, 'presence-')) {
            return $this->error(400, 'Users are only available on presence channels');
        }

        $users = [];
        foreach (array_keys($this->server->presenceMembers($channel)) as $id) {
            $users[] = ['id' => (string) $id];
        }

        return ['status' => 200, 'body' => ['users' => $users]];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * The channels an events payload names, from either `channel` or `channels`.
     *
     * @param array<string,mixed> $payload
     * @return list<string>
     */
    private function channelList(array $payload): array
    {
        if (isset($payload['channels']) && is_array($payload['channels'])) {
            return array_values(array_filter(
                array_map('strval', $payload['channels']),
                static fn (string $c): bool => $c !== ''
            ));
        }

        $single = (string) ($payload['channel'] ?? '');

        return $single === '' ? [] : [$single];
    }

    /**
     * @return array{status:int, body:array<string,mixed>}
     */
    private function error(int $status, string $message): array
    {
        return ['status' => $status, 'body' => ['error' => $message]];
    }
}
