<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2;

/**
 * The OpenID Connect claims a user's scopes grant — for `/oauth/userinfo` and the ID token.
 *
 * One builder for both, because a relying party reads the same claim from either and they must
 * agree: `email` gives `email` and `email_verified`, `profile` the name claims, `phone`
 * `phone_number`, `user` this framework's `maingroup` and `regdate`. `sub` always.
 */
final class UserClaims
{
    /**
     * @param list<string> $scopes
     * @return array<string, mixed>
     */
    public static function for(int $userId, array $scopes): array
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('#PREFIX#users')
            ->where('userid', $userId)
            ->where('active', 1)
            ->first();

        if (!$result || $result->numRows == 0) {
            return ['sub' => (string) $userId];
        }

        $u       = (array) $result->fields;
        $payload = ['sub' => (string) $userId];

        if (in_array('email', $scopes, true)) {
            $payload['email']          = $u['email'] ?? '';
            $payload['email_verified'] = isset($u['validated']) && in_array((int) $u['validated'], [1, 3], true);
        }

        if (in_array('profile', $scopes, true)) {
            $payload['name']               = trim(($u['firstname'] ?? '') . ' ' . ($u['lastname'] ?? ''));
            $payload['given_name']         = $u['firstname']  ?? '';
            $payload['family_name']        = $u['lastname']   ?? '';
            $payload['preferred_username'] = $u['username']   ?? '';
            $payload['updated_at']         = $u['modified']   ?? null;
            $payload['picture']            = $u['avatarurl']  ?? null;
            $payload['website']            = $u['website']    ?? null;
        }

        if (in_array('phone', $scopes, true)) {
            $payload['phone_number'] = $u['mobile'] ?? $u['phone'] ?? null;
        }

        if (in_array('user', $scopes, true)) {
            $payload['maingroup'] = $u['maingroup'] ?? null;
            $payload['regdate']   = $u['regdate']   ?? null;
        }

        return $payload;
    }
}
