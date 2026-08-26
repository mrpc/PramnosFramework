<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\ClientCredentialsAuthTrait;

/**
 * Client credentials must be found where Apache actually leaves them.
 *
 * RFC 6749 §2.3.1 lets a client authenticate with HTTP Basic, and that is what a
 * CI pipeline pushing a capabilities manifest does. Running as a module, Apache
 * decodes the header into `PHP_AUTH_USER` / `PHP_AUTH_PW` and does **not** pass
 * the raw `Authorization` header through — and the usual
 * `E=HTTP_AUTHORIZATION` rewrite does not help, because by then there is nothing
 * left to copy.
 *
 * The extractor read only the raw header, so a correctly authenticated client was
 * told `invalid_client` / "Client credentials required". That reads as a wrong
 * secret rather than as credentials that never arrived, so the obvious next step
 * is to re-check the secret — and it never helps.
 */
class ClientCredentialsFromBasicAuthTest extends TestCase
{
    private object $subject;

    protected function setUp(): void
    {
        unset(
            $_SERVER['HTTP_AUTHORIZATION'],
            $_SERVER['PHP_AUTH_USER'],
            $_SERVER['PHP_AUTH_PW']
        );
        $_POST = [];

        $this->subject = new class {
            use ClientCredentialsAuthTrait;

            /** @return array{client_id: string, client_secret: string}|null */
            public function credentials(): ?array
            {
                return $this->extractClientCredentials();
            }
        };
    }

    protected function tearDown(): void
    {
        unset(
            $_SERVER['HTTP_AUTHORIZATION'],
            $_SERVER['PHP_AUTH_USER'],
            $_SERVER['PHP_AUTH_PW']
        );
        $_POST = [];
    }

    /**
     * The raw header is read when it is there.
     */
    public function testTheRawAuthorizationHeaderIsRead(): void
    {
        // Arrange
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('client-a:secret-a');

        // Act
        $credentials = $this->subject->credentials();

        // Assert
        $this->assertSame('client-a', $credentials['client_id']);
        $this->assertSame('secret-a', $credentials['client_secret']);
    }

    /**
     * And what Apache left behind is read when the header is not.
     *
     * This is the case that was failing, and it is the common one: Apache as a
     * module, which is how most of these servers run.
     */
    public function testApachesParsedBasicCredentialsAreRead(): void
    {
        // Arrange — no HTTP_AUTHORIZATION, which is what Apache leaves
        $_SERVER['PHP_AUTH_USER'] = 'client-b';
        $_SERVER['PHP_AUTH_PW'] = 'secret-b';

        // Act
        $credentials = $this->subject->credentials();

        // Assert
        $this->assertNotNull($credentials, 'Basic auth parsed by Apache must be found');
        $this->assertSame('client-b', $credentials['client_id']);
        $this->assertSame('secret-b', $credentials['client_secret']);
    }

    /**
     * An explicit header wins over the parsed pair.
     *
     * They should agree; when they do not, the one the client actually sent is the
     * one to honour.
     */
    public function testTheRawHeaderWinsOverTheParsedPair(): void
    {
        // Arrange
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('from-header:h');
        $_SERVER['PHP_AUTH_USER'] = 'from-apache';
        $_SERVER['PHP_AUTH_PW'] = 'a';

        // Act
        $credentials = $this->subject->credentials();

        // Assert
        $this->assertSame('from-header', $credentials['client_id']);
    }

    /**
     * A form-encoded pair still works.
     *
     * The other half of §2.3.1, and what a curl one-liner usually sends.
     */
    public function testFormEncodedCredentialsStillWork(): void
    {
        // Arrange
        $_POST = ['client_id' => 'client-c', 'client_secret' => 'secret-c'];

        // Act
        $credentials = $this->subject->credentials();

        // Assert
        $this->assertSame('client-c', $credentials['client_id']);
    }

    /**
     * A username with no password is still credentials.
     *
     * A public client has no secret. Refusing the pair here would report it as
     * "no credentials" instead of letting the authenticator decide.
     */
    public function testAUsernameWithNoPasswordIsStillCredentials(): void
    {
        // Arrange
        $_SERVER['PHP_AUTH_USER'] = 'public-client';

        // Act
        $credentials = $this->subject->credentials();

        // Assert
        $this->assertSame('public-client', $credentials['client_id']);
        $this->assertSame('', $credentials['client_secret']);
    }

    /**
     * With nothing presented, nothing is invented.
     */
    public function testNoCredentialsMeansNull(): void
    {
        // Assert
        $this->assertNull($this->subject->credentials());
    }
}
