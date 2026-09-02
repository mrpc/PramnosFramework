<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Http\Response;
use Pramnos\User\User;

/**
 * Me — the current authenticated user (framework base API controller).
 *
 * Feature-generic, JSON-only endpoints that virtually every authenticated API
 * needs, built entirely on the framework's public User API so it works for any
 * application:
 *
 *   GET    /me               → the current user's public profile
 *   GET    /me/tokens        → the current user's active tokens
 *   DELETE /me/tokens/{id}   → revoke one of the current user's tokens
 *
 * Applications thin-wrap this in their own Api\Controllers namespace and override
 * any action (or {@see publicProfile()}) to shape the payload. Every action
 * returns HTTP 401 when there is no authenticated user — the guard is applied
 * inline (via {@see User::getCurrentUser()}) so it holds regardless of how the
 * action is invoked (standard dispatch or a direct API-route closure).
 */
class Me extends Controller
{
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        // Actions are registered as authenticated; the inline getCurrentUser()
        // check is the real guard for the API-closure invocation path.
        $this->addAuthAction(['display', 'tokens', 'deleteTokens']);
        parent::__construct($application);
    }

    /**
     * GET /me — the current authenticated user's public profile.
     */
    public function display(): mixed
    {
        $user = $this->currentUser();
        if ($user === null) {
            return Response::json(['error' => 'not_authenticated'], 401);
        }

        return Response::json(['data' => $this->publicProfile($user)]);
    }

    /**
     * GET /me/tokens — the current user's active tokens.
     */
    public function tokens(): mixed
    {
        $user = $this->currentUser();
        if ($user === null) {
            return Response::json(['error' => 'not_authenticated'], 401);
        }

        return Response::json(['data' => $user->getAllTokens()]);
    }

    /**
     * DELETE /me/tokens/{tokenid} — revoke one of the current user's tokens.
     *
     * @param mixed $tokenid The token id to revoke (from the route parameter).
     */
    public function deleteTokens(mixed $tokenid = null): mixed
    {
        $user = $this->currentUser();
        if ($user === null) {
            return Response::json(['error' => 'not_authenticated'], 401);
        }

        if ($tokenid === null || $tokenid === '') {
            return Response::json(
                ['error' => 'invalid_request', 'error_description' => 'Missing token id'],
                400
            );
        }

        $user->deleteToken((int) $tokenid);

        return Response::json(['status' => 'ok'], 200);
    }

    /**
     * Resolve the current authenticated user, or null when none is logged in.
     *
     * {@see resolveUser()} returns false for anonymous requests; the guest
     * account (userid < 1) is also treated as "not authenticated".
     */
    protected function currentUser(): ?User
    {
        $user = $this->resolveUser();
        if (!$user || (int) $user->userid < 1) {
            return null;
        }

        return $user;
    }

    /**
     * Fetch the framework's current user (a thin, overridable seam around the
     * static lookup so {@see currentUser()} stays unit-testable).
     *
     * @return User|false
     */
    protected function resolveUser()
    {
        // thin static wrapper; overridden in tests
        return User::getCurrentUser(); // @codeCoverageIgnore
    }

    /**
     * The public, safe subset of user fields exposed by the API.
     *
     * Override in a subclass to add or remove fields for your application.
     *
     * @return array<string, mixed>
     */
    protected function publicProfile(User $user): array
    {
        return [
            'id'        => (int) $user->userid,
            'username'  => $user->username,
            'email'     => $user->email,
            'usertype'  => (int) $user->usertype,
            'language'  => $user->language,
            'avatarurl' => $user->avatarurl,
            'active'    => (int) $user->active,
        ];
    }
}
