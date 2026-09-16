<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\OAuth2\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\OAuth2\Client\Connection;
use Pramnos\Auth\OAuth2\Client\ConnectionStore;
use Pramnos\Auth\OAuth2\Client\OAuthClient;
use Pramnos\Auth\OAuth2\Client\OAuthClientException;
use Pramnos\Auth\OAuth2\Client\Provider;
use Pramnos\Console\Commands\OauthRefresh;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A store that answers from memory and records what was asked of it.
 *
 * The command's job is triage — which connections to touch, what to do when one fails, and
 * what to tell whoever reads the output — so the store is stubbed to put those decisions
 * under a microscope. The store's own behaviour is covered against real databases in
 * `tests/Integration/Auth/OAuth2/`.
 */
class RecordingConnectionStore extends ConnectionStore
{
    /** @var list<Connection> */
    public array $refreshed = [];

    /** @param list<Connection> $due */
    public function __construct(private array $due, private array $failures = [])
    {
        parent::__construct(null);
    }

    public function dueForRefresh(int $within = 3600, ?int $now = null): array
    {
        return $this->due;
    }

    public function refresh(Connection $connection, OAuthClient $client): Connection
    {
        if (isset($this->failures[$connection->provider])) {
            throw $this->failures[$connection->provider];
        }

        $this->refreshed[] = $connection;

        return $connection;
    }
}

/**
 * `oauth:refresh` — the scheduled job's triage.
 *
 * What is asserted here is almost entirely about failure, because the success path is one
 * call and the failures are where the decisions are. Three of them are distinct and would
 * each be a different production problem if merged: a provider missing from the
 * configuration must not kill live connections, a revoked grant must not be retried for
 * ever, and a network blip must not be reported as a dead connection.
 */
#[CoversClass(OauthRefresh::class)]
class OauthRefreshCommandTest extends TestCase
{
    /** A connection due for refresh. */
    private function connection(string $provider, int $userId = 1): Connection
    {
        return new Connection(
            id: $userId,
            userId: $userId,
            provider: $provider,
            accountId: '',
            accountName: '',
            accessToken: 'at',
            refreshToken: 'rt',
            expiresAt: time() + 60,
            refreshExpiresAt: null,
            scopes: [],
        );
    }

    private function provider(string $name): Provider
    {
        return new Provider(
            name: $name,
            authorizeUrl: 'https://' . $name . '.test/authorize',
            tokenUrl: 'https://' . $name . '.test/token',
            clientId: 'id',
            clientSecret: 'secret',
            redirectUri: 'https://app.test/callback',
        );
    }

    /**
     * Run the command with a stubbed store and a configured provider list.
     *
     * @param list<Connection>               $due
     * @param array<string, \Throwable>      $failures
     * @param list<string>                   $configured
     * @return array{0: CommandTester, 1: RecordingConnectionStore}
     */
    private function runCommand(array $due, array $failures = [], array $configured = ['acme'], array $input = []): array
    {
        $store     = new RecordingConnectionStore($due, $failures);
        $providers = [];
        foreach ($configured as $name) {
            $providers[$name] = $this->provider($name);
        }

        $command = new class ($store, $providers) extends OauthRefresh {
            public function __construct(private ConnectionStore $stub, private array $configured)
            {
                parent::__construct();
            }

            protected function store(): ConnectionStore
            {
                return $this->stub;
            }

            protected function providers(): array
            {
                return $this->configured;
            }
        };

        (new Application())->add($command);
        $tester = new CommandTester($command);
        $tester->execute($input, ['interactive' => false]);

        return [$tester, $store];
    }

