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
            'twofactor'  => $this->twoFactor(),
        ];
    }

    /**
     * The second factor: what this account holds, what the site demands, and what is in
     * flight.
     *
     * Three questions that are otherwise answered by reading a session nobody can see and a
     * policy spread across two configuration switches:
     *
     *  - **why am I being asked for a code** — the sign-in floor applies to this usertype,
     *    or the account enrolled something itself;
     *  - **why does every page redirect me to the setup screen** — the enrolment floor
     *    applies and what the account holds is not strong enough. Without this the wall
     *    looks like a redirect loop, and the first guess is always a routing bug;
     *  - **where in the step-up am I** — a half-finished login lives entirely in the
     *    session, so from outside a stuck sign-in and a fresh one are the same page.
     *
     * **No code and no secret is ever in here.** Not because a debug payload is public — it
     * is not, and the bar only renders where debugging is on — but because a live six-digit
     * code in a network log is a live six-digit code, and this payload ends up pasted into
     * bug reports. What travels is whether a code exists and how long the resend has left.
     *
     * Skipped entirely for a request with nobody in it and nothing pending: the reads below
     * are queries, and an anonymous page load should not pay for them.
     *
     * @return array<string, mixed>|null
     */
    private function twoFactor(): ?array
    {
        try {
            $flow      = new \Pramnos\Auth\LoginFlow();
            $pendingId = $flow->pendingUserId();
            $user      = \Pramnos\User\User::getCurrentUser();
            $userId    = is_object($user) ? (int) ($user->userid ?? 0) : 0;

            if ($userId < 1 && $pendingId === null) {
                return null;
            }

            $signInFloor = \Pramnos\Auth\SecurityPolicy::secondFactorFromUsertype();
            $enrolFloor  = \Pramnos\Auth\SecurityPolicy::factorEnrolmentFromUsertype();
            $enrolment   = new \Pramnos\Auth\FactorEnrolment();
            $subject     = $userId > 0 ? $userId : (int) $pendingId;
            $usertype    = $userId > 0 ? (int) ($user->usertype ?? 0) : $this->usertypeOf($subject);

            $state = [
                'held'                => $enrolment->factorsFor($subject),
                'sign_in_floor'       => $signInFloor,
                'enrolment_floor'     => $enrolFloor,
                'required_to_sign_in' => $signInFloor > 0 && $usertype >= $signInFloor,
                'must_enrol'          => $enrolment->isRequiredFor($subject, $usertype),
                'pending'             => null,
            ];

            if ($pendingId !== null) {
                $methods = [];
                foreach ($flow->pendingFactors() as $factor) {
                    $methods[] = $factor->name();
                }

                $state['pending'] = [
                    'userid'       => $pendingId,
                    'methods'      => $methods,
                    'mailed_code'  => $flow->hasLiveEmailCode(),
                    'resend_in'    => $flow->secondsUntilResend(),
                    'waiting_for'  => max(0, time() - (int) ($_SESSION['loginflow_pending_time'] ?? time())),
                ];
            }

            if ($this->revealsCodes()) {
                $state['revealed'] = $this->revealedCodes($subject);
            }

            return $state;
        } catch (\Throwable $exception) {
            // A panel that raises takes the page with it, and this one is describing the
            // request rather than serving it. Reported in the payload so the developer sees
            // that the reading failed instead of reading "no second factor" as an answer.
            return ['error' => $exception->getMessage()];
        }
    }

    /**
     * Whether this installation has asked for live codes in the panel.
     *
     * `debug.reveal_factor_codes`, and **off unless it is set**:
     *
     * ```php
     * // app/app.php — development only
     * 'debug' => ['reveal_factor_codes' => true],
     * ```
     *
     * The argument for showing them is sound as far as it goes: the panel renders only where
     * debugging is on, and the codes below belong to the viewer's own session — the enrolment
     * secret is on the setup screen they can open, and the mailed code went to their own
     * mailbox. Nothing here is disclosed to somebody who could not already get it.
     *
     * The argument for a switch is what happens to the payload afterwards. It rides on
     * responses, sits in the browser's network log, and gets pasted into bug reports and
     * screenshots — and a debug flag left on by accident is a normal kind of accident. A live
     * six-digit code and a TOTP secret in a paste are a credential in a paste, so this is a
     * decision an installation makes on purpose rather than a default it inherits.
     */
    private function revealsCodes(): bool
    {
        $application = \Pramnos\Application\Application::currentInstance();

        if (!is_object($application)) {
            return false;
        }

        return ($application->applicationInfo['debug']['reveal_factor_codes'] ?? false) === true;
    }

    /**
     * The codes themselves, for a developer who would otherwise be reading a mailbox.
     *
     * Two things, and each answers a question that costs minutes every time:
     *
     *  - **the enrolment secret and a code valid right now** — "the setup screen wants six
     *    digits and my phone is on the other desk";
     *  - **the mailed code** — the six digits a step-up just sent. Recovered from the
     *    recorded mail body, because the store keeps only an HMAC of it: the code cannot be
     *    read back from `twofactor_email_codes` by design, and that is worth keeping.
     *
     * Every read is guarded on its own. A panel is a description of a request, and a
     * description that raises takes the page with it.
     *
     * @return array<string, mixed>
     */
    private function revealedCodes(int $userId): array
    {
        $revealed = ['note' => 'debug.reveal_factor_codes is on — development only'];

        try {
            $service = new \Pramnos\Auth\TwoFactorAuthService(
                \Pramnos\Framework\Factory::getDatabase()
            );
            $secret = $service->getSecret($userId) ?: $this->pendingSetupSecret($userId);

            if (is_string($secret) && $secret !== '') {
                $revealed['totp_secret'] = $secret;
                $revealed['totp_now']    = \Pramnos\Auth\TOTPHelper::generateCode($secret);
            }
        } catch (\Throwable $exception) {
            $revealed['totp_error'] = $exception->getMessage();
        }

        try {
            $mailed = $this->lastMailedCode($userId);

            if ($mailed !== null) {
                $revealed['mailed_code'] = $mailed;
            }
        } catch (\Throwable $exception) {
            $revealed['mailed_error'] = $exception->getMessage();
        }

        return $revealed;
    }

    /**
     * The secret of an enrolment somebody is in the middle of, before it is confirmed.
     *
     * `user_twofactor.secret` is only written when the setup completes, so during the step
     * where the screen shows a QR code the secret lives in `twofactor_setup` — which is
     * exactly the moment a developer wants to read it.
     */
    private function pendingSetupSecret(int $userId): ?string
    {
        $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
            ->table('authserver.twofactor_setup')
            ->select(['temp_secret'])
            ->where('userid', $userId)
            ->where('used', 0)
            ->orderBy('created_at', 'desc')
            ->limit(1)
            ->get();

        $row = $result === false || $result === null ? null : $result->fetch();

        return is_array($row) && ($row['temp_secret'] ?? '') !== '' ? (string) $row['temp_secret'] : null;
    }

    /**
     * The six digits of the most recent code mailed to this account.
     *
     * Out of the mail log, not the code store: `twofactor_email_codes` holds an HMAC and
     * nothing else, which is the right design and means the code is unrecoverable from it.
     * The mail body is where it exists, and only for as long as the log keeps it.
     */
    private function lastMailedCode(int $userId): ?string
    {
        $user  = new \Pramnos\User\User($userId);
        $email = trim((string) ($user->email ?? ''));

        if ($email === '') {
            return null;
        }

        $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
            ->table('#PREFIX#mails')
            ->select(['content', 'date'])
            ->whereRaw('LOWER(tomail) = ?', [strtolower($email)])
            ->orderBy('date', 'desc')
            ->limit(5)
            ->get();

        if ($result === false || $result === null) {
            return null;
        }

        while (($row = $result->fetch()) !== null) {
            // The first standalone six-digit run in the newest mail that has one. Ordered
            // newest-first and capped at five, because a code from last week is worse than
            // no code: somebody would type it.
            if (preg_match('/\b(\d{6})\b/', (string) ($row['content'] ?? ''), $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }

    /**
     * A pending account's usertype, which the request does not have to hand.
     *
     * During a step-up nobody is signed in yet — `getCurrentUser()` is anonymous — so the
     * floors cannot be evaluated against the session. One read, and only while a step-up is
     * actually in flight.
     */
    private function usertypeOf(int $userId): int
    {
        if ($userId < 1) {
            return 0;
        }

        return (int) (new \Pramnos\User\User($userId))->usertype;
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
