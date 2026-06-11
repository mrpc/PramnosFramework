<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Drivers;

function class_exists(string $class, bool $autoload = true): bool
{
    if ($class === 'Pusher\\Pusher' && isset($GLOBALS['mock_pusher_absent'])) {
        return false;
    }
    return \class_exists($class, $autoload);
}
