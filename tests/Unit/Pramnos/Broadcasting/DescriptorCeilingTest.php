<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Auth\AllowAllAuthorizer;
use Pramnos\Broadcasting\LocalBroadcastServer;

/**
 * The per-node client ceiling, and the warning that puts the number somewhere an
 * operator will see it.
 *
 * `stream_select()` is `select(2)`, whose descriptor sets are fixed-size bitmaps
 * bounded by `FD_SETSIZE` — typically 1024. Past it the call does not degrade and does
 * not return a partial result: **it returns false**, so the loop stops serving every
 * connected client at once.
 *
 * Reported by a deployment that went looking for the number rather than waiting for
 * it, and the reason it is worth a warning is in their words: the limit reads as absent
 * until you hit it, because `ulimit -n` on that host is 1,048,576 and nothing in the
 * environment suggests a bound near a thousand. The class docblock said "up to ~100
 * concurrent connections without tuning", which is vague in the dangerous direction —
 * it suggests a slope where there is a cliff.
 */
#[CoversClass(LocalBroadcastServer::class)]
class DescriptorCeilingTest extends TestCase
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
     * A server with $count clients already connected and a real listener installed,
     * so acceptClient() can run.
     *
     * @return array{0:LocalBroadcastServer, 1:int} [server, listening port]
     */
    private function serverWithClients(int $count): array
    {
        $server   = new LocalBroadcastServer('key', null, new AllowAllAuthorizer());
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($listener, 'test needs a local listening socket');
        $this->sockets[] = $listener;

        (new \ReflectionProperty($server, 'serverSocket'))->setValue($server, $listener);

        // Synthetic clients: the count is what the check reads, and opening a
        // thousand real sockets to assert a log line would be the test itself
        // hitting the ceiling.
        $clients = [];
        for ($i = 1; $i <= $count; $i++) {
            $clients[$i] = [
                'socket'      => null,
                'state'       => 'connected',
                'buffer'      => '',
                'channels'    => [],
                'socketId'    => $i . '.1',
                'pingAt'      => time() + 30,
                'connectedAt' => time(),
                'assembler'   => null,
            ];
        }
        (new \ReflectionProperty($server, 'clients'))->setValue($server, $clients);

        // Past the seeded keys, or acceptClient() reuses id 1 and *overwrites* a
        // synthetic client instead of adding one — leaving the count unchanged and the
        // assertion measuring nothing.
        (new \ReflectionProperty($server, 'nextSocketId'))->setValue($server, $count + 1);

        $port = (int) explode(':', (string) stream_socket_get_name($listener, false))[1];

        return [$server, $port];
    }

    /** Whether the warning has fired. */
    private function warned(LocalBroadcastServer $server): bool
    {
        return (bool) (new \ReflectionProperty($server, 'descriptorWarningLogged'))->getValue($server);
    }

    private function accept(LocalBroadcastServer $server, int $port): void
    {
        $client = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 1.0);
        $this->assertNotFalse($client);
        $this->sockets[] = $client;

        (new \ReflectionMethod($server, 'acceptClient'))->invoke($server);
    }

    /**
     * The ceiling is reported, and it is the `select(2)` bound rather than the
     * process's descriptor limit.
     *
     * Those are different numbers by orders of magnitude on a normal host, and
     * confusing them is exactly how the limit stays invisible: `ulimit -n` says a
     * million, `select(2)` says a thousand, and only one of them is the ceiling that
     * matters here.
     */
    public function testTheCeilingIsTheSelectBoundNotTheProcessLimit(): void
    {
        // Act
        $ceiling = LocalBroadcastServer::descriptorCeiling();

        // Assert
        $this->assertGreaterThan(0, $ceiling);
        $this->assertLessThanOrEqual(
            65536,
            $ceiling,
            'a "ceiling" in the millions would be ulimit -n, which is not what select(2) is bounded by'
        );
    }

    /**
     * Well below the ceiling, nothing is logged.
     *
     * A warning that fires early is a warning nobody reads.
     */
    public function testNoWarningWellBelowTheCeiling(): void
    {
        // Arrange
        [$server, $port] = $this->serverWithClients(10);

        // Act
        $this->accept($server, $port);

        // Assert
        $this->assertFalse($this->warned($server));
    }

    /**
     * Past the warning ratio, the warning fires.
     */
    public function testWarnsAsTheCeilingApproaches(): void
    {
        // Arrange — one under the threshold, so the accept crosses it
        $threshold = (int) floor(
            LocalBroadcastServer::descriptorCeiling() * LocalBroadcastServer::CLIENT_WARN_RATIO
        );
        [$server, $port] = $this->serverWithClients($threshold - 1);

        // Act
        $this->accept($server, $port);

        // Assert
        $this->assertTrue($this->warned($server), 'the operator must be told before the cliff');
    }

    /**
     * The warning fires once, not per accept.
     *
     * A node at the ceiling is accepting as fast as it can, and a line per connection
     * would bury the one that matters under thousands of copies of itself.
     */
    public function testTheWarningFiresOnlyOnce(): void
    {
        // Arrange
        $threshold = (int) floor(
            LocalBroadcastServer::descriptorCeiling() * LocalBroadcastServer::CLIENT_WARN_RATIO
        );
        [$server, $port] = $this->serverWithClients($threshold);
        $flag = new \ReflectionProperty($server, 'descriptorWarningLogged');

        // Act
        $this->accept($server, $port);
        $this->assertTrue($flag->getValue($server));

        // Reset the flag and confirm the guard, not chance, is what stops it
        $flag->setValue($server, true);
        $this->accept($server, $port);

        // Assert
        $this->assertTrue($flag->getValue($server), 'still latched, never un-set');
    }

    /**
     * The listening socket and the Redis ingest count against the same ceiling.
     *
     * They sit in the same `select(2)` set as the clients, so the usable client count
     * is the ceiling minus a couple — and a server that counted only clients would
     * warn slightly too late, which is the direction that matters.
     */
    public function testTheListenerAndIngestCountTowardsTheCeiling(): void
    {
        // Arrange — exactly at the threshold once the listener is counted
        $threshold = (int) floor(
            LocalBroadcastServer::descriptorCeiling() * LocalBroadcastServer::CLIENT_WARN_RATIO
        );
        [$server, $port] = $this->serverWithClients($threshold - 2);

        // Act — the accept makes it threshold-1 clients, +1 listener = threshold
        $this->accept($server, $port);

        // Assert
        $this->assertTrue(
            $this->warned($server),
            'the listener is one of the descriptors being watched'
        );
    }

    /**
     * The docblock names the ceiling, because that is where somebody sizing a
     * deployment will look.
     *
     * Asserted rather than trusted: the previous text said "up to ~100 concurrent
     * connections without tuning", which is both wrong and wrong in the dangerous
     * direction — it reads as a soft limit an operator can push past.
     */
    public function testTheClassDocblockNamesTheCeiling(): void
    {
        // Arrange
        $doc = (string) (new \ReflectionClass(LocalBroadcastServer::class))->getDocComment();

        // Assert
        $this->assertStringContainsString('FD_SETSIZE', $doc);
        $this->assertStringContainsString('1024', $doc);
        $this->assertStringNotContainsString(
            '~100 concurrent connections',
            $doc,
            'the old soft-sounding figure must be gone'
        );
    }
}
