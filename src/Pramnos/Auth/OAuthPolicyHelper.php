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

    /**
     * Descriptive registry of every supported OAuth2 client authentication method.
     *
     * Each entry carries a machine-readable `method` key, a human-readable `name`,
     * and a `description` suitable for display in developer-facing UIs.
     *
     * @return array<int, array{method: string, name: string, description: string}>
     */
    public static function getAuthenticationMethods(): array
    {
        return [
            [
                'method'      => 'client_secret_post',
                'name'        => 'Client Secret Post',
                'description' => 'The client sends `client_id` and `client_secret` as POST body parameters.',
            ],
            [
                'method'      => 'private_key_jwt',
                'name'        => 'JWT Client Assertion',
                'description' => 'The client authenticates by signing a JWT with its private key (RFC 7523 — JWT Client Assertion).',
            ],
            [
                'method'      => 'none',
                'name'        => 'None / PKCE',
                'description' => 'Public client using the Authorization Code flow with PKCE. No client secret is required.',
            ],
            [
                'method'      => 'client_secret_basic',
                'name'        => 'Client Secret Basic',
                'description' => 'The client authenticates via an HTTP Basic Authorization header (Base64-encoded client_id:client_secret).',
            ],
        ];
    }

    /**
     * Descriptive registry of every supported OAuth2 grant type.
     *
     * Each entry carries a machine-readable `method` key, a human-readable `name`,
     * and a `description` suitable for display in developer-facing UIs.
     *
     * @return array<int, array{method: string, name: string, description: string}>
     */
    public static function getGrantTypes(): array
    {
        return [
            [
                'method'      => 'authorization_code',
                'name'        => 'Authorization Code',
                'description' => 'Standard three-legged OAuth2 flow; the client exchanges an authorization code for tokens.',
            ],
            [
                'method'      => 'password',
                'name'        => 'Resource Owner Password Credentials',
                'description' => 'The user provides credentials directly to the client (deprecated by OAuth 2.1).',
            ],
            [
                'method'      => 'client_credentials',
                'name'        => 'Client Credentials',
                'description' => 'Machine-to-machine flow; the client authenticates using its own credentials with no user context.',
            ],
            [
                'method'      => 'refresh_token',
                'name'        => 'Refresh Token',
                'description' => 'Obtain a new access token by presenting a previously issued refresh token.',
            ],
            [
                'method'      => 'device_code',
                'name'        => 'Device Code',
                'description' => 'For input-constrained devices; the user authorizes on a secondary device.',
            ],
            [
                'method'      => 'exchange_token',
                'name'        => 'Exchange Token',
                'description' => 'Exchange one token type for another (e.g., a short-lived token for a long-lived one).',
            ],
            [
                'method'      => 'jwt_bearer',
                'name'        => 'JWT Bearer Grant',
                'description' => 'Service-to-service delegated user identity (RFC 7523 §2.1). The client presents a signed JWT assertion to obtain a token on behalf of a user.',
            ],
        ];
    }

    /**
     * Registry of webhook event types the OAuth server can dispatch.
     *
     * Each entry carries a machine-readable `type` key, a human-readable `name`,
     * and a `description` suitable for display in developer-facing UIs.
     *
     * @return array<int, array{type: string, name: string, description: string}>
     */
    public static function getWebhookTypes(): array
    {
        return [
            [
                'type'        => 'user_deauthorized',
                'name'        => 'User Deauthorized',
                'description' => 'A user has revoked authorization for the application.',
            ],
            [
                'type'        => 'token_revoked',
                'name'        => 'Token Revoked',
                'description' => 'An access or refresh token has been revoked.',
            ],
            [
                'type'        => 'gdpr_request',
                'name'        => 'GDPR Request',
                'description' => 'A GDPR action (e.g., data export or deletion) has been requested.',
            ],
            [
                'type'        => 'user_profile_changed',
                'name'        => 'User Profile Changed',
                'description' => "A user's profile information has been updated.",
            ],
            [
                'type'        => 'device_deauthorized',
                'name'        => 'Device Deauthorized',
                'description' => 'A device has been deauthorized.',
            ],
            [
                'type'        => 'account_deleted',
                'name'        => 'Account Deleted',
                'description' => 'A user account has been permanently deleted.',
            ],
            [
                'type'        => 'scope_changed',
                'name'        => 'Permissions Changed',
                'description' => 'The scopes granted to the application by a user have changed.',
            ],
        ];
    }
}
