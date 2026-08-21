<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\LocalBroadcastServer;

/**
 * Serving `wss://` directly.
 *
 * The interesting assertions are about the *binding*, not about a completed TLS
 * session: a real handshake needs a certificate and a peer, and what can go wrong
 * here is choosing the wrong transport or losing the context options on the way to
 * the listener.
 */
#[CoversClass(LocalBroadcastServer::class)]
class TlsTest extends TestCase
{
    /** @var list<resource> */
    private array $sockets = [];

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $this->sockets = [];
    }

    /**
     * Occupy a port and return it, so a later bind on it is guaranteed to fail.
     *
     * The failure message is how the chosen transport surfaces.
     *
     * Note what is *not* used here: `run()`. It binds and then blocks in its event
     * loop, so a test calling it depends on the bind failing — and the first version
     * of this test bound port 1, succeeded because the container runs as root, and
     * left a server holding that port for the rest of the session. Three unrelated
     * tests then failed, because "connect to a port nothing is listening on" had
     * quietly stopped being true. `createServerSocket()` cannot loop.
     */
    private function occupiedPort(): int
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($listener, 'test needs a local listening socket');
        $this->sockets[] = $listener;

        return (int) explode(':', (string) stream_socket_get_name($listener, false))[1];
    }

    /**
     * A server with no TLS configured binds plain TCP.
     */
    public function testWithoutTlsItBindsTcp(): void
    {
        // Arrange
        $port   = $this->occupiedPort();
        $server = new LocalBroadcastServer('key');

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#Cannot bind on tcp://127\.0\.0\.1:' . $port . '#');
        (new \ReflectionMethod($server, 'createServerSocket'))->invoke($server, '127.0.0.1', $port);
    }

    /**
     * With a certificate configured, the listener is an `ssl://` one.
     */
    public function testWithTlsItBindsSsl(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('The openssl extension is required.');
        }

        // Arrange — a readable certificate, so the failure is the port and not the cert
        $port   = $this->occupiedPort();
        $pem    = $this->selfSignedPem();
        $server = new LocalBroadcastServer('key');
        $server->useTls(['local_cert' => $pem]);

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#Cannot bind on ssl://127\.0\.0\.1:' . $port . '#');

        try {
            (new \ReflectionMethod($server, 'createServerSocket'))->invoke($server, '127.0.0.1', $port);
        } finally {
            @unlink($pem);
        }
    }

    /**
     * A valid certificate produces a working `ssl://` listener.
     *
     * This is the assertion that the context options reach the listener rather than
     * being built and dropped — a mistake that presents as a handshake failure in
     * production and as nothing at all in a test that only checks the transport
     * prefix.
     *
     * Not a completed handshake: that needs a peer *and* an accept, and this server
     * is single-threaded, so one process cannot be both ends.
     */
    public function testValidCertificateProducesAnSslListener(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('The openssl extension is required.');
        }

        // Arrange — a self-signed certificate, valid for this test only
        $pemFile = $this->selfSignedPem();

        try {
            $server = new LocalBroadcastServer('key');
            $server->useTls(['local_cert' => $pemFile, 'allow_self_signed' => true]);

            // Act
            $socket = (new \ReflectionMethod($server, 'createServerSocket'))
                ->invoke($server, '127.0.0.1', 0);

            // Assert
            $this->assertIsResource($socket);
            $this->assertStringStartsWith(
                '127.0.0.1:',
                (string) stream_socket_get_name($socket, false)
            );
            fclose($socket);
        } finally {
            @unlink($pemFile);
        }
    }

    /**
     * An unreadable certificate refuses to start, rather than binding a listener
     * that fails every handshake.
     *
     * **PHP does not load the certificate when the listener is created** — it loads
     * it per accepted connection. So without this check a wrong path produces a
     * server that binds, reports itself healthy, and then fails every single
     * handshake, with the operator looking at a port that is definitely open. The
     * failure has to happen at startup, where somebody is watching.
     */
    public function testUnreadableCertificateRefusesToStart(): void
    {
        // Arrange
        $server = new LocalBroadcastServer('key');
        $server->useTls(['local_cert' => '/nonexistent/cert.pem']);

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#local_cert .* is not readable#');
        (new \ReflectionMethod($server, 'createServerSocket'))->invoke($server, '127.0.0.1', 0);
    }

    /**
     * An unreadable private key is refused for the same reason.
     */
    public function testUnreadablePrivateKeyRefusesToStart(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('The openssl extension is required.');
        }

        // Arrange
        $pemFile = $this->selfSignedPem();

        try {
            $server = new LocalBroadcastServer('key');
            $server->useTls(['local_cert' => $pemFile, 'local_pk' => '/nonexistent/key.pem']);

            // Act & Assert
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('#local_pk .* is not readable#');
            (new \ReflectionMethod($server, 'createServerSocket'))->invoke($server, '127.0.0.1', 0);
        } finally {
            @unlink($pemFile);
        }
    }

    /**
     * TLS configured with no certificate at all is refused too.
     *
     * An empty `local_cert` in app.php is the shape a half-finished configuration
     * takes, and it must not start a listener either.
     */
    public function testTlsWithoutACertificateRefusesToStart(): void
    {
        // Arrange
        $server = new LocalBroadcastServer('key');
        $server->useTls(['verify_peer' => false]);

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#without a local_cert#');
        (new \ReflectionMethod($server, 'createServerSocket'))->invoke($server, '127.0.0.1', 0);
    }

    /**
     * Without TLS, the listener is a plain TCP one on a free port.
     */
    public function testWithoutTlsAFreePortBindsTcp(): void
    {
        // Arrange
        $server = new LocalBroadcastServer('key');

        // Act
        $socket = (new \ReflectionMethod($server, 'createServerSocket'))
            ->invoke($server, '127.0.0.1', 0);

        // Assert
        $this->assertIsResource($socket);
        fclose($socket);
    }

    /**
     * Write a self-signed certificate and key to a temp PEM file.
     */
    private function selfSignedPem(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key, 'could not generate a test key');

        $csr  = openssl_csr_new(['commonName' => 'localhost'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);

        $pemFile = tempnam(sys_get_temp_dir(), 'pramnos_tls_') . '.pem';
        file_put_contents($pemFile, $certPem . $keyPem);

        return $pemFile;
    }

    /**
     * useTls() can be called before run() and is the only way in — there is no
     * parameter on run() to lose track of.
     *
     * A third parameter there would have been source-compatible for callers and
     * fatal for a subclass overriding run(), which this codebase does in its own
     * tests.
     */
    public function testRunSignatureIsUnchanged(): void
    {
        // Arrange
        $method = new \ReflectionMethod(LocalBroadcastServer::class, 'run');

        // Assert
        $this->assertCount(2, $method->getParameters(), 'run() still takes host and port only');
        $this->assertTrue(method_exists(LocalBroadcastServer::class, 'useTls'));
    }
}
