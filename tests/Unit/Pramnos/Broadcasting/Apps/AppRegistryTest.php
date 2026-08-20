<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting\Apps;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Apps\AppSource;
use Pramnos\Broadcasting\Apps\AuthServerAppRegistry;
use Pramnos\Broadcasting\Apps\BroadcastApp;
use Pramnos\Broadcasting\Apps\ConfigAppRegistry;

/**
 * Covers where the realtime edge looks up the app behind a connection key.
 *
 * Two properties are load-bearing and both are about agreement rather than about
 * any single lookup: the source must resolve identically in a web request and in a
 * daemon, and an unknown key must be indistinguishable from a rejected one.
 */
#[CoversClass(AppSource::class)]
#[CoversClass(ConfigAppRegistry::class)]
#[CoversClass(AuthServerAppRegistry::class)]
#[CoversClass(BroadcastApp::class)]
class AppRegistryTest extends TestCase
{
    // -------------------------------------------------------------------------
    // AppSource
    // -------------------------------------------------------------------------

    /**
     * With no `apps.source` set, an installation running the authserver feature
     * resolves to the AuthServer registry and one without it resolves to config.
     *
     * This is the behaviour the user asked for: the two features travel together,
     * and without the authserver the simple implementation is what runs.
     */
    public function testAutoFollowsTheAuthserverFeature(): void
    {
        // Act & Assert
        $this->assertSame(
            AppSource::AUTHSERVER,
            AppSource::resolve([], ['broadcasting', 'authserver'])
        );
        $this->assertSame(
            AppSource::CONFIG,
            AppSource::resolve([], ['broadcasting'])
        );
    }

    /**
     * An empty features array resolves to config, which is the state of every
     * installation that has not opted into anything.
     */
    public function testAutoWithNoFeaturesResolvesToConfig(): void
    {
        // Act & Assert
        $this->assertSame(AppSource::CONFIG, AppSource::resolve([], []));
    }

    /**
     * `config` stays config even where the authserver is enabled, so an operator
     * can keep the simple registry on an installation that has both features.
     */
    public function testExplicitConfigWinsOverTheFeature(): void
    {
        // Act & Assert
        $this->assertSame(
            AppSource::CONFIG,
            AppSource::resolve(['apps' => ['source' => 'config']], ['authserver'])
        );
    }

    /**
     * Naming `authserver` without enabling the feature is an error, not a silent
     * fallback.
     *
     * Falling back to config would authorize channels against a different secret
     * than the operator asked for — a security decision quietly reversed by a
     * missing line in app.php.
     */
    public function testExplicitAuthserverWithoutTheFeatureThrows(): void
    {
        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/'authserver' feature is not enabled/");
        AppSource::resolve(['apps' => ['source' => 'authserver']], ['broadcasting']);
    }

    /**
     * An unrecognised source names the valid values rather than guessing.
     */
    public function testUnknownSourceThrowsWithTheValidValues(): void
    {
        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Valid values: auto, config, authserver/');
        AppSource::resolve(['apps' => ['source' => 'ldap']], ['authserver']);
    }

    /**
     * The source name is matched case-insensitively, since it comes from a
     * hand-edited config file.
     */
    public function testSourceIsCaseInsensitive(): void
    {
        // Act & Assert
        $this->assertSame(
            AppSource::AUTHSERVER,
            AppSource::resolve(['apps' => ['source' => 'AuthServer']], ['authserver'])
        );
    }

    /**
     * registry() builds the type its resolution names.
     */
    public function testRegistryBuildsTheResolvedType(): void
    {
        // Act & Assert
        $this->assertInstanceOf(
            ConfigAppRegistry::class,
            AppSource::registry(['pusher' => ['app_key' => 'k']], [])
        );
        $this->assertInstanceOf(
            AuthServerAppRegistry::class,
            AppSource::registry([], ['authserver'])
        );
    }

    // -------------------------------------------------------------------------
    // ConfigAppRegistry
    // -------------------------------------------------------------------------

    /**
     * The configured key and secret become the single app, and it is the default.
     */
    public function testConfigRegistryExposesTheConfiguredApp(): void
    {
        // Arrange
        $registry = new ConfigAppRegistry([
            'pusher' => ['app_key' => 'my-key', 'app_secret' => 'my-secret', 'app_id' => '77'],
        ]);

        // Act
        $found = $registry->findByKey('my-key');

        // Assert
        $this->assertInstanceOf(BroadcastApp::class, $found);
        $this->assertSame('my-secret', $found->secret);
        $this->assertSame('77', $found->id);
        $this->assertSame($found, $registry->defaultApp(), 'the one app is also the default');
    }

