<?php

namespace Pramnos\Console;

/**
 * systemd service-manager notifications (the `sd_notify` protocol), in plain PHP.
 *
 * When a unit runs as `Type=notify`, systemd waits for the service to announce
 * itself ready and can restart it if it stops pinging a watchdog. The protocol is
 * a datagram written to the socket named in `NOTIFY_SOCKET`:
 *
 *   READY=1       the service has finished starting up
 *   WATCHDOG=1    liveness ping (with WatchdogSec set, a missed ping → restart)
 *   STOPPING=1    the service is shutting down on purpose
 *   STATUS=...    a human-readable status line, shown in `systemctl status`
 *
 * Every method is a no-op when the process is not running under `Type=notify`
 * (no `NOTIFY_SOCKET`), so a worker behaves identically under cron, by hand, or
 * supervised — call {@see enabled()} only if you want to branch on it.
 *
 * A standalone primitive (like {@see WorkerLock}/{@see WorkerReloader}): a bespoke
 * worker script uses it directly, and {@see CommandBase} composes it so console
 * workers get the watchdog for free.
 */
class SystemdNotifier
{
    /** The socket path, or null when not running under Type=notify. */
    private ?string $socket;

    /**
     * @param string|null $socket Defaults to the NOTIFY_SOCKET environment variable.
     */
    public function __construct(?string $socket = null)
    {
        $raw = $socket ?? (getenv('NOTIFY_SOCKET') ?: '');

        // Abstract-namespace sockets (a leading '@') are not reachable from PHP
        // streams, so treat them as "no socket" rather than failing on every send.
        $this->socket = ($raw !== '' && $raw[0] !== '@') ? $raw : null;
    }

    /** Whether notifications will actually be sent (running under Type=notify). */
    public function enabled(): bool
    {
        return $this->socket !== null;
    }

    /** Announce startup is complete (READY=1). */
    public function ready(): bool
    {
        return $this->notify("READY=1\n");
    }

    /** Liveness ping (WATCHDOG=1) — call on every heartbeat. */
    public function watchdog(): bool
    {
        return $this->notify("WATCHDOG=1\n");
    }

    /** Announce a deliberate shutdown (STOPPING=1). */
    public function stopping(): bool
    {
        return $this->notify("STOPPING=1\n");
    }

    /** Set the human-readable status line shown by `systemctl status`. */
    public function status(string $text): bool
    {
        return $this->notify('STATUS=' . str_replace("\n", ' ', $text) . "\n");
    }

    /**
     * Send a raw sd_notify datagram. Returns false (without error) when there is
     * no socket or the write fails — notifications are best-effort by design.
     */
    public function notify(string $state): bool
    {
        if ($this->socket === null) {
            return false;
        }

        $fp = @stream_socket_client('udg://' . $this->socket, $errno, $errstr, 1, STREAM_CLIENT_CONNECT);
        if ($fp === false) {
            return false;
        }

        $ok = @fwrite($fp, $state);
        fclose($fp);

        return $ok !== false;
    }
}
