<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Auth\IssuesAccessTokens;
use Pramnos\Http\Response;

/**
 * ApiPasskey — passwordless sign-in and passkey management for the REST API.
 *
 * The JSON counterpart to the web {@see Passkey} controller, and deliberately a
 * subclass of it rather than a second implementation: a WebAuthn ceremony is a
 * precise conversation — challenge correlation, base64url in both directions,
 * the exact attestation shape webauthn-lib deserialises — and the SPA needs none
 * of it done differently. Two things differ, and they are the two things this
 * class contains:
 *
 * **1. What a verified assertion is answered with.** The web controller
 * establishes a session, because that is what the page it came from expects. A
 * SPA authenticates every later call with a bearer token, so this answers with
 * the same envelope {@see ApiAccount::login()} returns — `access_token`,
 * `token_type`, `user` — and a client stores it with the code it already has.
 * No session is established: the API's identity is the token it just issued, and
 * a login cookie left behind would answer for calls that presented no credential.
 *
 * **2. Where a field is read from.** A SPA posts `application/json`; the web
 * views post a form. The ceremony's own payloads are raw bodies either way, but
 * `username`, `label`, `id` and `name` are fields, and without this they would
 * arrive empty — which reads as "no such user" and "invalid_request", neither of
 * which points at the body.
 *
 *   POST /passkey/loginOptions     → request options (public)
 *   POST /passkey/login            → verify assertion, issue a bearer token (public)
 *   POST /passkey/registerOptions  → creation options (auth)
 *   POST /passkey/register         → verify attestation, store credential (auth)
 *   GET  /passkey/list             → list the caller's passkeys (auth)
 *   POST /passkey/rename           → rename a passkey (auth)
 *   POST /passkey/revoke           → revoke a passkey (auth)
 *
 * The in-flight challenge still lives server-side, so the SPA's calls have to
 * carry the session cookie the ceremony started with — which the generated
 * `lib/api.js` already does (`credentials: 'same-origin'`). That cookie
 * correlates a ceremony; it never authenticates anything.
 *
 * `display()` is inherited but never routed: it renders an HTML management page,
 * which an API has no use for — the SPA draws its own from `list()`.
 */
class ApiPasskey extends Passkey
{
    use IssuesAccessTokens;

    /** @var array<string, mixed>|null decoded JSON body cache */
    private ?array $jsonBodyCache = null;

    /**
     * What a verified assertion is answered with: a bearer token.
     *
     * Overrides the session the web flow would have established. Everything
     * before this point — the challenge, the assertion, the verification — is
     * the parent's, unchanged; everything after it is
     * {@see \Pramnos\Auth\IssuesAccessTokens}, the same trait the password
     * login mints with. Neither half is written twice.
     */
    protected function loginResponse(int $userId): mixed
    {
        return $this->tokenResponse($this->userFor($userId));
    }

    /**
     * GET — list the caller's passkeys.
     *
     * The parent allows any method; a REST client that mistyped this as a POST
     * gets a straight answer rather than a list it was not entitled to ask for
     * that way.
     */
    public function list(): mixed
    {
        if ($this->requestMethod() !== 'GET') {
            return Response::json(['error' => 'method_not_allowed'], 405);
        }

        return parent::list();
    }

    /**
     * A request field, from the JSON body first and the form afterwards.
     *
     * Trimmed and cast like the parent's, so every downstream check (`$id <= 0`,
     * `$name === ''`) keeps behaving as it does on the web side even when the
     * client sent a JSON number rather than a string.
     */
    protected function input(string $key): string
    {
        $json = $this->jsonBody();
        if (array_key_exists($key, $json) && is_scalar($json[$key])) {
            return trim((string) $json[$key]);
        }

        return parent::input($key);
    }

    /**
     * The decoded JSON request body (empty array when absent or not an object).
     *
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        if ($this->jsonBodyCache !== null) {
            return $this->jsonBodyCache;
        }

        $raw     = trim($this->rawRequestBody());
        $decoded = $raw === '' ? null : json_decode($raw, true);

        return $this->jsonBodyCache = is_array($decoded) ? $decoded : [];
    }

    /**
     * The HTTP method, upper-cased.
     *
     * @codeCoverageIgnore — env seam; set directly in tests.
     */
    protected function requestMethod(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }
}
