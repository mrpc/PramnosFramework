<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * The browser is already signed in; hand this request's user an API token.
 *
 * ## The gap this fills
 *
 * A hybrid application serves a session-authenticated website and a token-authenticated
 * SPA from one origin. The two credentials have different lifetimes, and the symptom is
 * always reported the same way: *"I am signed in on the site; if I leave it a while and
 * then open the panel, it asks me to log in again."* The site knows exactly who they
 * are. The panel has no way to ask.
 *
 * {@see \Pramnos\Http\Middleware\UnifiedAuthMiddleware} solves the **other** direction —
 * it lets an API endpoint accept a cookie plus a CSRF token. That is not this, and
 * substituting it has a cost worth naming: it makes the API authenticate with cookies,
 * which quietly invalidates every decision an application made *because* it does not —
 * a permissive CORS default being the usual one, introduced far from where it would
 * break.
 *
 * What is wanted is an **exchange**: the browser proves who it is with its session, and
 * receives a bearer token for the API. One direction, one moment, no cookie ever read
 * by the API.
 *
 * ## The four decisions this makes so an application does not have to
 *
 * Three of them are only wrong in ways nobody notices.
 *
 * **1. The role is re-read here, not taken from the session.** A remember-me cookie can
 * outlive a demotion by a fortnight, and a token minted from that session would then be
 * good for its full lifetime afterwards. {@see issue()} loads the user fresh and checks
 * the minimum against what the database says now.
 *
 * **2. The token goes in the URL *fragment*, never a query parameter.** A fragment is
 * not sent to a server: it stays out of access logs, out of `Referer`, and out of every
 * proxy in between. A query parameter is in the access log of every hop, forever, and
 * nothing about the code looks different. {@see redirectUrl()} builds the fragment form.
 *
 * **3. Nothing is issued for a caller who is not signed in.** No implicit anonymous
 * token, no partial credential — null, so the caller decides what an unauthenticated
 * visitor sees.
 *
 * **4. Failure is null, not an exception.** This is called from a route whose job is to
 * redirect somewhere sensible either way.
 *
 * The fifth decision belongs to the consumer and cannot be made here: an SPA that
 * bounces to the exchange route when it has no token **must record that it has bounced
 * before redirecting**, not after. The route redirects back without a fragment when it
 * cannot help, so a flag written after the redirect is an infinite loop on the one page
 * an operator opens when something is already wrong.
 *
 * ## Usage
 *
 * ```php
 * // In a session-authenticated route:
 * $token = SessionExchange::issue(minimumUserType: 90, ttl: 43200);
 * if ($token === null) {
 *     return Response::redirect(sURL . 'login');
 * }
 *
 * return Response::redirect(SessionExchange::redirectUrl(sURL . 'panel/', $token));
 * ```
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class SessionExchange
{
    /**
     * Issue an API token for the user the **session** says is signed in.
     *
     * Refuses when the request's identity was sealed by anything other than a session —
     * a bearer token exchanging itself for another token is a refresh, with rotation and
     * revocation questions this method does not answer.
     *
     * @param  int  $minimumUserType Minimum `usertype` required, re-read from the
     *                               database rather than trusted from the session. 0
     *                               accepts any signed-in user.
     * @param  int  $ttl             Token lifetime in seconds. 0 uses the application's
     *                               `auth.token_ttl`, which itself defaults to no
     *                               expiry — pass a value for a short-lived exchange
     *                               token, which is the point of exchanging one.
     * @param  string $notes         Recorded on the token row, so a session list can
     *                               say where a credential came from.
     * @return string|null           The token, or null when nobody is signed in, the
     *                               minimum is not met, or no signing key is configured.
     */
    public static function issue(
        int $minimumUserType = 0,
        int $ttl = 0,
        string $notes = 'session_exchange'
    ): ?string {
        try {
            // Only a session may be exchanged.
            //
            // `getCurrentUser()` prefers a *sealed* identity over the session, so an
            // API request authenticated by a bearer token would otherwise reach here
            // and mint a fresh token from a token — an unbounded refresh with no
            // rotation policy, from a method whose whole contract is that a **session**
            // is what proved the identity.
            //
            // Identified positively rather than by excluding token-ish `via` values: a
            // blocklist here would have to enumerate every credential that is not a
            // session, which is unbounded, and the framework has already been bitten
            // by that shape once this week in `MakeWebhook::detectCliName()`.
            if (\Pramnos\Http\RequestIdentity::isSealed()
                && \Pramnos\Http\RequestIdentity::via() !== 'session'
            ) {
                \Pramnos\Logs\Logger::log(
                    'SessionExchange::issue() refused: this request was authenticated '
                    . 'by ' . (\Pramnos\Http\RequestIdentity::via() ?: 'nothing')
                    . ', not by a session. Exchanging a token for a token is a refresh, '
                    . 'which this is not.',
                    'auth'
                );

                return null;
            }

            $sessionUser = \Pramnos\User\User::getCurrentUser();
            $userId      = (int) ($sessionUser->userid ?? 0);

            // Guest users are id 0 or 1 by this framework's convention, and a guest has
            // nothing to exchange.
            if ($userId <= 1) {
                return null;
            }

            // Re-read, deliberately. This is the difference between a token that
            // reflects the account and one that reflects a cookie issued a fortnight
            // ago — see the class docblock.
            $user = new \Pramnos\User\User($userId);
            if ((int) ($user->userid ?? 0) !== $userId) {
                return null;
            }
            if ($minimumUserType > 0 && (int) ($user->usertype ?? 0) < $minimumUserType) {
                return null;
            }

            $token = self::mint($user, $ttl, $notes);
            if ($token === null) {
                return null;
            }

            // The toolbar can describe what was just handed over — its claims and its
            // expiry, never its value — at the moment somebody wants to know how long
            // they have. `seal()` has always had a slot for this; until now only the
            // API login filled it.
            \Pramnos\Http\RequestIdentity::seal($user, 'session-exchange', $token);

            ActivityLog::record($userId, 'session_exchange', [
                'minimum_usertype' => $minimumUserType,
                'ttl'              => $ttl > 0 ? $ttl : self::configuredTtl(),
            ]);

            return $token;
        } catch (\Throwable $ex) {
            \Pramnos\Logs\Logger::log(
                'SessionExchange::issue() failed: ' . $ex->getMessage(),
                'auth'
            );

            return null;
        }
    }

    /**
     * A redirect URL carrying the token where it will not be logged.
     *
     * `https://example.com/panel/#session=<token>`
     *
     * The fragment is not sent to a server. That is the whole reason for this method
     * existing rather than the caller appending `?token=` — which works, looks the same
     * in review, and writes the credential into the access log of every hop between the
     * browser and the application, where it stays for as long as logs are kept.
     *
     * The receiving page must clear it from the address bar once adopted
     * (`history.replaceState`), or it survives in browser history and in whatever the
     * visitor pastes when asking for help.
     *
     * @param  string $target The SPA entry point
     * @param  string $token  The token from {@see issue()}
     * @param  string $key    Fragment key; `session` unless the consumer prefers another
     * @return string
     */
    public static function redirectUrl(string $target, string $token, string $key = 'session'): string
    {
        // Any fragment already on the target is replaced: two fragments are not a thing,
        // and silently concatenating them would produce a URL that neither half reads.
        $base = explode('#', $target, 2)[0];

        return $base . '#' . $key . '=' . rawurlencode($token);
    }

    /**
     * Mint and record the token.
     *
     * The claim set matches the API login's, so a token from an exchange is
     * indistinguishable to every verifier — which is the point. A second shape of token
     * would mean a second thing to keep in step, and the one that is not exercised is
     * the one that rots.
     *
     * @param  object $user  The user, freshly loaded
     * @param  int    $ttl   Lifetime in seconds, or 0 for the configured default
     * @param  string $notes Recorded on the token row
     * @return string|null   Null when no signing key is configured
     */
    private static function mint(object $user, int $ttl, string $notes): ?string
    {
        $key = static::signingKey();
        if ($key === '') {
            \Pramnos\Logs\Logger::log(
                'SessionExchange: no usable signing key — the application declares no '
                . 'authenticationKey and there is no sURL to derive one from. Refusing '
                . 'rather than signing with a constant every installation would share.',
                'auth'
            );

            return null;
        }

        $now      = time();
        $lifetime = $ttl > 0 ? $ttl : self::configuredTtl();

        $claims = [
            'iss' => defined('sURL') ? sURL : '',
            'aud' => self::audience(),
            'iat' => $now,
            'nbf' => $now - (3600 * 12),
        ];

        $expires = null;
        if ($lifetime > 0) {
            $expires       = $now + $lifetime;
            $claims['exp'] = $expires;
        }

        $jwt = JWT::encode($claims, $key);

        // 'auth' rather than a type of its own: the verifier looks up tokens by type,
        // and inventing one here would mean every consumer's token lookup needed to
        // learn about it. The origin is in `notes`, where a session list can show it.
        $user->addToken('auth', $jwt, $notes, null, $expires);

        return $jwt;
    }

    /**
     * The application's JWT signing key, or an empty string.
     *
     * `currentInstance()` rather than `getInstance()`, which is a **factory**: given no
     * existing instance it reads `app.php`, defines constants, and runs the whole
     * constructor — database, language and session. The framework's own docblock on
     * `currentInstance()` states the rule and the incident behind it: a CSRF fingerprint
     * check that booted an application was a side effect in the middle of a security
     * decision, and a reference application's login tests began failing on valid tokens
     * because a second instance was being constructed underneath them.
     *
     * Minting a bearer token is that same kind of code. In a real request an application
     * always exists, so this is the identical answer — it differs only where there is
     * none, and there the honest answer is that no key is configured.
     *
     * @return string
     */
    /**
     * The site's base URL, read through a seam.
     *
     * `sURL` is a constant, so a test cannot unset it — and the guard below refuses to mint a
     * credential when there is none. Without a seam that refusal is untestable the moment a test
     * environment defines the constant, which is exactly when it stops being exercised and starts
     * being assumed.
     *
     * The property it protects is worth a test: with no site URL the key derivation reduces to
     * `md5($version)` with `$version` defaulting to `edge`, so every installation in that state
     * would sign with the same publicly computable constant and a token from any of them would
     * verify against all of them.
     */
    protected static function siteUrl(): string
    {
        return defined('sURL') ? (string) sURL : '';
    }

    protected static function signingKey(): string
    {
        $app = \Pramnos\Application\Application::currentInstance();
        if (is_object($app) && isset($app->authenticationKey) && $app->authenticationKey !== '') {
            return (string) $app->authenticationKey;
        }

        // Falling back is the normal path here, not the exceptional one. `Api` declares and
        // computes `authenticationKey`; a plain `Application` never has. This method is
        // called from a session-authenticated MVC route by definition — that is what a
        // session is — so reading the property alone returned an empty key every time and
        // the exchange issued nothing at all.
        //
        // But only with a site URL to derive from. Without `sURL` the derivation reduces to
        // `md5($version)`, and the version defaults to `edge` — so every installation in
        // that state would sign with the same publicly computable constant, and a token
        // from any of them would verify against all of them. `Api` has always derived it
        // that way and changing that is not this method's business; refusing to *mint* a
        // credential under a world-known key is.
        if (static::siteUrl() === '') {
            return '';
        }

        return \Pramnos\Application\Api::deriveAuthenticationKey();
    }

    /**
     * The audience claim — the application's API key, when there is one.
     *
     * @return string
     */
    private static function audience(): string
    {
        $app = \Pramnos\Application\Application::currentInstance();

        if (is_object($app) && isset($app->apiKey) && is_object($app->apiKey)) {
            return (string) ($app->apiKey->apikey ?? '');
        }

        return '';
    }

    /**
     * `auth.token_ttl` from the application config, or 0 for no expiry.
     *
     * @return int
     */
    private static function configuredTtl(): int
    {
        $app = \Pramnos\Application\Application::currentInstance();

        if (is_object($app) && isset($app->applicationInfo['auth']['token_ttl'])) {
            return max(0, (int) $app->applicationInfo['auth']['token_ttl']);
        }

        return 0;
    }
}
