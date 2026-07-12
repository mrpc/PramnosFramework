<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

/**
 * Shadow function for function_exists in the Pramnos\Tests\Unit\Console namespace.
 *
 * Simulates the presence of pcntl signal functions inside test class namespaces
 * during tests that run on environments lacking the pcntl PHP extension.
 *
 * @param string $name The function name.
 * @return bool True if the function exists, false otherwise.
 */
function function_exists(string $name): bool
{
    if ($name === 'pcntl_signal' && isset($GLOBALS['mock_pcntl_support'])) {
        return true;
    }
    return \function_exists($name);
}

/**
 * Shadow function for pcntl_signal in the Pramnos\Tests\Unit\Console namespace.
 *
 * Acts as a dummy fallback to prevent execution errors when registering signals
 * inside test setups where pcntl functions are mock-supported.
 *
 * @param int $sig The signal number.
 * @param callable|int $handler The handler closure or constant.
 * @return bool Always returns true.
 */
function pcntl_signal(int $sig, $handler): bool
{
    return true;
}
