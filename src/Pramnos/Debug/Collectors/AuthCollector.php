<?php

declare(strict_types=1);

namespace Pramnos\Debug\Collectors;

use Pramnos\Auth\JWT;
use Pramnos\Http\Request;

/**
 * Who this request is, and what convinced the server of it.
 *
 * "It worked and then it stopped" is almost always this, and every part of the
 * answer used to live somewhere the developer could not see: the credential is
 * in a request header, the identity is in a session the browser cannot read,
 * and the expiry is inside a token nobody decodes by hand at four in the
 * afternoon.
 *
 * Four questions, in the order they get asked:
 *
 *  - **who** — user id, username, type, or "anonymous";
 *  - **what** — apiKey, accessToken, a legacy `userAuth` header, or a session
 *    cookie. An API request that quietly fell back to a cookie is a bug that
 *    only shows up on somebody else's machine;
 *  - **where from** — the exact header or cookie, because "the token" means the
 *    `accessToken` header to one developer and `Authorization: Bearer` to
 *    another, and only one of them is being sent;
 *  - **until when** — the token's `exp`, so the panel can count down to it.
 *
 * **The token itself never travels.** Only its claims do, and only the ones that
 * identify rather than authorise: the payload this collector feeds is attached
 * to responses and sits in a browser's network log, so putting a live
 * credential in it would hand out the thing the panel exists to explain. The
 * signature is dropped, and the claims are read without verifying it — this is
 * a description of what the client sent, not a second authentication.
 */
class AuthCollector implements CollectorInterface
{
    /**
     * Claims worth showing. Everything else in a token is the application's
     * business and may be anything at all, including data it would not want in
     * a network log.
     *
     * @var list<string>
     */
    private const SAFE_CLAIMS = ['sub', 'iss', 'aud', 'iat', 'exp', 'nbf', 'jti', 'userid', 'username'];

    public function name(): string
    {
        return 'auth';
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $credential = $this->credential();

        return [
            'user'       => $this->user(),
            'credential' => $credential['type'],
            'source'     => $credential['source'],
            'token'      => $credential['token'],
        ];
    }

    /**
     * Who the server thinks is making this request.
     *
     * @return array<string, mixed>
     */
    private function user(): array
    {
        // The identity of this request, asked for the same way the application
        // asks. Reading $_SESSION directly is what let this panel report
        // "authenticated" for a request that /me answered 401 to — the collector
        // and the application were consulting different sources, and the panel
        // was describing a world the code did not live in.
        $user = \Pramnos\User\User::getCurrentUser();

        if (!is_object($user) || (int) ($user->userid ?? 0) < 1) {
            return ['authenticated' => false];
        }

        return [
            'authenticated' => true,
            'userid'        => (int) $user->userid,
            'username'      => (string) ($user->username ?? ''),
            'usertype'      => (int) ($user->usertype ?? 0),
        ];
    }

    /**
     * What the client presented, and where it presented it.
     *
     * Checked in the order the API middleware checks them, so the answer is the
     * credential that will actually be used rather than the first one present.
     *
     * @return array{type: string, source: string, token: array<string, mixed>|null}
     */
    private function credential(): array
    {
        // What the request itself said, when something said it. A password
        // login presents no header at all — the token it returns is issued *by*
        // that call — so without this the moment somebody signs in reads as
        // anonymous, and the panel only catches up on the next request.
        $via = \Pramnos\Http\RequestIdentity::via();

        if ($via === 'signed-out') {
            return [
                'type'   => 'none',
                'source' => 'this request signed out',
                'token'  => null,
            ];
        }

        if ($via === 'password') {
            $issued = \Pramnos\Http\RequestIdentity::issuedToken();

            return [
                'type'   => 'password',
                'source' => 'this request signed in',
                // The token this call just issued, described the same way any
                // other is: claims and expiry, never the value. Somebody who has
                // just signed in wants to know how long they have, and this is
                // the only response that can tell them.
                'token'  => $issued !== null ? $this->describeToken($issued) : null,
            ];
        }

        $accessToken = Request::accessToken();

        if ($accessToken !== null) {
            $viaHeader = trim((string) ($_SERVER['HTTP_ACCESSTOKEN'] ?? '')) !== '';

            return [
                'type'   => 'accessToken',
                'source' => $viaHeader ? 'accessToken header' : 'Authorization: Bearer',
                'token'  => $this->describeToken($accessToken),
            ];
        }

        if (!empty($_SERVER['HTTP_USERAUTH'])) {
            return [
                'type'   => 'userAuth',
                // Named as deprecated where a reader will see it: this header
                // carries a password hash, and the reason to show it at all is
                // that finding an application still sending it is the point.
                'source' => 'userAuth header (deprecated)',
                'token'  => null,
            ];
        }

        if (!empty($_SERVER['HTTP_APIKEY'])) {
            return ['type' => 'apiKey', 'source' => 'apiKey header', 'token' => null];
        }

        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['logged'])) {
            return [
                'type'   => 'session',
                'source' => 'cookie ' . session_name(),
                'token'  => null,
            ];
        }

        return ['type' => 'none', 'source' => '', 'token' => null];
    }

    /**
     * A token described by its claims, never by its value.
     *
     * Read without verifying the signature on purpose: this reports what the
     * client sent, and a token the server is about to reject is exactly the one
     * worth looking at. Whether it is *accepted* is the middleware's answer, and
     * it shows up as a 403 in the requests list.
     *
     * @return array<string, mixed>|null Null when it is not a JWT at all
     */
    private function describeToken(string $token): ?array
    {
        try {
            $claims = JWT::decodeUnverified($token);
        } catch (\Throwable $e) {
            $claims = false;
        }

        // An opaque token — a random string looked up in a table — is a
        // perfectly good credential with nothing inside it to read.
        if ($claims === false || (!is_object($claims) && !is_array($claims))) {
            return ['format' => 'opaque'];
        }

        $claims = (array) $claims;
        $shown  = [];

        foreach (self::SAFE_CLAIMS as $claim) {
            if (array_key_exists($claim, $claims) && is_scalar($claims[$claim])) {
                $shown[$claim] = $claims[$claim];
            }
        }

        return [
            'format' => 'jwt',
            'claims' => $shown,
            // The panel counts down to this. Sent as the token's own absolute
            // timestamp rather than as "seconds left", because the response may
            // sit in a browser for a while before anybody looks at it.
            'expires_at' => isset($claims['exp']) && is_numeric($claims['exp'])
                ? (int) $claims['exp']
                : null,
            'issued_at' => isset($claims['iat']) && is_numeric($claims['iat'])
                ? (int) $claims['iat']
                : null,
        ];
    }
}
