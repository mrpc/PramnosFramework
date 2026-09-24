<?php

namespace Pramnos\Auth;

use Pramnos\Database\Database;

/**
 * Delivers queued OAuth2 webhook events to registered application endpoints.
 *
 * The delivery pipeline runs on top of two tables in the applications schema:
 *   - oauth2_webhook_endpoints  — registered URLs + HMAC secrets per app/event-type
 *   - oauth2_webhook_events     — delivery queue / audit log (status lifecycle:
 *                                  pending → sent | failed | cancelled)
 *
 * On PostgreSQL the PL/pgSQL function create_webhook_event() enqueues events
 * automatically from triggers and stored procedures. On MySQL, or when the PHP
 * layer creates events directly, call queueEvent() instead.
 *
 * Typical usage:
 *   $svc = new WebhookService($db);
 *   $stats = $svc->processQueue();            // from a cron/daemon
 *   $svc->queueEvent('token_revoked', $uid, ['token_id' => 42]);  // MySQL path
 *
 * Request signing: X-Webhook-Signature: sha256=<HMAC-SHA256(secret, body)>
 * Retries: exponential back-off starting at 5 minutes (capped at 24 h).
 *
 */
class WebhookService
{
    private const TABLE_ENDPOINTS = 'applications.oauth2_webhook_endpoints';
    private const TABLE_EVENTS    = 'applications.oauth2_webhook_events';

    /**
     * The event types an endpoint may subscribe to.
     *
     * Repeated from the table's own CHECK constraint on purpose: a value the database
     * refuses should be refused before it gets there, with a message naming the
     * alternatives, rather than coming back as a constraint violation nobody can act on.
     */
    public const EVENT_TYPES = [
        'user_deauthorized',
        'token_revoked',
        'gdpr_request',
        'user_profile_changed',
        'device_deauthorized',
        'account_deleted',
        'scope_changed',
        'permissions_changed',
    ];

    /** An endpoint the application registered itself, through `/Webhook/register`. */
    public const REGISTERED_BY_CLIENT = 'client';

    /** An endpoint an administrator entered on the application's screen. */
    public const REGISTERED_BY_ADMIN = 'admin';

    private Database $database;
    private string $lastError = '';

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    // ── Event queuing ─────────────────────────────────────────────────────────

    /**
     * Queue one webhook event row per active endpoint subscribed to $eventType.
     *
     * This is the cross-database path. On PostgreSQL the PL/pgSQL function
     * create_webhook_event() does the same thing inside transactions/triggers.
     * Calling this method from PHP is safe on both engines.
     *
     * `$userId` is nullable, because the column is: `oauth2_webhook_events.user_id`
     * is `REFERENCES users(userid) ON DELETE SET NULL`, so NULL is a value the
     * schema was built to hold. It was declared `int` here, which left no way to
     * queue an event that is not about a particular person — a test ping, or
     * anything describing the application rather than a user. Passing 0 instead
     * violates the foreign key, so the only options were a real user or nothing.
     *
     * Widening the parameter is additive: every caller passing an int still
     * type-checks.
     *
     * `$onlyEndpoint` narrows the fan-out to one registered endpoint. Fanning out is right for a
     * real event — `token_revoked` concerns every application holding a token — but wrong for an
     * event one application asked to have sent: a test ping named a webhook, and every other
     * subscriber to that type received one too, so any client could generate traffic against
     * another client's URL by subscribing to the same event. The endpoint id is still matched
     * against `$eventType` and `is_active`, so passing one that does not subscribe queues nothing.
     *
     * Returns the number of event rows inserted (one per matching endpoint).
     */
    public function queueEvent(
        string  $eventType,
        ?int    $userId,
        array   $payload,
        ?string $deviceCode = null,
        ?int    $tokenId    = null,
        ?int    $onlyEndpoint = null
    ): int {
        $query = $this->database->queryBuilder()
            ->table(self::TABLE_ENDPOINTS)
            ->select(['webhook_id', 'retry_count'])
            ->where('webhook_type', $eventType)
            ->where('is_active', 1);

        if ($onlyEndpoint !== null) {
            $query->where('webhook_id', $onlyEndpoint);
        }

        $endpoints = $query->get();

        if ($endpoints->numRows === 0) {
            return 0;
        }

        $now   = date('Y-m-d H:i:s');
        $count = 0;

        while ($endpoints->fetch()) {
            $this->database->queryBuilder()
                ->table(self::TABLE_EVENTS)
                ->insert([
                    'webhook_id'      => (int) $endpoints->fields['webhook_id'],
                    'event_type'      => $eventType,
                    'user_id'         => $userId,
                    'device_code'     => $deviceCode,
                    'token_id'        => $tokenId,
                    'payload'         => json_encode($payload),
                    'status'          => 'pending',
                    'max_attempts'    => (int) $endpoints->fields['retry_count'],
                    'next_attempt_at' => $now,
                    'created_at'      => $now,
                ]);
            $count++;
        }

        return $count;
    }

