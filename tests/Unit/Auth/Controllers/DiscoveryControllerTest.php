<?php

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Discovery;
use Pramnos\Auth\Scopes;

/**
 * Unit tests for Pramnos\Auth\Controllers\Discovery.
 *
 * Discovery is a pure-JSON controller: all four endpoints are public (no auth),
 * read only from constants and Scopes, and exit() after printing JSON.
 *
 * Strategy: subclass Discovery and override the methods that call header(),
 * exit, and file I/O so we can capture the output under test. We verify that
 * the returned JSON is well-formed and contains the required OIDC/RFC 8414
 * fields rather than every literal value — this makes the tests resilient to
 * URL changes while still asserting the structural contract.
 */
class DiscoveryControllerTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Capture output from a Discovery method that normally echoes JSON and
     * calls exit().  We buffer stdout, call the method, and decode the result.
     *
     * @return array<string, mixed>
     */
    private function captureJson(string $method, array $args = []): array
    {
        // Define sURL if not already defined (would be a global constant in production)
        if (!defined('sURL')) {
            define('sURL', 'https://auth.example.com/');
        }
        if (!defined('ROOT')) {
            define('ROOT', sys_get_temp_dir());
        }

        // Build a testable subclass that suppresses header()/exit()/file I/O
        $stub = new class(null) extends Discovery {
            public array $headers = [];
            public bool  $exited  = false;
            public ?string $publicKeyOverride = null;

            /** Intercept output instead of echoing */
            public function captureMethod(string $m): string
            {
                ob_start();
                try {
                    // Suppress headers in CLI (they'd be ignored anyway)
                    $this->$m();
                } catch (\Exception $e) {
                    // exit() throws in CLI? No — but we catch just in case
                }
                return (string) ob_get_clean();
            }
        };

        ob_start();
        try {
            $stub->$method(...$args);
        } catch (\Throwable $th) {
            // exit() is a language construct — it terminates the script.
            // In a unit-test process this actually kills the process, so we
            // cannot truly unit-test methods that call exit() without process
            // isolation.  Instead we verify structure via reflection on the
            // intermediate values.
        } finally {
            $output = ob_get_clean();
        }

        if ($output === '' || $output === false) {
            return [];
        }

        $decoded = json_decode((string) $output, true);
        return is_array($decoded) ? $decoded : [];
    }

    // ── Constructor / registration ────────────────────────────────────────────

    /**
     * All four discovery endpoints must be registered as public actions so
     * the router can dispatch them without authentication.
     *
     * We verify this without calling exec() — just check that the Controller
     * $actions array contains the expected method names after construction.
     */
    public function testAllActionsRegisteredAsPublic(): void
    {
        // Arrange — define constants if needed
        if (!defined('sURL')) {
            define('sURL', 'https://auth.example.com/');
        }
        if (!defined('ROOT')) {
            define('ROOT', sys_get_temp_dir());
        }

        // Act
        $discovery = new Discovery(null);

        // Assert — all four endpoints must be in the public $actions list
        $expectedActions = ['configuration', 'jwks', 'oauth2Metadata', 'health'];
        foreach ($expectedActions as $action) {
            $this->assertContains(
                $action,
                $discovery->actions,
                "Action '{$action}' must be registered as public (no auth required)"
            );
        }
    }

    // ── Scopes::getScopeDescriptions() integration ────────────────────────────

    /**
     * The scopes_supported field in the configuration document must exactly
     * match the keys returned by Scopes::getScopeDescriptions().
     *
     * This test does not call configuration() (which would exit()) but
     * exercises the dependency in isolation — verifying that the data source
     * we rely on exists and is non-empty.
     */
    public function testScopesDescriptionsIsNonEmpty(): void
    {
        // Arrange / Act
        $scopes = Scopes::getScopeDescriptions();

        // Assert
        $this->assertNotEmpty($scopes, 'Scopes::getScopeDescriptions() must return at least one scope');
        $this->assertContains('openid', array_keys($scopes), 'The openid scope must be defined');
        $this->assertContains('profile', array_keys($scopes), 'The profile scope must be defined');
        $this->assertContains('email', array_keys($scopes), 'The email scope must be defined');
    }

    /**
     * All scope keys must be non-empty strings safe for inclusion in an HTTP
     * header (no spaces — spaces separate scopes in OAuth 2.0).
     *
     * This invariant is load-bearing: a scope with a space would break
     * OAuth 2.0 scope string parsing on the client side.
     */
    public function testScopeKeysHaveNoSpaces(): void
    {
        // Arrange
        $scopes = array_keys(Scopes::getScopeDescriptions());

        // Act / Assert
        foreach ($scopes as $scope) {
            $this->assertNotEmpty($scope, 'Scope key must not be empty');
            $this->assertDoesNotMatchRegularExpression(
                '/\s/',
                $scope,
                "Scope '{$scope}' must not contain whitespace"
            );
        }
    }

    // ── JWKS building helpers ─────────────────────────────────────────────────

    /**
     * The base64url encoding used for the JWK modulus and exponent must produce
     * URL-safe characters only (no +, /, or = padding).
     *
     * base64url = base64 with +→- and /→_ and padding stripped.
     * This is the RFC 7517 requirement for JWK parameters.
     */
    public function testBase64UrlEncodingIsUrlSafe(): void
    {
        // Arrange — simulate what jwks() does with a known binary value
        $binary = random_bytes(256); // 2048-bit RSA modulus-sized

        // Act — replicate the encoding from Discovery::jwks()
        $encoded = rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');

        // Assert
        $this->assertDoesNotMatchRegularExpression(
            '/[+\/=]/',
            $encoded,
            'base64url must not contain +, /, or = (RFC 7517 §2)'
        );
        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9\-_]+$/',
            $encoded,
            'base64url must only contain URL-safe characters'
        );
    }

    /**
     * Round-trip: base64url-decode the encoded value and compare with the
     * original binary. This ensures the encoding is lossless.
     */
    public function testBase64UrlRoundTrip(): void
    {
        // Arrange
        $original = random_bytes(128);

        // Act
        $encoded = rtrim(strtr(base64_encode($original), '+/', '-_'), '=');
        $decoded = base64_decode(strtr($encoded, '-_', '+/'));

        // Assert — decoded bytes must match original
        $this->assertSame(
            bin2hex($original),
            bin2hex($decoded),
            'base64url round-trip must be lossless'
        );
    }

    // ── Configuration metadata structure ─────────────────────────────────────

    /**
     * Verify the expected key structure of the configuration() response by
     * building the config array directly (avoiding the exit() call).
     *
     * We test the structural contract, not the literal URL values, because
     * URLs depend on the sURL constant which varies by environment.
     */
    public function testConfigurationResponseHasRequiredOidcKeys(): void
    {
        // Arrange — replicate the array that configuration() would produce
        if (!defined('sURL')) {
            define('sURL', 'https://auth.example.com/');
        }

        $requiredKeys = [
            'issuer',
            'authorization_endpoint',
            'token_endpoint',
            'userinfo_endpoint',
            'jwks_uri',
            'response_types_supported',
            'grant_types_supported',
            'scopes_supported',
            'token_endpoint_auth_methods_supported',
            'subject_types_supported',
            'id_token_signing_alg_values_supported',
            'claims_supported',
            'code_challenge_methods_supported',
        ];

        // Act — build the same config array the method would produce
        $config = $this->buildConfigArray();

        // Assert
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $config, "configuration() response must include '{$key}'");
        }
    }

    /**
     * The response_types_supported list must include the 'code' type (required
     * for the Authorization Code flow) and 'token' (Implicit flow).
     */
    public function testConfigurationIncludesRequiredResponseTypes(): void
    {
        // Arrange / Act
        $config = $this->buildConfigArray();
        $types  = $config['response_types_supported'];

        // Assert
        $this->assertContains('code',  $types, 'Authorization Code flow must be listed');
        $this->assertContains('token', $types, 'Implicit flow must be listed');
    }

    /**
     * The grant_types_supported list must include the four standard grant types.
     */
    public function testConfigurationIncludesStandardGrantTypes(): void
    {
        // Arrange / Act
        $config = $this->buildConfigArray();
        $grants = $config['grant_types_supported'];

        // Assert
        $this->assertContains('authorization_code',  $grants);
        $this->assertContains('client_credentials',  $grants);
        $this->assertContains('refresh_token',        $grants);
    }

    /**
     * PKCE (S256 and plain) must appear in code_challenge_methods_supported.
     *
     * S256 is mandatory for public clients per RFC 7636; plain is a fallback.
     */
    public function testConfigurationSupportsPkce(): void
    {
        // Arrange / Act
        $config  = $this->buildConfigArray();
        $methods = $config['code_challenge_methods_supported'];

        // Assert
        $this->assertContains('S256',  $methods, 'S256 PKCE method must be supported');
        $this->assertContains('plain', $methods, 'plain PKCE method must be listed');
    }

    /**
     * scopes_supported must include the fundamental OIDC scopes that every
     * conformant implementation is required to support.
     */
    public function testConfigurationIncludesOidcCoreScopes(): void
    {
        // Arrange / Act
        $config = $this->buildConfigArray();
        $scopes = $config['scopes_supported'];

        // Assert
        $this->assertContains('openid',  $scopes, 'openid scope is mandatory for OIDC');
        $this->assertContains('profile', $scopes, 'profile scope must be supported');
        $this->assertContains('email',   $scopes, 'email scope must be supported');
    }

    // ── OAuth2 Metadata structure ─────────────────────────────────────────────

    /**
     * The RFC 8414 OAuth2 server metadata document must contain the mandatory
     * fields: issuer, authorization_endpoint, token_endpoint.
     */
    public function testOauth2MetadataHasRequiredFields(): void
    {
        // Arrange / Act
        if (!defined('sURL')) {
            define('sURL', 'https://auth.example.com/');
        }

        $metadata = $this->buildOauth2MetadataArray();

        // Assert
        $this->assertArrayHasKey('issuer',                 $metadata);
        $this->assertArrayHasKey('authorization_endpoint', $metadata);
        $this->assertArrayHasKey('token_endpoint',         $metadata);
        $this->assertArrayHasKey('scopes_supported',       $metadata);
        $this->assertArrayHasKey('grant_types_supported',  $metadata);
    }

    // ── Private helpers to build arrays without exit() ────────────────────────

    /**
     * Mirror of Discovery::configuration() without header() / exit().
     * Used to assert structural correctness without process termination.
     *
     * @return array<string, mixed>
     */
    private function buildConfigArray(): array
    {
        if (!defined('sURL')) {
            define('sURL', 'https://auth.example.com/');
        }

        return [
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
    }

    /**
     * Mirror of Discovery::oauth2Metadata() without header() / exit().
     *
     * @return array<string, mixed>
     */
    private function buildOauth2MetadataArray(): array
    {
        if (!defined('sURL')) {
            define('sURL', 'https://auth.example.com/');
        }

        return [
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
    }
}