    /**
     * The key may be configured under `websocket` instead of `pusher`, which is
     * where the built-in server's key already lives.
     */
    public function testConfigRegistryReadsTheWebsocketKey(): void
    {
        // Arrange
        $registry = new ConfigAppRegistry([
            'websocket' => ['app_key' => 'ws-key'],
            'pusher'    => ['app_secret' => 'shared-secret'],
        ]);

        // Act & Assert
        $this->assertSame('shared-secret', $registry->findByKey('ws-key')?->secret);
    }

    /**
     * A key that is not the configured one yields null, and so does an empty one.
     */
    public function testConfigRegistryRejectsOtherKeys(): void
    {
        // Arrange
        $registry = new ConfigAppRegistry(['pusher' => ['app_key' => 'right']]);

        // Act & Assert
        $this->assertNull($registry->findByKey('wrong'));
        $this->assertNull($registry->findByKey(''));
    }

    /**
     * With nothing configured there is no app at all, rather than an app with an
     * empty key that any empty key would match.
     */
    public function testConfigRegistryWithNoKeyHasNoApp(): void
    {
        // Arrange
        $registry = new ConfigAppRegistry([]);

        // Act & Assert
        $this->assertNull($registry->defaultApp());
        $this->assertNull($registry->findByKey(''));
    }

    // -------------------------------------------------------------------------
    // AuthServerAppRegistry
    // -------------------------------------------------------------------------

    /**
     * A registry whose row loader is supplied, so the decision logic can be tested
     * without a database. Counts loads, to assert caching.
     */
    private function authServerRegistry(?array $row, int $ttl = 60, ?callable $clock = null): object
    {
        return new class($row, $ttl, $clock) extends AuthServerAppRegistry {
            public int $loads = 0;

            public function __construct(private ?array $row, int $ttl, ?callable $clock)
            {
                parent::__construct($ttl, $clock);
            }

            protected function loadRow(string $key): ?array
            {
                $this->loads++;
                return $this->row;
            }
        };
    }

    /**
     * An applications row becomes a BroadcastApp, with the dedicated
     * broadcast_secret preferred over apisecret.
     *
     * The preference is the point: the realtime daemon holds this secret in memory
     * for the life of every connection, so it must be separable from the OAuth2
     * client secret rather than the same value.
     */
    public function testPrefersBroadcastSecretOverApiSecret(): void
    {
        // Arrange
        $registry = $this->authServerRegistry([
            'apikey'           => 'live-key',
            'apisecret'        => 'oauth-secret',
            'broadcast_secret' => 'realtime-secret',
            'appid'            => 12,
            'name'             => 'Radio',
        ]);

        // Act
        $app = $registry->findByKey('live-key');

        // Assert
        $this->assertSame('realtime-secret', $app->secret);
        $this->assertSame('12', $app->id);
        $this->assertSame('Radio', $app->name);
    }

    /**
     * With no broadcast_secret, apisecret is used — so the registry works before
     * the migration has run.
     */
    public function testFallsBackToApiSecret(): void
    {
        // Arrange
        $registry = $this->authServerRegistry([
            'apikey'    => 'live-key',
            'apisecret' => 'oauth-secret',
        ]);

        // Act & Assert
        $this->assertSame('oauth-secret', $registry->findByKey('live-key')?->secret);
    }

    /**
     * A NULL broadcast_secret is treated as absent rather than as an empty secret.
     *
     * A driver may return either a missing key or a null value for the same
     * column, and an empty secret would make canSign() false on a perfectly
     * configured app.
     */
    public function testNullBroadcastSecretFallsBack(): void
    {
        // Arrange
        $registry = $this->authServerRegistry([
            'apikey'           => 'live-key',
            'broadcast_secret' => null,
            'apisecret'        => 'oauth-secret',
        ]);

        // Act & Assert
        $this->assertSame('oauth-secret', $registry->findByKey('live-key')?->secret);
    }

    /**
     * A key with no row yields null.
     */
    public function testUnknownKeyYieldsNull(): void
    {
        // Arrange
        $registry = $this->authServerRegistry(null);

        // Act & Assert
        $this->assertNull($registry->findByKey('nope'));
    }

