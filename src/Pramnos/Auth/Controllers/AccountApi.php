<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Routing\Router;

/**
 * The signed-in person's account under the application's API, with the API's credentials — what
 * the account's own pages offer, for a desktop or mobile application or a SPA: sessions, the
 * password, the profile and its picture, the data export, deleting the account (which an app
 * store requires inside the app), the applications given access, and the trusted devices.
 *
 * ```php
 * \Pramnos\Auth\Controllers\AccountApi::routes($r);           // under /me
 * ```
 *
 * The same rules as the pages, through the same code: the password's policy and history, the
 * profile's address check and its notices, the export's sections, the erasure's order. Answers in
 * the API's shape: `['status' => …, 'data' => …]` or `['status' => …, 'error' => …]`.
 */
class AccountApi extends Account
{
    /** Mount the routes, under `$path`, each described for the API's document. */
    public static function routes(Router $r, string $path = '/me'): void
    {
        $path = '/' . trim($path, '/');
        $r->get($path . '/sessions', static fn () => (new static())->sessionList())->describeFrom([static::class, 'sessionList']);
        $r->delete($path . '/sessions/{sid}', static fn ($sid) => (new static())->signOutSession((string) $sid))->describeFrom([static::class, 'signOutSession']);
        $r->post($path . '/password', static fn () => (new static())->password())->describeFrom([static::class, 'password']);
        $r->put($path . '/profile', static fn () => (new static())->saveProfile())->describeFrom([static::class, 'saveProfile']);
        $r->post($path . '/photo', static fn () => (new static())->photo())->describeFrom([static::class, 'photo']);
        $r->delete($path . '/photo', static fn () => (new static())->removePhoto())->describeFrom([static::class, 'removePhoto']);
        $r->get($path . '/export', static fn () => (new static())->export())->describeFrom([static::class, 'export']);
        $r->post($path . '/delete', static fn () => (new static())->eraseAccount())->describeFrom([static::class, 'eraseAccount']);
        $r->get($path . '/applications', static fn () => (new static())->applicationList())->describeFrom([static::class, 'applicationList']);
        $r->delete($path . '/applications/{client_id}', static fn ($client_id) => (new static())->revokeApplicationAccess((string) $client_id))
            ->describeFrom([static::class, 'revokeApplicationAccess']);
        $r->get($path . '/devices', static fn () => (new static())->deviceList())->describeFrom([static::class, 'deviceList']);
        $r->delete($path . '/devices/{device_id}', static fn ($device_id) => (new static())->forgetDevice((int) $device_id))->describeFrom([static::class, 'forgetDevice']);
        $r->delete($path . '/devices', static fn () => (new static())->forgetDevice(0))->describeFrom([static::class, 'forgetDevice']);
    }

    /**
     * The sessions one is signed in with: each `sid`, address, browser, when last seen and where.
     *
     * @return array<string, mixed>
     */
    public function sessionList(): array
    {
        return $this->asMe(fn (object $me): array => ['sessions' => $this->getActiveSessions((int) $me->userid)]);
    }

    /**
     * Sign one of one's sessions out, by its `sid`.
     *
     * @return array<string, mixed>
     */
    public function signOutSession(string $sid): array
    {
        return $this->asMe(function (object $me) use ($sid): array {
            $this->database()->queryBuilder()->table('#PREFIX#sessions')->where('userid', (int) $me->userid)->where('sid', $sid)->update(['logout' => 1]);
            \Pramnos\Auth\ActivityLog::record((int) $me->userid, 'session_revoked');

            return ['ended' => $sid];
        });
    }

    /**
     * Change one's password: `current_password`, `new_password`, `confirm_password`.
     *
     * The policy and the passwords used before apply, as on the page. Where the application asks
     * for it (`auth.security`), every session is signed out — a token's call has no session of
     * its own to spare.
     *
     * @return array<string, mixed>
     */
    public function password(): array
    {
        return $this->asMe(function (object $me): array {
            $body    = $this->body();
            $changed = $this->applyPasswordChange($this->account($me), (string) ($body['current_password'] ?? ''),
                (string) ($body['new_password'] ?? ''), (string) ($body['confirm_password'] ?? ''), '');
            if ($changed['error'] !== null) {
                throw new \InvalidArgumentException($changed['error']);
            }

            return ['changed' => true, 'sessions_ended' => $changed['ended']];
        });
    }

    /**
     * Change one's name, address and phone: `firstname`, `lastname`, `email`, `phone`.
     *
     * A changed address is told to the old one, and the fields changed to the applications that
     * listen. Answers the profile as `/me` shows it.
     *
     * @return array<string, mixed>
     */
    public function saveProfile(): array
    {
        return $this->asMe(function (object $me): array {
            $account = $this->account($me);
            $error   = $this->applyProfile($account, $this->body());
            if ($error !== null) {
                throw new \InvalidArgumentException($error);
            }

            return ['profile' => ['userid' => (int) $account->userid, 'firstname' => (string) $account->firstname, 'lastname' => (string) $account->lastname,
                'email' => (string) $account->email, 'phone' => (string) ($account->phone ?? '')]];
        });
    }

    /**
     * Set one's profile picture: the upload `photo` (multipart), or `gravatar` = 1 for the
     * Gravatar of one's address.
     *
     * @return array<string, mixed>
     */
    public function photo(): array
    {
        return $this->asMe(function (object $me): array {
            $account = $this->account($me);
            $error   = !empty($_POST['gravatar']) || !empty($this->body()['gravatar'])
                ? \Pramnos\User\ProfilePhoto::fromGravatar($account, $this->gravatarFetcher())
                : \Pramnos\User\ProfilePhoto::set($account, (array) ($_FILES['photo'] ?? []));
            if ($error !== null) {
                throw new \InvalidArgumentException($error);
            }
            $photo = (int) ((new \Pramnos\User\User((int) $account->userid))->photo ?? 0);

            return ['photo' => $photo > 0 ? \Pramnos\User\ProfilePhoto::url($photo) : null];
        });
    }

