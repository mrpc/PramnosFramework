<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Auth\Scopes;
use Pramnos\Application\Controller;

/**
 * OpenID Connect and OAuth 2.0 discovery endpoints.
 *
 * All actions are public (no authentication required).
 *
 */
class Discovery extends Controller
{
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addaction(['configuration', 'jwks', 'oauth2Metadata', 'health']);
        parent::__construct($application);
    }

    /**
     * OpenID Connect discovery document (RFC 8414 + OpenID Core §4).
     * Endpoint: /.well-known/openid-configuration
     */
    public function configuration(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=3600');

        $config = [
            'issuer'                               => sURL,
            'authorization_endpoint'               => sURL . 'oauth/authorize',
            'token_endpoint'                       => sURL . 'oauth/token',
            'userinfo_endpoint'                    => sURL . 'oauth/userinfo',
            'logout_endpoint'                      => sURL . 'oauth/logout',
            'session_check_endpoint'               => sURL . 'session/check',
            'session_heartbeat_endpoint'           => sURL . 'session/heartbeat',
            'device_authorization_endpoint'        => sURL . 'oauth/deviceauthorization',
            'jwks_uri'                             => sURL . '.well-known/jwks.json',
            'end_session_endpoint'                 => sURL . 'logout',

            'response_types_supported' => [
                'code', 'token', 'id_token',
                'code id_token', 'code token',
                'id_token token', 'code id_token token',
            ],
            'response_modes_supported' => ['query', 'fragment', 'form_post'],
            'grant_types_supported'    => [
                'authorization_code', 'client_credentials',
                'password', 'refresh_token', 'implicit',
            ],
            'scopes_supported'                          => array_keys(Scopes::getScopeDescriptions()),
            'token_endpoint_auth_methods_supported'     => [
                'client_secret_basic', 'client_secret_post',
                'private_key_jwt', 'none',
            ],
            'subject_types_supported'                   => ['public'],
            'id_token_signing_alg_values_supported'     => ['RS256'],
            'userinfo_signing_alg_values_supported'     => ['RS256', 'none'],
            'request_parameter_supported'               => false,
            'request_uri_parameter_supported'           => false,
            'claims_supported'                          => [
                'sub', 'iss', 'aud', 'exp', 'iat',
                'name', 'email', 'email_verified',
                'preferred_username', 'given_name', 'family_name', 'locale',
            ],
            'revocation_endpoint'                       => sURL . 'oauth/revoke',
            'introspection_endpoint'                    => sURL . 'oauth/introspect',
            'registration_endpoint'                     => sURL . 'register',
            'frontchannel_logout_supported'             => false,
            'frontchannel_logout_session_supported'     => false,
            'backchannel_logout_supported'              => true,
            'backchannel_logout_session_supported'      => true,
            'code_challenge_methods_supported'          => ['S256', 'plain'],
            'service_documentation'                     => sURL . 'docs',
            'ui_locales_supported'                      => ['en', 'el'],
        ];

        echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return;
    }

    /**
     * JSON Web Key Set (JWKS) endpoint — exposes the RSA public key used to
     * sign JWTs so relying parties can verify token signatures without
     * contacting the authorization server.
     *
     * Endpoint: /.well-known/jwks.json
     */
    public function jwks(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=86400');

        $publicKeyPath = ROOT . '/app/keys/public.key';

        if (!file_exists($publicKeyPath)) {
            echo json_encode(['keys' => []], JSON_PRETTY_PRINT);
            return;
        }

        try {
            $publicKeyContent = file_get_contents($publicKeyPath);
            $publicKey        = openssl_pkey_get_public($publicKeyContent);

            if ($publicKey === false) {
                throw new \RuntimeException('Failed to read public key');
            }

            $keyDetails = openssl_pkey_get_details($publicKey);

            if ($keyDetails !== false && $keyDetails['type'] === OPENSSL_KEYTYPE_RSA) {
                // Modulus and exponent as base64url (RFC 7517)
                $n = rtrim(strtr(base64_encode($keyDetails['rsa']['n']), '+/', '-_'), '=');
                $e = rtrim(strtr(base64_encode($keyDetails['rsa']['e']), '+/', '-_'), '=');

                $jwks = [
                    'keys' => [[
                        'kty' => 'RSA',
                        'use' => 'sig',
                        'kid' => 'auth-key-1',
                        'alg' => 'RS256',
                        'n'   => $n,
                        'e'   => $e,
                    ]],
                ];
            } else {
                $jwks = ['keys' => []];
            }
        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::log('JWKS Error: ' . $ex->getMessage());
            $jwks = ['keys' => []];
        }

        echo json_encode($jwks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return;
    }

    /**
     * OAuth 2.0 Authorization Server Metadata (RFC 8414).
     * Endpoint: /.well-known/oauth-authorization-server
     */
    public function oauth2Metadata(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=3600');

        $metadata = [
            'issuer'                                => sURL,
            'authorization_endpoint'                => sURL . 'oauth/authorize',
            'token_endpoint'                        => sURL . 'oauth/token',
            'registration_endpoint'                 => sURL . 'register',
            'scopes_supported'                      => array_keys(Scopes::getScopeDescriptions()),
            'response_types_supported'              => ['code', 'token'],
            'grant_types_supported'                 => [
                'authorization_code', 'client_credentials',
                'password', 'refresh_token',
            ],
            'token_endpoint_auth_methods_supported' => [
                'client_secret_basic', 'client_secret_post',
            ],
            'revocation_endpoint'                   => sURL . 'oauth/revoke',
            'introspection_endpoint'                => sURL . 'oauth/introspect',
        ];

        echo json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return;
    }

    /**
     * Health-check endpoint — verifies database connectivity.
     * Returns HTTP 503 when any component is unhealthy.
     *
     * Endpoint: /.well-known/health
     */
    public function health(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Content-Type: application/json');

        $dbStatus = 'ok';

        try {
            $database = \Pramnos\Database\Database::getInstance();
            $sql      = $database->prepareQuery('SELECT 1 AS test');
            $result   = $database->query($sql);
            if (!$result || $result->numRows == 0) {
                $dbStatus = 'error';
            }
        } catch (\Exception $ex) {
            $dbStatus = 'error';
        }

        $health = [
            'status'     => $dbStatus === 'ok' ? 'healthy' : 'unhealthy',
            'timestamp'  => date('c'),
            'components' => [
                'database' => $dbStatus,
                'session'  => session_status() === PHP_SESSION_ACTIVE ? 'ok' : 'inactive',
            ],
        ];

        if ($health['status'] !== 'healthy') {
            http_response_code(503);
        }

        echo json_encode($health, JSON_PRETTY_PRINT);
        return;
    }
}
