<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Testing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Response;
use Pramnos\Testing\TestClient;
use Pramnos\Testing\TestResponse;

/**
 * `TestClient::submitForm()` — posting a form the way a browser would.
 *
 * WHAT: it reads the form off the page the client last received — the action, the method
 *       and every field already rendered, the hidden CSRF input included — and sends it.
 *
 * WHY:  the method threw `submitForm is not yet fully implemented` while the class
 *       documented it as the way to post a form. So a test that wanted to exercise
 *       registration, a password reset or a settings form built the POST by hand, and
 *       the hard part is the CSRF token: the field's **name** is a private property and
 *       its **value** is `getFingerprint()`, which is also not public. The only way in
 *       was a regular expression over `getTokenField()`'s markup —
 *
 *       ```php
 *       preg_match('/name="([^"]+)" value="([^"]+)"/', $session->getTokenField(), $m);
 *       ```
 *
 *       — in every application that tests a form. Those are the highest-value tests in
 *       an application with accounts in it, and the ones most likely to be skipped,
 *       because the first hour of writing one goes on this.
 *
 * The client's dispatch is not exercised here: what is being tested is the reading of a
 * form and the request built from it, so `call()` is captured rather than run. A test
 * that booted the application to assert a parsed action would be testing routing.
 */
#[CoversClass(TestClient::class)]
class SubmitFormTest extends TestCase
{
    /**
     * A client holding one page, whose `call()` records instead of dispatching.
     *
     * @param string $html The page the client last received
     */
    private function clientShowing(string $html, string $uri = '/register'): object
    {
        $client = new class extends TestClient {
            /** @var array{method: string, uri: string, parameters: array<string, mixed>}|null */
            public ?array $sent = null;

            // The parent constructor boots an Application, which this test does not need
            // and which would make it an integration test by accident.
            public function __construct()
            {
            }

            public function call(string $method, string $uri, array $parameters = [], array $headers = []): TestResponse
            {
                $this->sent = ['method' => $method, 'uri' => $uri, 'parameters' => $parameters];

                return new TestResponse(Response::make('ok', 200));
            }

            public function showPage(string $html, string $uri): void
            {
                $reflection = new \ReflectionClass(TestClient::class);

                $response = $reflection->getProperty('lastResponse');
                $response->setValue($this, new TestResponse(Response::make($html, 200)));

                $address = $reflection->getProperty('lastUri');
                $address->setValue($this, $uri);
            }
        };

        $client->showPage($html, $uri);

        return $client;
    }

    /** The register form as a scaffolded project renders it, CSRF field and all. */
    private const REGISTER_PAGE = <<<'HTML'
        <html><body>
        <form method="post" action="/register">
            <input type="hidden" name="a1b2c3" value="the-fingerprint" />
            <input type="text" name="username" value="" />
            <input type="email" name="email" value="prefilled@example.com" />
            <button type="submit">Create account</button>
        </form>
        </body></html>
        HTML;

    /**
     * The hidden CSRF field comes along without the caller naming it.
     *
     * **The assertion this method exists for.** Everything else here can be written by
     * hand in a line; this one cannot be written at all without reaching into a private
     * property or parsing markup.
     */
    public function testTheHiddenCsrfFieldIsSubmittedWithoutBeingNamed(): void
    {
        // Arrange
        $client = $this->clientShowing(self::REGISTER_PAGE);

        // Act
        $client->submitForm('Create account', ['username' => 'someone']);

        // Assert
        $this->assertSame('the-fingerprint', $client->sent['parameters']['a1b2c3'] ?? null);
        $this->assertSame('someone', $client->sent['parameters']['username']);
    }

    /**
     * The action and the method come from the form.
     *
     * A form posting somewhere other than the page it is on is ordinary — the register
     * form of this framework's own scaffold does — and a client that assumed otherwise
     * would silently test the wrong endpoint.
     */
    public function testTheActionAndMethodComeFromTheForm(): void
    {
        // Arrange
        $client = $this->clientShowing(self::REGISTER_PAGE, '/account/register');

        // Act
        $client->submitForm('Create account');

        // Assert
        $this->assertSame('POST', $client->sent['method']);
        $this->assertSame('/register', $client->sent['uri']);
    }

    /**
     * A field the caller does not name keeps the value the page rendered.
     *
     * The same rule as the CSRF token, and it matters for the same reason: a settings
     * form has thirty fields and a test changes one. Blanking the other twenty-nine
     * would be testing a form nobody ever submits.
     */
    public function testUnnamedFieldsKeepWhatThePageRendered(): void
    {
        // Arrange
        $client = $this->clientShowing(self::REGISTER_PAGE);

        // Act
        $client->submitForm('Create account', ['username' => 'someone']);

        // Assert
        $this->assertSame('prefilled@example.com', $client->sent['parameters']['email']);
    }

    /**
     * An empty action posts back to the page it came from.
     *
     * `<form method="post">` with no action is what a browser posts to the current URL,
     * and it is how several of this framework's own views are written.
     */
    public function testAnEmptyActionPostsBackToTheSamePage(): void
    {
        // Arrange
        $client = $this->clientShowing(
            '<form method="post"><input name="x" value="1"><button>Go</button></form>',
            '/account/profile'
        );

        // Act
        $client->submitForm('Go');

        // Assert
        $this->assertSame('/account/profile', $client->sent['uri']);
    }

    /**
     * A button that is on no form says so, and says how buttons are matched.
     *
     * The likeliest mistake when writing one of these, and the message is the only place
     * the caller finds out that the text, the value and the name all count.
     */
    public function testAnUnknownButtonNamesWhatItMatchesOn(): void
    {
        // Arrange
        $client = $this->clientShowing(self::REGISTER_PAGE);

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No form on the last page has a button/');

        // Act
        $client->submitForm('Sign in');
    }
}
