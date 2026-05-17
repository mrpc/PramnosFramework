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
 * @package PramnosFramework
 */
class WebhookService
{
    private const TABLE_ENDPOINTS = 'applications.oauth2_webhook_endpoints';
    private const TABLE_EVENTS    = 'applications.oauth2_webhook_events';

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
     * Returns the number of event rows inserted (one per matching endpoint).
     */
    public function queueEvent(
        string $eventType,
        int    $userId,
        array  $payload,
        ?string $deviceCode = null,
        ?int    $tokenId    = null
    ): int {
        $endpoints = $this->database->queryBuilder()
            ->table(self::TABLE_ENDPOINTS)
            ->select(['webhook_id', 'retry_count'])
            ->where('webhook_type', $eventType)
            ->where('is_active', 1)
            ->get();

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
                ->select(['endpoint_url', 'secret_key', 'timeout_seconds'])
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
        $secret    = (string) $event['secret_key'];
        $url       = (string) $event['endpoint_url'];
        $eventType = (string) $event['event_type'];
        $timestamp = time();
        $timeout   = max(1, (int) ($event['timeout_seconds'] ?? 30));

        $signature = self::buildSignature($body, $secret);

        $ch = curl_init($url);
        if ($ch === false) {
            $this->lastError = 'curl_init() failed';
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 5),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Webhook-Signature: '  . $signature,
                'X-Webhook-Event-Type: ' . $eventType,
                'X-Webhook-Timestamp: '  . $timestamp,
                'User-Agent: PramnosFramework-Webhook/1.0',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            $this->lastError = 'cURL error: ' . $curlErr;
            return false;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $this->lastError = '';
            return true;
        }

        $preview = is_string($response) ? substr($response, 0, 200) : '';
        $this->lastError = "HTTP {$httpCode}: {$preview}";
        return false;
    }
}
