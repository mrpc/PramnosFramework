<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\JWT;
use Pramnos\Auth\SessionExchange;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Http\RequestIdentity;

/**
 * `SessionExchange::issue()` against a real database, from the session to the token row.
 *
 * The refusals live in a unit test, because refusing is a decision about the request. This
 * class is the other half — what happens when the answer is yes — and it has to be an
 * integration test for two reasons that a mock removes rather than models:
 *
 *   - **the role is re-read from the database.** That is the first of the four documented
 *     decisions and the whole reason a remember-me cookie cannot outlive a demotion here.
 *     A stubbed user proves the code calls something; only a real row proves it reads the
 *     account instead of the session.
 *   - **`addToken()` writes a row.** A credential nobody can revoke is worse than no
 *     credential, and revocation is a query against `usertokens`. If the insert silently
 *     did not happen — a column with no default, a type mismatch — the token would still
 *     verify by signature and could never be withdrawn.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class SessionExchangeMintTest extends TestCase
{
    /** @var int The first of the user ids these tests use */
    private const USER_BASE = 771201;

    /** @var int Bumped per test, so no two tests share a user id */
    private static int $sequence = 0;

    /** @var int This test's user id */
    private int $userId = self::USER_BASE;

    /** @var string The signing key these tests configure */
    private const KEY = 'session-exchange-integration-key';

    /**
     * A second key, for the test that the declared one is preferred.
     *
     * Long deliberately: `JWT::encode()` rejects a short key outright, so a 19-character
     * one produced an `InvalidArgumentException: Invalid key length` that `issue()` caught
     * and reported as a null token — indistinguishable from every other refusal.
     *
     * @var string
     */
    private const DECLARED_KEY = 'a-very-specific-declared-signing-key';

    /** @var Database The fixture connection */
    protected Database $db;

    /**
     * Builds the two tables this exercises, once for the class.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        $test = new static('setUpBeforeClass');
        $test->boot();
        $test->dropTables();
        $test->createTables();
    }

    /**
     * Leaves the database as it was found.
     *
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
        $test = new static('tearDownAfterClass');
        $test->boot();
        $test->dropTables();
    }

    /**
     * Fresh rows and a fresh identity per test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->boot();

        // Rows only — the schema belongs to the class. Recreating it per test was most of
        // the runtime of the classes converted during the suite performance work.
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->query('DELETE FROM `#PREFIX#usertokens`');
        $this->db->query('DELETE FROM `#PREFIX#users`');
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

        // `new User($id)` caches its read, so a row written now would otherwise be
        // answered from a previous test's miss.
        //
        // The category, not everything. A full `cacheflush()` walks and deletes whatever
        // the rest of the suite has written to the file cache: 0.7s for this class alone
        // and 13s inside a full run, all of it spent emptying a cache that the tests after
        // this one then have to refill. `userlist` is the category `User::load()` reads
        // through — see its `get(true, 10, 'userlist')`.
        $this->db->cacheflush('userlist');

        RequestIdentity::reset();

        // A fresh id per test, because `new User($id)` caches its read for an hour and the
        // cache is keyed on the id. Two tests seeding the same user with different roles
        // is exactly the shape of `testTheRoleComesFromTheDatabaseAndNotTheSession`, and
        // with a shared id it passed by reading the previous test's role — the failure
        // looked like the production code ignoring a demotion.
        $this->userId = self::USER_BASE + (++self::$sequence);
    }

    /**
     * Clears the process-wide identity and the injected application.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        RequestIdentity::reset();
        $this->setApplication(null);
    }

    /**
     * Loads the fixture settings and connects.
     *
     * @return void
     */
    protected function boot(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);

        $this->db = Factory::getDatabase();

        // Repair the singleton rather than trust it.
        //
        // `Database::getInstance()` caches one instance per name in a method-static, built
        // from whatever settings existed the first time anybody asked. A unit test earlier in
        // the run that merely constructs a `User` creates that instance before any settings
        // are loaded, leaving it pointed at `localhost` over a socket that does not exist —
        // and every integration class afterwards inherits it and cannot connect.
        //
        // The static is not reachable to reset, and `loadSettings()` on an existing singleton
        // does not retrofit it, so the fields are assigned from the fixture file directly.
        // Order-independence is the point: this class must pass alone, after the unit tests,
        // and in the middle of the whole suite.
        if (!$this->db->connected) {
            $config = require $settingsFile;
            $db     = $config['database'];

            $this->db->server   = $db['hostname'];
            $this->db->database = $db['database'];
            $this->db->user     = $db['user'];
            $this->db->password = $db['password'];
            $this->db->prefix   = (string) ($db['prefix'] ?? '');
        }

        if (!$this->db->connected) {
            $this->db->connect(true);
        }
    }

    /**
     * Creates `users` and `usertokens` from the framework migrations.
     *
     * The migrations rather than hand-written DDL: `addToken()` writes eleven columns and
     * several are `NOT NULL` with no default, so a hand-rolled table would pass while the
     * real one rejected the insert.
     *
     * @return void
     */
    protected function createTables(): void
    {
        $authDir = dirname(__DIR__, 3) . '/database/migrations/framework/auth';

        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            '2020_01_01_000010_create_users_table.php',
            '2020_01_01_000014_create_usertokens_table.php',
        ] as $file) {
            $path = $authDir . '/' . $file;
            require_once $path;

            $parts = array_slice(explode('_', basename($path, '.php')), 4);
            $class = 'Pramnos\\Framework\\Migrations\\Auth\\'
                . implode('', array_map('ucfirst', $parts));

            if (class_exists($class)) {
                (new $class($this->migrationApp()))->up();
            }
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Drops them.
     *
     * @return void
     */
    protected function dropTables(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->query('DROP TABLE IF EXISTS `#PREFIX#usertokens`');
        $this->db->query('DROP TABLE IF EXISTS `#PREFIX#users`');
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * A minimal application for a migration to run against.
     *
     * @return Application
     */
    protected function migrationApp(): Application
    {
        $app = $this->bareApplication();
        $app->database = $this->db;

        return $app;
    }

    /**
     * A real `Application` with its constructor skipped.
     *
     * **Not a PHPUnit mock, and the reason is a trap worth naming.** `Application` extends
     * `Base`, which implements `__set()` to stash unknown properties in `_data`. A mock
     * replaces `__set()` with a no-op stub, so `$app->authenticationKey = 'k'` was accepted,
     * silently discarded, and `isset()` reported false — which read as the production code
     * failing to find a key it had just been handed.
     *
     * @return Application
     */
    protected function bareApplication(): Application
    {
        return new class extends Application {
            /** Skips the real constructor: no database, language or session is wanted. */
            public function __construct()
            {
            }
        };
    }

    /**
     * Installs (or removes) the application `SessionExchange` reads its key from.
     *
     * Reflection into `Application::$appInstances`, because the class reads the current
     * instance rather than accepting one — and reads it with `currentInstance()`, which
     * returns null instead of constructing an application. That is the behaviour under
     * test in {@see testNoConfiguredKeyMeansNoToken}: without the injection there is
     * genuinely no application, which is the state a misconfigured deployment is in.
     *
     * @param  object|null $app The application to install, or null to clear
     * @return void
     */
    protected function setApplication(?object $app): void
    {
        $instances = new \ReflectionProperty(Application::class, 'appInstances');
        $last      = new \ReflectionProperty(Application::class, 'lastUsedApplication');

        if ($app === null) {
            $instances->setValue(null, []);
            $last->setValue(null, null);

            return;
        }

        $instances->setValue(null, ['default' => $app]);
        $last->setValue(null, 'default');
    }

    /**
     * An application carrying a signing key and an optional configured TTL.
     *
     * @param  string   $key The JWT signing key
     * @param  int|null $ttl `auth.token_ttl`, or null to leave it unset
     * @return Application
     */
    protected function appWithKey(string $key, ?int $ttl = null): Application
    {
        $app = $this->bareApplication();

        $app->database          = $this->db;
        $app->authenticationKey = $key;
        if ($ttl !== null) {
            $app->applicationInfo = ['auth' => ['token_ttl' => $ttl]];
        }

        return $app;
    }

    /**
     * Inserts a user row.
     *
     * @param  int $usertype The role stored in the database
     * @return void
     */
    protected function seedUser(int $usertype): void
    {
        $this->db->query(
            $this->db->prepareQuery(
                // Every column here is NOT NULL with no default in the framework's own
                // migration — `usertype`, `sex`, `birthdate` and `modified` included. Named
                // explicitly rather than trusted to defaults, because the first version of
                // this fixture guessed `status` and `registered` from another project's
                // schema and every test failed on the insert rather than on the behaviour.
                'INSERT INTO `#PREFIX#users` (`userid`, `username`, `email`, `password`,
                    `usertype`, `active`, `validated`, `regdate`, `sex`, `birthdate`,
                    `modified`)
                 VALUES (%d, %s, %s, %s, %d, 1, 1, %d, 0, 0, %d)',
                $this->userId,
                'exchange-' . $this->userId,
                'exchange@example.test',
                'not-a-real-hash',
                $usertype,
                time(),
                time()
            )
        );
        $this->db->cacheflush('userlist');
    }

    /**
     * Seals a session-authenticated identity for the seeded user.
     *
     * `usertype` is deliberately passed as the value the *session* believes, which the
     * tests below set to something the database contradicts.
     *
     * @param  int $sessionUsertype What the session thinks the role is
     * @return void
     */
    protected function sealSession(int $sessionUsertype): void
    {
        RequestIdentity::seal(
            (object) ['userid' => $this->userId, 'usertype' => $sessionUsertype],
            'session'
        );
    }

    /**
     * Rows in `usertokens` for the seeded user.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function tokenRows(): array
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                'SELECT * FROM `#PREFIX#usertokens` WHERE `userid` = %d',
                $this->userId
            )
        );

        return ($result === false) ? [] : $result->fetchAll();
    }

    /**
     * A signed-in session receives a token, and the token is recorded.
     *
     * The happy path, asserted at both ends. A JWT that verifies but was never written to
     * `usertokens` is a credential nobody can revoke — worse than no credential — because
     * revocation is a query against that table and not a property of the signature.
     *
     * @return void
     */
    public function testASignedInSessionReceivesARecordedToken(): void
    {
        // Arrange
        $this->setApplication($this->appWithKey(self::KEY));
        $this->seedUser(usertype: 99);
        $this->sealSession(sessionUsertype: 99);

        // Act
        $token = SessionExchange::issue();

        // Assert — a usable token
        $this->assertIsString($token);
        $claims = JWT::decode($token, self::KEY, ['HS256']);
        $this->assertIsObject($claims);

        // …and a row that can revoke it
        $rows = $this->tokenRows();
        $this->assertCount(1, $rows);
        $this->assertSame($token, $rows[0]['token']);
        $this->assertSame('auth', $rows[0]['tokentype']);
        $this->assertSame('session_exchange', $rows[0]['notes']);
        $this->assertSame(1, (int) $rows[0]['status']);
    }

    /**
     * The role is read from the database, not from the session.
     *
     * The decision the class exists to make. A remember-me cookie can outlive a demotion
     * by a fortnight, and a token minted from that session would then be good for its full
     * lifetime afterwards — so the session here claims `usertype` 99 while the row says 10,
     * and a minimum of 90 must be refused.
     *
     * This is the test that fails if somebody later "optimises away" the second read.
     *
     * @return void
     */
    public function testTheRoleComesFromTheDatabaseAndNotTheSession(): void
    {
        // Arrange — the session is stale in the direction that matters
        $this->setApplication($this->appWithKey(self::KEY));
        $this->seedUser(usertype: 10);
        $this->sealSession(sessionUsertype: 99);

        // Act
        $token = SessionExchange::issue(minimumUserType: 90);

        // Assert
        $this->assertNull($token, 'The demotion in the database must win.');
        $this->assertSame([], $this->tokenRows(), 'And nothing may be written.');
    }

    /**
     * The same session is accepted when the database agrees.
     *
     * The other side of the test above, so that its refusal is attributable to the role
     * rather than to the class refusing everything it is handed.
     *
     * @return void
     */
    public function testTheSameRequestSucceedsWhenTheDatabaseAgrees(): void
    {
        // Arrange
        $this->setApplication($this->appWithKey(self::KEY));
        $this->seedUser(usertype: 95);
        $this->sealSession(sessionUsertype: 95);

        // Act
        $token = SessionExchange::issue(minimumUserType: 90);

        // Assert
        $this->assertIsString($token);
        $this->assertCount(1, $this->tokenRows());
    }

    /**
     * A session for a user who no longer exists is refused.
     *
     * A deleted account with a live cookie. `new User($id)` returns an unloaded object
     * rather than throwing, so without the identity check that follows it the mint would
     * proceed and sign a token for nobody.
     *
     * @return void
     */
    public function testASessionForADeletedUserIsRefused(): void
    {
        // Arrange — sealed, but no row was ever seeded
        $this->setApplication($this->appWithKey(self::KEY));
        $this->sealSession(sessionUsertype: 99);

        // Act
        $token = SessionExchange::issue();

        // Assert
        $this->assertNull($token);
        $this->assertSame([], $this->tokenRows());
    }

    /**
     * An explicit TTL becomes both the `exp` claim and the stored expiry.
     *
     * Two places, one value. If they disagree the token outlives its row or the row
     * outlives the token, and either way a session list shows a person something other
     * than what they hold.
     *
     * @return void
     */
    public function testAnExplicitTtlReachesBothTheClaimAndTheRow(): void
    {
        // Arrange
        $this->setApplication($this->appWithKey(self::KEY));
        $this->seedUser(usertype: 99);
        $this->sealSession(sessionUsertype: 99);
        $before = time();

        // Act
        $token = SessionExchange::issue(ttl: 3600);

        // Assert
        $this->assertIsString($token);
        $claims = JWT::decode($token, self::KEY, ['HS256']);
        $this->assertGreaterThanOrEqual($before + 3600, (int) $claims->exp);
        $this->assertLessThanOrEqual(time() + 3600, (int) $claims->exp);
        $this->assertSame((int) $claims->exp, (int) $this->tokenRows()[0]['expires']);
    }

    /**
     * With no TTL anywhere the token does not expire, and says so by omission.
     *
     * `auth.token_ttl` itself defaults to no expiry, so this is what an application that
     * configured nothing gets. An `exp` of 0 would be an expiry in 1970 and would reject
     * the token immediately, which is why the claim is omitted rather than zeroed.
     *
     * @return void
     */
    public function testWithNoTtlConfiguredTheTokenCarriesNoExpiry(): void
    {
        // Arrange
        $this->setApplication($this->appWithKey(self::KEY));
        $this->seedUser(usertype: 99);
        $this->sealSession(sessionUsertype: 99);

        // Act
        $token = SessionExchange::issue();

        // Assert
        $claims = JWT::decode((string) $token, self::KEY, ['HS256']);
        $this->assertFalse(isset($claims->exp), 'No expiry claim at all, not exp = 0.');
    }

    /**
     * The application's configured TTL applies when the caller names none.
     *
     * @return void
     */
    public function testTheConfiguredTtlAppliesWhenTheCallerNamesNone(): void
    {
        // Arrange
        $this->setApplication($this->appWithKey(self::KEY, ttl: 7200));
        $this->seedUser(usertype: 99);
        $this->sealSession(sessionUsertype: 99);
        $before = time();

        // Act
        $token = SessionExchange::issue();

        // Assert
        $claims = JWT::decode((string) $token, self::KEY, ['HS256']);
        $this->assertGreaterThanOrEqual($before + 7200, (int) $claims->exp);
    }

    /**
     * The notes reach the row, so a session list can say where a credential came from.
     *
     * The reason the token type stays `auth`: every verifier looks tokens up by type, and
     * inventing a type here would mean every consumer's lookup had to learn about it. The
     * origin lives in `notes` instead — which only helps if it is actually written.
     *
     * @return void
     */
    public function testTheNotesReachTheRow(): void
    {
        // Arrange
        $this->setApplication($this->appWithKey(self::KEY));
        $this->seedUser(usertype: 99);
        $this->sealSession(sessionUsertype: 99);

        // Act
        SessionExchange::issue(notes: 'panel_bounce');

        // Assert
        $this->assertSame('panel_bounce', $this->tokenRows()[0]['notes']);
        $this->assertSame('auth', $this->tokenRows()[0]['tokentype']);
    }

    /**
     * A successful exchange re-seals the identity with the token it issued.
     *
     * So the debug toolbar can describe what was just handed over — its claims and its
     * expiry, never its value — at the moment somebody wants to know how long they have.
     *
     * @return void
     */
    public function testTheIdentityIsResealedWithTheIssuedToken(): void
    {
        // Arrange
        $this->setApplication($this->appWithKey(self::KEY));
        $this->seedUser(usertype: 99);
        $this->sealSession(sessionUsertype: 99);

        // Act
        $token = SessionExchange::issue();

        // Assert
        $this->assertSame('session-exchange', RequestIdentity::via());
        $this->assertSame($token, RequestIdentity::issuedToken());
        $this->assertSame($this->userId, RequestIdentity::subject());
    }

    /**
     * With no key to declare and no site URL to derive one from, nothing is issued.
     *
     * The interesting half of this is *why* it cannot simply derive one anyway.
     * `Api::deriveAuthenticationKey()` reduces to `md5($version)` when `sURL` is undefined,
     * and the version defaults to `edge` — so every installation in that state would sign
     * with the same publicly computable constant, and a token minted by any of them would
     * verify against all of them. That is not a weak key, it is no key.
     *
     * Refusing is also the only honest answer: a token signed with an empty key verifies
     * against an empty key, so the alternative to null is not an error but a credential
     * anybody can forge.
     *
     * There is no application installed at all here, which additionally proves
     * `currentInstance()` returns null rather than constructing one in the middle of
     * minting a credential — the thing its own docblock forbids.
     *
     * @return void
     */
    public function testWithNoKeyAndNoSiteUrlNothingIsIssued(): void
    {
        // Arrange — `sURL` is not defined under the test bootstrap, which is what makes
        // this reachable without touching a constant that cannot be unset
        if (defined('sURL') && (string) sURL !== '') {
            $this->markTestSkipped('sURL is defined; the derivation is site-specific here.');
        }

        $this->setApplication(null);
        $this->seedUser(usertype: 99);
        $this->sealSession(sessionUsertype: 99);

        // Act
        $token = SessionExchange::issue();

        // Assert
        $this->assertNull($token);
        $this->assertSame([], $this->tokenRows(), 'And no row promising a credential.');
    }

    /**
     * A declared `authenticationKey` is preferred over any derivation.
     *
     * An application that configured one explicitly — or an `Api`, which computes its own
     * in the constructor — must have that value used, or a token would be signed with one
     * key and verified with another. That failure surfaces as an authentication error
     * arbitrarily far from its cause.
     *
     * @return void
     */
    public function testADeclaredKeyIsPreferredOverTheDerivedOne(): void
    {
        // Arrange
        $this->setApplication($this->appWithKey(self::DECLARED_KEY));
        $this->seedUser(usertype: 99);
        $this->sealSession(sessionUsertype: 99);

        // Act
        $token = (string) SessionExchange::issue();

        // Assert — it verifies against the declared key
        $this->assertIsObject(JWT::decode($token, self::DECLARED_KEY, ['HS256']));

        // …and not against the derived one
        $this->expectException(\Throwable::class);
        JWT::decode($token, \Pramnos\Application\Api::deriveAuthenticationKey(), ['HS256']);
    }

    /**
     * A redirect URL built from a real token survives the round trip.
     *
     * The unit tests cover the fragment's shape; this one checks that a token as actually
     * minted — dots, base64url, and whatever `rawurlencode()` makes of it — comes back
     * byte-identical. A token mangled in transport fails authentication far from here,
     * where it reads as an expiry problem.
     *
     * @return void
     */
    public function testARealTokenSurvivesTheRedirectUrl(): void
    {
        // Arrange
        $this->setApplication($this->appWithKey(self::KEY));
        $this->seedUser(usertype: 99);
        $this->sealSession(sessionUsertype: 99);
        $token = (string) SessionExchange::issue();

        // Act
        $url      = SessionExchange::redirectUrl('https://example.test/panel/', $token);
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        parse_str((string) $fragment, $parsed);

        // Assert
        $this->assertSame($token, $parsed['session']);
        $this->assertNull(
            parse_url($url, PHP_URL_QUERY),
            'The credential must never end up in a query string.'
        );
    }

    /**
     * A declared key that `JWT::encode()` rejects yields null, not a half-issued token.
     *
     * `JWT::encode()` enforces a minimum key length, so an application that configured a
     * short `authenticationKey` throws from inside the mint — after the role check passed
     * and before anything was recorded. This is the only path through the `catch`, and what
     * it must not do is leave a row for a token the caller never received: a session list
     * showing a credential nobody holds is worse than showing none.
     *
     * Found by making exactly this mistake in a fixture, where a 19-character key produced
     * a null indistinguishable from every other refusal.
     *
     * @return void
     */
    public function testAKeyTooShortToSignWithYieldsNullAndNoRow(): void
    {
        // Arrange
        $this->setApplication($this->appWithKey('too-short'));
        $this->seedUser(usertype: 99);
        $this->sealSession(sessionUsertype: 99);

        // Act
        $token = SessionExchange::issue();

        // Assert
        $this->assertNull($token);
        $this->assertSame([], $this->tokenRows(), 'No row for a token that was never issued.');
    }

    /**
     * The audience claim is the application's API key when one is loaded.
     *
     * `aud` is what lets a verifier reject a token minted for a different API on the same
     * host. Omitting it silently — which is what happens when nothing reads `apiKey` — makes
     * every such token acceptable everywhere, and no test of the happy path would notice.
     *
     * @return void
     */
    public function testTheAudienceClaimCarriesTheApiKey(): void
    {
        // Arrange
        $app = $this->appWithKey(self::KEY);
        $app->apiKey = (object) ['apikey' => 'the-api-key-of-this-application'];
        $this->setApplication($app);
        $this->seedUser(usertype: 99);
        $this->sealSession(sessionUsertype: 99);

        // Act
        $token = (string) SessionExchange::issue();

        // Assert
        $claims = JWT::decode($token, self::KEY, ['HS256']);
        $this->assertSame('the-api-key-of-this-application', $claims->aud);
    }
}
