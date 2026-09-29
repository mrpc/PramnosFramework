<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Session;

/**
 * A bearer token is a session only when the server signed it and the database still has it.
 *
 * `Session` checked the signature with the server's public key, and — without the key pair — with
 * HS256 keyed by the application's client id. The client id is public, so that check accepted a
 * token anybody could sign. The key is now required, and the row has to belong to an application
 * that still exists.
 */
#[CoversClass(Session::class)]
class SessionBearerValidationTest extends TestCase
{
    private const USER = 7701;
    private const APP  = 7701;

    private \Pramnos\Database\Database $db;

    private string $privateKey = '';

    private string $publicKeyFile = '';

    protected function setUp(): void
    {
        \Pramnos\Application\Settings::clearSettings();
        \Pramnos\Application\Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        $this->db = \Pramnos\Framework\Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        // A key pair of its own: the one under app/keys is shared with every other test.
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($pair, $this->privateKey);
        $this->publicKeyFile = (string) tempnam(sys_get_temp_dir(), 'pk');
        file_put_contents($this->publicKeyFile, openssl_pkey_get_details($pair)['key']);
        $this->clean();
        $this->db->queryBuilder()->table('applications')->insert([
            'appid' => self::APP, 'name' => 'Session probe', 'status' => 1, 'apikey' => str_repeat('session_probe_client', 3),
        ]);
    }

    protected function tearDown(): void
    {
        $this->clean();
        @unlink($this->publicKeyFile);
    }

    private function clean(): void
    {
        $this->db->queryBuilder()->table('usertokens')->where('userid', self::USER)->delete();
        $this->db->queryBuilder()->table('applications')->where('appid', self::APP)->delete();
    }

    /** Store a token row for the probe application, as the token endpoint would. */
    private function store(string $token): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        $this->db->queryBuilder()->table('usertokens')->insert([
            'userid' => self::USER, 'tokentype' => 'access_token', 'token' => $token,
            'token_lookup' => \Pramnos\User\Token::lookup($token), 'created' => time(), 'status' => 1,
            'expires' => time() + 600, 'lastused' => time(), 'notes' => '', 'actions' => 0, 'removedate' => 0,
            'deviceinfo' => '', 'scope' => '', 'applicationid' => self::APP,
        ]);
        $this->db->query('SET FOREIGN_KEY_CHECKS=1');
    }

    private function validate(string $token, ?string $publicKeyFile = null): array|false
    {
        $session = new class extends Session {
            public string $keyFile = '';

            public function __construct()
            {
            }

            protected function publicKeyPath(): string
            {
                return $this->keyFile;
            }
        };
        $session->keyFile = $publicKeyFile ?? $this->publicKeyFile;

        return (new \ReflectionMethod(Session::class, 'validateAccessToken'))->invoke($session, $token);
    }

    private function signed(): string
    {
        return \Pramnos\Auth\JWT::encode(['sub' => (string) self::USER, 'exp' => time() + 600], $this->privateKey, 'RS256');
    }

    /**
     * A token the server signed, stored and live, is a session.
     */
    public function testATokenTheServerSignedIsASession(): void
    {
        // Arrange
        $token = $this->signed();
        $this->store($token);

        // Act
        $row = $this->validate($token);

        // Assert
        $this->assertIsArray($row);
        $this->assertSame(self::USER, (int) $row['userid']);
    }

    /**
     * A token signed with the client id is not, even when a row for it exists.
     */
    public function testATokenSignedWithTheClientIdIsNot(): void
    {
        // Arrange
        $token = \Pramnos\Auth\JWT::encode(['sub' => (string) self::USER, 'exp' => time() + 600], str_repeat('session_probe_client', 3));
        $this->store($token);

        // Act & Assert
        $this->assertFalse($this->validate($token));
    }

    /**
     * A token of an application that no longer exists is not a session.
     */
    public function testATokenOfAnApplicationThatIsGoneIsNot(): void
    {
        // Arrange
        $token = $this->signed();
        $this->store($token);
        $this->db->queryBuilder()->table('applications')->where('appid', self::APP)->delete();

        // Act & Assert
        $this->assertFalse($this->validate($token));
    }

    /**
     * Without the server's public key nothing is a session — there is no symmetric fallback.
     */
    public function testWithoutTheKeyNothingIsASession(): void
    {
        // Arrange
        $token = $this->signed();
        $this->store($token);

        // Act & Assert
        $this->assertFalse($this->validate($token, '/nonexistent/public.key'));
    }
}
