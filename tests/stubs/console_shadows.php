<?php

declare(strict_types=1);

namespace Pramnos\Console;

// Define PCNTL signal constants if they are not defined in the current PHP environment
// (e.g. if the pcntl extension is missing or disabled).
if (!defined('SIGINT')) {
    define('SIGINT', 2);
}
if (!defined('SIG_IGN')) {
    define('SIG_IGN', 1);
}
if (!defined('SIG_DFL')) {
    define('SIG_DFL', 0);
}

/**
 * Shadow function for function_exists in the Pramnos\Console namespace.
 *
 * This allows the unit tests to pretend that the 'pcntl_signal' function exists
 * even on platforms/environments where the pcntl extension is not installed,
 * enabling testing of conditional signal registration pathways.
 *
 * @param string $name The function name to check.
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
 * Shadow function for pcntl_signal in the Pramnos\Console namespace.
 *
 * Registers a signal handler using the real pcntl_signal function if it is
 * available, and sets a global test execution flag to verify that signal
 * registration logic in the console daemons was triggered.
 *
 * @param int $sig The signal number.
 * @param callable|int $handler The signal handler.
 * @return bool True on success, false on failure.
 */
function pcntl_signal(int $sig, $handler): bool
{
    $GLOBALS['mock_debug_pcntl_signal_called'] = true;
    if (\function_exists('pcntl_signal')) {
        return \pcntl_signal($sig, $handler);
    }
    return true;
}
