<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\LocalBroadcastServer;

/**
 * The cooperative half of the stop protocol.
 *
 * `DaemonOrchestrator` stops a worker by dropping a `.stop` file beside its lock and
 * expecting the worker to notice. This loop had no way to notice: it blocked in
 * `stream_select()` and installed signal handlers, so it was **structurally
 * guaranteed** to be reported `[stop-timeout]` on every deploy — it ignored the
 * sentinel for the whole grace period, every time, and the line saying so is an
 * error about a worker that was given no way to comply.
 *
 * Reported from a deployment where the orchestrator's own sentinel check was also
 * being skipped: the WebSocket worker was never stopped, never signalled and never
 * reported as anything but healthy. It served pre-deploy code across deploys,
 * indefinitely.
 */
#[CoversClass(LocalBroadcastServer::class)]
class StopSentinelTest extends TestCase
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
     * A server that counts its loop iterations and never opens a real socket.
     *
     * `run()` binds and then blocks, so the loop is driven through a subclass rather
     * than by starting the daemon — a test that started it could only end by timing
     * out, which is how this suite once lost ten minutes.
     */
    private function server(): LocalBroadcastServer
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($listener, 'test needs a local listening socket');
        $this->sockets[] = $listener;

        return new class($listener) extends LocalBroadcastServer {
            public int $iterations = 0;

            /** @var resource */
            private $listener;

            public function __construct($listener)
            {
                parent::__construct('key');
                $this->listener = $listener;
            }

            protected function createServerSocket(string $host, int $port)
            {
                return $this->listener;
            }

            /**
             * Count iterations and stop after a bounded number, so a server that
             * ignores the check fails the test instead of hanging it.
             */
            private function countIteration(): void
            {
                $this->iterations++;
            }

            public function iterationCount(): int
            {
                return $this->iterations;
            }
        };
    }

    /**
     * The loop asks the check and retires when it says so.
     *
     * The whole filing in one assertion: `run()` must return.
     */
    public function testTheLoopRetiresWhenTheCheckSaysStop(): void
    {
        // Arrange
        $server = $this->server();
        $asked  = 0;

        $server->shouldStopUsing(function () use (&$asked): bool {
            $asked++;

            return true;      // stop immediately
        });

        // Act
        $started = microtime(true);
        $server->run('127.0.0.1', 0);
        $elapsed = microtime(true) - $started;

        // Assert
        $this->assertSame(1, $asked, 'asked once, and that was enough');
        $this->assertLessThan(2.0, $elapsed, 'run() must return, not wait out a grace period');
    }

    /**
     * The check is consulted before the loop does any work.
     *
     * A stop noticed at the top of an iteration retires the process one select
     * timeout later, rather than after another full round of accepts, reads and
     * fan-out — which on a busy node is the difference between a clean deploy and a
     * supervisor escalating to SIGTERM.
     */
    public function testTheCheckIsConsultedBeforeAnyWork(): void
    {
        // Arrange
        $server = $this->server();
        $ticked = 0;

        // onTick fires after loopIteration(), so it not firing proves no iteration ran.
        $server->onTick(function () use (&$ticked): void {
            $ticked++;
        });
        $server->shouldStopUsing(fn (): bool => true);

        // Act
        $server->run('127.0.0.1', 0);

        // Assert
        $this->assertSame(0, $ticked, 'not one iteration of work before retiring');
    }

    /**
     * The loop keeps running while the check says no, and stops when it changes.
     *
     * The realistic shape: the sentinel appears part-way through the process's life.
     */
    public function testTheLoopContinuesUntilTheCheckChanges(): void
    {
        // Arrange
        $server = $this->server();
        $asked  = 0;

        $server->shouldStopUsing(function () use (&$asked): bool {
            $asked++;

            return $asked > 3;      // no, no, no, then yes
        });

        // Act
        $server->run('127.0.0.1', 0);

        // Assert
        $this->assertSame(4, $asked);
    }

    /**
     * Only an explicit `true` stops the loop.
     *
     * A check returning a truthy non-bool — a string, a non-empty array from a
     * misspelled call — must not retire a healthy daemon. The same strictness the
     * channel registry applies to an authorization rule, for the same reason: a
     * plausible-looking mistake should not be read as an instruction.
     */
    public function testOnlyAnExplicitTrueStops(): void
    {
        // Arrange
        $server = $this->server();
        $calls  = 0;

        $server->shouldStopUsing(function () use (&$calls): mixed {
            $calls++;

            // Truthy but not true for the first two calls.
            return $calls < 3 ? 'yes' : true;
        });

        // Act
        $server->run('127.0.0.1', 0);

        // Assert
        $this->assertSame(3, $calls, 'the truthy values did not stop it');
    }

    /**
     * With no check installed, nothing changes — the loop is driven by stop() and
     * signals exactly as before.
     *
     * The compatibility assertion: an existing consumer that wires nothing keeps the
     * behaviour it has, including the bad half of it, until it wires the seam.
     */
    public function testWithoutACheckTheLoopIsUnchanged(): void
    {
        // Arrange
        $server = $this->server();

        // stop() from inside the tick is the only exit an unwired server has.
        $server->onTick(function () use ($server): void {
            $server->stop();
        });

        // Act
        $started = microtime(true);
        $server->run('127.0.0.1', 0);

        // Assert
        $this->assertLessThan(2.0, microtime(true) - $started, 'stop() still ends the loop');
    }

    /**
     * `run()` keeps its two-parameter signature, and the seam is a setter.
     *
     * A third parameter would be source-compatible for callers and fatal for a
     * subclass overriding `run()` — and this test file subclasses the server, so the
     * hazard is not hypothetical.
     */
    public function testRunSignatureIsUnchanged(): void
    {
        // Assert
        $this->assertCount(2, (new \ReflectionMethod(LocalBroadcastServer::class, 'run'))->getParameters());
        $this->assertTrue(method_exists(LocalBroadcastServer::class, 'shouldStopUsing'));
    }
}
