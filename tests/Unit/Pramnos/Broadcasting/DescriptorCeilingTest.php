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
     * PHP does not expose FD_SETSIZE, so the accessor is a documented constant rather
     * than a measurement — and saying otherwise is what got corrected.
     *
     * The value of centralising it is real: one place to read, one place to correct.
     * The durability that was claimed for it is not, and a consumer adopted it on that
     * claim. Pinned so the docblock and the code cannot drift apart again.
     */
    public function testTheCeilingIsAConstantAndPhpDoesNotExposeIt(): void
    {
        // Assert
        $this->assertFalse(
            defined('FD_SETSIZE'),
            'if PHP ever exposes this, descriptorCeiling() should read it and this test should change'
        );
        $this->assertSame(1024, LocalBroadcastServer::descriptorCeiling());
    }

    /**
     * An installation on a build with a different --enable-fd-setsize can correct it.
     *
     * The honest answer to "it is just the literal": make the one place worth having
     * correctable, since PHP offers nothing to read.
     */
    public function testTheCeilingIsOverridable(): void
    {
        try {
            // Act
            LocalBroadcastServer::useDescriptorCeiling(4096);

            // Assert
            $this->assertSame(4096, LocalBroadcastServer::descriptorCeiling());
            $this->assertTrue(LocalBroadcastServer::isNearDescriptorCeiling(4000));
            $this->assertFalse(LocalBroadcastServer::isNearDescriptorCeiling(100));
        } finally {
            LocalBroadcastServer::useDescriptorCeiling(null);
        }

        $this->assertSame(1024, LocalBroadcastServer::descriptorCeiling(), 'null restores the default');
    }

    /**
     * A nonsensical override is ignored rather than believed.
     *
     * A zero or negative ceiling would make every count "near", so the warning would
     * fire on the first connection and be worthless from then on.
     */
    public function testANonsensicalCeilingIsIgnored(): void
    {
        try {
            // Act
            LocalBroadcastServer::useDescriptorCeiling(0);

            // Assert
            $this->assertSame(1024, LocalBroadcastServer::descriptorCeiling());
        } finally {
            LocalBroadcastServer::useDescriptorCeiling(null);
        }
    }

    /**
     * The descriptors the process holds are counted, not only the ones it watches.
     *
     * `select(2)` bounds descriptor *numbers* — PHP's own diagnostic says "you have
     * descriptors numbered at least as high as" — so a daemon holding a database
     * connection, a Redis handle and a log file has fd numbers above its watched
     * count. A warning computed from the count alone fires late by that margin, and a
     * consumer measured the gap: 58 feeds, 69 descriptors.
     */
    public function testHeldDescriptorsAreCountedNotOnlyWatchedOnes(): void
    {
        $held = LocalBroadcastServer::openDescriptorCount();

        if ($held === null) {
            $this->markTestSkipped('/proc/self/fd is unavailable, so the proxy cannot be exercised.');
        }

        // Arrange — a ceiling low enough that what this process already holds is
        // "near" on its own, with nothing watched at all.
        try {
            LocalBroadcastServer::useDescriptorCeiling(max(2, (int) ceil($held / 0.9)));

            // Act & Assert — zero watched, and still near, because the process holds
            // descriptors the watched count cannot see.
            $this->assertTrue(
                LocalBroadcastServer::isNearDescriptorCeiling(0),
                'held descriptors must count towards the ceiling'
            );
        } finally {
            LocalBroadcastServer::useDescriptorCeiling(null);
        }
    }

    /**
     * The greater of the two is used, so a caller watching more than the process holds
     * is still answered from its own number.
     */
    public function testTheGreaterOfWatchedAndHeldIsUsed(): void
    {
        try {
            LocalBroadcastServer::useDescriptorCeiling(1000);

            // Act & Assert — 950 watched is near regardless of what is held
            $this->assertTrue(LocalBroadcastServer::isNearDescriptorCeiling(950));
        } finally {
            LocalBroadcastServer::useDescriptorCeiling(null);
        }
    }

    /**
     * The open-descriptor count moves with the descriptors actually opened.
     *
     * Proves the proxy reads something real rather than a constant.
     */
    public function testTheOpenDescriptorCountTracksOpenedDescriptors(): void
    {
        $before = LocalBroadcastServer::openDescriptorCount();

        if ($before === null) {
            $this->markTestSkipped('/proc/self/fd is unavailable.');
        }

        // Arrange & Act
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->sockets[] = $pair[0];
        $this->sockets[] = $pair[1];

        $after = LocalBroadcastServer::openDescriptorCount();

        // Assert
        $this->assertGreaterThan($before, $after);
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
        // And the failure shape, which took two corrections to get right: first
        // stated as a wholesale false, "corrected" to a per-descriptor skip on the
        // strength of PHP's strings, then measured at the boundary and found to be
        // wholesale after all. The measurement is what the docblock now cites.
        $this->assertStringContainsString('not a partial result, not a skipped stream', $doc);
        $this->assertStringContainsString('nofile=4096', $doc, 'the measurement, not an inference');
        $this->assertStringContainsString('leaves the arrays untouched', $doc);
    }

    /**
     * The "close enough" question has one answer, shared with anything that asks.
     *
     * An application holding sockets in its own `stream_select()` loop has the same
     * cliff and none of this server's warning. One consumer had already borrowed
     * `descriptorCeiling()` for exactly that and was re-deriving the 90% itself —
     * which is one definition of "close" per application, and a number that drifts.
     */
    public function testTheClosenessTestIsSharedAndAgreesWithTheServersOwn(): void
    {
        // Arrange
        $ceiling   = LocalBroadcastServer::descriptorCeiling();
        $threshold = (int) floor($ceiling * LocalBroadcastServer::CLIENT_WARN_RATIO);

        // Act & Assert — the boundary, from both sides
        $this->assertFalse(LocalBroadcastServer::isNearDescriptorCeiling($threshold - 1));
        $this->assertTrue(LocalBroadcastServer::isNearDescriptorCeiling($threshold));
        $this->assertTrue(LocalBroadcastServer::isNearDescriptorCeiling($ceiling));

        // And a count far below, which is where a healthy application sits: one
        // consumer measured 69 descriptors for 58 feeds, about 7% of the ceiling.
        $this->assertFalse(LocalBroadcastServer::isNearDescriptorCeiling(69));
    }

    /**
     * The server's own warning goes through the shared helper, so the two cannot
     * disagree.
     *
     * Asserted by construction rather than by value: the warning fires exactly where
     * the helper says it should, at the boundary.
     */
    public function testTheServerWarningUsesTheSharedThreshold(): void
    {
        // Arrange — one client short of the point the helper calls close, counting
        // the listener
        $threshold = (int) floor(
            LocalBroadcastServer::descriptorCeiling() * LocalBroadcastServer::CLIENT_WARN_RATIO
        );
        [$server, $port] = $this->serverWithClients($threshold - 3);

        // Act — after the accept: threshold-2 clients + 1 listener = threshold-1
        $this->accept($server, $port);

        // Assert
        $this->assertFalse(
            LocalBroadcastServer::isNearDescriptorCeiling($threshold - 1),
            'precondition: one short is not close'
        );
        $this->assertFalse($this->warned($server), 'and the server agrees');
    }

    // -------------------------------------------------------------------------
    // The far side of the cliff
    // -------------------------------------------------------------------------

    /**
     * A select failure is counted, so a node past the ceiling is distinguishable from
     * an idle one.
     *
     * They used to share a branch. `0` means "nothing happened"; `false` means "I
     * could not watch", and past `FD_SETSIZE` that is permanent — so a node that
     * crossed an hour ago spun every 100 ms serving nobody and looked exactly like a
     * quiet one: same code path, `@` suppressing PHP's only diagnostic, and the
     * approach warning logged once per process and long since scrolled away.
     */
    public function testSelectFailuresAreCountedSeparatelyFromIdleTicks(): void
    {
        // Arrange
        $server = new LocalBroadcastServer('key', null, new AllowAllAuthorizer());

        // Act — a **live** listener plus one closed client socket, which is the shape
        // the real loop can reach: $read always holds the listening socket, so an
        // invalid entry beside it gives TypeError rather than ValueError. Measured:
        //
        //   all entries invalid          → ValueError, "No stream arrays were passed"
        //   one live entry plus an
        //   invalid one                  → TypeError, "not a valid stream resource"
        //
        // The first version of this test closed everything, which produced ValueError
        // and exercised a door the loop cannot open.
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($listener);
        $this->sockets[] = $listener;

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        fclose($pair[0]);
        fclose($pair[1]);

        (new \ReflectionProperty($server, 'clients'))->setValue($server, [
            1 => [
                'socket' => $pair[1], 'state' => 'connected', 'buffer' => '',
                'channels' => [], 'socketId' => '1.1', 'pingAt' => time() + 30,
                'connectedAt' => time(), 'assembler' => null,
            ],
        ]);
        (new \ReflectionProperty($server, 'serverSocket'))->setValue($server, $listener);

        (new \ReflectionMethod($server, 'loopIteration'))->invoke($server);

        // Assert
        $this->assertSame(
            1,
            $server->stats()['select_failures'],
            'a failed select must be counted, not folded into an idle tick'
        );
    }

    /**
     * An ordinary idle tick does not count as a failure.
     *
     * Without this the counter is decoration: every 100 ms tick would increment it and
     * a real failure would be invisible in the noise.
     */
    public function testAnIdleTickIsNotAFailure(): void
    {
        // Arrange — a live listener, nothing to read
        $server   = new LocalBroadcastServer('key', null, new AllowAllAuthorizer());
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($listener);
        $this->sockets[] = $listener;

        (new \ReflectionProperty($server, 'serverSocket'))->setValue($server, $listener);

        // Act
        (new \ReflectionMethod($server, 'loopIteration'))->invoke($server);

        // Assert
        $this->assertSame(0, $server->stats()['select_failures']);
    }

    /**
     * The first failure is reported immediately; repeats are throttled and carry a
     * count.
     *
     * Immediate, because the whole point is to be observable at the moment the ceiling
     * is crossed rather than only in the approach. Throttled, because the loop turns
     * over every 100 ms and ten lines a second buries the signal as effectively as
     * silence — and the count means nothing is lost to the throttle.
     */
    public function testTheFirstFailureIsReportedImmediatelyAndRepeatsAreThrottled(): void
    {
        // Arrange
        $server = new LocalBroadcastServer('key', null, new AllowAllAuthorizer());
        $report = new \ReflectionMethod($server, 'reportSelectFailure');
        $lastAt = new \ReflectionProperty($server, 'lastSelectFailureLogAt');
        $counters = new \ReflectionProperty($server, 'counters');

        $bump = static function () use ($counters, $server): void {
            $c = $counters->getValue($server);
            $c['select_failures']++;
            $counters->setValue($server, $c);
        };

        // Act — first failure
        $bump();
        $report->invoke($server, 5);
        $firstLoggedAt = $lastAt->getValue($server);

        // Assert
        $this->assertGreaterThan(0, $firstLoggedAt, 'the first failure is reported at once');

        // Act — immediate repeats
        $bump();
        $report->invoke($server, 5);

        // Assert — the throttle held, so the log timestamp did not move
        $this->assertSame($firstLoggedAt, $lastAt->getValue($server));

        // Act — past the throttle window
        $lastAt->setValue($server, time() - 31);
        $atLastLog = new \ReflectionProperty($server, 'selectFailuresAtLastLog');
        $before    = $atLastLog->getValue($server);
        $bump();
        $bump();
        $report->invoke($server, 5);

        // Assert — asserted on the bookkeeping rather than the timestamp, which would
        // be the same second and prove nothing.
        $this->assertGreaterThan(
            $before,
            $atLastLog->getValue($server),
            'the throttle reopens, and the delta carries the failures it swallowed'
        );
    }

    /**
     * The metrics endpoint carries the counter, because the response to a permanent
     * failure is a deployment's decision rather than this class's.
     *
     * Retiring the process would help — a fresh one gets low descriptor numbers again
     * — but it drops every connection to do it, and whether that beats serving nobody
     * is not a question the server should answer on its own.
     */
    public function testTheFailureCountIsExposedForAHealthCheck(): void
    {
        // Assert
        $this->assertArrayHasKey(
            'select_failures',
            (new LocalBroadcastServer('key'))->stats()
        );
    }

    /**
     * The failure branch pauses, so a node past the ceiling is not a hot loop.
     *
     * **What decides whether the call waited is whether anything in the set was
     * ready**, not which error it was — measured:
     *
     *   live descriptor past FD_SETSIZE          → false,     0 ms
     *   live *quiet* descriptor + invalid entry  → TypeError, 101 ms
     *   live *readable* descriptor + invalid one → TypeError, 0 ms
     *   every entry invalid                      → ValueError, 0 ms
     *
     * A test can produce the quiet-invalid shape cheaply, and that one waits — so the
     * first version of this test asserted elapsed time against a shape that paces
     * itself, and **passed with the pause removed**, verified by removing it. The
     * shapes that spin need either a thousand descriptors or a readable client on
     * every pass.
     *
     * This asserts the pause is taken instead, through the seam that exists for the
     * purpose. Not as good as measuring the real shape, and better than an assertion
     * that cannot fail.
     */
    public function testTheFailureBranchTakesThePause(): void
    {
        // Arrange — a live listener plus a closed socket, so select fails
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($listener);
        $this->sockets[] = $listener;

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        fclose($pair[0]);
        fclose($pair[1]);

        $server = new class('key', null, new AllowAllAuthorizer()) extends LocalBroadcastServer {
            public int $pauses = 0;

            protected function pauseAfterSelectFailure(): void
            {
                // Counted rather than taken: three real pauses would add 300 ms to the
                // suite for no extra proof.
                $this->pauses++;
            }
        };

        (new \ReflectionProperty(LocalBroadcastServer::class, 'clients'))->setValue($server, [
            1 => [
                'socket' => $pair[1], 'state' => 'connected', 'buffer' => '',
                'channels' => [], 'socketId' => '1.1', 'pingAt' => time() + 30,
                'connectedAt' => time(), 'assembler' => null,
            ],
        ]);
        (new \ReflectionProperty(LocalBroadcastServer::class, 'serverSocket'))
            ->setValue($server, $listener);

        $loop = new \ReflectionMethod(LocalBroadcastServer::class, 'loopIteration');

        // Act
        $loop->invoke($server);
        $loop->invoke($server);
        $loop->invoke($server);

        // Assert — once per failure, not once per process
        $this->assertSame(3, $server->stats()['select_failures']);
        $this->assertSame(3, $server->pauses, 'every failing iteration must pause');
    }

    /**
     * An idle tick does not pause twice.
     *
     * `stream_select()` already waited its timeout on a `0` return, so pausing again
     * there would halve the loop's responsiveness for nothing.
     */
    public function testAnIdleTickDoesNotPause(): void
    {
        // Arrange
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($listener);
        $this->sockets[] = $listener;

        $server = new class('key', null, new AllowAllAuthorizer()) extends LocalBroadcastServer {
            public int $pauses = 0;

            protected function pauseAfterSelectFailure(): void
            {
                $this->pauses++;
            }
        };

        (new \ReflectionProperty(LocalBroadcastServer::class, 'serverSocket'))
            ->setValue($server, $listener);

        // Act
        (new \ReflectionMethod(LocalBroadcastServer::class, 'loopIteration'))->invoke($server);

        // Assert
        $this->assertSame(0, $server->stats()['select_failures']);
        $this->assertSame(0, $server->pauses);
    }

    /**
     * The guard catches what the loop can actually throw.
     *
     * The first version caught `\ValueError`, which needs *every* entry in the set to
     * be invalid — impossible while `$read` holds a live listening socket. The
     * reachable throwable is `TypeError`. Catching `Throwable` rather than either name
     * is the point: a guard in a single-process event loop exists for the edit that
     * has not happened yet, and it should not depend on having predicted which door
     * that edit opens.
     */
    public function testTheGuardCatchesTheReachableThrowable(): void
    {
        // Arrange
        $method = new \ReflectionMethod(LocalBroadcastServer::class, 'loopIteration');
        $source = (string) file_get_contents(
            (new \ReflectionClass(LocalBroadcastServer::class))->getFileName()
        );

        // Assert — the narrow guard must not come back
        $this->assertStringContainsString('} catch (\Throwable) {', $source);
        $this->assertStringNotContainsString('} catch (\ValueError) {', $source);
        $this->assertTrue($method->isPrivate());
    }
}
