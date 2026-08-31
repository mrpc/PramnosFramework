<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Security\Encrypter;
use Pramnos\User\Token;
use Pramnos\User\User;

/**
 * `usertokens.token` is encrypted, and `token_lookup` is what authentication matches.
 *
 * The column used to hold the token itself and every lookup was
 * `WHERE token = <presented>`. Anyone who could read that table held live bearer
 * credentials — usable until they expired, without needing the client secret or
 * anything else, and the framework's own token screens deliberately showed only a
 * fingerprint precisely because the value was too dangerous to display.
 *
 * The split gives each job its own column, because they pull in opposite directions:
 * matching needs a deterministic value, and a copy button needs the original back.
 *
 * These tests assert on the columns, with SQL that does not pass through the model —
 * an implementation that stored plaintext and returned plaintext would satisfy every
 * API-level test there is.
 *
 * Requires the Docker MySQL container.
 */
#[CoversClass(Token::class)]
class TokenAtRestTest extends BaseTestCase
{
    private \Pramnos\Database\Database $db;
    private ?\Pramnos\Database\Database $previousSingleton = null;
    private string|false $originalKey = false;

    private int $userId = 0;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $dbRef = &\Pramnos\Database\Database::getInstance();
        $this->previousSingleton = $dbRef;
        $dbRef = null;
        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if ($this->db->type === 'postgresql') {
            $this->markTestSkipped('Runs on MySQL; the QueryBuilder abstracts the dialect.');
        }

        $this->originalKey = getenv('APP_KEY');
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv('APP_KEY=' . $key);
        $_ENV['APP_KEY'] = $key;

        // setupDb() brings an existing table up to date as well as creating a missing
        // one, which is what puts token_lookup on a table left by an earlier suite.
        User::setupDb();

