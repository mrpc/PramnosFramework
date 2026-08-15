<?php

namespace Pramnos\Http\Middleware;

use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;

/**
 * Returns a 503 response when a maintenance flag file is present.
 *
 * The flag is created and removed by a deployment script, an admin command, or by
 * the framework itself — and that last one is why this class watches **two** paths
 * rather than one.
 *
 * `Application::startMaintenance()` and `MigrationRunner` both raise
 * `<ROOT>/var/MAINTENANCE`. This middleware was written against
 * `<ROOT>/maintenance.flag`. The two never met, so an application that registered
 * this middleware exactly as documented and then ran a migration served the whole
 * migration from the live site: the runner raised its flag, the middleware watched
 * the other one, and nothing appeared to be wrong. The failure is silent in the
 * worst direction — the operator can see the middleware in the pipeline and
 * reasonably conclude the site is protected.
 *
 * So with no argument, **either** flag stops the request. Passing a path explicitly
 * keeps the old behaviour exactly: that path and nothing else, because an
 * application that named its own file has said which file it means.
 *
 * Usage — register globally so every route is gated:
 *   $router->addGlobalMiddleware(new MaintenanceModeMiddleware());
 *
 * Usage — custom flag file (this path only):
 *   $router->addGlobalMiddleware(
 *       new MaintenanceModeMiddleware('/var/run/myapp/maintenance.flag')
 *   );
 *
 * Usage — bypass for admin routes (do NOT add the middleware on those routes):
 *   $router->addGlobalMiddleware(new MaintenanceModeMiddleware());
 *   $router->get('/admin/maintenance/off', fn() => ...);  // no middleware
 *
 * Enable maintenance mode:  touch /path/to/maintenance.flag
 * Disable maintenance mode: rm /path/to/maintenance.flag
 *
 * `Retry-After` defaults to an hour and is overridden by the
 * `PRAMNOS_MAINTENANCE_RETRY_AFTER` constant — the same knob
 * `Application::showError()` reads, so the two cannot disagree about how long a
 * crawler should stay away.
 */
class MaintenanceModeMiddleware implements MiddlewareInterface
{
    /**
     * Flag paths; the request is stopped when any of them exists.
     *
     * @var array<int, string>
     */
    private array $flagFiles;

    /**
     * @param string $flagFile Watch only this path. Empty means watch the two
     *                         framework defaults: `<ROOT>/var/MAINTENANCE`, which
     *                         `startMaintenance()` and `MigrationRunner` raise, and
     *                         `<ROOT>/maintenance.flag`, this class's original
     *                         default, kept so existing deployments keep working.
     */
    public function __construct(string $flagFile = '')
    {
        if ($flagFile !== '') {
            $this->flagFiles = [$flagFile];

            return;
        }

        $root = defined('ROOT') ? ROOT : getcwd();

        $this->flagFiles = [
            $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'MAINTENANCE',
            $root . DIRECTORY_SEPARATOR . 'maintenance.flag',
        ];
    }

    /**
     * The paths this instance watches.
     *
     * Exposed so a test can assert *which* files are watched rather than only that
     * some file stopped the request — the defect this class carried was entirely
     * about watching the wrong path, and a test that only proves "a flag works"
     * would have passed throughout.
     *
     * @return array<int, string>
     */
    public function flagFiles(): array
    {
        return $this->flagFiles;
    }

    /**
     * How long clients should wait, in seconds.
     *
     * @return int
     */
    private function retryAfter(): int
    {
        if (defined('PRAMNOS_MAINTENANCE_RETRY_AFTER')) {
            $seconds = (int) constant('PRAMNOS_MAINTENANCE_RETRY_AFTER');
            if ($seconds > 0) {
                return $seconds;
            }
        }

        return 3600;
    }

    /**
     * Stop the request with a 503 while any watched flag exists.
     *
     * @param  Request  $request The incoming request
     * @param  callable $next    The rest of the pipeline
     * @return mixed
     * @throws \Exception With code 503 while the site is down
     */
    public function handle(Request $request, callable $next): mixed
    {
        foreach ($this->flagFiles as $flagFile) {
            if (file_exists($flagFile)) {
                if (!headers_sent()) {
                    header('Retry-After: ' . $this->retryAfter());
                }
                throw new \Exception(
                    'The application is currently under maintenance. Please try again later.',
                    503
                );
            }
        }

        return $next($request);
    }
}
