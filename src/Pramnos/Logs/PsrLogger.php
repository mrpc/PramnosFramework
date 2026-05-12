<?php

declare(strict_types=1);

namespace Pramnos\Logs;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

/**
 * PSR-3 compliant instance-based logger.
 *
 * Wraps the static `Logger` class so that any library expecting a
 * `Psr\Log\LoggerInterface` can receive a `PsrLogger` instance. Each
 * instance is bound to a log channel (file name) that defaults to
 * `pramnosframework`.
 *
 * ## Usage
 *
 * ```php
 * // Via Logger factory
 * $log = Logger::channel('myapp');
 * $log->info('User {id} logged in', ['id' => 42]);
 *
 * // Direct instantiation
 * $log = new PsrLogger('payments');
 * $log->error('Payment failed', ['order' => $orderId]);
 * ```
 *
 * ## Context interpolation
 *
 * Placeholder values in the form `{key}` are replaced with the
 * corresponding value from the $context array, per the PSR-3 spec.
 *
 * @package     PramnosFramework
 * @subpackage  Logs
 * @see         https://www.php-fig.org/psr/psr-3/
 */
class PsrLogger extends AbstractLogger
{
    /** Valid PSR-3 log levels in severity order (highest first). */
    private const VALID_LEVELS = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        LogLevel::INFO,
        LogLevel::DEBUG,
    ];

    /**
     * @param string $file  Log file name (without extension) — the "channel".
     */
    public function __construct(private readonly string $file = 'pramnosframework') {}

    /**
     * Log a message at the given level.
     *
     * Delegates to the static `Logger` infrastructure so that all output
     * goes through the same structured-log pipeline (JSON envelope,
     * directory auto-creation, file-locking).
     *
     * @param  mixed                  $level   A PSR-3 LogLevel constant.
     * @param  string|\Stringable     $message Message, may contain {placeholder} tokens.
     * @param  mixed[]                $context Placeholder values + optional metadata.
     * @throws InvalidArgumentException        If $level is not a valid PSR-3 level.
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $levelStr = (string) $level;

        if (!in_array($levelStr, self::VALID_LEVELS, true)) {
            throw new InvalidArgumentException(
                "Invalid PSR-3 log level: '{$levelStr}'"
            );
        }

        $interpolated = $this->interpolate((string) $message, $context);

        $context['level'] = $levelStr;
        Logger::log($interpolated, $this->file, 'log', false, $context);
    }

    /**
     * Return the channel (log file name) this logger is bound to.
     */
    public function getChannel(): string
    {
        return $this->file;
    }

    /**
     * Interpolate context values into the message placeholders.
     * Implements the PSR-3 placeholder spec: {key} → (string) $context['key'].
     *
     * @param  string  $message
     * @param  mixed[] $context
     * @return string
     */
    private function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }

        $replace = [];
        foreach ($context as $key => $value) {
            if (!is_array($value) && (!is_object($value) || method_exists($value, '__toString'))) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replace);
    }
}
