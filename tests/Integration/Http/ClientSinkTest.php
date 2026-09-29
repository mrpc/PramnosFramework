<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Client;
use Pramnos\Http\ClientException;
use Pramnos\Http\ClientResponse;

/**
 * `Client::sink()` — a download written to a file as it arrives, against a real socket.
 *
 * glideday's finding: a ~60 MB gzip download (a geolocation database, ~130 MB inflated) had to
 * sit in memory whole, twice, to be written to disk, so it used PHP's own `copy()` instead. The
 * transport is what is under test — the write callback, the status it checks, the ceiling, the
 * inflation — so the server is a forked one-shot socket, the way `ClientBodyCeilingTest` does
 * it, and the fakes are covered separately.
 */
#[CoversClass(Client::class)]
#[\PHPUnit\Framework\Attributes\Group('integration')]
class ClientSinkTest extends TestCase
{
    /** @var int[] */
    private array $children = [];

    private string $file = '';

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('These tests fork a server; ext-pcntl is required.');
        }
        Client::resetFakes();
        $this->file = sys_get_temp_dir() . '/pramnos-sink-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $pid) {
            @posix_kill($pid, SIGKILL);
            @pcntl_waitpid($pid, $status);
        }
        $this->children = [];
        @unlink($this->file);
        Client::resetFakes();
    }

    /**
     * A 2xx body goes to the file, whole, and not into the response.
     */
    public function testASuccessfulBodyIsWrittenToTheFile(): void
    {
        // Arrange — a megabyte, so it arrives in many chunks
        $payload = str_repeat('0123456789abcdef', 65536);
        $url     = $this->serve($this->answer(200, 'application/octet-stream', $payload));

        // Act
        $response = Client::get($url)->timeout(10)->sink($this->file)->send();

        // Assert
        $this->assertSame(200, $response->status());
        $this->assertSame('', $response->body(), 'the body went to the file, not to memory');
        $this->assertSame($payload, (string) file_get_contents($this->file));
    }

    /**
     * A `.gz` download is inflated on its way into the file.
     *
     * Served as a file (`application/gzip`), not with `Content-Encoding` — which cURL would
     * decode on its own and which is not what a database dump is.
     */
    public function testAGzipFileIsInflatedIntoTheSink(): void
    {
        // Arrange
        $plain = str_repeat("city,country\nAthens,GR\n", 20000);
        $url   = $this->serve($this->answer(200, 'application/gzip', (string) gzencode($plain)));

        // Act
        Client::get($url)->timeout(10)->sink($this->file)->decodeGzip()->send();

        // Assert
        $this->assertSame($plain, (string) file_get_contents($this->file));
    }

    /**
     * An error's body is the response's body, and the file is not touched.
     *
     * Otherwise a 404 page would be sitting where the download should be, looking like it.
     */
    public function testAnErrorIsKeptInMemoryAndTheFileIsNotCreated(): void
    {
        // Arrange
        $url = $this->serve($this->answer(404, 'text/html', '<h1>Not here</h1>'));

        // Act
        $response = Client::get($url)->timeout(10)->sink($this->file)->send();

        // Assert
        $this->assertSame(404, $response->status());
        $this->assertSame('<h1>Not here</h1>', $response->body());
        $this->assertFileDoesNotExist($this->file);
    }

    /**
     * The ceiling counts what is written, and the response says it was cut short.
     */
    public function testTheCeilingAppliesToWhatIsWritten(): void
    {
        // Arrange
        $url = $this->serve($this->answer(200, 'application/octet-stream', str_repeat('x', 200000)));

        // Act
        $response = Client::get($url)->timeout(10)->sink($this->file)->maxResponseBytes(1000)->send();

        // Assert
        $this->assertTrue($response->truncated());
        $this->assertSame(1000, filesize($this->file));
    }

    /**
     * A successful answer with no body still leaves the file — empty.
     */
    public function testAnEmptySuccessStillLeavesTheFile(): void
    {
        // Arrange
        file_put_contents($this->file, 'stale content from a previous download');
        $url = $this->serve($this->answer(200, 'application/octet-stream', ''));

        // Act
        Client::get($url)->timeout(10)->sink($this->file)->send();

        // Assert
        $this->assertSame('', (string) file_get_contents($this->file));
    }

    /**
     * A file that cannot be written, or a body that is not gzip, raises rather than succeeding.
     *
     * Either way the caller would otherwise be told the download worked.
     */
    public function testAFileThatCannotBeWrittenRaises(): void
    {
        // Arrange
        $url = $this->serve($this->answer(200, 'application/octet-stream', 'data'));

        // Assert
        $this->expectException(ClientException::class);

        // Act
        Client::get($url)->timeout(10)->sink('/nonexistent-directory/for/sure/file')->send();
    }

    /** The same for an empty success, which writes the file only at the end. */
    public function testAnEmptySuccessToAFileThatCannotBeWrittenRaises(): void
    {
        // Arrange
        $url = $this->serve($this->answer(200, 'application/octet-stream', ''));

        // Assert
        $this->expectException(ClientException::class);

        // Act
        Client::get($url)->timeout(10)->sink('/nonexistent-directory/for/sure/file')->send();
    }

    /** And for a faked download, which is written in one go. */
    public function testAFakedDownloadToAFileThatCannotBeWrittenRaises(): void
    {
        // Arrange
        Client::fake(['https://dl.test/*' => ClientResponse::make('data', 200)]);

        // Assert
        $this->expectException(ClientException::class);

        // Act
        Client::get('https://dl.test/file')->sink('/nonexistent-directory/for/sure/file')->send();
    }

    public function testABodyThatIsNotGzipRaisesWhenAskedToInflate(): void
    {
        // Arrange
        $url = $this->serve($this->answer(200, 'application/gzip', 'plain text, not gzip'));

        // Assert
        $this->expectException(ClientException::class);

        // Act
        Client::get($url)->timeout(10)->sink($this->file)->decodeGzip()->send();
    }

    /**
     * A faked response is delivered to the sink, inflated, as a real one is — and an error is not.
     *
     * So an application's download command can be tested without the network.
     */
    public function testAFakeIsWrittenToTheSinkToo(): void
    {
        // Arrange
        Client::fake([
            'https://dl.test/ok.gz'   => ClientResponse::make((string) gzencode('inflated'), 200),
            'https://dl.test/missing' => ClientResponse::make('gone', 404),
            'https://dl.test/bad.gz'  => ClientResponse::make('not gzip', 200),
        ]);

        // Act
        $ok      = Client::get('https://dl.test/ok.gz')->sink($this->file)->decodeGzip()->send();
        $written = (string) file_get_contents($this->file);
        @unlink($this->file);
        $missing = Client::get('https://dl.test/missing')->sink($this->file)->send();

        // Assert
        $this->assertSame('', $ok->body());
        $this->assertSame('inflated', $written);
        $this->assertSame('gone', $missing->body());
        $this->assertFileDoesNotExist($this->file);

        $this->expectException(ClientException::class);
        Client::get('https://dl.test/bad.gz')->sink($this->file)->decodeGzip()->send();
    }

    /** A one-shot answer with this status, type and body. */
    private function answer(int $status, string $type, string $body): callable
    {
        return static function ($conn) use ($status, $type, $body): void {
            fwrite($conn, "HTTP/1.1 {$status} X\r\nContent-Type: {$type}\r\n"
                . 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n");
            foreach (str_split($body === '' ? '' : $body, 16384) as $part) {
                if ($part !== '' && @fwrite($conn, $part) === false) {
                    return;
                }
            }
        };
    }

    /** Fork a one-shot HTTP server; the same shape as ClientBodyCeilingTest::serve(). */
    private function serve(callable $respond): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "test needs a listening socket: {$errstr} ({$errno})");
        $port = (int) explode(':', (string) stream_socket_get_name($server, false))[1];

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            $conn = @stream_socket_accept($server, 10);
            if (is_resource($conn)) {
                $request = '';
                while (!str_contains($request, "\r\n\r\n")) {
                    $line = fgets($conn, 8192);
                    if ($line === false) {
                        break;
                    }
                    $request .= $line;
                }
                $respond($conn);
                @fclose($conn);
            }
            @fclose($server);
            posix_kill(posix_getpid(), SIGKILL);
        }

        fclose($server);
        $this->children[] = $pid;

        return 'http://127.0.0.1:' . $port;
    }
}
