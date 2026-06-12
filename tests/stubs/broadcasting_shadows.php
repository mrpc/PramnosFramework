<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Drivers;

/**
 * Shadow function for class_exists in the Pramnos\Broadcasting\Drivers namespace.
 *
 * This allows unit/integration tests to simulate the absence of external libraries
 * (such as the Pusher SDK) without physically uninstalling the package from vendor.
 * If the global mock variable $GLOBALS['mock_pusher_absent'] is set, this function
 * will intercept checks for 'Pusher\Pusher' and return false. Otherwise, it delegates
 * to PHP's built-in class_exists function.
 *
 * @param string $class The class name to check.
 * @param bool $autoload Whether to trigger autoloading.
 * @return bool True if the class exists, false otherwise.
 */
function class_exists(string $class, bool $autoload = true): bool
{
    if ($class === 'Pusher\\Pusher' && isset($GLOBALS['mock_pusher_absent'])) {
        return false;
    }
    return \class_exists($class, $autoload);
}