    /**
     * Take one's profile picture away.
     *
     * @return array<string, mixed>
     */
    public function removePhoto(): array
    {
        return $this->asMe(function (object $me): array {
            \Pramnos\User\ProfilePhoto::remove($this->account($me));

            return ['photo' => null];
        });
    }

    /**
     * Everything the application holds about one, as the page's export gives it (GDPR Article 20).
     *
     * @return array<string, mixed>
     */
    public function export(): array
    {
        return $this->asMe(function (object $me): array {
            $data = $this->buildExportData((int) $me->userid);
            \Pramnos\Auth\ActivityLog::record((int) $me->userid, 'data_export_requested');
            \Pramnos\Auth\WebhookService::userEvent('gdpr_request', (int) $me->userid, ['request_type' => 'export', 'requested_by' => (int) $me->userid]);

            return $data;
        });
    }

    /**
     * Delete one's account for good: `password`, and `confirmation` = `DELETE`.
     *
     * As on the page: the scheduled tasks handed over first where `tasks` chooses
     * (`{taskid: userid}`), then everything erased in order. Required inside an app by the app
     * stores.
     *
     * @return array<string, mixed>
     */
    public function eraseAccount(): array
    {
        return $this->asMe(function (object $me): array {
            $body = $this->body();
            if (!$this->verifyUserPassword((int) $me->userid, (string) ($body['password'] ?? ''))) {
                throw new \InvalidArgumentException(t('The password you entered is incorrect.'));
            }
            if ((string) ($body['confirmation'] ?? '') !== 'DELETE') {
                throw new \InvalidArgumentException(t('You must type DELETE in the confirmation field.'));
            }
            \Pramnos\Logs\Logger::log('account_deleted: userid=' . (int) $me->userid . ' ip=' . \Pramnos\Http\Request::clientIp('unknown') . ' via=api', 'auth');
            $this->handOverTasks((int) $me->userid, is_array($body['tasks'] ?? null) ? $body['tasks'] : []);
            $this->eraseUserData((int) $me->userid);

            return ['deleted' => true];
        });
    }

    /**
     * The applications one has given access to one's account.
     *
     * @return array<string, mixed>
     */
    public function applicationList(): array
    {
        return $this->asMe(fn (object $me): array => ['applications' => $this->getAuthorizedApplications((int) $me->userid)]);
    }

    /**
     * Take an application's access away, by its `client_id`: its tokens with it.
     *
     * @return array<string, mixed>
     */
    public function revokeApplicationAccess(string $clientId): array
    {
        return $this->asMe(function (object $me) use ($clientId): array {
            $service = new \Pramnos\Auth\AuthorizedApplications();
            $app     = $service->byClientId($clientId) ?? throw new \DomainException(t('Application not found'));
            $service->revoke((int) $me->userid, $app['appid']);

            return ['revoked' => $clientId];
        });
    }

    /**
     * The devices that skip the second step of signing in, each with its name and whether it is
     * this one.
     *
     * @return array<string, mixed>
     */
    public function deviceList(): array
    {
        return $this->asMe(fn (object $me): array => ['devices' => $this->trustedDevices()->forUser((int) $me->userid)]);
    }

    /**
     * Stop trusting a device (`device_id`) — or, with none, every one: each is asked for the
     * second step again.
     *
     * @return array<string, mixed>
     */
    public function forgetDevice(int $deviceId): array
    {
        return $this->asMe(function (object $me) use ($deviceId): array {
            $done = $deviceId > 0 ? $this->trustedDevices()->revoke((int) $me->userid, $deviceId) : $this->trustedDevices()->revokeAll((int) $me->userid) > 0;
            if ($deviceId > 0 && !$done) {
                throw new \DomainException(t('That device is not trusted.'));
            }

            return ['forgotten' => $deviceId > 0 ? $deviceId : 'all'];
        });
    }

    /**
     * An action as the person the API's credentials name: a refusal for nobody, 404 for what is
     * not theirs, 422 for what does not make sense.
     *
     * @return array<string, mixed>
     */
    protected function asMe(\Closure $action): array
    {
        $me = \Pramnos\User\User::getCurrentUser();
        if (!is_object($me) || (int) ($me->userid ?? 0) < 2) {
            return ['status' => 401, 'error' => 'not_authenticated'];
        }
        try {
            return ['status' => 200, 'data' => $action($me)];
        } catch (\DomainException $e) {
            return ['status' => 404, 'error' => $e->getMessage()];
        } catch (\InvalidArgumentException $e) {
            return ['status' => 422, 'error' => $e->getMessage()];
        }
    }

    /** The full account: an API request's identity may be a plain object with no save(). */
    protected function account(object $me): \Pramnos\User\User
    {
        return $me instanceof \Pramnos\User\User ? $me : new \Pramnos\User\User((int) $me->userid);
    }

    /** The request's body, JSON or a form. @return array<string, mixed> */
    protected function body(): array
    {
        return (array) (new \Pramnos\Http\Request())->body();
    }

    /** The connection. */
    protected function database(): \Pramnos\Database\Database
    {
        return \Pramnos\Framework\Factory::getDatabase();
    }
}
