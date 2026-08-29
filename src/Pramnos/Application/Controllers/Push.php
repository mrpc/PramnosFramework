<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Push\Subscriptions;
use Pramnos\Push\Vapid;

/**
 * The three things a browser needs to subscribe to notifications, and to stop.
 *
 * - `GET /push/key` — the VAPID public key. Public on purpose: it is the half of the pair a
 *   browser is *supposed* to hold, and `subscribe()` cannot be called without it.
 * - `POST /push/subscribe` — record this browser for the signed-in account.
 * - `POST /push/unsubscribe` — forget it.
 *
 * The last two require a session, because a subscription belongs to an account: without one there
 * is nobody to notify. That is the opposite of the mail endpoints beside it, where requiring a
 * session would break every one-click request — and the difference is worth noticing rather than
 * copying the wrong pattern.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class Push extends \Pramnos\Application\Controller
{
    public $actions = ['key', 'subscribe', 'unsubscribe'];

    /**
     * The VAPID public key, for `PushManager.subscribe()`.
     */
    public function key(): mixed
    {
        $vapid = $this->keyPair();

        if ($vapid === null) {
            return $this->json([
                'error' => 'This installation has no VAPID key pair. Run `push:vapid-generate`.',
            ], 503);
        }

        return $this->json(['publicKey' => $vapid['publicKey']]);
    }

    /**
     * Record this browser's subscription.
     */
    public function subscribe(): mixed
    {
        $userId = $this->currentUser();

        if ($userId === null) {
            return $this->json(['error' => 'Sign in first.'], 401);
        }

        $subscription = $this->body();

        if ($subscription === []) {
            return $this->json(['error' => 'Send the subscription as JSON.'], 400);
        }

        $stored = $this->store(
            $userId,
            $subscription,
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );

        return $stored
            ? $this->json(['ok' => true])
            : $this->json([
                'error' => 'That subscription is not usable — it needs an https endpoint and '
                    . 'both keys.',
            ], 400);
    }

    /**
     * Forget it.
     *
     * Answers success when there was nothing to forget. The browser has already unsubscribed by
     * the time it calls this, and telling it "no such subscription" would leave a page reporting
     * a failure for something that is in exactly the state it asked for.
     */
    public function unsubscribe(): mixed
    {
        $userId = $this->currentUser();

        if ($userId === null) {
            return $this->json(['error' => 'Sign in first.'], 401);
        }

        $endpoint = (string) ($this->body()['endpoint'] ?? '');

        if ($endpoint === '') {
            return $this->json(['error' => 'Send the endpoint.'], 400);
        }

        $this->forget($endpoint, $userId);

        return $this->json(['ok' => true]);
    }

    /**
     * The three static calls this controller makes, as seams.
     *
     * The endpoints' own decisions — who may call them, what each status code means — are worth
     * asserting without a database and a key pair on disk, and there is nothing else in them.
     *
     * @return array{publicKey: string, privateKey: string, subject: string}|null
     */
    protected function keyPair(): ?array
    {
        return Vapid::load();
    }

    /** @param array<string, mixed> $subscription */
    protected function store(int $userId, array $subscription, string $userAgent): bool
    {
        return Subscriptions::store($userId, $subscription, $userAgent);
    }

    protected function forget(string $endpoint, int $userId): void
    {
        Subscriptions::forget($endpoint, $userId);
    }

    /**
     * The signed-in account, or null.
     */
    protected function currentUser(): ?int
    {
        $user = \Pramnos\User\User::getCurrentUser();
        $id   = (int) ($user->userid ?? 0);

        return $id > 1 ? $id : null;
    }

    /**
     * The request body, decoded.
     *
     * JSON, because `PushSubscription.toJSON()` is what a page has to hand and posting it as a
     * form would mean flattening a nested object for no reason.
     *
     * @return array<string, mixed>
     */
    protected function body(): array
    {
        $raw = $this->rawBody();

        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** The request body as it arrived. Separate only because `php://input` cannot be arranged. */
    protected function rawBody(): string
    {
        return (string) file_get_contents('php://input');
    }

    /**
     * @param array<string, mixed> $data
     */
    /**
     * @param array<string, mixed> $data
     */
    protected function json(array $data, int $status = 200): mixed
    {
        \Pramnos\Framework\Factory::getDocument('json');

        /*
         * The status goes **into the Response**, not into `http_response_code()` beside it.
         *
         * A returned Response is dispatched by the application, which sets the code from the
         * object — so a status set here and not there is overwritten by the object's default
         * 200. Every refusal would answer 200 with an error in the body, and a page checking
         * `response.ok` would read «sign in first» as success.
         */
        return \Pramnos\Http\Response::json($data, $status);
    }
}
