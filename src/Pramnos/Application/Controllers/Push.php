<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Auth\PushApprovals;
use Pramnos\Auth\TrustedDevices;
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
    public $actions = ['key', 'subscribe', 'unsubscribe', 'ack', 'respond', 'test', 'testack', 'teststatus'];

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

        /*
         * A trusted device's browser says which device it is, by the trust cookie it carries.
         * That link is what makes it one of the phones a sign-in elsewhere can be approved
         * from — and only a browser that is trusted can make it.
         */
        if ($stored) {
            $this->linkTrustedDevice($userId, (string) ($subscription['endpoint'] ?? ''));
        }

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
     * The phone's receipt for a sign-in approval, sent by the service worker as the push
     * arrives. The token in the address is the credential: the worker may have no page open.
     */
    public function ack(): mixed
    {
        return $this->approvals()->acknowledge($this->token())
            ? $this->json(['ok' => true])
            : $this->json(['error' => 'No such approval, or it has expired.'], 404);
    }

    /**
     * "Yes, it's me" or "No", straight from the notification's buttons.
     *
     * Yes appears only on an ask that needs no number; sent for one that does, it changes
     * nothing. The answer counts only from a browser signed in as the account and trusted by
     * it — see PushApprovals::decide().
     */
    public function respond(): mixed
    {
        // A POST, as the worker sends it: an answer must not be something a link can give.
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return $this->json(['error' => 'POST only.'], 405);
        }

        $userId = $this->currentUser();

        if ($userId === null) {
            return $this->json(['error' => 'Sign in first.'], 401);
        }

        $decision = (string) ($_GET['decision'] ?? '');
        $result   = $this->approvals()->decide($this->token(), $userId, $decision);

        return in_array($result, [PushApprovals::APPROVED, PushApprovals::DENIED], true)
            ? $this->json(['ok' => true, 'decision' => $result])
            : $this->json(['error' => 'That approval cannot be answered from here.', 'reason' => $result], 403);
    }

    /**
     * Send a test notification to the signed-in account's browsers — or to the one posting.
     *
     * `{"endpoint": "…"}` in the body, as `PushSubscription.toJSON()` gives it, limits the test
     * to that browser: what a "This device" screen sends. Without it every browser on the
     * account gets one. Each answer carries a token for {@see teststatus()}, which says when the
     * device confirmed it arrived.
     *
     * POST only: a link must not be able to send notifications.
     */
    public function test(): mixed
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return $this->json(['error' => 'POST only.'], 405);
        }

        $userId = $this->currentUser();
        if ($userId === null) {
            return $this->json(['error' => 'Sign in first.'], 401);
        }

        $endpoint = (string) ($this->body()['endpoint'] ?? '');
        $tests    = $this->testPush()->send($userId, $endpoint === '' ? null : hash('sha256', $endpoint));

        if ($tests === []) {
            return $this->json([
                'error' => $endpoint === ''
                    ? 'No browser on this account is subscribed to notifications.'
                    : 'This browser is not subscribed to notifications for this account.',
            ], 404);
        }

        return $this->json(['tests' => array_map(static fn (array $t): array => [
            'token'   => $t['token'],
            'browser' => $t['user_agent'],
            'sent'    => $t['outcome']['kind'] === 'delivered',
            'outcome' => $t['outcome']['label'],
            'explanation' => $t['outcome']['explanation'],
        ], $tests)]);
    }

    /**
     * A device's receipt for a test, posted by the service worker the moment it arrives.
     * The token in the address is the credential: the worker may have no page or session.
     */
    public function testack(): mixed
    {
        return $this->testPush()->acknowledge($this->token())
            ? $this->json(['ok' => true])
            : $this->json(['error' => 'No such test, or it has expired.'], 404);
    }

    /**
     * Whether a test has arrived, for the page that sent it — only the account's own tests.
     */
    public function teststatus(): mixed
    {
        $userId = $this->currentUser();
        if ($userId === null) {
            return $this->json(['error' => 'Sign in first.'], 401);
        }

        $status = $this->testPush()->status($this->token());
        if ($status === null || $status['userid'] !== $userId) {
            return $this->json(['error' => 'No such test.'], 404);
        }

        return $this->json([
            'received'    => $status['received_at'] !== null,
            'received_at' => $status['received_at'],
            'expired'     => $status['expired'],
        ]);
    }

    /** The test sender, as a seam. */
    protected function testPush(): \Pramnos\Push\TestPush
    {
        return new \Pramnos\Push\TestPush();
    }

    protected function token(): string
    {
        return (string) ($_GET['token'] ?? '');
    }

    protected function approvals(): PushApprovals
    {
        return new PushApprovals();
    }

    protected function linkTrustedDevice(int $userId, string $endpoint): void
    {
        $device = (new TrustedDevices())->current($userId);

        if ($device !== null) {
            Subscriptions::linkTrustedDevice($userId, $endpoint, (int) $device['device_id']);
        }
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
