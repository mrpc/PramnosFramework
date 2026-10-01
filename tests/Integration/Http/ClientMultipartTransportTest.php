<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Client;

/**
 * A `multipart()` body, sent over a real socket and read by PHP's own parser.
 *
 * Building the body is half of it; the other half is that a real receiver understands it.
 * The receiver here is PHP itself — `php -S` with a router that answers with `$_POST` and
 * `$_FILES` — so the boundary, the dispositions and the binary contents are judged by an
 * implementation this code did not write.
 */
#[CoversClass(Client::class)]
#[\PHPUnit\Framework\Attributes\Group('integration')]
class ClientMultipartTransportTest extends TestCase
{
    /** @var resource|null The `php -S` process. */
    private $server = null;

    private string $baseUrl = '';

    /**
     * Starts `php -S` on a free port and waits until it accepts connections.
     */
    protected function setUp(): void
    {
        Client::resetFakes();

        // A free port: bind to 0, read the number, release it for the server.
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($probe, 'no free port for the test server');
        $port = (int) explode(':', (string) stream_socket_get_name($probe, false))[1];
        fclose($probe);

        $router       = __DIR__ . '/Fixtures/multipart-echo.php';
        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        $this->assertIsResource($this->server, 'php -S did not start');
        $this->baseUrl = 'http://127.0.0.1:' . $port;

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50000);
        }
        $this->fail('php -S never accepted a connection');
    }

    /**
     * Stops the server.
     */
    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server, 9);
            proc_close($this->server);
        }
    }

    /**
     * A field and two files arrive as `$_POST` and `$_FILES`, binary contents intact.
     *
     * The shape a chat platform's webhook takes: a JSON field beside indexed files, one of
     * them binary with a CRLF and a NUL in it — the bytes most likely to be mangled by a body
     * built carelessly.
     */
    public function testPhpParsesTheBodyIntoFieldsAndFiles(): void
    {
        // Arrange
        $png  = "\x89PNG\r\n\x1a\n\0" . random_bytes(64);
        $json = '{"content":"Καλημέρα"}';

        // Act
        $response = Client::post($this->baseUrl . '/upload')->multipart([
            ['name' => 'payload_json', 'contents' => $json, 'type' => 'application/json'],
            ['name' => 'files[0]', 'contents' => $png, 'filename' => 'cover.png', 'type' => 'image/png'],
            ['name' => 'files[1]', 'contents' => 'second', 'filename' => 'note.txt', 'type' => 'text/plain'],
        ])->send();
        $seen = $response->json();

        // Assert — the field
        $this->assertSame(200, $response->status());
        $this->assertSame(['payload_json' => $json], $seen['post']);

        // Assert — both files, in order, with their names, types and exact bytes
        $this->assertCount(2, $seen['files']);
        $this->assertSame(['files', 'cover.png', 'image/png', 0], [
            $seen['files'][0]['field'], $seen['files'][0]['name'], $seen['files'][0]['type'], $seen['files'][0]['error'],
        ]);
        $this->assertSame($png, base64_decode($seen['files'][0]['contents']), 'the binary file was altered in transit');
        $this->assertSame('note.txt', $seen['files'][1]['name']);
        $this->assertSame('second', base64_decode($seen['files'][1]['contents']));
    }

    /**
     * An escaped quote in a filename reaches the receiver as the escape, not as a broken header.
     */
    public function testAQuotedFilenameArrivesInOnePiece(): void
    {
        // Act
        $seen = Client::post($this->baseUrl . '/upload')->multipart([
            ['name' => 'f', 'contents' => 'x', 'filename' => 'my "best".txt'],
        ])->send()->json();

        // Assert — one file, whose name is the whole escaped value
        $this->assertCount(1, $seen['files']);
        $this->assertSame('my %22best%22.txt', $seen['files'][0]['name']);
    }
}
