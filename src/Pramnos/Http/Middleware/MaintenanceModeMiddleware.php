<?php

namespace Pramnos\Http\Middleware;

use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;

/**
 * Returns a 503 response when a maintenance flag file is present.
 *
 * The flag file is created and removed by a deployment script or admin command.
 * Default path: <ROOT>/maintenance.flag  (ROOT constant, or cwd if undefined).
 *
 * Usage — register globally so every route is gated:
 *   $router->addGlobalMiddleware(new MaintenanceModeMiddleware());
 *
 * Usage — custom flag file:
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
 * @package    PramnosFramework
 * @subpackage Http\Middleware
 */
class MaintenanceModeMiddleware implements MiddlewareInterface
{
    private string $flagFile;

    public function __construct(string $flagFile = '')
    {
        if ($flagFile !== '') {
            $this->flagFile = $flagFile;
        } else {
            $root = defined('ROOT') ? ROOT : getcwd();
            $this->flagFile = $root . DIRECTORY_SEPARATOR . 'maintenance.flag';
        }
    }

    public function handle(Request $request, callable $next): mixed
    {
        if (file_exists($this->flagFile)) {
            header('Retry-After: 3600');
            throw new \Exception(
                'The application is currently under maintenance. Please try again later.',
                503
            );
        }

        return $next($request);
    }
}
