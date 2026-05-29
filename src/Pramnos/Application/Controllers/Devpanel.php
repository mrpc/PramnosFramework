<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

/**
 * Framework routing bridge for the DevPanel.
 *
 * The Application::getFrameworkController() method resolves controller names
 * against `Pramnos\Application\Controllers\`.  This thin class lets the
 * framework discover the DevPanel without any app-level registration.
 *
 * All logic lives in DevPanelController — this class only provides the
 * namespace anchor needed for auto-discovery.
 *
 */
class Devpanel extends \Pramnos\DevPanel\DevPanelController
{
}
