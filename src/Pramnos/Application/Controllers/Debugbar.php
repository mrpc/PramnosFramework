<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

/**
 * Framework routing anchor for the debug-toolbar grant screen.
 *
 * `Application::getFrameworkController()` resolves controller names against
 * `Pramnos\Application\Controllers\`, so this thin class is what makes `/debugbar`
 * reachable without the application registering anything — the same arrangement
 * {@see Devpanel} uses.
 *
 * All logic lives in {@see \Pramnos\Debug\DebugGrantController}.
 */
class Debugbar extends \Pramnos\Debug\DebugGrantController
{
}
