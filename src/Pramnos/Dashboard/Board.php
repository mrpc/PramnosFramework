<?php

declare(strict_types=1);

namespace Pramnos\Dashboard;

/**
 * A dashboard board drawn inside any screen, not only on a dashboard page of its own.
 *
 * ```php
 * // app/app.php
 * 'dashboard' => ['boards' => ['wcm' => ['widgets' => [...], 'default' => [...]]]],
 *
 * // in the WCM screen's action, above or instead of its tabs
 * $html = \Pramnos\Dashboard\Board::render('wcm', 'Overview');
 * ```
 *
 * Each person arranges it as they arrange the dashboard: Customise, move, resize, add from the
 * board's catalogue. Its controls talk to the application's dashboard controller with
 * `?board=wcm`, so the screen it sits in needs no actions of its own. The controller is the one
 * app.php names (`'dashboard' => ['controller' => App\Admin\Controllers\Dashboard::class]`), the
 * application's `Controllers\Dashboard`, or the framework's — whichever is a
 * {@see \Pramnos\Application\Controllers\DashboardController} first.
 */
final class Board
{
    /**
     * The board's HTML for the signed-in person, or '' when the board is not theirs to use.
     *
     * @param string $board A board app.php lists under `dashboard.boards`
     * @param string $title A heading over it; '' for none
     * @throws \InvalidArgumentException For a board app.php does not list
     */
    public static function render(string $board, string $title = ''): string
    {
        return \Pramnos\Application\Controllers\DashboardController::boardFor(self::controller(), $board, $title);
    }

    /** The dashboard controller a board's controls are sent to. */
    public static function controller(): \Pramnos\Application\Controllers\DashboardController
    {
        $app       = \Pramnos\Application\Application::currentInstance();
        $namespace = is_object($app) ? trim((string) ($app->applicationInfo['namespace'] ?? ''), '\\') : '';
        $named     = is_object($app) ? ($app->applicationInfo['dashboard']['controller'] ?? null) : null;
        foreach ([$named, $namespace !== '' ? $namespace . '\\Controllers\\Dashboard' : null] as $class) {
            if (is_string($class) && class_exists($class) && is_a($class, \Pramnos\Application\Controllers\DashboardController::class, true)) {
                return new $class($app);
            }
        }

        return new \Pramnos\Application\Controllers\DashboardController($app);
    }
}
