<?php

declare(strict_types=1);

namespace Pramnos\Auth;

use Pramnos\Http\Response;
use Pramnos\User\User;

/**
 * Minting an API access token for a user whose identity is already settled.
 *
 * "Already settled" is the point. *How* somebody proved who they are is the part
 * that differs — a password, a second factor, a passkey assertion — and what
 * follows is identical every time: this application's signing key and lifetime, a
 * row in `usertokens` so {@see \Pramnos\User\User::loadByToken()} accepts it, the
 * identity sealed onto the request so the toolbar and the activity log see the
 * login as it happens, and one response envelope so a client stores the token
 * with the code it already has.
 *
 * A trait rather than a service, for two reasons that both come from the same
 * fact — every one of these is a **seam**:
 *
 *   - An application overrides `signingKey()`, `tokenTtl()`, `audience()`,
 *     `userPayload()` or `userFor()` on its own controller subclass, and has
 *     done so since before this was extracted. A service would have moved the
 *     hooks somewhere those overrides do not reach.
 *   - These are protected, and must stay protected. A public method on a
 *     controller is a routable action, and one taking an `int` first parameter
 *     cannot be called by a dispatcher that passes an array — which is a route
 *     that exists and fatals, not one that 404s.
 *
 * Used by {@see \Pramnos\Auth\Controllers\ApiAccount} (password, and the second
 * factor after it) and {@see \Pramnos\Auth\Controllers\ApiPasskey} (a WebAuthn
 * assertion, and no password at all). It reads `$this->application` for the key
 * and the audience, so it belongs on a controller.
 */
trait IssuesAccessTokens
{
    /**
     * The user a settled identity names.
     *
     * The default loads the row. A controller that already holds the object —
     * {@see \Pramnos\Auth\Controllers\ApiAccount} does, having just verified
     * its credentials — declares its own and keeps the seam an application may
     * have overridden to return its own User subclass.
     *
     * @codeCoverageIgnore — a bare row load. The seam is replaced in unit tests,
     *                       and the real path is exercised by the integration
     *                       suite against a live database.
     */
    protected function userFor(int $userId): User
    {
        return new User($userId);
    }

    /**
     * The success response: a bearer token for a fully verified user.
     *
     * Shared by every way of proving an identity, so a login that needed a
     * second factor — or no password at all — is answered exactly like one that
     * did not.
     */
    protected function tokenResponse(User $user): mixed
    {
        // This request *did* authenticate somebody — with a password rather than
        // a token, which is the only reason it did not look like it. Saying so
        // is what lets the debug toolbar show the login at the moment it
        // happens, instead of only on whatever call comes next; and it means
        // anything reading the current user during this response (activity logs,
        // tracking) sees who it was for.
        // `currentInstance()`: `getInstance()` is a factory, and this is a login response
        // — not the place to construct an application, a database connection and a session
        // as a side effect. The guard was already written for a null this call never
        // returned.
        $app = \Pramnos\Application\Application::currentInstance();
        if ($app) {
            $app->currentUser = $user;
        }

        $token = $this->issueToken($user);
        if ($token === null) {
            \Pramnos\Http\RequestIdentity::seal($user, 'password');

            return Response::json(
                ['error' => 'token_unavailable', 'error_description' => 'The API signing key is not configured.'],
                500
            );
        }

        // Sealed after the token exists, so the toolbar can describe what was
        // just issued — how long it lasts, and what is in it — at the moment it
        // is handed over. Before this, a login showed "signed in" and the expiry
        // only appeared on the next request, which is the one moment nobody is
        // looking. The token's *value* still never enters the payload; the
        // response beside it already carries that to the client that asked.
        \Pramnos\Http\RequestIdentity::seal($user, 'password', $token);

        return Response::json([
            'status'       => 'success',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $this->userPayload($user),
        ]);
    }

    /**
     * Mint a signed JWT for the user and persist it as an `auth` token, so the API
     * auth middleware accepts it. Returns null when no signing key is available.
     */
    protected function issueToken(User $user): ?string
    {
        $key = $this->signingKey();
        if ($key === '') {
            return null;
        }

        $now = time();
            // `jti` — a random identifier, and the only thing making two tokens differ.
            //
            // The claims above are `iss`, `aud`, `iat`, `nbf` and `exp`: not one of them
            // names the user, and all of them are identical for any two tokens minted in
            // the same second. So two issuances one second apart produced the **same
            // string**, and `usertokens.token_lookup` is unique — the second insert failed,
            // which surfaced as a sign-in that did nothing. Two people signing in at the
            // same moment was enough; so was a SPA opening two tabs.
            //
            // It is what `jti` is for (RFC 7519 §4.1.7), and nothing verifies it: a token
            // is still resolved to its user by the `usertokens` row, exactly as before.
        $claims = [
            'iss' => defined('sURL') ? sURL : '',
            'aud' => $this->audience(),
            'iat' => $now,
            'nbf' => $now - (3600 * 12),
            'jti' => bin2hex(random_bytes(16)),
        ];

        // Optional expiry. TTL of 0 keeps the historical never-expires behaviour;
        // a positive TTL stamps both the JWT `exp` claim (rejected by JWT::decode
        // once past) and the usertokens.expires column (rejected by loadByToken).
        $ttl = $this->tokenTtl();
        $expires = null;
        if ($ttl > 0) {
            $expires = $now + $ttl;
            $claims['exp'] = $expires;
        }

        $jwt = JWT::encode($claims, $key);

        $user->addToken('auth', $jwt, 'api_login', null, $expires);

        return $jwt;
    }

    /**
     * Access-token lifetime in seconds. 0 (the default) means the token never
     * expires — preserving the historical behaviour. Apps enable expiry by
     * overriding this or by setting the `auth.token_ttl` application config key.
     */
    protected function tokenTtl(): int
    {
        $app = $this->application;
        if (is_object($app) && isset($app->applicationInfo['auth']['token_ttl'])) {
            return max(0, (int) $app->applicationInfo['auth']['token_ttl']);
        }

        return 0;
    }

    /**
     * The public profile returned alongside the token.
     *
     * @return array<string, mixed>
     */
    protected function userPayload(User $user): array
    {
        return [
            'id'       => (int) $user->userid,
            'username' => $user->username,
            'email'    => $user->email,
        ];
    }

    /**
     * The HS256 signing key — the API application's authentication key.
     *
     * @codeCoverageIgnore — reads the Api instance; overridden in tests.
     */
    protected function signingKey(): string
    {
        $app = $this->application;
        return (is_object($app) && isset($app->authenticationKey)) ? (string) $app->authenticationKey : '';
    }

    /**
     * The token audience — the authenticated application's API key (if any).
     *
     * @codeCoverageIgnore — reads the Api instance; overridden in tests.
     */
    protected function audience(): string
    {
        $app = $this->application;
        if (is_object($app) && isset($app->apiKey) && is_object($app->apiKey)) {
            return (string) ($app->apiKey->apikey ?? '');
        }

        return '';
    }
}
