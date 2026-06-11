<?php

declare(strict_types=1);

namespace Pramnos\Console;

if (!defined('SIGINT')) {
    define('SIGINT', 2);
}
if (!defined('SIG_IGN')) {
    define('SIG_IGN', 1);
}
if (!defined('SIG_DFL')) {
    define('SIG_DFL', 0);
}

function function_exists(string $name): bool
{
    if ($name === 'pcntl_signal' && isset($GLOBALS['mock_pcntl_support'])) {
        return true;
    }
    return \function_exists($name);
}

function pcntl_signal(int $sig, $handler): bool
{
    return true;
}
