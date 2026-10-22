<?php

declare(strict_types=1);

namespace Pramnos\Conversations\Beacon;

/**
 * `/beacon/*`: what the outside watcher, the primary and the browsers ask of the beacon — and, on
 * the primary, its health for the watcher. Served by `www/beacon.php` without the application:
 * no session, no database of the beacon's own, so it answers when everything else does not.
 *
 * | path | who | does |
 * |---|---|---|
 * | `GET health` | the watcher (its HMAC) | the primary: `{ok, db, redis, daemon, queue_lag}`, 503 when the database or the daemon is down; the beacon: `{ok, role, primary, since, pending, last_sync_at, people}` |
 * | `POST alert` | the watcher | `{down: [...], since}`: the primary is down — once, a push to the team and a line in the emergency channel; `down: []` it is back |
 * | `POST sync` | the primary (its HMAC) | `{people, revoked, push}`: the team, every minute |
 * | `POST session` | a browser (`Bearer` its emergency key) | `{me, people, conversations}` |
 * | `GET messages?c=&after=` | a browser | a conversation's messages |
 * | `POST messages` | a browser | `{c, body, client_id}`: written once |
 * | `POST react` | a browser | `{id, c, emoji, on}` |
 * | `GET export?after=` | the primary | what was written, to import |
 * | `POST ack` | the primary | `{cursor}`: imported, out of the log |
 *
 * A browser's request comes from another origin: answered with CORS for the `origins` configured.
 */
final class Endpoint
{
    /** The longest message the beacon takes — text only, as the conversations'. */
    public const MAX_LENGTH = 4000;

    /**
     * @param (callable(): array{db: bool, redis: bool, daemon: bool, queue_lag: ?int})|null $probe The primary's checks, where a test sets them
     * @param (callable(string, string): void)|null                                           $push  How the team is told: title, body
     */
    public function __construct(
        private readonly ?Store $store = null,
        private $probe = null,
        private $push = null,
    ) {
    }