    // ── Queue processing ──────────────────────────────────────────────────────

    /**
     * Process up to $batchSize pending webhook events.
     *
     * For each event the endpoint URL is fetched, the payload is signed with
     * HMAC-SHA256, and an HTTP POST is sent. Successful deliveries set
     * status = 'sent'; failures decrement retries and apply exponential back-off.
     * Events that exhaust all retry attempts are marked 'failed'.
     *
     * Returns ['sent' => int, 'failed' => int].
     */
    public function processQueue(int $batchSize = 50): array
    {
        $now    = date('Y-m-d H:i:s');
        $sent   = 0;
        $failed = 0;

        $events = $this->database->queryBuilder()
            ->table(self::TABLE_EVENTS)
            ->select(['event_id', 'webhook_id', 'event_type', 'payload', 'attempts', 'max_attempts'])
            ->where('status', 'pending')
            ->where('next_attempt_at', '<=', $now)
            ->limit($batchSize)
            ->get();

        if ($events->numRows === 0) {
            return ['sent' => 0, 'failed' => 0];
        }

        while ($events->fetch()) {
            $event    = $events->fields;
            $eventId  = (int) $event['event_id'];
            $attempts = (int) $event['attempts'];

            $endpoint = $this->database->queryBuilder()
                ->table(self::TABLE_ENDPOINTS)
                // Every column rather than a list: `registered_by` decides how far the
                // delivery is trusted, and a database that has not run the migration adding
                // it must still deliver — as `client`, the stricter answer.
                ->where('webhook_id', (int) $event['webhook_id'])
                ->first();

            if ($endpoint->numRows === 0) {
                // Endpoint was deleted — cancel event
                $this->database->queryBuilder()
                    ->table(self::TABLE_EVENTS)
                    ->where('event_id', $eventId)
                    ->update(['status' => 'cancelled']);
                continue;
            }

            $deliveryData = array_merge($event, $endpoint->fields);
            $success      = $this->deliverEvent($deliveryData);

            if ($success) {
                $this->database->queryBuilder()
                    ->table(self::TABLE_EVENTS)
                    ->where('event_id', $eventId)
                    ->update([
                        'status'   => 'sent',
                        'attempts' => $attempts + 1,
                        'sent_at'  => date('Y-m-d H:i:s'),
                    ]);
                $sent++;
            } else {
                $newAttempts = $attempts + 1;
                $maxAttempts = (int) $event['max_attempts'];
                $status      = $newAttempts >= $maxAttempts ? 'failed' : 'pending';
                // Exponential back-off: 5 min * 2^(attempt-1), capped at 24 h
                $backoff     = min(300 * (2 ** ($newAttempts - 1)), 86400);

                $this->database->queryBuilder()
                    ->table(self::TABLE_EVENTS)
                    ->where('event_id', $eventId)
                    ->update([
                        'status'          => $status,
                        'attempts'        => $newAttempts,
                        'next_attempt_at' => date('Y-m-d H:i:s', time() + $backoff),
                        'last_error'      => substr($this->lastError, 0, 500),
                    ]);
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    // ── Maintenance ────────────────────────────────────────────────────────────

    /**
     * Delete sent/failed/cancelled events older than $daysOld days.
     *
     * Returns the count of deleted rows.
     */
    public function purgeOldEvents(int $daysOld = 30): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));

        $count = $this->database->queryBuilder()
            ->table(self::TABLE_EVENTS)
            ->whereIn('status', ['sent', 'failed', 'cancelled'])
            ->where('created_at', '<', $cutoff)
            ->count();

        if ($count > 0) {
            $this->database->queryBuilder()
                ->table(self::TABLE_EVENTS)
                ->whereIn('status', ['sent', 'failed', 'cancelled'])
                ->where('created_at', '<', $cutoff)
                ->delete();
        }

        return (int) $count;
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    /**
     * The non-public ranges a client-registered endpoint may resolve to.
     *
     * From `authserver.webhooks` in `app/app.php`:
     *
     * - `allow_private` (default **true**) — the organisation's private network:
     *   {@see \Pramnos\Security\OutboundUrl::PRIVATE_NETWORK_RANGES}, which is RFC 1918,
     *   carrier-grade NAT and IPv6 unique-local. Loopback and link-local are not in it, so
     *   the cloud metadata address stays out of reach either way.
     * - `allow_private_ranges` — CIDR ranges allowed in addition, whatever `allow_private`
     *   says. With `allow_private => false` this is the whole list: `['10.8.0.0/24']` lets
     *   the VPN in and nothing else. A range outside the private network — `127.0.0.1/32` —
     *   is allowed only by being named here.
     *
     * @return list<string>
     */
    public static function allowedPrivateRanges(): array
    {
        $config = self::config();

        $ranges = ($config['allow_private'] ?? true)
            ? \Pramnos\Security\OutboundUrl::PRIVATE_NETWORK_RANGES
            : [];

        foreach ((array) ($config['allow_private_ranges'] ?? []) as $range) {
            $ranges[] = (string) $range;
        }

        return array_values(array_unique($ranges));
    }

    /**
     * Whether an endpoint must be `https://`.
     *
     * `require_https` in `authserver.webhooks`, default **true**: the event describes a person
     * and is signed with a shared secret, and over plaintext on the internet both are
     * readable by anything on the path. Set it to `false` where the network is encrypted
     * underneath — a VPN — or where receivers under development have no certificate.
     *
     * Deliberately not tied to this server's own development mode: whether *this* site is
     * being developed says nothing about the receivers, which are other people's
     * applications — one of them under development on somebody's own machine while this
     * server is the live one.
     */
    public static function requiresHttps(): bool
    {
        return (bool) (self::config()['require_https'] ?? true);
    }

    /**
     * `authserver.webhooks` from the application's configuration, or an empty array.
     *
     * @return array<string, mixed>
     */
    private static function config(): array
    {
        $application = \Pramnos\Application\Application::currentInstance();
        $config      = is_object($application)
            ? ($application->applicationInfo['authserver']['webhooks'] ?? [])
            : [];

        return is_array($config) ? $config : [];
    }

    /**
     * Why a client may not register this endpoint's host, or null if it may.
     *
     * Only the address is judged here — the URL's shape and scheme are the caller's to check
     * first. A name that does not resolve yet is accepted: DNS is set up after registration
     * as often as before it, and every delivery resolves the name again, checks it and pins
     * the address.
     */
    public static function addressRefusal(string $url): ?string
    {
        $host    = (string) parse_url($url, PHP_URL_HOST);
        $allowed = self::allowedPrivateRanges();

        foreach (\Pramnos\Security\OutboundUrl::addressesOf($host) as $address) {
            if (!\Pramnos\Security\OutboundUrl::isPublicAddress($address)
                && !\Pramnos\Security\OutboundUrl::inRanges($address, $allowed)
            ) {
                return 'endpoint_url resolves to an address inside this network';
            }
        }

        return null;
    }

    /**
     * Insert or replace an application's endpoint for one event type.
     *
     * One endpoint per (application, type): registering the same type again replaces the
     * URL and the secret, which is what somebody does when they have lost the secret. It
     * also replaces `registered_by`, so an application re-registering an endpoint an
     * administrator entered makes it its own — and subject to the guard — again.
     *
     * @param string $secret       The plain signing secret; stored encrypted when APP_KEY is set
     * @param string $registeredBy {@see REGISTERED_BY_CLIENT} or {@see REGISTERED_BY_ADMIN}
     */
    public function saveEndpoint(
        int $appId,
        string $url,
        string $type,
        string $secret,
        string $registeredBy = self::REGISTERED_BY_CLIENT
    ): void {
        $fields = [
            'endpoint_url'  => $url,
            'secret_key'    => self::sealSecret($secret),
            'is_active'     => true,
            'registered_by' => $registeredBy,
        ];

        $existing = $this->database->queryBuilder()
            ->table(self::TABLE_ENDPOINTS)
            ->select(['webhook_id'])
            ->where('appid', $appId)
            ->where('webhook_type', $type)
            ->first();

        if ($existing && $existing->numRows > 0) {
            $this->database->queryBuilder()
                ->table(self::TABLE_ENDPOINTS)
                ->where('webhook_id', (int) $existing->fields['webhook_id'])
                ->update($fields + ['updated_at' => date('Y-m-d H:i:s')]);

            return;
        }

        $this->database->queryBuilder()
            ->table(self::TABLE_ENDPOINTS)
            ->insert($fields + ['appid' => $appId, 'webhook_type' => $type]);
    }

    /**
     * An application's endpoints, with how their deliveries have gone — never the secrets.
     *
     * @return list<array<string, mixed>> Each row: webhook_id, endpoint_url, webhook_type,
     *                                    is_active, registered_by, created_at, updated_at,
     *                                    and `events` — counts by status
     */
    public function endpointsFor(int $appId): array
    {
        $rows = $this->database->queryBuilder()
            ->table(self::TABLE_ENDPOINTS)
            ->where('appid', $appId)
            ->orderBy('webhook_type')
            ->get();

        $endpoints = [];
        while ($rows && $rows->fetch()) {
            $row = (array) $rows->fields;
            unset($row['secret_key']);
            $row['registered_by'] = (string) ($row['registered_by'] ?? self::REGISTERED_BY_CLIENT);
            $row['events']        = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0];
            $endpoints[(int) $row['webhook_id']] = $row;
        }

        if ($endpoints === []) {
            return [];
        }

        $counts = $this->database->queryBuilder()
            ->table(self::TABLE_EVENTS)
            ->select(['webhook_id', 'status', 'COUNT(*) AS total'])
            ->whereIn('webhook_id', array_keys($endpoints))
            ->groupBy(['webhook_id', 'status'])
            ->get();

        while ($counts && $counts->fetch()) {
            $id     = (int) $counts->fields['webhook_id'];
            $status = (string) $counts->fields['status'];
            if (isset($endpoints[$id]['events'][$status])) {
                $endpoints[$id]['events'][$status] = (int) $counts->fields['total'];
            }
        }

        return array_values($endpoints);
    }

