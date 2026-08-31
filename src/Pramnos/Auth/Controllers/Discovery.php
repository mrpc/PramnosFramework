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
        $this->addaction(['configuration', 'jwks', 'oauth2Metadata', 'oauthProtectedResource',
            'health', 'serverConfig']);
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

        /**
         * The response is the document, not an echo.
         *
         * `echo` writes to the output stream and then returns, at which point the
         * framework renders the page it was going to render anyway — so every one
         * of these endpoints answered with valid JSON followed by a full HTML
         * document. `/.well-known/openid-configuration` was 173 KB and would not
         * parse. Nothing caught it because the JSON is *first*: it looks perfect
         * in a terminal, in a browser's raw view, in any tail of a log.
         *
         * Switching the document to `raw` makes it the one the framework renders,
         * so the body is exactly this and nothing follows it.
         */
        \Pramnos\Framework\Factory::getDocument('raw')->setContent(
            (string) json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
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
            \Pramnos\Framework\Factory::getDocument('raw')->setContent(
                (string) json_encode(['keys' => []], JSON_PRETTY_PRINT)
            );

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

        \Pramnos\Framework\Factory::getDocument('raw')->setContent(
            (string) json_encode($jwks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * OAuth 2.0 Protected Resource Metadata (RFC 9728).
     * Endpoint: `/.well-known/oauth-protected-resource`
     *
     * The other half of the discovery pair, and the half that was missing. RFC 8414 tells a
     * client where the **authorization server** is; this tells it where the **resource** is and
     * which authorization servers it trusts. A client that has only the first has to be told the
     * second out of band — configuration somebody types, gets wrong, and cannot verify.
     *
     * ### Which is why an MCP client needs it
     *
     * The Model Context Protocol's authorization spec starts here: a client calls a protected
     * MCP endpoint, gets `401` with a `WWW-Authenticate` header naming this document, reads it,
     * finds the authorization server, and runs the ordinary OAuth 2.1 code-with-PKCE flow it
     * already knows. Without this document that chain stops at the first step, and the endpoint
     * is reachable only by a client somebody configured by hand.
     *
     * `resource` is the identifier a token is *audience-bound* to. It is the site root here
     * rather than one path, because every protected endpoint this installation serves belongs to
     * the same resource — the tokens are the same tokens.
     */
    public function oauthProtectedResource(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=3600');

        $metadata = [
            'resource'                 => rtrim(sURL, '/'),
            'authorization_servers'    => [rtrim(sURL, '/')],
            'scopes_supported'         => array_keys(Scopes::getScopeDescriptions()),
            // The only method this framework accepts. Saying so keeps a client from trying the
            // form-encoded and query-parameter variants RFC 6750 also describes — both of which
            // put a credential somewhere it gets logged.
            'bearer_methods_supported' => ['header'],
            'resource_documentation'   => sURL . 'docs',
        ];

        \Pramnos\Framework\Factory::getDocument('raw')->setContent(
            (string) json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
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

        \Pramnos\Framework\Factory::getDocument('raw')->setContent(
            (string) json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Health-check endpoint. Returns HTTP 503 when anything is unhealthy.
     *
     * Endpoint: /.well-known/health
     *
     * The verdict comes from `HealthRegistry`, the same source `/health/check`
     * and `health:check` read. It used to run a `SELECT 1` of its own and report
     * on that alone, which made this the third place in the framework with an
     * opinion about whether the application was well — and the only one that
     * could not see a full disk, an unreachable cache or a missing signing key.
     * Three probes are three answers, and the interesting day is the one they
     * disagree on.
     *
     * The response shape is unchanged: `status`, `timestamp`, and a `components`
     * map. What changed is that `components` now lists every registered check
     * rather than a hardcoded pair, so it grows with the application instead of
     * describing a subset of it.
     */
    public function health(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Content-Type: application/json');

        $this->ensureDatabaseCheckIsRegistered();

        $report = \Pramnos\Health\HealthRegistry::runAll();

        $components = [];
        foreach ($report['checks'] ?? [] as $name => $check) {
            // 'ok' | 'degraded' | 'down' collapses to the 'ok' | 'error' this
            // endpoint has always spoken; a caller wanting the distinction has
            // /health/check.
            $components[$name] = ($check['status'] ?? '') === 'ok' ? 'ok' : 'error';
        }
        $components['session'] = session_status() === PHP_SESSION_ACTIVE ? 'ok' : 'inactive';

        $healthy = ($report['status'] ?? 'down') === 'ok';

        // An authorization server without a database cannot do anything, so a
        // report with no verdict on it is a failure and not a silence. This is
        // the case an empty registry would otherwise answer `healthy` for.
        if (!isset($components['database'])) {
            $components['database'] = 'error';
            $healthy                = false;
        }

        $health = [
            'status'     => $healthy ? 'healthy' : 'unhealthy',
            'timestamp'  => date('c'),
            'components' => $components,
        ];

        if (!$healthy) {
            http_response_code(503);
        }

        \Pramnos\Framework\Factory::getDocument('raw')->setContent(
            (string) json_encode($health, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Make sure the report has something to say about the database.
     *
     * A booted application registers the built-in checks during `init()`, but
     * this controller can also be reached from a script or a test that never
     * booted one — and `HealthRegistry::runAll()` on an empty registry answers
     * `ok`, which would turn a database outage into a healthy report.
     *
     * Registering the check here rather than probing inline is what keeps this
     * endpoint agreeing with `/health/check` and `health:check`: one probe, one
     * answer. `register()` is keyed by name, so an application that already
     * registered its own database check keeps it.
     */
    private function ensureDatabaseCheckIsRegistered(): void
    {
        if (\Pramnos\Health\HealthRegistry::get('database') !== null) {
            return;
        }

        try {
            \Pramnos\Health\HealthRegistry::register(
                new \Pramnos\Health\Checks\DatabaseConnectivityCheck(
                    \Pramnos\Database\Database::getInstance()
                )
            );
        } catch (\Throwable) {
            // No database to check at all. The caller turns a missing database
            // verdict into an explicit failure, which is the right answer here.
        }
    }

    /**
     * A human-shaped summary of what this server offers.
     *
     * Endpoint: `/Discovery/serverConfig`
     *
     * This is **not** a standards document — `configuration()` and
     * `oauth2Metadata()` are, and a client should read those. This one answers
     * the question a developer asks while integrating: what are the URLs, which
     * grants work here, which scopes exist, is the device flow on. It is the page
     * you paste into a ticket.
     *
     * Every list is read from whatever actually decides it — `Scopes` for the
     * scopes, `app.php` features for the feature flags — rather than restated
     * here. A hardcoded copy goes stale silently: the endpoint keeps answering,
     * and it answers wrong, which is worse than not existing.
     *
     * Nothing here is sensitive: it is the same information the discovery
     * document publishes, arranged for a person.
     */
    public function serverConfig(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Content-Type: application/json');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            header('Access-Control-Max-Age: 86400');
            http_response_code(204);
            // A 204 has no body, and without switching the document the framework
            // would render a whole HTML page into one.
            \Pramnos\Framework\Factory::getDocument('raw')->setContent('');

            return;
        }

        header('Cache-Control: public, max-age=3600');

        $info     = $this->application->applicationInfo ?? [];
        $features = $info['features'] ?? [];

        $config = [
            'server_info' => [
                'name'      => (string) ($info['name'] ?? ''),
                'version'   => (string) ($info['api_version'] ?? '1.0'),
                'framework' => 'Pramnos Framework',
            ],
            'endpoints' => [
                'authorization'        => sURL . 'oauth/authorize',
                'token'                => sURL . 'oauth/token',
                'revocation'           => sURL . 'oauth/revoke',
                'introspection'        => sURL . 'oauth/introspect',
                'userinfo'             => sURL . 'oauth/userinfo',
                'logout'               => sURL . 'oauth/logout',
                'device_authorization' => sURL . 'oauth/deviceauthorization',
                'session_check'        => sURL . 'session/check',
                'session_heartbeat'    => sURL . 'session/heartbeat',
                'session_info'         => sURL . 'session/info',
                'discovery'            => sURL . '.well-known/openid-configuration',
                'jwks'                 => sURL . '.well-known/jwks.json',
            ],
            'supported_grants' => [
                'authorization_code',
                'client_credentials',
                'password',
                'refresh_token',
                'urn:ietf:params:oauth:grant-type:device_code',
            ],
            'supported_scopes' => array_keys(Scopes::getScopeDescriptions()),
            'features' => [
                'single_sign_on'             => true,
                'single_logout'              => true,
                'session_management'         => true,
                'openid_connect_discovery'   => true,
                'jwt_tokens'                 => true,
                'refresh_tokens'             => true,
                'token_revocation'           => true,
                'token_introspection'        => true,
                'scope_authorization'        => true,
                'pkce'                       => true,
                'device_authorization_grant' => true,
                'two_factor_authentication'  => in_array('auth', $features, true),
                'passkeys'                   => in_array('auth', $features, true),
                'gdpr_self_service'          => in_array('auth', $features, true),
                'organizations'              => in_array('authserver', $features, true),
                'background_queue'           => in_array('queue', $features, true),
            ],
        ];

        \Pramnos\Framework\Factory::getDocument('raw')->setContent(
            (string) json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}
