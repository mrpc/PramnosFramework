<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\JWT;
use Pramnos\Auth\OAuth2\EndSession;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Framework\Testing\Schema;

/**
 * An application asks this server to sign its user out and send them back (RP-Initiated Logout).
 *
 * The discovery document named an end-session endpoint that answered 404, and the browser logout
 * always ended on the sign-in page. The return address is the part that matters for safety: it
 * must be one the client registered, matched exactly, or the endpoint becomes a way to send a
 * just-signed-out user anywhere a link names.
 */
#[CoversClass(EndSession::class)]
class EndSessionTest extends BaseTestCase
{
    private const APP = 990401;

    private const USER = 990402;

    /** One key pair for the class. */
    private static ?array $keys = null;

    private Database $db;

    private ?Database $previous = null;

    private string $publicKeyPath = '';

    /** The engine under test: the suite's own connection here, PostgreSQL in the subclass. */
    protected function connection(): Database
    {
        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }

        return $db;
    }

    protected function setUp(): void
    {
        $this->previous = Factory::getDatabase();
        $this->db       = $this->connection();
        $singleton      = &Factory::getDatabase();
        $singleton      = $this->db;

        Schema::table('users', $this->db);
        Schema::table('applications', $this->db);
        Schema::table('usertokens', $this->db);
        $this->cleanUp();
        $this->db->queryBuilder()->table('users')->insert([
            'userid' => self::USER, 'username' => 'leaver', 'email' => 'leaver@example.com', 'active' => 1,
            'usertype' => 0, 'sex' => 0, 'birthdate' => 0, 'modified' => 0,
        ]);
        $this->db->queryBuilder()->table('applications')->insert([
            'appid' => self::APP, 'name' => 'Leaving app', 'status' => 1, 'apikey' => 'leaving-app',
            'callback' => 'https://app.example/callback https://app.example/signed-out', 'url' => 'https://app.example',
        ]);

        if (self::$keys === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            self::$keys = [$private, openssl_pkey_get_details($key)['key']];
        }
        $this->publicKeyPath = tempnam(sys_get_temp_dir(), 'endsession');
        file_put_contents($this->publicKeyPath, self::$keys[1]);
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        @unlink($this->publicKeyPath);
        $singleton = &Factory::getDatabase();
        $singleton = $this->previous;
    }

    /** Remove this class's rows. */
    private function cleanUp(): void
    {
        $this->db->queryBuilder()->table('usertokens')->where('userid', self::USER)->delete();
        $this->db->queryBuilder()->table('applications')->where('appid', self::APP)->delete();
        $this->db->queryBuilder()->table('users')->where('userid', self::USER)->delete();
    }

    /** An ID token this server would issue, with claims replaced. */
    private function idToken(array $override = [], ?string $key = null): string
    {
        return JWT::encode(array_merge([
            'iss' => 'https://auth.example', 'sub' => (string) self::USER, 'aud' => 'leaving-app',
            'iat' => time() - 7200, 'exp' => time() - 3600,
        ], $override), $key ?? self::$keys[0], 'RS256');
    }

    /** The decision for these parameters, with the user signed in. */
    private function resolve(array $params, ?int $userId = self::USER): array
    {
        return (new EndSession($this->db, $this->publicKeyPath))->resolve($params, $userId);
    }

    /**
     * A hint and a registered address: back to the application with its state, and its tokens revoked.
     *
     * The hint has expired, as an ID token usually has by the time somebody signs out; it only
     * needs to be one this server signed.
     */
    public function testAHintAndARegisteredAddressReturnToTheApplication(): void
    {
        // Act
        $answer = $this->resolve([
            'id_token_hint' => $this->idToken(), 'post_logout_redirect_uri' => 'https://app.example/signed-out', 'state' => 'a b',
        ]);

        // Assert
        $this->assertSame('https://app.example/signed-out?state=a%20b', $answer['redirect']);
        $this->assertSame(self::APP, $answer['appid']);
        $this->assertTrue($answer['revoke']);
        $this->assertNull($answer['refused']);
    }

    /**
     * The application's homepage is a registered address too; local=1 keeps the tokens.
     */
    public function testTheHomepageIsAllowedAndLocalKeepsTheTokens(): void
    {
        // Act
        $answer = $this->resolve(['id_token_hint' => $this->idToken(), 'post_logout_redirect_uri' => 'https://app.example', 'local' => '1']);

        // Assert
        $this->assertSame('https://app.example', $answer['redirect']);
        $this->assertFalse($answer['revoke']);
    }

    /**
     * An address the client did not register is refused — exactly, not by prefix.
     */
    public function testAnUnregisteredAddressIsRefused(): void
    {
        // Act
        $prefixed = $this->resolve(['client_id' => 'leaving-app', 'post_logout_redirect_uri' => 'https://app.example/signed-out/../../evil']);
        $elsewhere = $this->resolve(['client_id' => 'leaving-app', 'post_logout_redirect_uri' => 'https://evil.example/']);

        // Assert
        foreach ([$prefixed, $elsewhere] as $answer) {
            $this->assertNull($answer['redirect']);
            $this->assertStringContainsString('not registered', (string) $answer['refused']);
        }
    }

    /**
     * A bare client_id may return to a registered address, but revokes nothing.
     *
     * Such a link can sit on any page; it should not end a user's sessions in the application.
     */
    public function testABareClientIdRevokesNothing(): void
    {
        // Act
        $answer = $this->resolve(['client_id' => 'leaving-app', 'post_logout_redirect_uri' => 'https://app.example/signed-out']);

        // Assert
        $this->assertSame('https://app.example/signed-out', $answer['redirect']);
        $this->assertFalse($answer['revoke']);
    }

    /**
     * A hint that is forged, for another client, or about another user is refused.
     */
    public function testAHintThatDoesNotCheckOutIsRefused(): void
    {
        // Arrange
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $otherPem);
        $uri = 'https://app.example/signed-out';

        // Act + Assert
        $this->assertStringContainsString('not an ID token', (string) $this->resolve(['id_token_hint' => $this->idToken([], $otherPem), 'post_logout_redirect_uri' => $uri])['refused']);
        $this->assertStringContainsString('audience', (string) $this->resolve(['id_token_hint' => $this->idToken(), 'client_id' => 'someone-else', 'post_logout_redirect_uri' => $uri])['refused']);
        $this->assertStringContainsString('another user', (string) $this->resolve(['id_token_hint' => $this->idToken(['sub' => '1234'])])['refused']);
        $this->assertStringContainsString('Unknown client', (string) $this->resolve(['client_id' => 'nobody', 'post_logout_redirect_uri' => $uri])['refused']);
    }

    /**
     * Nothing about a client: nothing to do beyond the sign-out itself.
     */
    public function testNoClientMeansThePlainSignOut(): void
    {
        // Act
        $answer = $this->resolve([]);

        // Assert
        $this->assertSame(['redirect' => null, 'appid' => 0, 'revoke' => false, 'refused' => null], $answer);
    }

    /**
     * Revoking ends the user's access and refresh tokens for that client, and nothing else.
     */
    public function testRevokingEndsThatClientsTokensOnly(): void
    {
        // Arrange
        foreach ([['a1', 'access_token', self::APP], ['r1', 'refresh_token', self::APP], ['w1', 'auth', self::APP], ['o1', 'access_token', 1]] as [$value, $type, $app]) {
            $this->db->queryBuilder()->table('usertokens')->insert([
                'userid' => self::USER, 'applicationid' => $app, 'tokentype' => $type, ...\Pramnos\User\Token::storageFor($value),
                'status' => 1, 'created' => time(), 'expires' => time() + 3600, 'deviceinfo' => '', 'scope' => '',
            ]);
        }

        // Act
        $count = (new EndSession($this->db, $this->publicKeyPath))->revoke(self::USER, self::APP);

        // Assert
        $this->assertSame(2, $count);
        $active = $this->db->queryBuilder()->table('usertokens')->select('tokentype')->where('userid', self::USER)->where('status', 1)->getAll();
        $this->assertEqualsCanonicalizing(['auth', 'access_token'], array_column($active, 'tokentype'), 'the web session, and another client\'s token');
    }
}
