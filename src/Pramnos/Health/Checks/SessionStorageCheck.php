<?php

declare(strict_types=1);

namespace Pramnos\Health\Checks;

use Pramnos\Health\HealthCheck;
use Pramnos\Health\HealthCheckResult;

/**
 * Where PHP is keeping sessions, and whether that survives a second server.
 *
 * `files` is PHP's default and is right on one machine: free, no dependency, nothing to
 * configure. On two it is the quietest failure in a deployment. A visitor whose next
 * request lands on the other node has **no session** — signed out at random, on a site that
 * is otherwise working — and it presents as an expiry, a cookie problem, a `SameSite`
 * mistake. Everything except a load balancer.
 *
 * Nothing else reports it, which is the argument for a check. The application works, every
 * test passes, and the failure only exists in a topology the developer's machine does not
 * have.
 *
 * **`notice`, not `degraded`.** A single-server installation is not broken and must not be
 * paged for — most installations are single-server, and a check that cries wolf gets muted.
 *
 * That sentence was here before the status was. `degraded` answered **503**, so the check
 * did the one thing it documented itself as avoiding, to the majority case it named: a
 * correct installation's `/health/check` went from 200 to 503 the moment this shipped, and
 * an uptime monitor pointed at it — which is what that endpoint is for — alerted for ever.
 * {@see \Pramnos\Health\HealthStatus::Notice}, which is the category this wanted and had
 * to borrow.
 *
 * It says which store is in use either way, because the common way to get this wrong is not
 * leaving it on files but pointing it at a Redis that is not the one the other nodes use.
 *
 * @see \Pramnos\Http\Session::applyConfiguredStore() for how to change it, which is a
 *      setting rather than a `php.ini` edit.
 */
class SessionStorageCheck implements HealthCheck
{
    /**
     * Handlers that more than one machine can reach.
     *
     * A list rather than "anything that is not files", because `user` — a handler the
     * application registered itself — could be either, and answering `ok` for one that
     * writes to local disk would be worse than saying nothing. An installation with its own
     * handler knows what it built and can silence this by naming it here.
     *
     * @var list<string>
     */
    private const SHARED = ['redis', 'rediscluster', 'memcached', 'memcache'];

    /**
     * The two ini settings, injectable.
     *
     * Not a convenience: `session.save_handler` and `session.save_path` cannot be set once
     * a session is active, so a test that drove this through `ini_set()` passed on its own
     * and failed in a full run — where something earlier had started one. The same seam
     * {@see CacheBackendCheck} takes its cache through, and for the same reason.
     *
     * Both null — which is every caller in production — reads the live values.
     */
    public function __construct(
        private ?string $handler = null,
        private ?string $path = null
    ) {
    }

    public function getName(): string
    {
        return 'session_storage';
    }

    public function run(): HealthCheckResult
    {
        $handler = $this->handler ?? (string) ini_get('session.save_handler');
        $path    = $this->path ?? (string) ini_get('session.save_path');

        $details = [
            'handler' => $handler,
            // The path can carry credentials for Redis (`tcp://h:6379?auth=…`), and a
            // health endpoint is the last place to print one.
            'path'    => $this->withoutSecrets($path),
        ];

        if (in_array($handler, self::SHARED, true)) {
            return HealthCheckResult::ok(
                $this->getName(),
                'Sessions are on ' . $handler . ', which more than one server can reach',
                $details
            );
        }

        return HealthCheckResult::notice(
            $this->getName(),
            'Sessions are on "' . $handler . '", which is local to this machine. '
            . 'Correct on a single server; on more than one a visitor is signed out '
            . 'whenever the load balancer sends them to a different node.',
            $details + [
                /*
                 * "Reuses the cache host" used to be the whole of this advice, and on a
                 * shared host it is advice to put session ids somewhere every other site
                 * on the machine can read. A session id is an account. One Redis with many
                 * vhosts — Virtualmin, cPanel — is exactly the case where the cache host is
                 * not this application's to reuse.
                 */
                'fix' => "Set APP_SESSION_HANDLER=redis in .env (or 'session' => "
                    . "['handler' => 'redis'] in app/config/app.php), pointed at a Redis "
                    . 'this application controls — with no path it reuses the cache host, '
                    . 'which on a shared server is readable by every other site on it, and '
                    . 'a session id is an account. Use a dedicated instance, or at least a '
                    . 'separate database with its own credentials. Sticky sessions at the '
                    . 'balancer are the other answer.',
            ]
        );
    }

    /**
     * The save path with anything that looks like a credential removed.
     *
     * `tcp://redis:6379?auth=hunter2` is a valid Redis save path, and `health:check` output
     * is pasted into tickets and chat. The host is the useful half and the only half worth
     * printing.
     */
    private function withoutSecrets(string $path): string
    {
        if ($path === '' || !str_contains($path, '?')) {
            return $path;
        }

        return substr($path, 0, strpos($path, '?')) . '?…';
    }
}
