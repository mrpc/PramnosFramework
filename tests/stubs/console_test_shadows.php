<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

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
