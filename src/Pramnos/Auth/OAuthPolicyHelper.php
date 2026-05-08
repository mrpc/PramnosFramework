<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * OAuth2 server-wide policy defaults.
 *
 * Provides the default lists of allowed authentication methods and grant types
 * for OAuth2 clients that have no explicit per-client policy rows in the
 * database. These defaults are applied by the OAuth authorization server when
 * a client does not appear in the policy tables.
 *
 * 'none' (public client / PKCE flow) is intentionally excluded from the
 * default auth methods — it must be opted in explicitly per client because it
 * removes all client authentication.
 *
 * 'password' grant is excluded (deprecated by RFC 9126 / OAuth 2.1).
 *
 * @package     PramnosFramework
 * @subpackage  Auth
 */
class OAuthPolicyHelper
{
    /**
     * Client authentication methods allowed by default.
     *
     * Clients that do not have explicit rows in `oauth2_client_auth_methods`
     * are permitted to use any method in this list.
     *
     * @return string[]
     */
    public static function getDefaultAllowedAuthMethods(): array
    {
        return [
            'client_secret_basic',
            'client_secret_post',
            'private_key_jwt',
        ];
    }

    /**
     * Grant types allowed by default.
     *
     * Clients that do not have explicit rows in `oauth2_application_grants`
     * are permitted to use any grant type in this list.
     *
     * @return string[]
     */
    public static function getDefaultAllowedGrantTypes(): array
    {
        return [
            'authorization_code',
            'client_credentials',
            'device_code',
            'refresh_token',
            'exchange_token',
        ];
    }
}