    /** Answer the request PHP has, and print the answer. */
    public function serve(): void
    {
        $path    = (string) preg_replace('#^.*?/beacon/#', '', (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with((string) $name, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr((string) $name, 5)))] = (string) $value;
            }
        }
        $answer = $this->handle((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $headers, (string) file_get_contents('php://input'), $_GET);
        http_response_code($answer['status']);
        foreach ($answer['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $answer['body'];
    }

    /**
     * A request's answer.
     *
     * @param array<string, string> $headers Lower-case names
     * @param array<string, mixed>  $query
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function handle(string $method, string $path, array $headers, string $body, array $query = [], ?int $now = null): array
    {
        $now    = $now ?? time();
        $method = strtoupper($method);
        $cors   = $this->cors($headers['origin'] ?? '');
        if ($method === 'OPTIONS') {
            return ['status' => 204, 'headers' => $cors, 'body' => ''];
        }
        $role = (string) Config::value('role', '');
        $json = (array) json_decode($body, true);
        $by   = static fn (string $secret): bool => Signature::verify((string) Config::value($secret, ''), $headers['x-beacon-time'] ?? null,
            $headers['x-beacon-signature'] ?? null, $body, $now);
        try {
            $answer = match (true) {
                $path === 'health' && $method === 'GET' => $by('watcher_secret') ? $this->health($role, $now) : [401, ['error' => 'unsigned']],
                $role !== 'beacon'                      => [404, ['error' => 'not a beacon']],
                $path === 'alert' && $method === 'POST' => $by('watcher_secret') ? $this->alert($json, $now) : [401, ['error' => 'unsigned']],
                $path === 'sync' && $method === 'POST'  => $by('secret') ? $this->sync($json, $now) : [401, ['error' => 'unsigned']],
                $path === 'export' && $method === 'GET' => $by('secret') ? [200, ['messages' => $this->store()->export((string) ($query['after'] ?? ''))]] : [401, ['error' => 'unsigned']],
                $path === 'ack' && $method === 'POST'   => $by('secret') ? [200, ['taken' => $this->store()->acknowledge((string) ($json['cursor'] ?? ''))]] : [401, ['error' => 'unsigned']],
                in_array($path, ['session', 'messages', 'react'], true) => $this->asPerson($method, $path, $headers, $json, $query, $now),
                default                                 => [404, ['error' => 'no such thing here']],
            };
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Beacon: ' . $path . ': ' . $e->getMessage(), 'beacon');
            $answer = [503, ['error' => 'unavailable']];
        }

        return ['status' => $answer[0], 'headers' => $cors + ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
            'body' => (string) json_encode($answer[1], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }

    /**
     * The health the watcher reads.
     *
     * @return array{int, array<string, mixed>}
     */
    private function health(string $role, int $now): array
    {
        if ($role === 'beacon') {
            $store   = $this->store();
            $primary = $store->primary();

            return [200, ['ok' => true, 'role' => 'beacon', 'primary' => $primary['state'], 'since' => $primary['since'], 'pending' => $store->pending(),
                'last_sync_at' => $store->syncedAt(), 'people' => count($store->people())]];
        }
        $checks = ($this->probe ?? self::checks(...))();
        $ok     = $checks['db'] && $checks['daemon'];

        return [$ok ? 200 : 503, ['ok' => $ok, 'role' => 'primary'] + $checks];
    }

    /**
     * The primary's own state: its database, its Redis, its realtime daemon, how far its queue lags.
     *
     * @return array{db: bool, redis: bool, daemon: bool, queue_lag: ?int}
     */
    private static function checks(): array
    {
        // @codeCoverageIgnoreStart
        $db    = false;
        $lag   = null;
        $check = Config::value('db_check');
        $file  = Config::settingsFile();
        try {
            if (is_callable($check)) {
                // The application's own check of its database.
                $db = (bool) $check();
            } elseif ($file !== null) {
                $database = \Pramnos\Database\Database::getInstance(\Pramnos\Application\Settings::getInstance($file));
                $database->connect();
                $db     = true;
                $oldest = $database->queryBuilder()->table('#PREFIX#queueitems')->select(['createdat'])->where('status', 'pending')
                    ->orderBy('createdat')->limit(1)->getAllOrFail()[0]['createdat'] ?? null;
                $lag = $oldest === null ? 0 : max(0, time() - (is_numeric($oldest) ? (int) $oldest : (int) strtotime((string) $oldest)));
            }
        } catch (\Throwable) {
            // down: said as false
        }
        try {
            $redis = (bool) \Pramnos\Redis\ConnectionManager::forConfig((array) Config::value('redis', []))->connection()->ping();
        } catch (\Throwable) {
            $redis = false;
        }
        $daemon = (array) Config::value('daemon', ['host' => '127.0.0.1', 'port' => 6001]);
        $socket = @fsockopen((string) ($daemon['host'] ?? '127.0.0.1'), (int) ($daemon['port'] ?? 6001), $errno, $error, 2.0);
        if (is_resource($socket)) {
            fclose($socket);
        }

        return ['db' => $db, 'redis' => $redis, 'daemon' => is_resource($socket) || $socket !== false, 'queue_lag' => $lag];
        // @codeCoverageIgnoreEnd
    }

    /**
     * The watcher says the primary is down — or, with nothing down, that it is back.
     *
     * @param array<string, mixed> $json
     * @return array{int, array<string, mixed>}
     */
    private function alert(array $json, int $now): array
    {
        $down  = array_values(array_filter(array_map('strval', (array) ($json['down'] ?? []))));
        $since = (int) ($json['since'] ?? $now);
        $store = $this->store();
        if ($down !== []) {
            if ($store->mark('down', $since)) {
                $what = implode(', ', $down);
                $store->write(Store::EMERGENCY, Store::SYSTEM, sprintf(Config::words('down'), date('H:i', $since), $what), 'alert:' . $since, $now);
                ($this->push ?? $this->push(...))(Config::words('title'), sprintf(Config::words('body'), date('H:i', $since), $what));

                return [200, ['told' => true]];
            }

            return [200, ['told' => false]];
        }
        $before = $store->primary();
        if ($store->mark('up', $now)) {
            $minutes = $before['since'] === null ? 0 : (int) round(($now - $before['since']) / 60);
            $store->write(Store::EMERGENCY, Store::SYSTEM, sprintf(Config::words('back'), date('H:i', $now), (string) $minutes), 'recovered:' . $now, $now);

            return [200, ['told' => true]];
        }

        return [200, ['told' => false]];
    }

    /**
     * What the primary sends every minute.
     *
     * @param array<string, mixed> $json
     * @return array{int, array<string, mixed>}
     */
    private function sync(array $json, int $now): array
    {
        $people = array_values(array_filter((array) ($json['people'] ?? []), static fn ($p): bool => is_array($p) && (int) ($p['id'] ?? 0) > 0));
        $this->store()->sync(
            array_map(static fn (array $p): array => ['id' => (int) $p['id'], 'name' => (string) ($p['name'] ?? '')], $people),
            array_map('strval', (array) ($json['revoked'] ?? [])),
            array_values(array_filter((array) ($json['push'] ?? []), 'is_array')),
            $now
        );

        return [200, ['people' => count($people)]];
    }

    /**
     * A browser's request, with its emergency key.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $json
     * @param array<string, mixed>  $query
     * @return array{int, array<string, mixed>}
     */
    private function asPerson(string $method, string $path, array $headers, array $json, array $query, int $now): array
    {
        $key    = preg_match('/^Bearer\s+(\S+)$/i', $headers['authorization'] ?? '', $m) ? $m[1] : '';
        $claims = $key === '' ? null : EmergencyKey::verify($key, $now);
        if ($claims === null || !$this->store()->admits($claims)) {
            return [401, ['error' => 'not admitted']];
        }
        $me    = $claims['u'];
        $store = $this->store();
        if ($path === 'session') {
            $people = $store->people();
            $dms    = array_map(static fn (int $id): string => Store::dm($me, $id), array_values(array_diff(array_keys($people), [$me])));

            return [200, ['me' => ['id' => $me, 'name' => $people[$me] ?? $claims['n']], 'people' => $people, 'conversations' => [Store::EMERGENCY, ...$dms],
                'channel' => Config::channel(), 'primary' => $store->primary()]];
        }
        $conversation = (string) ($method === 'GET' ? ($query['c'] ?? '') : ($json['c'] ?? ''));
        if (!Store::mayUse($conversation, $me)) {
            return [403, ['error' => 'not yours']];
        }
        if ($path === 'messages' && $method === 'GET') {
            return [200, ['messages' => $store->read($conversation, (string) ($query['after'] ?? ''))]];
        }
        if ($path === 'messages' && $method === 'POST') {
            $text = trim((string) ($json['body'] ?? ''));
            if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
                return [422, ['error' => 'A message needs something in it, and at most ' . self::MAX_LENGTH . ' characters.']];
            }

            return [200, ['id' => $store->write($conversation, $me, $text, mb_substr((string) ($json['client_id'] ?? ''), 0, 64), $now)]];
        }
        if ($path === 'react' && $method === 'POST') {
            $emoji = trim((string) ($json['emoji'] ?? ''));
            if ($emoji === '' || mb_strlen($emoji) > 16) {
                return [422, ['error' => 'That is not an emoji.']];
            }
            $store->react((string) ($json['id'] ?? ''), $me, $emoji, !empty($json['on']) || !array_key_exists('on', $json));

            return [200, ['ok' => true]];
        }

        return [405, ['error' => 'not like that']];
    }

    /** The CORS headers for an origin the beacon serves; none for another. @return array<string, string> */
    private function cors(string $origin): array
    {
        if ($origin === '' || !in_array($origin, array_map('strval', (array) Config::value('origins', [])), true)) {
            return [];
        }

        return ['Access-Control-Allow-Origin' => $origin, 'Vary' => 'Origin', 'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type', 'Access-Control-Max-Age' => '600'];
    }

    /** The store: given, or the configured Redis. */
    private function store(): Store
    {
        return $this->store ?? Store::connect();
    }

    /** Tell the team by push, with the VAPID keys and the subscriptions the primary synced. */
    private function push(string $title, string $body): void
    {
        // @codeCoverageIgnoreStart
        $vapid   = \Pramnos\Push\Vapid::load();
        $library = \Pramnos\Notification\Channels\PushChannel::LIBRARY;
        $kind    = \Pramnos\Notification\Channels\PushChannel::SUBSCRIPTION;
        if ($vapid === null || !class_exists($library)) {
            \Pramnos\Logs\Logger::log('Beacon: no push — ' . ($vapid === null ? 'no VAPID keys' : 'no web-push library'), 'beacon');

            return;
        }
        $push = new $library(['VAPID' => ['subject' => $vapid['subject'], 'publicKey' => $vapid['publicKey'], 'privateKey' => $vapid['privateKey']]]);
        $push->setDefaultOptions(['TTL' => 3600, 'urgency' => 'high']);
        // Tapped, it opens the conversations — which, with the server down, their app's worker opens on the beacon.
        $payload = (string) json_encode(array_filter(['title' => $title, 'body' => $body, 'tag' => 'beacon', 'url' => (string) Config::value('page', '')]),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($this->store()->subscriptions() as $s) {
            $push->queueNotification($kind::create(['endpoint' => (string) $s['endpoint'], 'publicKey' => (string) ($s['p256dh'] ?? ''),
                'authToken' => (string) ($s['auth_secret'] ?? $s['auth'] ?? ''), 'contentEncoding' => (string) ($s['content_encoding'] ?? 'aes128gcm')]), $payload);
        }
        foreach ($push->flush() as $report) {
            if (!$report->isSuccess()) {
                \Pramnos\Logs\Logger::log('Beacon: push failed for ' . $report->getEndpoint(), 'beacon');
            }
        }
        // @codeCoverageIgnoreEnd
    }
}
