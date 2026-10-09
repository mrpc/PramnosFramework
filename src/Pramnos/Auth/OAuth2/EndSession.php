<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2;

use Pramnos\Database\Database;

/**
 * An application asking this server to sign its user out (OpenID Connect RP-Initiated Logout 1.0).
 *
 * The browser arrives at the end-session endpoint with any of:
 *
 * - `id_token_hint` — an ID token this server issued; names the client and the user. It may have
 *   expired, but must be validly signed and must be about the user signed in here.
 * - `client_id` — the client, when there is no hint; with one, it must be among the hint's `aud`.
 * - `post_logout_redirect_uri` — where to send the browser afterwards. Matched exactly against the
 *   client's registered redirect URIs and its homepage, so the endpoint cannot be used to send a
 *   signed-out user anywhere a link names.
 * - `state` — handed back on that redirect.
 * - `local=1` — end this browser session and leave the client's tokens valid. Without it, the
 *   user's tokens for that client are revoked too.
 *
 * Anything that does not check out still signs the browser out: the user asked to leave. It only
 * loses the redirect, and the tokens are left alone when the request cannot be tied to the user.
 */
class EndSession
{
    /**
     * @param Database $database      Where clients and tokens are read
     * @param string   $publicKeyPath This server's ID-token verification key
     */
    public function __construct(
        private readonly Database $database,
        private readonly string $publicKeyPath
    ) {
    }

    /**
     * Decide what a logout request may do.
     *
     * @param array<string, mixed> $params The request's parameters
     * @param int|null             $userId The user signed in here, before the sign-out
     * @return array{redirect: ?string, appid: int, revoke: bool, refused: ?string}
     *         Where to go (null: the server's own sign-in page), the client, whether to revoke the
     *         user's tokens for it, and why anything was refused
     */
    public function resolve(array $params, ?int $userId): array
    {
        $answer   = ['redirect' => null, 'appid' => 0, 'revoke' => false, 'refused' => null];
        $clientId = is_string($params['client_id'] ?? null) ? trim($params['client_id']) : '';
        $hint     = is_string($params['id_token_hint'] ?? null) ? trim($params['id_token_hint']) : '';

        $hintAboutUser = false;
        if ($hint !== '') {
            $claims = $this->verifiedHint($hint);
            if ($claims === null) {
                return ['refused' => 'The id_token_hint is not an ID token this server issued.'] + $answer;
            }
            $audiences = array_map('strval', is_array($claims->aud ?? null) ? $claims->aud : [$claims->aud ?? '']);
            if ($clientId !== '' && !in_array($clientId, $audiences, true)) {
                return ['refused' => 'The client_id is not the id_token_hint\'s audience.'] + $answer;
            }
            $clientId = $clientId !== '' ? $clientId : $audiences[0];
            if ($userId !== null && (string) ($claims->sub ?? '') !== (string) $userId) {
                return ['refused' => 'The id_token_hint is about another user.'] + $answer;
            }
            $hintAboutUser = $userId !== null;
        }

        if ($clientId === '') {
            return $answer;
        }
        $client = $this->database->queryBuilder()->table('#PREFIX#applications')
            ->where('apikey', $clientId)->where('status', 1)->first();
        if (!$client || $client->numRows === 0) {
            return ['refused' => 'Unknown client.'] + $answer;
        }
        $answer['appid'] = (int) $client->fields['appid'];
        // Revoking needs the request tied to the user: a link with a bare client_id is one
        // anybody can put on a page, and it should not end a user's sessions in that app.
        $answer['revoke'] = $hintAboutUser && !in_array((string) ($params['local'] ?? ''), ['1', 'true'], true);

        $uri = is_string($params['post_logout_redirect_uri'] ?? null) ? $params['post_logout_redirect_uri'] : '';
        if ($uri === '') {
            return $answer;
        }
        $allowed = \Pramnos\Auth\Application::parseRedirectUris((string) ($client->fields['callback'] ?? ''));
        if (trim((string) ($client->fields['url'] ?? '')) !== '') {
            $allowed[] = trim((string) $client->fields['url']);
        }
        if (!in_array($uri, $allowed, true)) {
            return ['refused' => 'The post_logout_redirect_uri is not registered for this client.'] + $answer;
        }

        $state = is_string($params['state'] ?? null) ? $params['state'] : '';
        $answer['redirect'] = $state === ''
            ? $uri
            : $uri . (str_contains($uri, '?') ? '&' : '?') . 'state=' . rawurlencode($state);

        return $answer;
    }

    /**
     * Revoke a user's active tokens for one client. Returns how many.
     */
    public function revoke(int $userId, int $appId): int
    {
        $tokens = fn () => $this->database->queryBuilder()->table('#PREFIX#usertokens')
            ->where('userid', $userId)->where('applicationid', $appId)->where('status', 1)
            ->whereIn('tokentype', ['access_token', 'refresh_token']);

        $count = $tokens()->count();
        if ($count > 0) {
            $tokens()->update(['status' => 0]);
        }

        return $count;
    }

    /**
     * The claims of an ID token this server signed, expired or not; null for anything else.
     *
     * Expiry is checked after the signature, so an expired token reaching that check is one
     * this server signed — which is all a hint needs to be.
     */
    private function verifiedHint(string $hint): ?object
    {
        $key = is_readable($this->publicKeyPath) ? (string) file_get_contents($this->publicKeyPath) : '';
        if ($key === '') {
            return null;
        }

        try {
            return \Pramnos\Auth\JWT::decode($hint, $key, ['RS256']);
        } catch (\Pramnos\Auth\ExpiredException) {
            return \Pramnos\Auth\JWT::decodeUnverified($hint) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
