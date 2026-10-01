<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Client;
use Pramnos\Http\ClientResponse;

/**
 * `Client::multipart()`: fields and files in one `multipart/form-data` body.
 *
 * Every platform that takes a file with a message — a chat webhook's `payload_json` plus
 * `files[n]`, a media upload — needs this body, and without it every caller wrote boundaries
 * by hand. These tests read what a fake receives; {@see \Pramnos\Tests\Integration\Http\ClientMultipartTransportTest}
 * has PHP's own parser read it off the wire.
 */
#[CoversClass(Client::class)]
class ClientMultipartTest extends TestCase
{
    protected function tearDown(): void
    {
        Client::resetFakes();
    }

    /**
     * Sends $parts through a fake and returns what it saw: the request and its raw body.
     *
     * @param list<array<string, string>> $parts
     * @return array{0: Client, 1: string, 2: string} The request, its body and its Content-Type
     */
    private function sendThroughFake(array $parts): array
    {
        $seen = null;
        Client::fake(['https://upload.test/*' => function (Client $request) use (&$seen): ClientResponse {
            $seen = $request;

            return ClientResponse::make(['ok' => true], 200);
        }]);

        Client::post('https://upload.test/messages')->multipart($parts)->send();
        $this->assertInstanceOf(Client::class, $seen, 'the fake was not reached');

        $body = (new \ReflectionProperty(Client::class, 'body'))->getValue($seen);
        $type = (new \ReflectionProperty(Client::class, 'contentType'))->getValue($seen);

        return [$seen, (string) $body, (string) $type];
    }

    /**
     * A field and a file make a well-formed body, and a fake can read the parts back.
     */
    public function testAFieldAndAFileMakeOneBody(): void
    {
        // Arrange
        $parts = [
            ['name' => 'payload_json', 'contents' => '{"content":"hi"}', 'type' => 'application/json'],
            ['name' => 'files[0]', 'contents' => "\x89PNG\r\n\x1a\n\0bytes", 'filename' => 'a.png', 'type' => 'image/png'],
        ];

        // Act
        [$request, $body, $type] = $this->sendThroughFake($parts);

        // Assert — the boundary in the header is the one in the body, and the body ends with it
        $this->assertSame(1, preg_match('/^multipart\/form-data; boundary=(pramnos[0-9a-f]{32})$/', $type, $m));
        $boundary = $m[1];
        $this->assertStringStartsWith('--' . $boundary . "\r\n", $body);
        $this->assertStringEndsWith("\r\n--" . $boundary . "--\r\n", $body);

        // The field, with its own type
        $this->assertStringContainsString(
            "Content-Disposition: form-data; name=\"payload_json\"\r\nContent-Type: application/json\r\n\r\n{\"content\":\"hi\"}\r\n",
            $body
        );
        // The file, bytes intact — binary contents, including a CRLF and a NUL, are not touched
        $this->assertStringContainsString(
            "name=\"files[0]\"; filename=\"a.png\"\r\nContent-Type: image/png\r\n\r\n\x89PNG\r\n\x1a\n\0bytes\r\n",
            $body
        );

        // A test asserts on the parts without parsing the body
        $this->assertSame($parts, $request->multipartParts());
    }

    /**
     * A part with no type gets no Content-Type line.
     */
    public function testAPartWithoutATypeHasNoContentTypeLine(): void
    {
        // Act
        [, $body] = $this->sendThroughFake([['name' => 'note', 'contents' => 'plain']]);

        // Assert
        $this->assertStringContainsString("name=\"note\"\r\n\r\nplain\r\n", $body);
    }

    /**
     * A double quote in a filename is sent as %22, so it cannot end the parameter early.
     */
    public function testAQuoteInAFilenameIsEscaped(): void
    {
        // Act
        [, $body] = $this->sendThroughFake([
            ['name' => 'f', 'contents' => 'x', 'filename' => 'my "best".jpg'],
        ]);

        // Assert
        $this->assertStringContainsString('filename="my %22best%22.jpg"', $body);
    }

    /**
     * A line break in a filename is refused: it would be a header of the caller's choosing.
     */
    public function testALineBreakInAFilenameIsRefused(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('line break in its filename');

        // Act
        (new Client())->multipart([
            ['name' => 'f', 'contents' => 'x', 'filename' => "a.jpg\r\nX-Injected: 1"],
        ]);
    }

    /**
     * A line break in a part's type is refused too.
     */
    public function testALineBreakInATypeIsRefused(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('line break in its type');

        // Act
        (new Client())->multipart([['name' => 'f', 'contents' => 'x', 'type' => "text/plain\nX: 1"]]);
    }

    /**
     * A part with no name is refused, since the receiver could not tell what it is.
     */
    public function testAPartWithoutANameIsRefused(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('has no name');

        // Act
        (new Client())->multipart([['contents' => 'x']]);
    }

    /**
     * Contents that are not a string are refused rather than cast.
     */
    public function testNonStringContentsAreRefused(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no string contents');

        // Act
        (new Client())->multipart([['name' => 'n', 'contents' => 42]]);
    }

    /**
     * The three other body setters, each as a callable applied to a client.
     *
     * @return array<string, array{\Closure(Client): Client}>
     */
    public static function otherBodies(): array
    {
        return [
            'json' => [static fn (Client $c): Client => $c->json(['a' => 1])],
            'form' => [static fn (Client $c): Client => $c->form(['a' => 1])],
            'body' => [static fn (Client $c): Client => $c->body('raw', 'text/plain')],
        ];
    }

    /**
     * Another body setter after multipart() clears the parts, so a test cannot read stale ones.
     *
     * @param \Closure(Client): Client $setBody
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('otherBodies')]
    public function testAnotherBodyClearsTheParts(\Closure $setBody): void
    {
        // Arrange
        $client = (new Client())->multipart([['name' => 'n', 'contents' => 'x']]);

        // Act
        $setBody($client);

        // Assert
        $this->assertSame([], $client->multipartParts());
    }

    /**
     * A new request built from a configured client starts without the previous one's parts.
     */
    public function testANewRequestStartsWithoutParts(): void
    {
        // Arrange
        $api = (new Client('https://upload.test'))->multipart([['name' => 'n', 'contents' => 'x']]);

        // Act
        $request = $api->make('POST', '/again');

        // Assert
        $this->assertSame([], $request->multipartParts());
    }
}
