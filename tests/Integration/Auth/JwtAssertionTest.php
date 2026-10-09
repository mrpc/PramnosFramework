<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Auth\Application;
use Pramnos\Auth\JWT;
use Pramnos\Auth\OAuth2\JwtAssertion;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Framework\Testing\Schema;

/**
 * A JWT an application signs with its own key and presents at the token endpoint (RFC 7523).
 *
 * The check was the signature, `sub` and `exp`. So an assertion caught in transit could be
 * presented again until it expired — however far away that was — and an assertion made for any
 * other server that trusts the same key was accepted here. Each test below is one of the checks
 * that closes that, refused on its own with everything else valid.
 */
#[CoversClass(JwtAssertion::class)]
class JwtAssertionTest extends BaseTestCase
{
    /** One RSA pair for the class: generating keys is the slow part. */
    private static ?array $rsa = null;

    private Database $db;

    private ?Database $previous = null;

    private bool $builtTable = false;

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

        $this->builtTable = !$this->db->schema()->hasTable('authserver.jwt_replay_prevention');
        Schema::table('authserver.jwt_replay_prevention', $this->db);
        $this->db->queryBuilder()->table('authserver.jwt_replay_prevention')->delete();
    }

    protected function tearDown(): void
    {
        if ($this->builtTable) {
            $this->db->schema()->dropTableIfExists('authserver.jwt_replay_prevention');
        }
        $singleton = &Factory::getDatabase();
        $singleton = $this->previous;
    }

    /** @return array{0: string, 1: string} private PEM, public PEM */
    private static function rsa(): array
    {
        if (self::$rsa === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            self::$rsa = [$private, openssl_pkey_get_details($key)['key']];
        }

        return self::$rsa;
    }

    /** An application with this client id and public key. */
    private function client(string $clientId = 'svc-client', ?string $publicKey = null, ?string $jwksUri = null): Application
    {
        $app = new Application(new \Pramnos\Application\Controller());
        $app->apikey     = $clientId;
        $app->public_key = $publicKey ?? self::rsa()[1];
        $app->jwks_uri   = $jwksUri;

        return $app;
    }

    /**
     * A valid client assertion, with some claims replaced or removed (null removes).
     *
     * @param array<string, mixed> $override
     */
    private function assertion(array $override = [], ?string $key = null, string $alg = 'RS256', ?string $kid = null): string
    {
        $claims = array_merge([
            'iss' => 'svc-client',
            'sub' => 'svc-client',
            'aud' => JwtAssertion::audiences()[0],
            'iat' => time(),
            'exp' => time() + 60,
            'jti' => bin2hex(random_bytes(8)),
        ], $override);

        return JWT::encode(array_filter($claims, static fn ($v) => $v !== null), $key ?? self::rsa()[0], $alg, $kid);
    }

    /** The verifier, reading a JWKS from this body instead of the network. */
    private function verifier(?string $jwks = null): JwtAssertion
    {
        return new JwtAssertion($this->db, $jwks === null ? null : static fn (string $url): string => $jwks);
    }

    /** The reason an assertion is refused, or null when it is accepted. */
    private function refusal(string $assertion, ?Application $client = null, ?JwtAssertion $verifier = null): ?string
    {
        try {
            ($verifier ?? $this->verifier())->verify($assertion, $client ?? $this->client());

            return null;
        } catch (\UnexpectedValueException $e) {
            return $e->getMessage();
        }
    }

    /**
     * An assertion that meets every check is accepted, and its claims come back for the caller's subject check.
     */
    public function testAValidAssertionIsAccepted(): void
    {
        // Act
        $claims = $this->verifier()->verify($this->assertion(['sub' => 'user@example.com']), $this->client());

        // Assert
        $this->assertSame('user@example.com', $claims->sub);
    }

    /**
     * The audience may be the issuer, and may be a list that contains it.
     */
    public function testTheAudienceMayBeTheIssuerOrAListWithIt(): void
    {
        // Act + Assert
        $this->assertNull($this->refusal($this->assertion(['aud' => JwtAssertion::audiences()[1]])));
        $this->assertNull($this->refusal($this->assertion(['aud' => ['https://elsewhere.example', JwtAssertion::audiences()[0]]])));
    }

    /**
     * Each check refuses on its own, with a reason a client developer can act on.
     */
    public function testEachCheckRefusesOnItsOwn(): void
    {
        // Assert
        $this->assertStringContainsString('iss', (string) $this->refusal($this->assertion(['iss' => 'someone-else'])));
        $this->assertStringContainsString('aud must be', (string) $this->refusal($this->assertion(['aud' => 'https://other-server.example/token'])));
        $this->assertStringContainsString('aud must be', (string) $this->refusal($this->assertion(['aud' => null])));
        $this->assertStringContainsString('exp and iat', (string) $this->refusal($this->assertion(['iat' => null])));
        $this->assertStringContainsString('at most 300', (string) $this->refusal($this->assertion(['exp' => time() + 3600])));
        $this->assertStringContainsString('jti', (string) $this->refusal($this->assertion(['jti' => null])));
        $this->assertStringContainsString('Expired token', (string) $this->refusal($this->assertion(['iat' => time() - 120, 'exp' => time() - 60])));
        // Signed by a key that is not the client's.
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $otherPem);
        $this->assertStringContainsString('not validly signed', (string) $this->refusal($this->assertion([], $otherPem)));
    }

    /**
     * HMAC is refused: with the public key as the secret, anybody could sign one.
     */
    public function testAnHmacAssertionIsRefused(): void
    {
        // Act
        $refusal = $this->refusal($this->assertion([], self::rsa()[1], 'HS256'));

        // Assert
        $this->assertStringContainsString('not validly signed', (string) $refusal);
    }

    /**
     * An assertion is accepted once; the second presentation is a replay.
     *
     * The same jti from another client is that client's own and is accepted — the memory is per client.
     */
    public function testAnAssertionIsAcceptedOnce(): void
    {
        // Arrange
        $assertion = $this->assertion(['jti' => 'once']);

        // Act
        $first  = $this->refusal($assertion);
        $second = $this->refusal($assertion);
        $other  = $this->refusal($this->assertion(['jti' => 'once', 'iss' => 'svc-other', 'sub' => 'svc-other']), $this->client('svc-other'));

        // Assert
        $this->assertNull($first);
        $this->assertStringContainsString('used before', (string) $second);
        $this->assertNull($other, 'another client\'s jti is its own');
    }

    /**
     * An expired jti is forgotten, so the store does not grow without end.
     */
    public function testExpiredJtisAreForgotten(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('authserver.jwt_replay_prevention')->insert([
            'jti' => str_repeat('a', 64), 'expires_at' => date('Y-m-d H:i:s', time() - 10), 'created_at' => date('Y-m-d H:i:s', time() - 70),
        ]);

        // Act
        $this->refusal($this->assertion());

        // Assert
        $this->assertSame(0, $this->db->queryBuilder()->table('authserver.jwt_replay_prevention')->where('jti', str_repeat('a', 64))->count());
    }

    /**
     * An EC key signs as well as an RSA one.
     */
    public function testAnEcKeyIsAccepted(): void
    {
        // Arrange
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $private);
        $client = $this->client('svc-client', openssl_pkey_get_details($key)['key']);

        // Act + Assert
        $this->assertNull($this->refusal($this->assertion([], $private, 'ES256'), $client));
    }

    /**
     * Without a public key, the keys the client's jwks_uri publishes are used, chosen by kid.
     */
    public function testTheJwksIsUsedWhenThereIsNoPublicKey(): void
    {
        // Arrange — the client's key, a second key, and an encryption key that must be ignored
        $details = openssl_pkey_get_details(openssl_pkey_get_private(self::rsa()[0]));
        $jwk     = ['kty' => 'RSA', 'use' => 'sig', 'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
                    'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '=')];
        $jwks    = json_encode(['keys' => [['kid' => 'enc'] + ['use' => 'enc'] + $jwk, ['kid' => 'k1'] + $jwk, ['kid' => 'junk', 'kty' => 'oct', 'k' => 'x']]]);
        $client  = $this->client('svc-client', '', 'https://client.example/jwks.json');

        // Act
        $byKid   = $this->refusal($this->assertion([], null, 'RS256', 'k1'), $client, $this->verifier($jwks));
        $noKid   = $this->refusal($this->assertion(), $client, $this->verifier($jwks));
        $single  = $this->refusal($this->assertion(), $client, $this->verifier(json_encode(['keys' => [$jwk]])));
        $nothing = $this->refusal($this->assertion(), $client, $this->verifier('not json'));

        // Assert
        $this->assertNull($byKid);
        $this->assertStringContainsString('not validly signed', (string) $noKid, 'several keys and no kid to choose with');
        $this->assertNull($single, 'one key and no kid: that key');
        $this->assertStringContainsString('no public key or JWKS', (string) $nothing);
    }

    /**
     * A client with neither a key nor a JWKS cannot sign anything.
     */
    public function testAClientWithoutAKeyIsRefused(): void
    {
        // Act
        $refusal = $this->refusal($this->assertion(), $this->client('svc-client', ''));

        // Assert
        $this->assertStringContainsString('no public key or JWKS', (string) $refusal);
    }
}
