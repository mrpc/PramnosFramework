<?php

namespace Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\SystemdNotifier;

/**
 * Unit tests for the sd_notify wrapper.
 *
 * enabled() branches are driven by the constructor argument / NOTIFY_SOCKET; the
 * actual send is verified against a real unix datagram socket bound in the temp
 * directory, so no systemd is needed.
 */
class SystemdNotifierTest extends TestCase
{
    /**
     * With no socket (empty / unset NOTIFY_SOCKET) the notifier is disabled and
     * every send is a harmless no-op returning false.
     */
    public function testDisabledWithoutSocket(): void
    {
        $notifier = new SystemdNotifier('');

        $this->assertFalse($notifier->enabled());
        $this->assertFalse($notifier->ready());
        $this->assertFalse($notifier->watchdog());
        $this->assertFalse($notifier->stopping());
    }

    /**
     * Abstract-namespace sockets (a leading '@') are not reachable from PHP
     * streams, so they are treated as "no socket".
     */
    public function testAbstractNamespaceSocketIsDisabled(): void
    {
        $this->assertFalse((new SystemdNotifier('@/org/example/notify'))->enabled());
    }

    /**
     * A concrete socket path (explicit, or from NOTIFY_SOCKET) enables the notifier.
     */
    public function testConcreteSocketEnables(): void
    {
        $this->assertTrue((new SystemdNotifier('/run/systemd/notify'))->enabled());

        $prev = getenv('NOTIFY_SOCKET');
        putenv('NOTIFY_SOCKET=/run/systemd/notify');
        try {
            $this->assertTrue((new SystemdNotifier())->enabled(), 'reads NOTIFY_SOCKET when no arg given');
        } finally {
            $prev === false ? putenv('NOTIFY_SOCKET') : putenv('NOTIFY_SOCKET=' . $prev);
        }
    }

    /**
     * ready()/watchdog()/stopping() send the exact sd_notify datagrams, received
     * here on a real unix datagram socket.
     */
    public function testSendsDatagramsToTheSocket(): void
    {
        $path = sys_get_temp_dir() . '/sdn_' . getmypid() . '_' . bin2hex(random_bytes(3)) . '.sock';
        @unlink($path);

        $server = @stream_socket_server('udg://' . $path, $errno, $errstr, STREAM_SERVER_BIND);
        if ($server === false) {
            $this->markTestSkipped('Unix datagram sockets not available: ' . $errstr);
        }

        try {
            $notifier = new SystemdNotifier($path);
            $this->assertTrue($notifier->enabled());

            $this->assertTrue($notifier->ready());
            $this->assertSame("READY=1\n", stream_socket_recvfrom($server, 64));

            $this->assertTrue($notifier->watchdog());
            $this->assertSame("WATCHDOG=1\n", stream_socket_recvfrom($server, 64));

            $this->assertTrue($notifier->stopping());
            $this->assertSame("STOPPING=1\n", stream_socket_recvfrom($server, 64));

            $this->assertTrue($notifier->status("processing\nqueue"));
            $this->assertSame('STATUS=processing queue' . "\n", stream_socket_recvfrom($server, 64));
        } finally {
            fclose($server);
            @unlink($path);
        }
    }
}