    /**
     * An empty key never reaches the database.
     */
    public function testEmptyKeySkipsTheLookup(): void
    {
        // Arrange
        $registry = $this->authServerRegistry(['apikey' => 'x']);

        // Act
        $result = $registry->findByKey('');

        // Assert
        $this->assertNull($result);
        $this->assertSame(0, $registry->loads, 'no query for an empty key');
    }

    /**
     * Repeated lookups inside the TTL hit the cache once.
     *
     * The daemon is a single-threaded select loop: a query per handshake blocks
     * every other connection for its duration, and after a deploy every client
     * reconnects at once.
     */
    public function testCachesWithinTheTtl(): void
    {
        // Arrange
        $now      = 1000;
        $registry = $this->authServerRegistry(
            ['apikey' => 'k', 'apisecret' => 's'],
            60,
            function () use (&$now): int { return $now; }
        );

        // Act
        $registry->findByKey('k');
        $registry->findByKey('k');
        $now += 59;
        $registry->findByKey('k');

        // Assert
        $this->assertSame(1, $registry->loads, 'one load for three lookups inside the TTL');
    }

    /**
     * Past the TTL the row is re-read, so revoking an app takes effect within one
     * TTL rather than at the next restart.
     */
    public function testReloadsAfterTheTtl(): void
    {
        // Arrange
        $now      = 1000;
        $registry = $this->authServerRegistry(
            ['apikey' => 'k', 'apisecret' => 's'],
            60,
            function () use (&$now): int { return $now; }
        );

        // Act
        $registry->findByKey('k');
        $now += 61;
        $registry->findByKey('k');

        // Assert
        $this->assertSame(2, $registry->loads);
    }

    /**
     * A negative result is cached too.
     *
     * Without that, an unknown key queries the database once per connection
     * attempt — which is precisely the load an attacker probing keys produces.
     */
    public function testCachesNegativeResults(): void
    {
        // Arrange
        $registry = $this->authServerRegistry(null, 60, fn (): int => 1000);

        // Act
        $registry->findByKey('nope');
        $registry->findByKey('nope');

        // Assert
        $this->assertSame(1, $registry->loads);
    }

    /**
     * A zero TTL disables caching, which is right for a web request that performs
     * one lookup and exits.
     */
    public function testZeroTtlDisablesCaching(): void
    {
        // Arrange
        $registry = $this->authServerRegistry(['apikey' => 'k', 'apisecret' => 's'], 0);

        // Act
        $registry->findByKey('k');
        $registry->findByKey('k');

        // Assert
        $this->assertSame(2, $registry->loads);
    }

    /**
     * flush() drops cached lookups, for a daemon told to reload.
     */
    public function testFlushDropsTheCache(): void
    {
        // Arrange
        $registry = $this->authServerRegistry(['apikey' => 'k', 'apisecret' => 's'], 60, fn (): int => 1000);
        $registry->findByKey('k');

        // Act
        $registry->flush();
        $registry->findByKey('k');

        // Assert
        $this->assertSame(2, $registry->loads);
    }

    /**
     * There is no default app in a multi-tenant registry: every connection must
     * name the app it means.
     */
    public function testNoDefaultAppInMultiTenantRegistry(): void
    {
        // Assert
        $this->assertNull($this->authServerRegistry(['apikey' => 'k'])->defaultApp());
    }

    // -------------------------------------------------------------------------
    // BroadcastApp
    // -------------------------------------------------------------------------

    /**
     * canSign() is false without a key or without a secret, so a caller learns it
     * before an HMAC comparison against an empty string.
     */
    public function testCanSignRequiresBothHalves(): void
    {
        // Assert
        $this->assertTrue((new BroadcastApp('k', 's'))->canSign());
        $this->assertFalse((new BroadcastApp('k', ''))->canSign());
        $this->assertFalse((new BroadcastApp('', 's'))->canSign());
    }

    /**
     * The log context reports whether a secret exists but never what it is.
     */
    public function testLogContextRedactsTheSecret(): void
    {
        // Act
        $context = (new BroadcastApp('k', 'super-secret', '3', 'App'))->toLogContext();

        // Assert
        $this->assertSame('present', $context['secret']);
        $this->assertNotContains('super-secret', $context, 'the secret must never be logged');
        $this->assertSame('absent', (new BroadcastApp('k', ''))->toLogContext()['secret']);
    }

