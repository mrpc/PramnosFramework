<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Client;

use Pramnos\Http\Client;

/**
 * The authorization-code conversation with a provider. No database, no session.
 *
 * This is the protocol half and nothing else: build the URL the browser is sent to,
 * exchange a code for tokens, exchange a refresh token for new ones. Everything that
 * persists — which user began the flow, where the tokens are kept, when they are
 * refreshed — is {@see ConnectionStore}'s. Keeping them apart is what makes this
 * testable against `Client::fake()` without a database, and it is the half that has to be
 * right: a mistake here is a security bug, and a mistake in storage is a bug.
 *
 * ```php
 * $client = new OAuthClient($provider);
 *
 * // 1. Send the user off. $state and $verifier are yours to keep until they come back.
 * [$url, $state, $verifier] = $client->authorizationUrl();
 *
 * // 2. They come back with ?code=…&state=…
 * $tokens = $client->exchange($code, $verifier);
 * ```
 *
 * **The `state` parameter is not optional and is not decoration.** Without it, anyone can
 * hand a signed-in user a link that completes *the attacker's* authorization flow, and the
 * application attaches the attacker's account to the victim's user — a login CSRF that
 * leaves no trace, because every step of it succeeded. {@see verifyState()} is a
 * constant-time comparison for the same reason password checks are.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
class OAuthClient
{
    /**
     * How long to wait on a provider's token endpoint.
     *
     * Short on purpose. This runs inside a request the user is watching — they are sitting
     * on a redirect back from Google — and a provider having a bad day should be an error
     * page rather than a worker held open until something else times out.
     */
    private const TIMEOUT = 15;

    /**
     * A ceiling on the token response.
     *
     * A token response is a few hundred bytes. This is not a real limit, it is a refusal
     * to read an unbounded body from a host that is only supposed to be sending JSON.
     */
    private const MAX_RESPONSE_BYTES = 256 * 1024;

    public function __construct(private readonly Provider $provider)
    {
    }

    /**
     * Where to send the browser, and the two secrets to keep until it comes back.
     *
     * The caller stores `$state` and `$verifier` somewhere tied to this user and this
     * attempt — the session is the usual answer — and produces them again in
     * {@see exchange()}. They are returned rather than stored here because this class has
     * no opinion about where a half-finished flow lives, and a SPA keeps it somewhere a
     * server-rendered page does not.
     *
     * @return array{0: string, 1: string, 2: string} The URL, the `state`, the PKCE
     *         verifier (empty when the provider is not configured for PKCE)
     */
    public function authorizationUrl(): array
    {
        $state    = bin2hex(random_bytes(32));
        $verifier = '';

        $query = [
            $this->provider->clientIdParam => $this->provider->clientId,
            'redirect_uri'  => $this->provider->redirectUri,
            'response_type' => 'code',
            'state'         => $state,
        ];

        if ($this->provider->scopes !== []) {
            $query['scope'] = $this->provider->scopeString();
        }

        if ($this->provider->usePkce) {
            // RFC 7636: 43–128 characters from an unreserved alphabet. 32 random bytes
            // base64url-encoded is 43, the shortest the specification allows and the
            // length every provider is known to accept.
            $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $query['code_challenge']        = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            // S256 rather than `plain`, always. `plain` puts the verifier in the URL,
            // which is the thing PKCE exists to avoid.
            $query['code_challenge_method'] = 'S256';
        }

        $query = array_merge($query, $this->provider->authorizeParams);

        return [
            $this->provider->authorizeUrl
                . (str_contains($this->provider->authorizeUrl, '?') ? '&' : '?')
                . http_build_query($query),
            $state,
            $verifier,
        ];
    }

    /**
     * Is the `state` that came back the one that went out?
     *
     * `hash_equals` rather than `===`: the comparison is against a secret an attacker is
     * trying to guess, and a byte-by-byte comparison that returns early leaks how much of
     * the guess was right. The same reason it is used for a password hash.
     */
    public function verifyState(string $expected, string $received): bool
    {
        return $expected !== '' && hash_equals($expected, $received);
    }

    /**
     * Exchange an authorization code for tokens.
     *
     * @param string $code     The `code` query parameter the provider sent back
     * @param string $verifier The PKCE verifier from {@see authorizationUrl()}, if any
     * @return TokenSet
     * @throws OAuthClientException when the provider refuses, or answers with something
     *         that is not a token response
     */
    public function exchange(string $code, string $verifier = ''): TokenSet
    {
        $form = [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $this->provider->redirectUri,
        ];

        if ($verifier !== '') {
            $form['code_verifier'] = $verifier;
        }

        return $this->post($this->provider->tokenUrl, 'POST', $form);
    }

    /**
     * Exchange a refresh token for a new access token.
     *
     * The new token set may or may not carry a new refresh token: some providers rotate
     * them on every use, some return the same one, some return none and mean "keep the one
     * you have". {@see TokenSet::mergedInto()} is where that is resolved — losing a refresh
     * token because the provider did not repeat it kills the connection at the next expiry.
     *
     * @throws OAuthClientException
     */
    public function refresh(string $refreshToken): TokenSet
    {
        return $this->post(
            $this->provider->refreshEndpoint(),
            $this->provider->refreshMethod,
            [
                'grant_type'    => $this->provider->refreshGrantType,
                'refresh_token' => $refreshToken,
            ]
        );
    }

    /**
     * One request to a token endpoint, with the credentials wherever this provider wants them.
     *
     * @param array<string,string> $form
     * @throws OAuthClientException
     */
    private function post(string $url, string $method, array $form): TokenSet
    {
        /*
         * **No `Security\OutboundUrl` check here, deliberately.**
         *
         * That guard is for a URL a visitor supplied, and it refuses anything resolving
         * inside this network. A token endpoint is configuration — an environment
         * variable a deployment sets — and an identity provider on a private address is
         * an ordinary arrangement rather than an attack: Keycloak on an internal host,
         * an enterprise directory behind a private endpoint, a staging provider on a VPN.
         * Applying the guard here refuses all of them, and the error reads as a network
         * fault rather than as a policy.
         *
         * The case where it *would* be right is an application that lets an untrusted
         * party configure a provider — a tenant bringing its own IdP. Then the URL is
         * input, and the application validates it before constructing the Provider, which
         * is where it knows that and this class does not. The guide says so.
         */
        $form = array_merge($form, $this->provider->tokenParams);

        if ($this->provider->authMethod === Provider::AUTH_BODY) {
            $form[$this->provider->clientIdParam]     = $this->provider->clientId;
            $form[$this->provider->clientSecretParam] = $this->provider->clientSecret;
        }

        // A refresh that is a GET carries its fields as query parameters; there is no body
        // for them to be in. Instagram is the provider that needs this.
        $request = strtoupper($method) === 'GET'
            ? Client::get($url . (str_contains($url, '?') ? '&' : '?') . http_build_query($form))
            : Client::post($url)->form($form);

        if ($this->provider->authMethod === Provider::AUTH_BASIC) {
            $request = $request->basicAuth($this->provider->clientId, $this->provider->clientSecret);
        }

        $response = $request
            ->header('Accept', 'application/json')
            ->timeout(self::TIMEOUT)
            ->maxResponseBytes(self::MAX_RESPONSE_BYTES)
            ->send();

        $payload = $response->json();
        if (!is_array($payload)) {
            /*
             * Not JSON, or JSON that is not an object. Worth its own branch rather than
             * falling through to "no access_token": the usual cause is a captive portal, a
             * proxy error page or an HTML maintenance notice, and "the provider returned
             * no access token" sends whoever reads it looking at scopes and credentials
             * instead of at the network.
             */
            throw new OAuthClientException(
                $this->provider->name . ' returned a ' . $response->status()
                    . ' that is not a JSON object',
                'invalid_response',
                $this->provider->name,
                $response->status()
            );
        }

        if (isset($payload['error']) || !isset($payload['access_token'])) {
            $error = (string) ($payload['error'] ?? 'invalid_response');

            /*
             * The provider's own description, and nothing of ours added to it. These
             * strings end up in a log and in an operator's hands, and the provider is the
             * only party that knows whether this was a revoked grant, a scope the user
             * declined or a clock that is wrong.
             */
            $description = (string) ($payload['error_description'] ?? $payload['error_message'] ?? '');

            throw new OAuthClientException(
                $this->provider->name . ': ' . ($description !== '' ? $description : $error),
                $error,
                $this->provider->name,
                $response->status()
            );
        }

        return TokenSet::fromResponse($payload);
    }
}
