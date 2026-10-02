<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Client;
use Pramnos\Http\ClientResponse;

/**
 * What a faked request can say about itself: method, URL, headers and body.
 *
 * "Was it sent the right way, and signed" is half of what a fake is for, and the URL pattern
 * a fake is registered under cannot tell a POST from a PATCH. Without these a test read the
 * client's private properties through reflection.
 */
#[CoversClass(Client::class)]
class ClientRequestGettersTest extends TestCase
{
    protected function tearDown(): void
    {
        Client::resetFakes();
    }

    /**
     * Sends $request through a fake and returns the request the fake received.
     */
    private function captured(Client $request): Client
    {
        $seen = null;
        Client::fake(['*' => function (Client $sent) use (&$seen): ClientResponse {
            $seen = $sent;

            return ClientResponse::make(['ok' => true], 200);
        }]);

        $request->send();
        $this->assertInstanceOf(Client::class, $seen, 'the fake was not reached');

        return $seen;
    }

    /**
     * A PATCH, signed with a custom Authorization header, says both.
     *
     * The case that prompted this: a rebuild of a chat message goes as PATCH and is signed
     * `Bot <token>`, and nothing but these getters can show it.
     */
    public function testMethodAndASignedHeaderAreReadable(): void
    {
        // Act
        $seen = $this->captured(
            Client::patch('https://discord.test/channels/1/messages/2')
                ->header('Authorization', 'Bot secret-token')
                ->json(['content' => 'edited'])
        );

        // Assert
        $this->assertSame('PATCH', $seen->method());
        $this->assertSame('https://discord.test/channels/1/messages/2', $seen->url());
        $this->assertSame('Bot secret-token', $seen->requestHeaders()['Authorization']);
        // The body setter's Content-Type is part of what is sent, so it is part of the answer.
        $this->assertSame('application/json', $seen->requestHeaders()['Content-Type']);
        $this->assertSame('{"content":"edited"}', $seen->requestBody());
    }

    /**
     * The URL has the base URL applied, as it is sent.
     */
    public function testUrlIncludesTheBaseUrl(): void
    {
        // Act
        $seen = $this->captured((new Client('https://api.test/v1'))->make('get', '/users'));

        // Assert — and the method is upper case whatever case it was given in
        $this->assertSame('https://api.test/v1/users', $seen->url());
        $this->assertSame('GET', $seen->method());
    }

    /**
     * A request without a body has no Content-Type and a null body.
     */
    public function testARequestWithoutABodyHasNoContentType(): void
    {
        // Act
        $seen = $this->captured(Client::get('https://api.test/ping')->userAgent('Probe/1'));

        // Assert
        $this->assertSame(['User-Agent' => 'Probe/1'], $seen->requestHeaders());
        $this->assertNull($seen->requestBody());
    }

    /**
     * A Content-Type set by hand replaces the body setter's, rather than both being sent.
     *
     * The transport builds its header list from the same method, so one name is one header.
     */
    public function testAnExplicitContentTypeWins(): void
    {
        // Act
        $seen = $this->captured(
            Client::post('https://api.test/x')->json(['a' => 1])->header('Content-Type', 'application/vnd.api+json')
        );

        // Assert
        $this->assertSame('application/vnd.api+json', $seen->requestHeaders()['Content-Type']);
    }
}
