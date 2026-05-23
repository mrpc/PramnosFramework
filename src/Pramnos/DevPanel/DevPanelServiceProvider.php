<?php

declare(strict_types=1);

namespace Pramnos\DevPanel;

use Pramnos\Application\ServiceProvider;
use Pramnos\Application\Settings;

/**
 * Bootstraps the DevPanel feature.
 *
 * Activated by listing 'devpanel' in app.php features.  The panel is only
 * accessible to authenticated admin users (minimum usertype 90 by default,
 * configurable via app.php devpanel.min_usertype).
 *
 * The controller is auto-discoverable via the framework's getFrameworkController()
 * mechanism (Devpanel.php in Application\Controllers\) — no route registration
 * is required.  This provider just validates config and sets up the feature.
 *
 * @package PramnosFramework
 * @subpackage DevPanel
 */
class DevPanelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind DevPanel config defaults so the controller can read them.
        // Nothing to pre-register — the controller is auto-discovered.
    }

    public function boot(): void
    {
        // DevPanel is only meaningful in HTTP context (not CLI).
        if (PHP_SAPI === 'cli') {
            return;
        }

        // Verify the minimum usertype setting is sane (warn in logs if not).
        $min = Settings::getSetting('devpanel.min_usertype');
        if ($min !== false && $min !== null && ((int) $min < 1 || (int) $min > 100)) {
            \Pramnos\Logs\Logger::log(
                'DevPanel: devpanel.min_usertype must be between 1 and 100; got ' . (string) $min,
                'devpanel',
            );
        }
    }
}
