<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth\OAuth2;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\OAuth2\Client\Connection;
use Pramnos\Auth\OAuth2\Client\ConnectionStore;
use Pramnos\Auth\OAuth2\Client\OAuthClient;
use Pramnos\Auth\OAuth2\Client\OAuthClientException;
use Pramnos\Auth\OAuth2\Client\Provider;
use Pramnos\Auth\OAuth2\Client\TokenSet;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;
use Pramnos\Framework\Factory;
use Pramnos\Http\Client;
use Pramnos\Http\ClientResponse;

/**
 * ConnectionStore against a live MySQL 8.0 database.
 *
 * The unit tests prove the conversation with a provider. These prove the half that
 * persists, which is the half a unit test cannot: that the upsert really is one statement
 * against a real unique key, that a token really is unreadable in the column, and that the
 * "due for refresh" query really selects what the scheduled job is supposed to act on.
 *
 * `APP_KEY` is set in setUp because this subsystem refuses to store a third-party token
 * without one — which is itself asserted, since it is a security property rather than a
 * convenience.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class ConnectionStoreTest extends TestCase
{
    protected Database $db;
    protected Application $app;
    protected ConnectionStore $store;
    private ?string $originalAppKey = null;

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);

        $this->db = $this->connect();
        if (!$this->db->connected) {
            $this->db->connect(true);
        }

        $this->app = $this->makeApp();

        // A key for the run. Tokens are encrypted at rest and the store refuses to write
        // without one, so this is setup rather than a subject — except in the one test
        // that takes it away again.
        $this->originalAppKey = getenv('APP_KEY') ?: null;
        putenv('APP_KEY=base64:' . base64_encode(random_bytes(32)));

        $this->dropTable();
        $this->runMigration();

        $this->store = new ConnectionStore($this->db);
    }

    protected function tearDown(): void
    {
        Client::resetFakes();
        $this->dropTable();

        if ($this->originalAppKey === null) {
            putenv('APP_KEY');
        } else {
            putenv('APP_KEY=' . $this->originalAppKey);
        }

        parent::tearDown();
    }

    /**
     * A connection round-trips: what is saved is what comes back.
     */
    public function testAConnectionIsStoredAndReadBack(): void
    {
        // Arrange
        $tokens = new TokenSet(
            accessToken: 'at-1',
            refreshToken: 'rt-1',
            expiresAt: time() + 3600,
            refreshExpiresAt: time() + 5184000,
            scopes: ['read', 'write'],
        );

        // Act
        $this->store->save(7, 'google', $tokens, 'acct-1', 'Example Channel');
        $connection = $this->store->find(7, 'google', 'acct-1');

        // Assert
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertSame('at-1', $connection->accessToken);
        $this->assertSame('rt-1', $connection->refreshToken);
        $this->assertSame(['read', 'write'], $connection->scopes);
        $this->assertTrue($connection->hasScope('write'));
        $this->assertSame('Example Channel', $connection->accountName);
        $this->assertFalse($connection->isDead());
    }

    /**
     * The tokens are not readable in the table.
     *
     * The assertion the whole encryption story rests on, and it has to be made against the
     * column rather than against the Encrypter: a store that forgot to call it would pass
     * every round-trip test in this file, because it would also forget to decrypt.
     */
    public function testTheStoredTokensAreEncryptedInTheColumn(): void
    {
        // Arrange
        $this->store->save(7, 'google', new TokenSet(accessToken: 'super-secret', refreshToken: 'also-secret'));

        // Act — read the raw column, going nowhere near the store.
        $result = $this->db->queryBuilder()->table('oauthconnections')->where('userid', 7)->first();

        // Assert
        $this->assertSame(1, (int) $result->numRows);
        $this->assertStringNotContainsString('super-secret', (string) $result->fields['access_token']);
        $this->assertStringNotContainsString('also-secret', (string) $result->fields['refresh_token']);
        // And it is genuinely the Encrypter's format rather than something home-made.
        $this->assertTrue(\Pramnos\Security\Encrypter::isEncrypted((string) $result->fields['access_token']));
    }

    /**
     * Without APP_KEY the store refuses rather than writing a plaintext credential.
     *
     * A third-party refresh token is a credential for an account on somebody else's
     * service, held on behalf of a user. Other callers of the Encrypter are allowed to
     * degrade to plaintext with a warning; this one is not, and the refusal names the
     * command that fixes it.
     */
    public function testItRefusesToStoreATokenWithoutAnAppKey(): void
    {
        // Arrange
        putenv('APP_KEY');
        unset($_ENV['APP_KEY']);

        // Act & Assert
        try {
            $this->store->save(7, 'google', new TokenSet(accessToken: 'at-1'));
            $this->fail('a third-party token must not be stored in plaintext');
        } catch (OAuthClientException $exception) {
            $this->assertSame('no_app_key', $exception->error);
            $this->assertStringContainsString('key:generate', $exception->getMessage());
        }

        // And nothing was written.
        $this->assertNull($this->store->find(7, 'google'));
    }

    /**
     * Saving twice updates in place rather than raising on the unique key.
     *
     * Authorising twice in quick succession — a double-clicked button, a callback the
     * browser retried — is ordinary. A check-then-insert would race and the second would
     * be an error out of a screen that had every reason to work.
     */
    public function testSavingTwiceUpdatesInPlace(): void
    {
        // Arrange
        $this->store->save(7, 'google', new TokenSet(accessToken: 'at-1', refreshToken: 'rt-1'));

        // Act
        $this->store->save(7, 'google', new TokenSet(accessToken: 'at-2', refreshToken: 'rt-2'));

        // Assert — one row, the newer values.
        $this->assertCount(1, $this->store->forUser(7, 'google'));
        $this->assertSame('at-2', $this->store->find(7, 'google')->accessToken);
    }

    /**
     * Two accounts on one platform are two connections, not one overwriting the other.
     *
     * The reason `account_id` is in the unique key. A user with two channels, two pages or
     * two advertising accounts is the normal case for anything that publishes, and a key
     * of (user, provider) silently loses the first the moment the second is connected.
     */
    public function testTwoAccountsOnOnePlatformCoexist(): void
    {
        // Arrange & Act
        $this->store->save(7, 'meta', new TokenSet(accessToken: 'at-page-1'), 'page-1', 'First Page');
        $this->store->save(7, 'meta', new TokenSet(accessToken: 'at-page-2'), 'page-2', 'Second Page');

        // Assert
        $this->assertCount(2, $this->store->forUser(7, 'meta'));
        $this->assertSame('at-page-1', $this->store->find(7, 'meta', 'page-1')->accessToken);
        $this->assertSame('at-page-2', $this->store->find(7, 'meta', 'page-2')->accessToken);
    }

    /**
     * `dueForRefresh()` selects what the scheduled job should act on, and nothing else.
     *
     * Three exclusions, each of which would be a distinct production problem: a token with
     * plenty of life left is a wasted request and a wasted rotation; a token with no expiry
     * would be refreshed on every run for ever; and a dead connection retried every quarter
     * of an hour is how a provider starts rate-limiting the live traffic too.
     */
    public function testOnlyActiveConnectionsNearExpiryAreDueForRefresh(): void
    {
        // Arrange
        $this->store->save(1, 'soon',   new TokenSet(accessToken: 'a', refreshToken: 'r', expiresAt: time() + 60));
        $this->store->save(2, 'later',  new TokenSet(accessToken: 'a', refreshToken: 'r', expiresAt: time() + 86400));
        $this->store->save(3, 'never',  new TokenSet(accessToken: 'a', refreshToken: 'r'));
        $this->store->save(4, 'dead',   new TokenSet(accessToken: 'a', refreshToken: 'r', expiresAt: time() + 60));
        $this->store->markDead($this->store->find(4, 'dead'), 'revoked');

        // Act
        $due = $this->store->dueForRefresh(3600);

        // Assert
        $this->assertSame(['soon'], array_map(static fn (Connection $c): string => $c->provider, $due));
    }

    /**
     * A refresh writes the new tokens, and keeps a refresh token the provider omitted.
     *
     * The request and the write are one call precisely so this cannot come apart. A
     * provider that rotates the refresh token and a store that does not record it presents
     * a retired token at the next refresh — and the connection dies for no reason the user
     * caused.
     */
    public function testARefreshPersistsTheNewTokens(): void
    {
        // Arrange
        $this->store->save(7, 'acme', new TokenSet(
            accessToken: 'at-old',
            refreshToken: 'rt-old',
            expiresAt: time() + 30,
            scopes: ['read'],
        ));

        Client::fake([
            'https://acme.test/token' => ClientResponse::make(
                // No refresh_token in the response: "keep using yours".
                ['access_token' => 'at-new', 'expires_in' => 3600],
                200
            ),
        ]);

        // Act
        $refreshed = $this->store->refresh($this->store->find(7, 'acme'), new OAuthClient($this->provider()));

        // Assert — returned…
        $this->assertSame('at-new', $refreshed->accessToken);
        $this->assertSame('rt-old', $refreshed->refreshToken);

        // …and persisted, which is the half that matters on the next run.
        $stored = $this->store->find(7, 'acme');
        $this->assertSame('at-new', $stored->accessToken);
        $this->assertSame('rt-old', $stored->refreshToken);
        $this->assertSame(['read'], $stored->scopes, 'an omitted scope list is not a revocation');
    }

    /**
     * A revoked grant marks the connection dead, with the provider's reason.
     *
     * The row is kept rather than deleted: an interface has to be able to tell "never
     * connected" from "disconnected in July", and those are different sentences to show a
     * user.
     */
    public function testATerminalRefreshFailureMarksTheConnectionDead(): void
    {
        // Arrange
        $this->store->save(7, 'acme', new TokenSet(accessToken: 'at', refreshToken: 'rt', expiresAt: time() + 30));

        Client::fake([
            'https://acme.test/token' => ClientResponse::make([
                'error'             => 'invalid_grant',
                'error_description' => 'Token has been expired or revoked.',
            ], 400),
        ]);

        // Act
        try {
            $this->store->refresh($this->store->find(7, 'acme'), new OAuthClient($this->provider()));
            $this->fail('a revoked grant must still raise');
        } catch (OAuthClientException $exception) {
            $this->assertTrue($exception->isTerminal());
        }

        // Assert
        $dead = $this->store->find(7, 'acme');
        $this->assertTrue($dead->isDead());
        $this->assertStringContainsString('revoked', $dead->deadReason);
        $this->assertNotNull($dead->deadAt);
        // And it drops out of the scheduled job's queue.
        $this->assertSame([], $this->store->dueForRefresh(86400));
    }

    /**
     * A transient failure leaves the connection alone.
     *
     * The other side of the distinction. Killing a live connection over a 503 makes every
     * affected user authorise again because of somebody else's bad afternoon.
     */
    public function testATransientRefreshFailureLeavesTheConnectionActive(): void
    {
        // Arrange
        $this->store->save(7, 'acme', new TokenSet(accessToken: 'at', refreshToken: 'rt', expiresAt: time() + 30));

        Client::fake([
            'https://acme.test/token' => ClientResponse::make(['error' => 'temporarily_unavailable'], 503),
        ]);

        // Act
        try {
            $this->store->refresh($this->store->find(7, 'acme'), new OAuthClient($this->provider()));
            $this->fail('a 503 must still raise');
        } catch (OAuthClientException $exception) {
            $this->assertFalse($exception->isTerminal());
        }

        // Assert — untouched, and still due, so the next run tries again.
        $this->assertFalse($this->store->find(7, 'acme')->isDead());
        $this->assertCount(1, $this->store->dueForRefresh(3600));
    }

    /**
     * A connection with no refresh token is marked dead without contacting anybody.
     *
     * Sending an empty refresh token is a guaranteed error, and some providers count it
     * against a rate limit the live connections share. The fake is registered to fail the
     * test if it is reached at all.
     */
    public function testAnUnrefreshableConnectionIsNotSentToTheProvider(): void
    {
        // Arrange — a provider-issued token with no refresh token at all.
        $this->store->save(7, 'acme', new TokenSet(accessToken: 'at', expiresAt: time() + 30));

        Client::fake([
            'https://acme.test/token' => function (): ClientResponse {
                self::fail('a connection with no refresh token must not reach the provider');
            },
        ]);

        // Act
        try {
            $this->store->refresh($this->store->find(7, 'acme'), new OAuthClient($this->provider()));
            $this->fail('an unrefreshable connection must raise');
        } catch (OAuthClientException $exception) {
            $this->assertTrue($exception->isTerminal());
        }

        // Assert
        $this->assertTrue($this->store->find(7, 'acme')->isDead());
    }

    /**
     * A connection whose refresh token has itself expired is dead, and is not sent anywhere.
     *
     * The sibling of the case above and a different sentence for the operator: this one
     * *was* renewable and was left too long. It is the failure the scheduled job exists to
     * prevent, so the reason has to distinguish it from "there was never a refresh token"
     * — otherwise the cure for a whole class of dead connections looks like the provider's
     * fault rather than a schedule that was never installed.
     */
    public function testAConnectionWithAnExpiredRefreshTokenIsDeadWithoutARequest(): void
    {
        // Arrange — a refresh token that expired an hour ago.
        $this->store->save(7, 'acme', new TokenSet(
            accessToken: 'at',
            refreshToken: 'rt',
            expiresAt: time() + 30,
            refreshExpiresAt: time() - 3600,
        ));

        Client::fake([
            'https://acme.test/token' => function (): ClientResponse {
                self::fail('an expired refresh token must not be sent to the provider');
            },
        ]);

        // Act
        try {
            $this->store->refresh($this->store->find(7, 'acme'), new OAuthClient($this->provider()));
            $this->fail('an expired refresh token must raise');
        } catch (OAuthClientException $exception) {
            $this->assertTrue($exception->isTerminal());
        }

        // Assert — and the reason names which of the two failures this was.
        $dead = $this->store->find(7, 'acme');
        $this->assertTrue($dead->isDead());
        $this->assertStringContainsString('expired before it was used', $dead->deadReason);
    }

    /**
     * Re-authorising revives a connection that had been marked dead.
     *
     * The only thing that can. A user who reconnects expects it to work, and a row left
     * `dead` after a successful authorisation would be skipped by the scheduled job for
     * ever — the connection would appear to work until its first expiry and then stop.
     */
    public function testReauthorisingRevivesADeadConnection(): void
    {
        // Arrange
        $this->store->save(7, 'acme', new TokenSet(accessToken: 'at', refreshToken: 'rt'));
        $this->store->markDead($this->store->find(7, 'acme'), 'revoked');

        // Act
        $this->store->save(7, 'acme', new TokenSet(accessToken: 'at-new', refreshToken: 'rt-new'));

        // Assert
        $revived = $this->store->find(7, 'acme');
        $this->assertFalse($revived->isDead());
        $this->assertSame('', $revived->deadReason);
        $this->assertNull($revived->deadAt);
    }

    /** Disconnecting removes the row entirely. */
    public function testForgettingAConnectionRemovesIt(): void
    {
        // Arrange
        $this->store->save(7, 'acme', new TokenSet(accessToken: 'at'));

        // Act
        $this->store->forget(7, 'acme');

        // Assert
        $this->assertNull($this->store->find(7, 'acme'));
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    protected function provider(): Provider
    {
        return new Provider(
            name: 'acme',
            authorizeUrl: 'https://acme.test/authorize',
            tokenUrl: 'https://acme.test/token',
            clientId: 'id',
            clientSecret: 'secret',
            redirectUri: 'https://app.test/callback',
        );
    }

    protected function connect(): Database
    {
        return Factory::getDatabase();
    }

    protected function makeApp(): Application
    {
        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;

        return $app;
    }

    protected function runMigration(): void
    {
        $dir = dirname(__DIR__, 4) . '/database/migrations/framework/oauth';
        foreach (MigrationLoader::loadFromDirectory($dir, $this->app) as $migration) {
            $migration->up();
        }
    }

    protected function dropTable(): void
    {
        $this->db->schema()->dropTableIfExists('#PREFIX#oauthconnections');
    }
}