    /**
     * Remove one of an application's endpoints. False when it has no such endpoint.
     *
     * Scoped to the owner as well as to the id: an id is guessable, and ownership is the
     * thing that must hold. Queued events are left for the delivery run to cancel, which
     * keeps the audit trail honest about what was attempted.
     */
    public function deleteEndpoint(int $appId, int $webhookId): bool
    {
        if (!$this->ownsEndpoint($appId, $webhookId)) {
            return false;
        }

        $this->database->queryBuilder()
            ->table(self::TABLE_ENDPOINTS)
            ->where('webhook_id', $webhookId)
            ->where('appid', $appId)
            ->delete();

        return true;
    }

    /**
     * Give one of an application's endpoints a new signing secret, and return it.
     *
     * Null when the application has no such endpoint. The value returned is the only
     * readable copy: it is stored encrypted and never displayed again.
     */
    public function rotateEndpointSecret(int $appId, int $webhookId): ?string
    {
        if (!$this->ownsEndpoint($appId, $webhookId)) {
            return null;
        }

        $secret = bin2hex(random_bytes(32));

        $this->database->queryBuilder()
            ->table(self::TABLE_ENDPOINTS)
            ->where('webhook_id', $webhookId)
            ->where('appid', $appId)
            ->update([
                'secret_key' => self::sealSecret($secret),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $secret;
    }

    /** Whether this endpoint exists and belongs to this application. */
    private function ownsEndpoint(int $appId, int $webhookId): bool
    {
        if ($webhookId <= 0) {
            return false;
        }

        $row = $this->database->queryBuilder()
            ->table(self::TABLE_ENDPOINTS)
            ->select(['webhook_id'])
            ->where('webhook_id', $webhookId)
            ->where('appid', $appId)
            ->first();

        return $row && $row->numRows > 0;
    }

    /**
     * The secret as it is stored.
     *
     * Encrypted, because it has to be recoverable — it is the HMAC key each delivery is
     * signed with — so hashing is not an option the way it is for a password. Anyone who
     * could read the column could forge a webhook the receiver would accept as ours.
     *
     * Left as-is when APP_KEY is unset: an installation without a key must still be able to
     * register an endpoint, and the row converts itself on the next write.
     * {@see deliverEvent()} reads through `maybeDecrypt()`, so both forms work.
     */
    private static function sealSecret(string $secret): string
    {
        return \Pramnos\Security\Encrypter::isAvailable()
            ? \Pramnos\Security\Encrypter::encrypt($secret)
            : $secret;
    }

    // ── Signature helpers ─────────────────────────────────────────────────────

    /**
     * Verify an inbound webhook request signature.
     *
     * The header value is expected in the format "sha256=<hex>".
     * Uses hash_equals() to prevent timing attacks.
     */
    public static function verifySignature(
        string $payload,
        string $secret,
        string $signatureHeader
    ): bool {
        if (!str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signatureHeader);
    }

    /**
     * Build the HMAC-SHA256 signature header value for an outbound request.
     * Exposed for testing and for callers that construct HTTP requests manually.
     */
    public static function buildSignature(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    // ── Private delivery ──────────────────────────────────────────────────────

    /**
     * Send one webhook HTTP POST. Returns true on a 2xx response.
     *
     * The request body is the raw JSON payload string. Three headers are added:
     *   X-Webhook-Signature   — HMAC-SHA256 of the body signed with secret_key
     *   X-Webhook-Event-Type  — the event type string (e.g. 'token_revoked')
     *   X-Webhook-Timestamp   — Unix timestamp of the delivery attempt
     */
    protected function deliverEvent(array $event): bool
    {
        $body      = is_string($event['payload']) ? $event['payload'] : json_encode($event['payload']);
        // Stored encrypted since the signing keys were moved off plaintext; rows
        // written before that carry no marker and come back unchanged.
        try {
            $secret = \Pramnos\Security\Encrypter::maybeDecrypt(
                (string) $event['secret_key']
            );
        } catch (\RuntimeException $e) {
            // APP_KEY missing or rotated. Signing with the ciphertext would send a
            // delivery the receiver rejects as forged, and the operator would be
            // reading the receiver's logs to find a key problem on this side. Fail
            // the attempt here, where the reason can be said plainly.
            $this->lastError = 'Webhook signing key could not be read: ' . $e->getMessage();
            return false;
        }
        $url       = (string) $event['endpoint_url'];
        $eventType = (string) $event['event_type'];
        $timestamp = time();
        $timeout   = max(1, (int) ($event['timeout_seconds'] ?? 30));

        $signature = self::buildSignature($body, $secret);

        /*
         * An address the relying party typed into `/Webhook/register` is a URL somebody else
         * chose: `forUserSuppliedUrl()` refuses one that resolves inside this network and pins
         * the address it checked, so the name cannot be rebound between the check and the
         * POST. Redirects are never followed — a receiver that moved re-registers, it does not
         * bounce a signed body elsewhere.
         */
        $client = \Pramnos\Http\Client::post($url);

        /*
         * An administrator's endpoint is delivered to as written: it is the operator's own
         * statement about their network, and a receiver on this host or the VPN is exactly
         * what one would type. Everything else goes through the guard, with the private
         * ranges this installation allows.
         */
        if (($event['registered_by'] ?? self::REGISTERED_BY_CLIENT) !== self::REGISTERED_BY_ADMIN) {
            $client->forUserSuppliedUrl(!self::requiresHttps())
                ->allowAddresses(self::allowedPrivateRanges());
        }

        try {
            $response = $client
                ->withoutRedirects()
                ->body($body, 'application/json')
                ->headers([
                    'X-Webhook-Signature'  => $signature,
                    'X-Webhook-Event-Type' => $eventType,
                    'X-Webhook-Timestamp'  => (string) $timestamp,
                    'User-Agent'           => 'PramnosFramework-Webhook/1.0',
                ])
                ->timeout($timeout)
                ->connectTimeout(min($timeout, 5))
                // Only the first 200 bytes are kept, for the error; nothing reads more.
                ->maxResponseBytes(4096)
                ->send();
        } catch (\Pramnos\Http\ClientException $e) {
            $this->lastError = 'Delivery refused or failed: ' . $e->getMessage();
            return false;
        }

        if ($response->successful()) {
            $this->lastError = '';
            return true;
        }

        $this->lastError = 'HTTP ' . $response->status() . ': ' . substr($response->body(), 0, 200);
        return false;
    }
}