        $this->userId = $this->ensureUser();
        $this->clearTokens();
    }

    protected function tearDown(): void
    {
        $this->clearTokens();

        if ($this->originalKey === false) {
            putenv('APP_KEY');
            unset($_ENV['APP_KEY']);
        } else {
            putenv('APP_KEY=' . $this->originalKey);
            $_ENV['APP_KEY'] = $this->originalKey;
        }

        $dbRef = &\Pramnos\Database\Database::getInstance();
        $dbRef = $this->previousSingleton;
    }

    /** A user for the tokens to hang off — `userid` carries a foreign key. */
    private function ensureUser(): int
    {
        $existing = $this->db->queryBuilder()
            ->table('users')
            ->where('username', 'token_at_rest_probe')
            ->first();

        if ($existing && $existing->numRows > 0) {
            return (int) $existing->fields['userid'];
        }

        $this->db->queryBuilder()->table('users')->insert([
            'username' => 'token_at_rest_probe',
            'email'    => 'token_at_rest@example.com',
            'active'   => 1,
        ]);

        return (int) $this->db->getInsertId();
    }

    private function clearTokens(): void
    {
        if ($this->userId > 0) {
            $this->db->queryBuilder()
                ->table('usertokens')
                ->where('userid', $this->userId)
                ->delete();
        }
    }

    /** The row as it actually is, read without going through the model. */
    private function rawRow(string $lookup): ?array
    {
        $r = $this->db->queryBuilder()
            ->table('usertokens')
            ->where('token_lookup', $lookup)
            ->first();

        return ($r && $r->numRows > 0) ? $r->fields : null;
    }

    // ── What lands in the columns ─────────────────────────────────────────────

    /**
     * THE point: the value is not readable in the table, and the digest is.
     *
     * A token in the clear is a live credential for whoever reads the database. The
     * digest is not — it cannot be presented to anything.
     */
    public function testATokenIsStoredEncryptedWithItsLookupDigest(): void
    {
        // Arrange
        $user  = new User($this->userId);
        $token = bin2hex(random_bytes(32));

        // Act
        $user->addToken('auth', $token);

        // Assert
        $row = $this->rawRow(Token::lookup($token));
        $this->assertNotNull($row, 'The row must be findable by its digest.');
        $this->assertStringNotContainsString($token, (string) $row['token']);
        $this->assertTrue(Encrypter::isEncrypted((string) $row['token']));
        $this->assertSame(hash('sha256', $token), (string) $row['token_lookup']);
    }

    /**
     * The digest is deterministic, which is the whole reason it exists.
     *
     * An encrypted value cannot be looked up — `Encrypter` uses a fresh nonce per
     * call, so the same token encrypts differently every time. If the digest were not
     * stable, nothing could authenticate.
     */
    public function testTheLookupIsDeterministicAndTheCiphertextIsNot(): void
    {
        // Arrange
        $token = bin2hex(random_bytes(32));

        // Act + Assert
        $this->assertSame(Token::lookup($token), Token::lookup($token));
        $this->assertNotSame(
            Encrypter::encrypt($token),
            Encrypter::encrypt($token),
            'A repeating ciphertext would mean the nonce is not fresh.'
        );
    }

    /** Different tokens get different digests. */
    public function testDifferentTokensGetDifferentLookups(): void
    {
        // Act + Assert
        $this->assertNotSame(Token::lookup('one'), Token::lookup('two'));
        $this->assertSame(64, strlen(Token::lookup('one')));
    }

    // ── Authentication still works ────────────────────────────────────────────

    /**
     * A token issued through the model authenticates through the model.
     *
     * The end-to-end property. Everything above could hold while login was broken.
     */
    public function testATokenIssuedCanBeLoadedBack(): void
    {
        // Arrange
        $user  = new User($this->userId);
        $token = bin2hex(random_bytes(32));
        $user->addToken('auth', $token);

        // Act
        $loaded = (new User())->loadByToken($token, 'auth', false);

        // Assert
        $this->assertSame($this->userId, (int) $loaded->userid);
    }

    /**
     * A token nobody issued authenticates nobody.
     *
     * `loadByToken()` answers null rather than a user with id 0 — the distinction
     * matters, since a caller comparing `->userid` on a null would fatal rather than
     * refuse.
     */
    public function testAnUnknownTokenLoadsNobody(): void
    {
        // Act
        $loaded = (new User())->loadByToken(bin2hex(random_bytes(32)), 'auth', false);

        // Assert
        $this->assertNull($loaded);
    }

    // ── reveal() ──────────────────────────────────────────────────────────────

    /**
     * `reveal()` gives the token back, which is what the copy button needs.
     *
     * Encryption rather than hashing was chosen precisely so this could exist: an
     * administrator debugging an integration wants the value to reproduce a request
     * with. Hashing would have made that impossible and taken a working feature away
     * from the applications that have one.
     */
    public function testRevealReturnsTheOriginalToken(): void
    {
        // Arrange
        $user  = new User($this->userId);
        $token = bin2hex(random_bytes(32));
        $user->addToken('auth', $token);
        $row = $this->rawRow(Token::lookup($token));

        // Act
        $revealed = (new Token())->reveal((string) $row['token']);

        // Assert
        $this->assertSame($token, $revealed);
    }

    /**
     * A value encrypted under a key this installation no longer has reveals nothing.
     *
     * Returning the ciphertext would put `enc:v1:…` behind a copy button, and
     * somebody would paste it into a request and spend an afternoon on the 401.
     */
    public function testRevealReturnsNothingWhenTheKeyHasChanged(): void
    {
        // Arrange
        $sealed  = Encrypter::encrypt('the-original-token');
        $rotated = 'base64:' . base64_encode(random_bytes(32));
        putenv('APP_KEY=' . $rotated);
        $_ENV['APP_KEY'] = $rotated;

        // Act + Assert
        $this->assertSame('', (new Token())->reveal($sealed));
    }

    /** An empty column reveals nothing rather than raising. */
    public function testRevealOfNothingIsEmpty(): void
    {
        // Act + Assert
        $this->assertSame('', (new Token())->reveal(''));
    }

    /**
     * A row written before the change reveals its plaintext unchanged.
     *
     * The migration path: authentication keeps working and the copy button keeps
     * working while the table converts.
     */
    public function testRevealPassesLegacyPlaintextThrough(): void
    {
        // Act + Assert
        $this->assertSame('legacy-value', (new Token())->reveal('legacy-value'));
    }

    // ── Without an APP_KEY ────────────────────────────────────────────────────

    /**
     * With no key, the token is stored as it was and authentication still works.
     *
     * An installation that never ran `key:generate` must not be locked out. It gets
     * no encryption — which is the same position it was in before — but it does get
     * the lookup column, so the auth path is identical either way.
     */
    public function testWithoutAnAppKeyTheTokenIsStoredPlainAndStillResolves(): void
    {
        // Arrange
        putenv('APP_KEY');
        unset($_ENV['APP_KEY']);

        $user  = new User($this->userId);
        $token = bin2hex(random_bytes(32));

        // Act
        $user->addToken('auth', $token);

        // Assert
        $row = $this->rawRow(Token::lookup($token));
        $this->assertNotNull($row);
        $this->assertSame($token, (string) $row['token'], 'No key, no encryption.');
        $this->assertSame(
            $this->userId,
            (int) (new User())->loadByToken($token, 'auth', false)->userid
        );
    }
}