    /**
     * A due connection with a configured provider is refreshed, and counted.
     */
    public function testADueConnectionIsRefreshed(): void
    {
        // Act
        [$tester, $store] = $this->runCommand([$this->connection('acme')]);

        // Assert
        $this->assertCount(1, $store->refreshed);
        $this->assertStringContainsString('1 refreshed', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    /**
     * A provider missing from the configuration is skipped, never killed.
     *
     * The failure this prevents is a deployment one: an environment variable that was not
     * set on the new server. Marking those connections dead would turn a missing variable
     * into every affected user having to authorise again — and re-authorising is the only
     * thing that undoes it.
     */
    public function testAnUnconfiguredProviderIsSkippedRatherThanFailed(): void
    {
        // Act — the connection's provider is not in the configured list.
        [$tester, $store] = $this->runCommand([$this->connection('tiktok')], configured: ['acme']);

        // Assert
        $this->assertSame([], $store->refreshed);
        $this->assertStringContainsString('not configured', $tester->getDisplay());
        $this->assertStringContainsString('1 not configured', $tester->getDisplay());
        // Not a failed run: there is nothing here for a supervisor to retry.
        $this->assertSame(0, $tester->getStatusCode());
    }

    /**
     * A revoked grant is reported as dead, and does not fail the run.
     *
     * Exiting non-zero would put a supervisor into a restart loop over something no retry
     * can fix. The connection has been recorded as dead by the store; the run did its job.
     */
    public function testARevokedGrantIsReportedAsDeadAndDoesNotFailTheRun(): void
    {
        // Act
        [$tester] = $this->runCommand(
            [$this->connection('acme')],
            ['acme' => new OAuthClientException('Token has been expired or revoked.', 'invalid_grant', 'acme')]
        );

        // Assert
        $display = $tester->getDisplay();
        $this->assertStringContainsString('dead', $display);
        $this->assertStringContainsString('1 dead', $display);
        $this->assertSame(0, $tester->getStatusCode());
    }

    /**
     * A transient failure fails the run, so a monitor sees it.
     *
     * The opposite decision from the one above, and for the opposite reason: this may
     * still be happening, and it is the case where somebody looking is useful.
     */
    public function testATransientFailureFailsTheRun(): void
    {
        // Act
        [$tester] = $this->runCommand(
            [$this->connection('acme')],
            ['acme' => new OAuthClientException('Gateway timeout', 'temporarily_unavailable', 'acme')]
        );

        // Assert
        $this->assertStringContainsString('1 failed', $tester->getDisplay());
        $this->assertSame(1, $tester->getStatusCode());
    }

    /**
     * `--dry-run` contacts nobody.
     *
     * The stub would record a refresh; asserting that it recorded none is what proves the
     * flag is honoured rather than merely printed.
     */
    public function testADryRunRefreshesNothing(): void
    {
        // Act
        [$tester, $store] = $this->runCommand([$this->connection('acme')], input: ['--dry-run' => true]);

        // Assert
        $this->assertSame([], $store->refreshed);
        $this->assertStringContainsString('would refresh', $tester->getDisplay());
        $this->assertStringContainsString('1 connection(s) due', $tester->getDisplay());
    }

    /**
     * `--provider` narrows the run to one platform.
     *
     * The operational case: one provider is having an incident, or one has just been
     * reconfigured, and re-running everything means touching connections that are fine.
     */
    public function testTheProviderOptionNarrowsTheRun(): void
    {
        // Act
        [, $store] = $this->runCommand(
            [$this->connection('acme', 1), $this->connection('other', 2)],
            configured: ['acme', 'other'],
            input: ['--provider' => 'acme']
        );

        // Assert
        $this->assertCount(1, $store->refreshed);
        $this->assertSame('acme', $store->refreshed[0]->provider);
    }

    /**
     * Nothing due says so and stops.
     */
    public function testNothingDueIsReportedAndSucceeds(): void
    {
        // Act
        [$tester] = $this->runCommand([]);

        // Assert
        $this->assertStringContainsString('Nothing due', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    /**
     * The command as the framework registers it: no providers, a real store, a real client.
     *
     * These three seams are what an application overrides, and the base implementations
     * are the contract it overrides *from*. Pinned because they are one-line methods that
     * look like scaffolding and are not: an application subclass calling `parent::` would
     * be relying on each of them, and the empty provider list in particular is a
     * documented behaviour — it is what makes an unconfigured deployment say so per
     * connection instead of succeeding silently.
     */
    public function testTheFrameworkDefaultsAreTheDocumentedOnes(): void
    {
        // Arrange — the command exactly as Console\Application registers it.
        $command = new OauthRefresh();

        $providers = new \ReflectionMethod($command, 'providers');
        $store     = new \ReflectionMethod($command, 'store');
        $client    = new \ReflectionMethod($command, 'client');

        // Act & Assert
        $this->assertSame([], $providers->invoke($command), 'the framework knows no client credentials');
        $this->assertInstanceOf(ConnectionStore::class, $store->invoke($command));
        $this->assertInstanceOf(OAuthClient::class, $client->invoke($command, $this->provider('acme')));
        $this->assertSame('oauth:refresh', $command->getName());
    }

    /**
     * With no providers configured at all, every connection is skipped and named.
     *
     * The framework registers this command with an empty provider list, because it cannot
     * know an application's client credentials. A line per connection saying "not
     * configured" is how somebody discovers the subclass was never written — as opposed to
     * a silent success, which is what "nothing to do" would look like.
     */
    public function testTheUnsubclassedCommandSkipsEverythingVisibly(): void
    {
        // Act
        [$tester, $store] = $this->runCommand([$this->connection('acme')], configured: []);

        // Assert
        $this->assertSame([], $store->refreshed);
        $this->assertStringContainsString('not configured', $tester->getDisplay());
    }
}
