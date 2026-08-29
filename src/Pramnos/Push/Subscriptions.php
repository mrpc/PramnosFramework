<?php

declare(strict_types=1);

namespace Pramnos\Push;

/**
 * The browsers that agreed to receive notifications, and what to do when one goes away.
 *
 * A subscription is a **credential for a delivery address**: whoever holds the endpoint can send
 * a notification to that browser. So it is treated like one — never logged, never returned by an
 * API, and removed the moment the push service says it is gone.
 *
 * The two answers a push service gives that this class exists to tell apart:
 *
 * - **404 / 410** — the subscription is dead. The browser was uninstalled, the site data
 *   cleared, permission revoked. Delete it. Retrying is not a recovery, and a table of dead
 *   subscriptions makes every future send slower for ever, because each one is still a full
 *   HTTPS round trip.
 * - **429 / 5xx** — the service is busy or briefly broken. Keep it and try again later.
 *
 * Confusing those two is how a live user stops receiving notifications, which is the failure
 * nobody reports because it looks like nothing happening.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class Subscriptions
{
    /** After this many consecutive failures a subscription is presumed dead. */
    public const MAX_FAILURES = 10;

    /**
     * Record a browser's subscription, or refresh the one it already had.
     *
     * @param  int                  $userId
     * @param  array<string, mixed> $subscription The `PushSubscription.toJSON()` from the browser
     * @param  string               $userAgent    So a person can recognise the device later
     * @return bool
     */
    public static function store(int $userId, array $subscription, string $userAgent = ''): bool
    {
        $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
        $keys     = (array) ($subscription['keys'] ?? []);
        $p256dh   = trim((string) ($keys['p256dh'] ?? ''));
        $auth     = trim((string) ($keys['auth'] ?? ''));

        if ($userId < 1 || !preg_match('~^https://~i', $endpoint) || $p256dh === '' || $auth === '') {
            /*
             * Refused rather than stored half-formed. A subscription without its keys cannot be
             * encrypted to, so it would sit in the table failing for ever — and an endpoint that
             * is not HTTPS is not a push service.
             */
            return false;
        }

        $row = [
            'userid'           => $userId,
            'endpoint'         => $endpoint,
            'endpoint_hash'    => hash('sha256', $endpoint),
            'p256dh'           => $p256dh,
            'auth_secret'      => $auth,
            'content_encoding' => (string) ($subscription['contentEncoding'] ?? 'aes128gcm'),
            'user_agent'       => substr($userAgent, 0, 255),
            'created_at'       => time(),
            'failure_count'    => 0,
        ];

        try {
            $db = \Pramnos\Framework\Factory::getDatabase();

            $existing = $db->queryBuilder()
                ->table('#PREFIX#pushsubscriptions')
                ->where('userid', $userId)
                ->where('endpoint_hash', $row['endpoint_hash'])
                ->first();

            if ($existing && ($existing->numRows ?? 0) > 0) {
                /*
                 * The same browser again. Its keys are rotated by the browser without warning —
                 * a `pushsubscriptionchange` the page may never see — so the stored ones are
                 * replaced rather than left, and the failure count is cleared: whatever was
                 * wrong before, this browser has just told us it is listening.
                 */
                unset($row['created_at']);

                $db->queryBuilder()
                    ->table('#PREFIX#pushsubscriptions')
                    ->where('id', (int) $existing->fields['id'])
                    ->update($row);

                return true;
            }

            $db->queryBuilder()->table('#PREFIX#pushsubscriptions')->insert($row);
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'Could not store a push subscription: ' . $exception->getMessage(),
                'push'
            );

            return false;
        }

        return true;
    }

    /**
     * Every subscription for one account.
     *
     * @return list<array<string, mixed>>
     */
    public static function forUser(int $userId): array
    {
        if ($userId < 1) {
            return [];
        }

        try {
            $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('#PREFIX#pushsubscriptions')
                ->where('userid', $userId)
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $rows = [];

        while ($result && $result->fetch()) {
            $rows[] = (array) $result->fields;
        }

        return $rows;
    }

    /**
     * Forget one subscription, by its endpoint.
     *
     * The endpoint rather than an id, because that is what both callers have: the browser
     * unsubscribing knows its own endpoint, and the push service's rejection names it.
     */
    public static function forget(string $endpoint, ?int $userId = null): bool
    {
        if (trim($endpoint) === '') {
            return false;
        }

        try {
            $query = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('#PREFIX#pushsubscriptions')
                ->where('endpoint_hash', hash('sha256', trim($endpoint)));

            if ($userId !== null) {
                $query->where('userid', $userId);
            }

            $query->delete();
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'Could not remove a push subscription: ' . $exception->getMessage(),
                'push'
            );

            return false;
        }

        return true;
    }

    /**
     * Record what a push service said about one subscription.
     *
     * The decision this class exists for. A `404` or `410` is the browser telling us, through
     * its provider, that this subscription no longer exists — the only correct response is to
     * delete it. Anything else is temporary until it has been temporary too many times.
     *
     * @param  int    $status   The HTTP status the push service answered with
     * @return bool   Whether the subscription survived
     */
    public static function recordResult(string $endpoint, int $status): bool
    {
        if ($status >= 200 && $status < 300) {
            self::touch($endpoint, true);

            return true;
        }

        if ($status === 404 || $status === 410) {
            // Gone means gone. Retrying a 410 is how a table fills with dead rows, and every
            // dead row costs a full HTTPS round trip on every future send.
            self::forget($endpoint);

            return false;
        }

        return self::touch($endpoint, false);
    }

    /**
     * Increment or clear the failure count.
     *
     * @return bool Whether the subscription survived
     */
    private static function touch(string $endpoint, bool $success): bool
    {
        try {
            $db    = \Pramnos\Framework\Factory::getDatabase();
            $hash  = hash('sha256', trim($endpoint));

            if ($success) {
                $db->queryBuilder()
                    ->table('#PREFIX#pushsubscriptions')
                    ->where('endpoint_hash', $hash)
                    ->update(['last_success_at' => time(), 'failure_count' => 0]);

                return true;
            }

            $db->queryBuilder()
                ->table('#PREFIX#pushsubscriptions')
                ->where('endpoint_hash', $hash)
                ->update([
                    'failure_count' => new \Pramnos\Database\Expression('failure_count + 1'),
                ]);

            $result = $db->queryBuilder()
                ->table('#PREFIX#pushsubscriptions')
                ->where('endpoint_hash', $hash)
                ->first();

            $failures = (int) ($result->fields['failure_count'] ?? 0);

            if ($failures >= self::MAX_FAILURES) {
                /*
                 * Ten consecutive failures that were never a 410. The service has never said
                 * this subscription is gone, and it has never accepted anything for it either —
                 * so it is presumed dead rather than retried for ever.
                 */
                self::forget($endpoint);

                return false;
            }
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'Could not update a push subscription: ' . $exception->getMessage(),
                'push'
            );
        }

        return true;
    }
}
