<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Auth\WebhookService;
use Pramnos\Http\Response;

/**
 * Webhook endpoint registration for relying parties.
 *
 * The authorization server queues an event whenever something an application
 * needs to hear about happens — a user deauthorizes it, a token is revoked, a
 * GDPR erasure completes. `WebhookService` signs and delivers those events, with
 * retries and exponential back-off.
 *
 * What was missing was the way in. The tables existed, the delivery worked, and an
 * application had no way to say where to send anything: the only route to a row in
 * `oauth2_webhook_endpoints` was an INSERT by hand.
 *
 * ## Authentication
 *
 * Every action authenticates with **client credentials**, the same pair used at
 * the token endpoint — as a Basic header or in the body. An application can
 * therefore only ever see and change its own endpoints; `appid` is taken from the
 * authenticated client and never from the request, so there is no parameter to
 * tamper with.
 *
 * ```
 * POST   /Webhook/register    endpoint_url, webhook_type  → { webhook_id, secret }
 * GET    /Webhook/list                                    → this client's endpoints
 * GET    /Webhook/stats                                   → delivery counts
 * POST   /Webhook/test        webhook_id                  → queue a ping
 * POST   /Webhook/delete      webhook_id                  → remove one
 * ```
 *
 * The secret is returned **once**, by `register`. It is what the receiver verifies
 * `X-Webhook-Signature` against; the server keeps it to sign with and never
 * re-displays it, because an endpoint that hands out its own signing secret to
 * anyone who can call it is not signing anything.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class Webhook extends Controller
{
    use ClientCredentialsAuthTrait;

    /** Endpoint registrations. */
    protected const TABLE_ENDPOINTS = 'applications.oauth2_webhook_endpoints';

    /** Delivery queue and audit log. */
    protected const TABLE_EVENTS = 'applications.oauth2_webhook_events';

    /**
     * The event types an endpoint may subscribe to.
     *
     * Repeated from the table's own CHECK constraint on purpose: a value the
     * database refuses should be refused here, with a message naming the
     * alternatives, rather than reaching the driver and coming back as a
     * constraint violation nobody can act on.
     */
    protected const EVENT_TYPES = [
        'user_deauthorized',
        'token_revoked',
        'gdpr_request',
        'user_profile_changed',
        'device_deauthorized',
        'account_deleted',
        'scope_changed',
        'permissions_changed',
    ];

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        // Public actions: each authenticates its caller with client credentials
        // rather than with a session, because the caller is a server.
        $this->addaction(['register', 'list', 'stats', 'test', 'delete']);
        parent::__construct($application);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    /**
     * Register an endpoint — `POST /Webhook/register`.
     *
     * Re-registering the same event type replaces the URL and issues a new
     * secret, because the table holds one endpoint per (application, type) and
     * because "register again" is what somebody does when they have lost the
     * secret.
     */
    public function register(): mixed
    {
        $appId = $this->requireClient();
        if (!is_int($appId)) {
            return $appId;
        }

        if (!$this->isPost()) {
            return Response::json(['error' => 'method_not_allowed'], 405);
        }

        $url  = trim((string) ($_POST['endpoint_url'] ?? ''));
        $type = trim((string) ($_POST['webhook_type'] ?? ''));

        $problem = $this->validateRegistration($url, $type);
        if ($problem !== null) {
            return Response::json($problem, 400);
        }

        $secret = $this->generateSecret();

        try {
            $this->storeEndpoint($appId, $url, $type, $secret);
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log('Webhook registration failed: ' . $exception->getMessage());

            return Response::json(['error' => 'server_error'], 500);
        }

        return Response::json([
            'webhook_type' => $type,
            'endpoint_url' => $url,
            // Shown once. Store it: it is what you verify the signature against,
            // and asking again means registering again.
            'secret'       => $secret,
            'signature'    => 'X-Webhook-Signature: sha256=HMAC-SHA256(secret, body)',
        ], 201);
    }

    /**
     * This client's endpoints — `GET /Webhook/list`.
     *
     * Without the secrets. They are write-only by design.
     */
    public function list(): mixed
    {
        $appId = $this->requireClient();
        if (!is_int($appId)) {
            return $appId;
        }

        $rows = $this->database()->queryBuilder()
            ->table(self::TABLE_ENDPOINTS)
            ->select(['webhook_id', 'endpoint_url', 'webhook_type', 'is_active', 'created_at'])
            ->where('appid', $appId)
            ->orderBy('webhook_type')
            ->get();

        $endpoints = [];
        if ($rows) {
            while ($rows->fetch()) {
                $endpoints[] = (array) $rows->fields;
            }
        }

        return Response::json([
            'endpoints'        => $endpoints,
            'supported_types'  => self::EVENT_TYPES,
        ]);
    }

    /**
     * Delivery counts — `GET /Webhook/stats`.
     *
     * `pending` is the number worth watching. A figure that only grows means the
     * delivery schedule is not running, which is the failure this whole feature
     * had for a long time and which nothing else surfaces.
     */
    public function stats(): mixed
    {
        $appId = $this->requireClient();
        if (!is_int($appId)) {
            return $appId;
        }

        $counts = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0];

        $rows = $this->database()->queryBuilder()
            ->table(self::TABLE_EVENTS . ' e')
            ->join(self::TABLE_ENDPOINTS . ' w', 'e.webhook_id', '=', 'w.webhook_id')
            ->select(['e.status', 'COUNT(*) AS total'])
            ->where('w.appid', $appId)
            ->groupBy(['e.status'])
            ->get();

        if ($rows) {
            while ($rows->fetch()) {
                $status = (string) ($rows->fields['status'] ?? '');
                if (array_key_exists($status, $counts)) {
                    $counts[$status] = (int) $rows->fields['total'];
                }
            }
        }

        return Response::json(['events' => $counts]);
    }

    /**
     * Queue a test event — `POST /Webhook/test`.
     *
     * Queued rather than delivered inline, so it travels the same path as a real
     * event: the same signing, the same retries, the same schedule. A test that
     * took a shortcut would prove the shortcut works.
     */
    public function test(): mixed
    {
        $appId = $this->requireClient();
        if (!is_int($appId)) {
            return $appId;
        }

        if (!$this->isPost()) {
            return Response::json(['error' => 'method_not_allowed'], 405);
        }

        $webhookId = (int) ($_POST['webhook_id'] ?? 0);
        $endpoint  = $this->findOwnedEndpoint($appId, $webhookId);

        if ($endpoint === null) {
            return Response::json(['error' => 'not_found'], 404);
        }

        /*
         * No user: this event is about the endpoint, not about a person. NULL is what the column
         * holds for that — 0 would violate its foreign key.
         *
         * Scoped to the endpoint that was named. Without the last argument the queue fans the
         * event out to every endpoint subscribed to that type, so testing one's own webhook sent a
         * ping to every other application subscribed to the same event.
         */
        $this->service()->queueEvent(
            (string) $endpoint['webhook_type'],
            null,
            ['test' => true, 'queued_at' => date('c')],
            null,
            null,
            (int) $endpoint['webhook_id']
        );

        return Response::json([
            'queued'  => true,
            'note'    => 'Delivered by the auth:webhook-deliver schedule, not immediately.',
        ], 202);
    }

    /**
     * Remove an endpoint — `POST /Webhook/delete`.
     *
     * Events already queued for it are left alone; the delivery pipeline cancels
     * an event whose endpoint has gone, which keeps the audit trail honest about
     * what was attempted.
     */
    public function delete(): mixed
    {
        $appId = $this->requireClient();
        if (!is_int($appId)) {
            return $appId;
        }

        if (!$this->isPost()) {
            return Response::json(['error' => 'method_not_allowed'], 405);
        }

        $webhookId = (int) ($_POST['webhook_id'] ?? 0);

        if ($this->findOwnedEndpoint($appId, $webhookId) === null) {
            return Response::json(['error' => 'not_found'], 404);
        }

        $this->database()->queryBuilder()
            ->table(self::TABLE_ENDPOINTS)
            ->where('webhook_id', $webhookId)
            // Scoped to the owner as well as to the id: an id is guessable and
            // ownership is the thing that must hold.
            ->where('appid', $appId)
            ->delete();

        return Response::json(['deleted' => true]);
    }

    // ── Seams ─────────────────────────────────────────────────────────────────

    /**
     * The authenticated client's application id, or the response to send instead.
     *
     * @return int|Response
     */
    protected function requireClient(): mixed
    {
        $credentials = $this->extractClientCredentials();

        if ($credentials === null) {
            return Response::json(
                ['error' => 'invalid_client', 'error_description' => 'Client credentials required'],
                401
            );
        }

        $appId = $this->authenticateClient(
            $credentials['client_id'],
            $credentials['client_secret']
        );

        if ($appId === null) {
            return Response::json(['error' => 'invalid_client'], 401);
        }

        return $appId;
    }

    /** Whether this request may change something. */
    protected function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    /**
     * Check a registration, returning an error body or null.
     *
     * @return array<string, string>|null
     */
    protected function validateRegistration(string $url, string $type): ?array
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return ['error' => 'invalid_request', 'error_description' => 'endpoint_url must be a valid URL'];
        }

        // An event carries information about a person and is signed with a shared
        // secret; sending either over plaintext gives both away.
        if (!str_starts_with(strtolower($url), 'https://')) {
            return ['error' => 'invalid_request', 'error_description' => 'endpoint_url must use https'];
        }

        if (!in_array($type, self::EVENT_TYPES, true)) {
            return [
                'error'             => 'invalid_request',
                'error_description' => 'webhook_type must be one of: ' . implode(', ', self::EVENT_TYPES),
            ];
        }

        return null;
    }

    /** A signing secret. 32 bytes, hex, from the CSPRNG. */
    protected function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Insert or replace this application's endpoint for one event type. */
    protected function storeEndpoint(int $appId, string $url, string $type, string $secret): void
    {
        // Encrypted at rest. The signing key has to be recoverable — it is the HMAC
        // key {@see \Pramnos\Auth\WebhookService::deliverEvent()} signs each
        // delivery with — so hashing is not an option here the way it is for a
        // password. Anyone who could read this column could forge a webhook the
        // receiver would accept as ours.
        //
        // Left as-is when APP_KEY is unset: an installation without a key must still
        // be able to register an endpoint, and the row converts itself on the next
        // write. WebhookService reads through maybeDecrypt(), so both forms work.
        if (\Pramnos\Security\Encrypter::isAvailable()) {
            $secret = \Pramnos\Security\Encrypter::encrypt($secret);
        }

        $builder = $this->database()->queryBuilder();

        $existing = $builder->table(self::TABLE_ENDPOINTS)
            ->select(['webhook_id'])
            ->where('appid', $appId)
            ->where('webhook_type', $type)
            ->first();

        if ($existing && $existing->numRows > 0) {
            $this->database()->queryBuilder()
                ->table(self::TABLE_ENDPOINTS)
                ->where('webhook_id', (int) $existing->fields['webhook_id'])
                ->update([
                    'endpoint_url' => $url,
                    'secret_key'   => $secret,
                    'is_active'    => true,
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]);

            return;
        }

        $this->database()->queryBuilder()
            ->table(self::TABLE_ENDPOINTS)
            ->insert([
                'appid'        => $appId,
                'endpoint_url' => $url,
                'webhook_type' => $type,
                'secret_key'   => $secret,
                'is_active'    => true,
            ]);
    }

    /**
     * One endpoint, only if it belongs to this application.
     *
     * @return array<string, mixed>|null
     */
    protected function findOwnedEndpoint(int $appId, int $webhookId): ?array
    {
        if ($webhookId <= 0) {
            return null;
        }

        $row = $this->database()->queryBuilder()
            ->table(self::TABLE_ENDPOINTS)
            ->select(['webhook_id', 'webhook_type', 'endpoint_url'])
            ->where('webhook_id', $webhookId)
            ->where('appid', $appId)
            ->first();

        if (!$row || $row->numRows === 0) {
            return null;
        }

        return (array) $row->fields;
    }

    /** The delivery service (seam so tests need no database). */
    protected function service(): WebhookService
    {
        return new WebhookService($this->database());
    }

    /** The database (seam so tests need no connection). */
    protected function database(): mixed
    {
        return \Pramnos\Framework\Factory::getDatabase();
    }
}
