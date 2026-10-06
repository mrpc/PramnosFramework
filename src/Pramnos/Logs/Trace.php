<?php

declare(strict_types=1);

namespace Pramnos\Logs;

/**
 * A stack trace without the arguments: where it went, not what it carried.
 *
 * `getTraceAsString()` writes each call's arguments, up to fifteen characters of every string,
 * whenever `zend.exception_ignore_args` is off — PHP's default. A call's arguments are whatever
 * passed through it: Symfony Mailer authenticates with `executeCommand(base64(password))`, so a
 * rejected SMTP password put most of itself into the log, and a failed query can carry the
 * values it was binding. Every place the framework records a trace — a log, a JSON error, the
 * changelog — goes through this instead.
 */
final class Trace
{
    /**
     * The trace in getTraceAsString()'s layout, each call written as `Class->method()`.
     *
     * @param \Throwable $throwable Whose trace to write
     * @return string
     */
    public static function of(\Throwable $throwable): string
    {
        $lines = [];
        foreach ($throwable->getTrace() as $i => $frame) {
            $where   = isset($frame['file']) ? $frame['file'] . '(' . ($frame['line'] ?? 0) . ')' : '[internal function]';
            $lines[] = '#' . $i . ' ' . $where . ': '
                . ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '') . '()';
        }
        $lines[] = '#' . count($lines) . ' {main}';

        return implode("\n", $lines);
    }
}
