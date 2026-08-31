<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Controllers\ApiAccount;
use Pramnos\Auth\PasswordHash;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\User;

/**
 * `POST /account/login` end to end: does the token it hands back actually work?
 *
 * The controller has thorough unit tests — a non-POST refused, missing credentials, a wrong
 * password, a lockout, the second-factor step, the shape of the response. Every one of them
 * replaces `verifyCredentials()` and `issueToken()` with a double, which is right for what they
 * assert and means the chain those two sit in had never run: 44 of the controller's 115
 * statements.
 *
 * That chain is the endpoint's whole purpose. A password goes in, and what comes back has to be
 * a credential the API will accept on the next request — three separate pieces of work
 * (`JWT::encode`, `User::addToken`, and the response) that agree on nothing unless somebody
 * checks. So this test signs in with a **real** password against a **real** users row and then
 * presents the token the way a client would.
 *
 * The expiry is the part with two answers. A TTL stamps the JWT's `exp` claim *and* the
 * `usertokens.expires` column, and they are rejected by different code — `JWT::decode` for the
 * first, `loadByToken()` for the second. Stamping one and not the other leaves a token that is
 * dead by one route and alive by the other, which is the shape of a session that will not end.
 *
 * Both backends: {@see ApiLoginIssuesAWorkingTokenPostgreSQLTest} re-runs it. The token write is
 * an insert with a nullable timestamp column, and reading it back is a `WHERE token_lookup = ?`
 * with an expiry comparison — a comparison whose column type differs between the engines.
 */
#[CoversClass(ApiAccount::class)]
class ApiLoginIssuesAWorkingTokenTest extends BaseTestCase
{
    private $db;

    private int $userId = 0;

    private const USERNAME = 'apilogin_probe';

    private const EMAIL = 'apilogin_probe@example.test';

    private const PASSWORD = 'a-real-password-9!';

    private const KEY = 'integration-signing-key-for-api-login';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        $this->runMigrations([
            \Pramnos\Framework\Migrations\Auth\CreateUsersTable::class,
            \Pramnos\Framework\Migrations\Auth\CreateLoginlockoutTable::class,
            \Pramnos\Framework\Migrations\Auth\CreateUsertokensTable::class,
            \Pramnos\Framework\Migrations\Auth\AddTokenLookupToUsertokens::class,
        ], $this->db);

