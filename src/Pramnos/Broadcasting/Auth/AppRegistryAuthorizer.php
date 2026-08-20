<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Auth;

use Pramnos\Broadcasting\Apps\AppRegistryInterface;
use Pramnos\Broadcasting\Apps\BroadcastApp;

/**
 * Pusher-compatible authorizer that resolves the app — and therefore the signing
 * secret — per connection, from an {@see AppRegistryInterface}.
 *
 * {@see PusherAuthorizer} holds one key and one secret, which is correct for a
 * single-app deployment and is all the built-in server could ever do. It is also
 * the piece that stopped the AuthServer registry from reaching the edge: the
 * `applications` table can describe fifty apps, and the daemon could only ever
 * verify against the one pair in `app.php`.
 *
 * ## The app key comes out of the token
 *
 * A Pusher channel token is `"<appKey>:<hmac>"`. So this needs no per-connection
 * bookkeeping to know which secret to verify against — the client tells it, in the
 * one field it cannot lie about, because naming the wrong app produces an HMAC that
 * does not verify. That is why the protocol puts the key there.
 *
 * ## Every failure is the same failure
 *
 * An unknown app key, a disabled application, an app with no secret and a bad
 * signature all return false. A caller has no use for the difference, and telling
 * them apart would let somebody probing keys learn which ones exist.
 */
final class AppRegistryAuthorizer implements PresenceAuthorizer
{
    public function __construct(private readonly AppRegistryInterface $registry)
    {
    }

    /**
     * A connection is admitted when its app key names an active app.
     *
     * A key naming an app with no secret is admitted here and refused at the first
     * private channel: the app exists and its public channels are legitimately
     * usable, and refusing the connection outright would report a signing
     * misconfiguration as an authentication failure.
     */
    public function authorizeConnection(string $appKey, array $params = []): bool
    {
        return $this->resolve($appKey) !== null;
    }

    public function authorizeChannel(
        string $channel,
        string $socketId,
        string $auth,
        ?string $channelData = null
    ): bool {
        if (!ChannelRegistry::needsAuthorization($channel)) {
            return true;
        }

        $app = $this->appFromToken($auth);

        if ($app === null || !$app->canSign()) {
            return false;
        }

        // One string-to-sign definition, shared with PusherAuthSigner. Presence
        // channel data is included as the exact bytes the client presented: signing
        // a re-encoded copy would invalidate a token nobody tampered with.
        $stringToSign = $socketId . ':' . $channel;
        if (ChannelRegistry::isPresence($channel) && $channelData !== null && $channelData !== '') {
            $stringToSign .= ':' . $channelData;
        }

        $expected = $app->key . ':' . hash_hmac('sha256', $stringToSign, $app->secret);

        return hash_equals($expected, $auth);
    }

    /**
     * Decode the member identity from already-verified channel data.
     *
     * Reached only after {@see authorizeChannel()} accepted the subscription, so the
     * signature has already covered these bytes.
     */
    public function presenceMember(string $channel, string $socketId, ?string $channelData): ?array
    {
        if ($channelData === null || $channelData === '') {
            return null;
        }

        $decoded = json_decode($channelData, true);

        if (
            !is_array($decoded)
            || !isset($decoded['user_id'])
            || (string) $decoded['user_id'] === ''
        ) {
            return null;
        }

        $member = ['user_id' => (string) $decoded['user_id']];

        if (isset($decoded['user_info']) && is_array($decoded['user_info'])) {
            $member['user_info'] = $decoded['user_info'];
        }

        return $member;
    }

    /**
     * The app named by the key half of a `"<appKey>:<hmac>"` token.
     */
    private function appFromToken(string $auth): ?BroadcastApp
    {
        if ($auth === '') {
            return null;
        }

        $separator = strpos($auth, ':');
        if ($separator === false || $separator === 0) {
            return null;
        }

        return $this->resolve(substr($auth, 0, $separator));
    }

    private function resolve(string $appKey): ?BroadcastApp
    {
        if ($appKey === '') {
            // No key presented: a single-app registry still has a default, a
            // multi-tenant one does not and refuses.
            return $this->registry->defaultApp();
        }

        return $this->registry->findByKey($appKey);
    }
}