    // -------------------------------------------------------------------------
    // AuthServerAppRegistry::loadRow — the database path and its guards
    // -------------------------------------------------------------------------

    /**
     * A registry with a supplied database object, so the query and each of its
     * guards can be exercised without a live connection.
     */
    private function registryWithDatabase(?object $database): AuthServerAppRegistry
    {
        return new class($database) extends AuthServerAppRegistry {
            public function __construct(private ?object $db)
            {
                parent::__construct(0);
            }

            protected function database(): ?object
            {
                return $this->db;
            }

            public function callLoadRow(string $key): ?array
            {
                return $this->loadRow($key);
            }
        };
    }

    /**
     * A fake query builder that records the query it was asked to build and
     * returns $row from first() — or throws, to stand in for a missing table.
     */
    private function fakeDatabase(mixed $row, bool $throw = false): object
    {
        return new class($row, $throw) {
            public array $calls = [];

            public function __construct(private mixed $row, private bool $throw)
            {
            }

            public function queryBuilder(): object
            {
                return new class($this->row, $this->throw, $this) {
                    public function __construct(
                        private mixed $row,
                        private bool $throw,
                        private object $parent
                    ) {
                    }

                    public function table(string $name): static
                    {
                        $this->parent->calls['table'] = $name;
                        return $this;
                    }

                    public function where(string $column, mixed $value): static
                    {
                        $this->parent->calls['where'][$column] = $value;
                        return $this;
                    }

                    public function first(): mixed
                    {
                        if ($this->throw) {
                            throw new \RuntimeException('relation "applications" does not exist');
                        }
                        return $this->row;
                    }
                };
            }
        };
    }

    /**
     * The lookup queries `applications` for the key, restricted to active rows.
     *
     * The `status` filter is the assertion that matters: a disabled application
     * must not be able to authorize a channel, and forgetting the filter is a
     * change nothing else would catch — every test with an active row would still
     * pass.
     */
    public function testQueriesActiveApplicationsByKey(): void
    {
        // Arrange
        $database = $this->fakeDatabase(['apikey' => 'k', 'apisecret' => 's']);
        $registry = $this->registryWithDatabase($database);

        // Act
        $row = $registry->callLoadRow('k');

        // Assert
        $this->assertSame(['apikey' => 'k', 'apisecret' => 's'], $row);
        $this->assertSame('applications', $database->calls['table']);
        $this->assertSame('k', $database->calls['where']['apikey']);
        $this->assertSame(1, $database->calls['where']['status'], 'disabled apps must not authorize');
    }

    /**
     * With no database at all, the lookup yields null instead of faulting.
     *
     * A console command that never connected is a normal state, not an error.
     */
    public function testNoDatabaseYieldsNull(): void
    {
        // Act & Assert
        $this->assertNull($this->registryWithDatabase(null)->callLoadRow('k'));
    }

    /**
     * An object that is not a database (no queryBuilder) yields null.
     */
    public function testNonDatabaseObjectYieldsNull(): void
    {
        // Act & Assert
        $this->assertNull($this->registryWithDatabase(new \stdClass())->callLoadRow('k'));
    }

    /**
     * A failing query — a missing table because the authserver migrations have not
     * run — yields null rather than propagating.
     *
     * The realtime edge must not go down over it: no app is found, the connection
     * is refused, and the misconfiguration is visible where migrations are.
     */
    public function testFailingQueryYieldsNull(): void
    {
        // Arrange
        $registry = $this->registryWithDatabase($this->fakeDatabase(null, throw: true));

        // Act & Assert
        $this->assertNull($registry->callLoadRow('k'));
    }

    /**
     * A driver returning false for "no row" is handled like null.
     */
    public function testFalseRowYieldsNull(): void
    {
        // Act & Assert
        $this->assertNull($this->registryWithDatabase($this->fakeDatabase(false))->callLoadRow('k'));
    }

    /**
     * A driver returning an object row is cast to an array, so the column reads
     * above work whichever fetch mode is configured.
     */
    public function testObjectRowIsCastToArray(): void
    {
        // Arrange
        $registry = $this->registryWithDatabase(
            $this->fakeDatabase((object) ['apikey' => 'k', 'broadcast_secret' => 'bs'])
        );

        // Act
        $row = $registry->callLoadRow('k');

        // Assert
        $this->assertSame(['apikey' => 'k', 'broadcast_secret' => 'bs'], $row);
    }
}