        User::clearUserCache();
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->userId = $this->probeUser();
        $this->clearTokens();
    }

    protected function tearDown(): void
    {
        $this->clearTokens();

        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        User::clearUserCache();

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    // ── The whole chain ───────────────────────────────────────────────────────

    /**
     * A correct password issues a token, and that token loads its own user back.
     *
     * The end-to-end claim, and the only one that catches the three pieces disagreeing:
     * `JWT::encode()` produces the string, `User::addToken()` writes the row the API middleware
     * looks up, and the response carries the value the client will send. A token that decodes
     * but was never stored, or stored under a different lookup, fails only here.
     */
    public function testACorrectPasswordIssuesATokenThatAuthenticates(): void
    {
        // Arrange
        $_POST = ['username' => self::USERNAME, 'password' => self::PASSWORD];

        // Act
        $answer = $this->decode($this->controller()->login());

        // Assert
        $this->assertSame('success', $answer['status'] ?? null, json_encode($answer));
        $this->assertSame('Bearer', $answer['token_type'] ?? null);

        $token = (string) ($answer['access_token'] ?? '');
        $this->assertNotSame('', $token);

        $loaded = new User();
        $this->assertNotFalse(
            $loaded->loadByToken($token, 'auth', false),
            'the token this endpoint just issued does not load its user'
        );
        $this->assertSame($this->userId, (int) $loaded->userid);
    }

    /**
     * The profile beside the token carries what a client needs and nothing else.
     *
     * `userPayload()` is built from a fully loaded `User`, which holds every column of the row
     * — the password hash among them. Naming three fields rather than serialising the object is
     * the whole of the protection, and this is the assertion that it stays that way.
     */
    public function testThePayloadCarriesNoCredentialMaterial(): void
    {
        // Arrange
        $_POST = ['username' => self::USERNAME, 'password' => self::PASSWORD];

        // Act
        $answer = $this->decode($this->controller()->login());
        $user   = (array) ($answer['user'] ?? []);

        // Assert
        $this->assertSame(
            ['id', 'username', 'email'],
            array_keys($user),
            'the profile grew a field; check what else is on a loaded User'
        );
        $this->assertSame($this->userId, (int) $user['id']);
        $this->assertStringNotContainsString(
            (string) $this->storedHash(),
            (string) json_encode($answer),
            'the stored password hash reached the response'
        );
    }

    /** A wrong password is refused, and writes no token at all. */
    public function testAWrongPasswordIssuesNothing(): void
    {
        // Arrange
        $_POST = ['username' => self::USERNAME, 'password' => 'not-the-password'];

        // Act
        $answer = $this->decode($this->controller()->login());

        // Assert
        $this->assertSame('invalid_credentials', $answer['error'] ?? null);
        $this->assertSame(0, $this->tokenCount(), 'a failed login wrote a token row');
    }

    /** An account that does not exist is refused the same way, and says nothing more. */
    public function testAnUnknownAccountIsRefusedWithoutDetail(): void
    {
        // Arrange
        $_POST = ['username' => 'nobody_by_that_name', 'password' => self::PASSWORD];

        // Act
        $answer = $this->decode($this->controller()->login());

        // Assert
        $this->assertSame(
            'invalid_credentials',
            $answer['error'] ?? null,
            'an unknown account must not be distinguishable from a wrong password'
        );
    }

    // ── The expiry, in both places ────────────────────────────────────────────

    /**
     * A TTL stamps the JWT claim **and** the stored row.
     *
     * Two mechanisms reject an expired token, and they read different things: `JWT::decode()`
     * refuses a past `exp` claim, and `loadByToken()` refuses a row past its `expires`. A TTL
     * that reached only one of them would leave a token that is dead to one caller and live to
     * another — and which of the two an installation notices depends on how it authenticates.
     */
    public function testATtlStampsBothTheClaimAndTheRow(): void
    {
        // Arrange
        $ttl = 3600;
        Application::currentInstance()->applicationInfo['auth']['token_ttl'] = $ttl;
        $_POST = ['username' => self::USERNAME, 'password' => self::PASSWORD];
        $before = time();

        // Act
        $token = (string) ($this->decode($this->controller()->login())['access_token'] ?? '');

        // Assert
        $this->assertNotSame('', $token);

        $claims = (array) \Pramnos\Auth\JWT::decode($token, self::KEY, ['HS256']);
        $this->assertGreaterThanOrEqual($before + $ttl, (int) $claims['exp']);
        $this->assertLessThanOrEqual(time() + $ttl, (int) $claims['exp']);

        $stored = $this->storedToken($token);
        $this->assertNotNull($stored, 'no row was written for the issued token');
        $this->assertNotNull($stored['expires'] ?? null, 'the row has no expiry, only the claim');
        $this->assertSame(
            (int) $claims['exp'],
            (int) $stored['expires'],
            'the claim and the row disagree about when the token dies'
        );
    }

    /**
     * With no TTL configured the token does not expire, which is the historical behaviour.
     *
     * Stated as a test because it is a default that a reasonable person would change: existing
     * installations have tokens with no expiry, and giving them one silently would sign every
     * client out at once on the day the framework is upgraded. Expiry is opt-in through
     * `auth.token_ttl`.
     */
    public function testWithNoTtlTheTokenDoesNotExpire(): void
    {
        // Arrange — no token_ttl anywhere in the application's settings.
        $application = Application::currentInstance();
        unset($application->applicationInfo['auth']['token_ttl']);
        $_POST = ['username' => self::USERNAME, 'password' => self::PASSWORD];

        // Act
        $token = (string) ($this->decode($this->controller()->login())['access_token'] ?? '');

        // Assert
        $claims = (array) \Pramnos\Auth\JWT::decode($token, self::KEY, ['HS256']);
        $this->assertArrayNotHasKey('exp', $claims);
        $this->assertNull(($this->storedToken($token) ?? [])['expires'] ?? null);
    }

    /**
     * A negative TTL is read as "no expiry" rather than as an already-dead token.
     *
     * `max(0, …)` on a configuration value somebody typed. The alternative is an installation
     * whose every login hands out a token that expired before the response was written, with
     * nothing in the answer to say so.
     */
    public function testANegativeTtlIsTreatedAsNoExpiry(): void
    {
        // Arrange
        Application::currentInstance()->applicationInfo['auth']['token_ttl'] = -60;
        $_POST = ['username' => self::USERNAME, 'password' => self::PASSWORD];

        // Act
        $token = (string) ($this->decode($this->controller()->login())['access_token'] ?? '');

        // Assert
        $claims = (array) \Pramnos\Auth\JWT::decode($token, self::KEY, ['HS256']);
        $this->assertArrayNotHasKey('exp', $claims);
    }

    /**
     * `nbf` is backdated twelve hours, on purpose.
     *
     * A "not before" of exactly now makes a token invalid on any client whose clock is a few
     * seconds behind the server's — which is most of them, some of the time, and produces a
     * login that fails only for some users and only sometimes. Twelve hours is generous enough
     * that nobody meets it; the `exp` claim is what actually bounds the token.
     */
    public function testTheNotBeforeClaimToleratesAClientClockBehindTheServer(): void
    {
        // Arrange
        $_POST = ['username' => self::USERNAME, 'password' => self::PASSWORD];
        $issuedAt = time();

        // Act
        $token = (string) ($this->decode($this->controller()->login())['access_token'] ?? '');

        // Assert
        $claims = (array) \Pramnos\Auth\JWT::decode($token, self::KEY, ['HS256']);
        $this->assertLessThanOrEqual($issuedAt - (3600 * 12), (int) $claims['nbf']);
    }

    // ── When the installation cannot sign ─────────────────────────────────────

    /**
     * With no signing key the answer is a 500 that says which key, and no token row.
     *
     * The failure of a misconfigured installation rather than of a caller, so a 4xx would send
     * an integrator looking at their own request. The important half is the second: nothing is
     * written, because a token row with no token is a row that can never be revoked.
     */
    public function testWithoutASigningKeyNothingIsIssued(): void
    {
        // Arrange
        $_POST = ['username' => self::USERNAME, 'password' => self::PASSWORD];

        // Act
        $answer = $this->decode($this->controller(signingKey: '')->login());

        // Assert
        $this->assertSame('token_unavailable', $answer['error'] ?? null);
        $this->assertStringContainsString('signing key', (string) $answer['error_description']);
        $this->assertSame(0, $this->tokenCount(), 'a row was written for a token that never existed');
    }

    // ── Logging out ───────────────────────────────────────────────────────────

    /**
     * Logging out stops the token loading its user.
     *
     * With no expiry by default, revocation is the only thing that ends an API session. Asserted
     * through `loadByToken()` rather than by reading `status`, because the column is only worth
     * what the lookup makes of it.
     */
    public function testLoggingOutEndsTheSession(): void
    {
        // Arrange
        $_POST = ['username' => self::USERNAME, 'password' => self::PASSWORD];
        $token = (string) ($this->decode($this->controller()->login())['access_token'] ?? '');
        $this->assertNotSame('', $token, 'precondition: a token was issued');

        // Act
        $_SERVER['HTTP_ACCESSTOKEN'] = $token;
        $this->controller()->logout();
        unset($_SERVER['HTTP_ACCESSTOKEN']);

        // Assert
        $loaded = new User();
        $this->assertFalse(
            (bool) $loaded->loadByToken($token, 'auth', false),
            'a revoked token still loads its user'
        );
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /**
     * The controller with the signing key supplied and nothing else replaced.
     *
     * `signingKey()` and `audience()` read the API application object — an `Api` instance built
     * by the router, which a test has no reason to construct. Everything below them is the real
     * thing: the login flow, the credentials check against the users table, `JWT::encode()`, and
     * the token write.
     */
    private function controller(string $signingKey = self::KEY): object
    {
        return new class ($signingKey) extends ApiAccount {
            public function __construct(private string $key)
            {
                /*
                 * No parent constructor — this needs a database, not a router and a theme — but
                 * the application itself is handed over, because `tokenTtl()` reads
                 * `$this->application->applicationInfo` rather than the current instance. A
                 * controller built without one silently gets a TTL of 0, which is the
                 * never-expires default; the router always supplies one, and a test that did not
                 * would be asserting against the wrong branch.
                 */
                $this->application = \Pramnos\Application\Application::currentInstance();
            }

            protected function signingKey(): string
            {
                return $this->key;
            }

            protected function audience(): string
            {
                return 'integration-audience';
            }
        };
    }

    /** The body of a response, decoded. */
    private function decode(mixed $answer): array
    {
        if (is_array($answer)) {
            return $answer;
        }

        return (array) json_decode((string) $answer->getBody(), true);
    }

    /** This test's account, with a password the real driver will verify. */
    private function probeUser(): int
    {
        $existing = $this->db->queryBuilder()->table('users')
            ->where('username', self::USERNAME)->first();

        if ($existing && $existing->numRows > 0) {
            return (int) $existing->fields['userid'];
        }

        $this->db->queryBuilder()->table('users')->insert([
            'username' => self::USERNAME,
            'email'    => self::EMAIL,
            'password' => PasswordHash::make(self::PASSWORD),
            'active'   => 1,
        ]);

        return (int) $this->db->getInsertId();
    }

    private function storedHash(): string
    {
        $row = $this->db->queryBuilder()->table('users')
            ->select(['password'])->where('userid', $this->userId)->first();

        return (string) ($row->fields['password'] ?? '');
    }

    /** @return array<string, mixed>|null */
    private function storedToken(string $token): ?array
    {
        $row = $this->db->queryBuilder()->table('#PREFIX#usertokens')
            ->where('token_lookup', \Pramnos\User\Token::lookup($token))
            ->first();

        return $row && $row->numRows > 0 ? (array) $row->fields : null;
    }

    private function tokenCount(): int
    {
        return (int) $this->db->queryBuilder()->table('#PREFIX#usertokens')
            ->where('userid', $this->userId)->count();
    }

    private function clearTokens(): void
    {
        if ($this->userId === 0) {
            return;
        }

        try {
            $this->db->queryBuilder()->table('#PREFIX#usertokens')
                ->where('userid', $this->userId)->delete();
        } catch (\Throwable $exception) {
            // No table on a lane mid-migration; nothing to clear.
        }
    }
}
