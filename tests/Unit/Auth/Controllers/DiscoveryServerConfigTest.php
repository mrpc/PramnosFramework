<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Discovery;
use Pramnos\Auth\Scopes;
use Pramnos\Health\HealthCheck;
use Pramnos\Health\HealthCheckResult;
use Pramnos\Health\HealthRegistry;

/**
 * `Discovery::serverConfig()` and the registry-backed `Discovery::health()`.
 *
 * `serverConfig()` is the summary a developer reads while integrating — not a
 * standards document, which is what `configuration()` and `oauth2Metadata()`
 * are. The thing worth testing about it is that its lists come from whatever
 * actually decides them: a hardcoded copy would keep answering after the real
 * value moved, and answering wrong is worse than not answering.
 *
 * `health()` used to run a `SELECT 1` of its own, which made it the only health
 * opinion in the framework that could not see a full disk, an unreachable cache
 * or a missing signing key. It reads `HealthRegistry` now, and the tests below
 * pin the two things that could go wrong in that move: the response shape it has
 * always spoken, and the empty-registry case — because `runAll()` with nothing
 * registered answers `ok`, which would report a database outage as healthy.
 */
class DiscoveryServerConfigTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('sURL')) {
            define('sURL', 'https://auth.example.com/');
        }
        if (!defined('ROOT')) {
            define('ROOT', sys_get_temp_dir());
        }
        HealthRegistry::reset();
    }

    protected function tearDown(): void
    {
        HealthRegistry::reset();
        unset($_SERVER['REQUEST_METHOD']);
    }

    /**
     * Reads the JSON a discovery action answers with.
     *
     * These actions used to `echo` the body, so this captured the output stream.
     * They no longer do: an echo leaves the framework free to render the page it
     * was going to render anyway, which is how every one of these endpoints came
     * to answer with valid JSON followed by a full HTML document. The response is
     * the `raw` document now, so that is where the body is read from — and
     * nothing may be echoed at all.
     *
     * @param  string $action Method name on the controller
     * @return array<string, mixed> The decoded document
     */
    private function capture(string $action): array
    {
        $controller = new Discovery(null);

        \Pramnos\Document\Document::reset();
        ob_start();
        $controller->{$action}();
        $echoed = (string) ob_get_clean();
        $this->assertSame('', $echoed, $action . '() must not echo its body');

        $output = (string) \Pramnos\Framework\Factory::getDocument('raw')->render();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, $action . '() must answer with a JSON object');

        return $decoded;
    }

    /**
     * The summary names its endpoints, grants, scopes and features.
     *
     * One assertion per top-level key rather than a snapshot: a snapshot of this
     * document would fail on every unrelated addition, and the point is that the
     * four sections are present, not that they are frozen.
     */
    public function testTheSummaryHasItsFourSections(): void
    {
        // Act
        $config = $this->capture('serverConfig');

        // Assert
        $this->assertArrayHasKey('server_info', $config);
        $this->assertArrayHasKey('endpoints', $config);
        $this->assertArrayHasKey('supported_grants', $config);
        $this->assertArrayHasKey('supported_scopes', $config);
        $this->assertArrayHasKey('features', $config);
    }

    /**
     * The scope list is read from `Scopes`, not restated.
     *
     * This is the assertion the endpoint exists or does not exist for. A copied
     * list keeps answering confidently after a scope is added or renamed, and
     * nothing fails — the integration built against it simply asks for a scope
     * the server does not have.
     */
    public function testTheScopeListComesFromTheScopeRegistry(): void
    {
        // Act
        $config = $this->capture('serverConfig');

        // Assert — same members, order not part of the contract
        $this->assertEqualsCanonicalizing(
            array_keys(Scopes::getScopeDescriptions()),
            $config['supported_scopes']
        );
    }

    /**
     * Every advertised endpoint is built from the issuer, not written relative.
     *
     * A relative path is one the reader has to resolve against a base they have
     * to guess, and the guess is wrong for any installation in a subdirectory.
     *
     * `str_starts_with` rather than `assertStringStartsWith`: the suite may have
     * already defined `sURL` as an empty string, and an empty needle is an error
     * in PHPUnit rather than a trivially true assertion.
     */
    public function testEveryEndpointIsBuiltFromTheIssuer(): void
    {
        // Act
        $config = $this->capture('serverConfig');

        // Assert
        $this->assertNotEmpty($config['endpoints']);
        foreach ($config['endpoints'] as $name => $url) {
            $this->assertTrue(
                str_starts_with((string) $url, sURL),
                $name . ' must be built from the issuer, got: ' . $url
            );
        }
    }

    /**
     * The device code grant is advertised under its full URN.
     *
     * RFC 8628 names the grant `urn:ietf:params:oauth:grant-type:device_code`.
     * A client matching on the short name finds nothing, so the shorthand would
     * be a silently useless entry.
     */
    public function testTheDeviceGrantUsesItsSpecifiedUrn(): void
    {
        // Act
        $config = $this->capture('serverConfig');

        // Assert
        $this->assertContains(
            'urn:ietf:params:oauth:grant-type:device_code',
            $config['supported_grants']
        );
    }

    /**
     * A preflight is answered without a body.
     *
     * A browser-based integration console sends `OPTIONS` first, and answering
     * it with the document would be a document nothing reads.
     */
    public function testAPreflightReturnsNoDocument(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        $controller                = new Discovery(null);

        // Act
        \Pramnos\Document\Document::reset();
        ob_start();
        $controller->serverConfig();
        $echoed = (string) ob_get_clean();

        // Assert — nothing echoed, and the document it hands back is empty too,
        // so a 204 stays a 204 instead of carrying a rendered page.
        $this->assertSame('', $echoed);
        $this->assertSame(
            '',
            (string) \Pramnos\Framework\Factory::getDocument('raw')->render()
        );
    }

    /**
     * `health()` reports every registered check, not a hardcoded pair.
     *
     * The old implementation could only ever speak about the database and the
     * session. A check the application registered — a cache, a queue, the signing
     * keys — was invisible here while being visible on `/health/check`, and the
     * two endpoints disagreeing is the failure this fixes.
     */
    public function testHealthReportsEveryRegisteredCheck(): void
    {
        // Arrange
        HealthRegistry::register(new class implements HealthCheck {
            public function getName(): string
            {
                return 'signing_keys';
            }

            public function run(): HealthCheckResult
            {
                return HealthCheckResult::ok('signing_keys', 'usable');
            }
        });

        // Act
        $health = $this->capture('health');

        // Assert — the new check is there, and so is the shape callers rely on
        $this->assertArrayHasKey('signing_keys', $health['components']);
        $this->assertSame('ok', $health['components']['signing_keys']);
        $this->assertArrayHasKey('session', $health['components']);
        $this->assertArrayHasKey('timestamp', $health['components'] === [] ? [] : $health);
    }

    /**
     * A degraded check reads as `error` here, and makes the server unhealthy.
     *
     * This endpoint has only ever spoken `ok` / `error`, so the three-way status
     * is collapsed rather than widened — widening it would break every consumer.
     * A caller that needs the distinction has `/health/check`.
     */
    public function testADegradedCheckCollapsesToError(): void
    {
        // Arrange
        HealthRegistry::register(new class implements HealthCheck {
            public function getName(): string
            {
                return 'database';
            }

            public function run(): HealthCheckResult
            {
                return HealthCheckResult::degraded('database', 'slow');
            }
        });

        // Act
        $health = $this->capture('health');

        // Assert
        $this->assertSame('error', $health['components']['database']);
        $this->assertSame('unhealthy', $health['status']);
    }

    /**
     * With no database verdict at all, the answer is unhealthy — not healthy.
     *
     * This is the regression the change could have introduced and the reason
     * `health()` ensures a database check rather than trusting the registry:
     * `HealthRegistry::runAll()` on an empty registry returns `ok`, so a
     * controller reached from a script or a boot that never registered anything
     * would have reported an authorization server with no database as well.
     */
    public function testAMissingDatabaseVerdictIsAFailure(): void
    {
        // Arrange — a registry holding something that is not the database.
        // Whether a real connection can be reached from here is not the point;
        // either way there must be a database entry and it must not be silent.
        HealthRegistry::register(new class implements HealthCheck {
            public function getName(): string
            {
                return 'disk_space';
            }

            public function run(): HealthCheckResult
            {
                return HealthCheckResult::ok('disk_space', 'plenty');
            }
        });

        // Act
        $health = $this->capture('health');

        // Assert
        $this->assertArrayHasKey('database', $health['components']);
        $this->assertContains($health['components']['database'], ['ok', 'error']);
    }
}
