<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Broadcasting\Apps\AppRegistryInterface;
use Pramnos\Broadcasting\Apps\AppSource;
use Pramnos\Broadcasting\Auth\ChannelRegistry;
use Pramnos\Broadcasting\Auth\PusherAuthSigner;
use Pramnos\Broadcasting\Encryption\ChannelEncrypter;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\User\User;

/**
 * The channel-authorization endpoint every Pusher-protocol client needs.
 *
 *     POST /broadcasting/auth      body: socket_id, channel_name
 *     → 200 {"auth": "key:hmac"}                        (private channel)
 *     → 200 {"auth": "key:hmac", "channel_data": "..."} (presence channel)
 *     → 403 {"error": "forbidden"}                      (rule said no)
 *
 * `pusher-js`, `pramnos-echo.js` and Laravel Echo all call this path by default,
 * so a client needs no configuration beyond the app key.
 *
 * Applications reach it by scaffolding a thin wrapper in their own `Controllers`
 * namespace — the same opt-in pattern as the framework's auth controllers, so
 * nothing becomes routable that the application did not ask for.
 *
 * ## This is where the authorization actually happens
 *
 * Everything expensive belongs here and not in the WebSocket daemon: this is a
 * normal request with a session and a database, while the daemon is a
 * single-threaded select loop where one permission query blocks every other
 * connection. The endpoint decides and signs; the daemon verifies an HMAC. That
 * is the whole reason the Pusher protocol has an auth endpoint at all.
 *
 * ## Failure modes, and why each one is what it is
 *
 * - **No rule matches the channel** → 403. An unroutable channel name is a missing
 *   rule, and defaulting to open would turn every typo into a hole.
 * - **A public channel** → 403. The protocol never asks for a token for one, so a
 *   request for it is a confused client at best; issuing a token would imply a
 *   guard that does not exist.
 * - **Unknown app key** → 403, identical to a rejected rule. Distinguishing them
 *   would tell a prober which keys are real.
 * - **App has no secret** → 500, not 403. That is the operator's misconfiguration
 *   rather than the user's lack of permission, and reporting it as "forbidden"
 *   sends whoever debugs it to the wrong file.
 */
class Broadcasting extends Controller
{
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        parent::__construct($application);
        $this->addaction('postAuth');
    }

    /**
     * POST /broadcasting/auth
     *
     * The method is named `postAuth` and not `auth` for two reasons, and the first
     * is not a style choice: `Controller::auth($action)` is the framework's
     * per-action authorization gate, called by `exec()` on every dispatch. A
     * controller action named `auth` would override it and break authorization for
     * the whole controller — PHP rejects the signature outright, which is the
     * friendlier of the two ways that could have gone.
     *
     * The name is also exactly what the dispatcher wants. For any non-GET request
     * `exec()` looks for `strtolower(METHOD . ucfirst($action))`, so `POST` on the
     * `auth` segment resolves here without a route entry. GET does not resolve at
     * all, which is correct: this endpoint has no representation to fetch.
     */
    public function postAuth(): mixed
    {
        $request  = Request::getInstance();
        $socketId = trim((string) $request->get('socket_id', '', 'post'));
        $channel  = trim((string) $request->get('channel_name', '', 'post'));

        if ($socketId === '' || $channel === '') {
            return Response::json([
                'error'   => 'invalid_request',
                'message' => 'socket_id and channel_name are required.',
            ], 400);
        }

        // A socket id is issued by the server as "<n>.<n>". Validating its shape
        // matters because it is signed verbatim: an id carrying a colon could
        // otherwise shift the boundary between the fields of the signed string and
        // make one channel's token verify for another.
        if (preg_match('/^\d+\.\d+$/', $socketId) !== 1) {
            return Response::json([
                'error'   => 'invalid_request',
                'message' => 'socket_id is malformed.',
            ], 400);
        }

        if (!ChannelRegistry::needsAuthorization($channel)) {
            return Response::json([
                'error'   => 'forbidden',
                'message' => 'Public channels are not authorized.',
            ], 403);
        }

        $app = $this->resolveApp();
        if ($app === null) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $authorization = $this->channels()->authorize($channel, $this->currentUser());

        if ($authorization === false) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        try {
            $body = (new PusherAuthSigner($app, $this->encrypter()))
                ->signFor($socketId, $channel, $authorization);
        } catch (\RuntimeException $e) {
            \Pramnos\Logs\Logger::log(
                'Broadcasting auth could not sign: ' . $e->getMessage(),
                'broadcasting'
            );

            return Response::json([
                'error'   => 'server_misconfigured',
                'message' => 'Channel authorization is not configured on this server.',
            ], 500);
        }

        return Response::json($body);
    }

    // -------------------------------------------------------------------------
    // Seams
    // -------------------------------------------------------------------------

    /**
     * The application's channel rules.
     *
     * Resolved from the container so an application registers its rules once, in
     * its own service provider, rather than per request.
     */
    protected function channels(): ChannelRegistry
    {
        $container = $this->application?->getContainer();

        if ($container !== null && $container->has('broadcasting.channels')) {
            $registry = $container->get('broadcasting.channels');
            if ($registry instanceof ChannelRegistry) {
                return $registry;
            }
        }

        // An empty registry denies everything, which is the right default for a
        // deployment that enabled the endpoint without writing any rules.
        return new ChannelRegistry();
    }

    /**
     * The app whose secret signs this token.
     *
     * A client may name the app it means; without one, a single-app deployment's
     * default is used, so the historical single-key setup needs no extra field.
     */
    protected function resolveApp(): ?\Pramnos\Broadcasting\Apps\BroadcastApp
    {
        $registry = $this->appRegistry();
        $key      = trim((string) Request::getInstance()->get('app_key', '', 'post'));

        return $key === '' ? $registry->defaultApp() : $registry->findByKey($key);
    }

    /**
     * The channel encrypter, when one is configured.
     *
     * Needed only for `private-encrypted-` channels, whose subscriber must be handed
     * the per-channel key alongside its token.
     */
    protected function encrypter(): ?ChannelEncrypter
    {
        $info = $this->application?->applicationInfo ?? [];
        $key  = (string) (($info['broadcasting']['encryption_key'] ?? '') ?: '');

        if ($key === '') {
            return null;
        }

        try {
            return ChannelEncrypter::fromBase64($key);
        } catch (\RuntimeException $e) {
            // A bad key is reported once, here, rather than throwing on every
            // request: the endpoint then refuses encrypted channels with a 500 and
            // keeps serving the private ones, which is the smaller failure.
            \Pramnos\Logs\Logger::log(
                'Broadcasting: encryption key is unusable: ' . $e->getMessage(),
                'broadcasting'
            );

            return null;
        }
    }

    protected function appRegistry(): AppRegistryInterface
    {
        $info = $this->application?->applicationInfo ?? [];

        // TTL 0: a web request performs one lookup and exits, so caching would
        // only add a way to serve a stale secret.
        return AppSource::registry(
            is_array($info['broadcasting'] ?? null) ? $info['broadcasting'] : [],
            is_array($info['features'] ?? null) ? $info['features'] : [],
            0
        );
    }

    /**
     * The authenticated user, or null. Separated so tests can supply one without a
     * session.
     */
    protected function currentUser(): ?object
    {
        $user = $this->resolveUser();

        if (!is_object($user) || (int) ($user->userid ?? 0) < 1) {
            return null;
        }

        return $user;
    }

    /**
     * @return User|false
     */
    protected function resolveUser()
    {
        // thin static wrapper; overridden in tests
        return User::getCurrentUser(); // @codeCoverageIgnore
    }
}
