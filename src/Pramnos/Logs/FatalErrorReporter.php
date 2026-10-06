<?php

declare(strict_types=1);

namespace Pramnos\Logs;

/**
 * Records what was running when the process died of a fatal error.
 *
 * PHP's own log line for a fatal names the file and line where it happened and nothing else.
 * For "Allowed memory size … exhausted" that is wherever the last allocation fell — a getter in
 * a model, say — and says nothing about the command or the request that loaded a million rows.
 * A backtrace cannot be had: by shutdown the stack is gone. What can be had is the entry point,
 * and that is usually the whole answer.
 *
 * So, once per process, a shutdown function: on a fatal it writes `fatal.log` with the command
 * line or the request, the request id, the peak memory against the limit, and how long it ran.
 * `Application`'s constructor registers it, so web requests, console commands, cron jobs and
 * daemons all have it.
 *
 * Nothing is written for anything else: a warning or an exception already has its own path.
 */
final class FatalErrorReporter
{
    /** The error types after which PHP stops: the only ones this reports. */
    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

    /** Memory held back so the report can be written after the limit was hit. */
    private const RESERVE_BYTES = 256 * 1024;

    /** Command-line options whose values are not written down. */
    private const SECRET_OPTIONS = '/^(--?[\w-]*(?:pass|secret|token|key)[\w-]*=)(.*)$/i';

    private static bool $registered = false;

    private static ?string $reserve = null;

    /**
     * Register the shutdown function, once per process.
     *
     * @return void
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        self::$reserve    = str_repeat(' ', self::RESERVE_BYTES);

        // Runs after the coverage driver has stopped, so it is never counted; what it calls is
        // tested directly, and a real out-of-memory process proves the whole path.
        // @codeCoverageIgnoreStart
        register_shutdown_function(static function (): void {
            self::onShutdown();
        });
        // @codeCoverageIgnoreEnd
    }

    /**
     * At shutdown: free the reserve, and report the last error if it was fatal.
     *
     * @param array{type?: int, message?: string, file?: string, line?: int}|null $error
     *        The error to judge; error_get_last() when called as the shutdown function
     * @return bool Whether a report was written
     */
    public static function onShutdown(?array $error = null): bool
    {
        self::$reserve = null;
        $error ??= error_get_last();

        // Out of memory: a little headroom to write the line in, or the report dies the same way.
        if (is_array($error) && str_contains((string) ($error['message'] ?? ''), 'Allowed memory size')) {
            @ini_set('memory_limit', (string) (memory_get_usage(true) + 32 * 1024 * 1024));
        }

        return self::report($error);
    }

    /**
     * Write the report for an error as error_get_last() returns it; nothing unless it is fatal.
     *
     * @param array{type?: int, message?: string, file?: string, line?: int}|null $error
     * @return bool Whether a report was written
     */
    public static function report(?array $error): bool
    {
        if ($error === null || ((int) ($error['type'] ?? 0) & self::FATAL) === 0) {
            return false;
        }

        // Logger does not raise when it cannot write, which matters here: an exception from a
        // shutdown function would only add a second error to the first.
        Logger::critical('Fatal error: ' . ($error['message'] ?? ''), self::context($error), 'fatal');

        return true;
    }

    /**
     * What was running: the context written beside the message.
     *
     * @param array{type?: int, message?: string, file?: string, line?: int} $error
     * @param string $sapi Which kind of process this is; PHP_SAPI unless a test says otherwise
     * @return array<string, mixed>
     */
    public static function context(array $error, string $sapi = PHP_SAPI): array
    {
        $started = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

        return [
            'where'           => ($error['file'] ?? '?') . ':' . ($error['line'] ?? 0),
            'entry'           => self::entry($sapi),
            'request_id'      => \Pramnos\Debug\RequestId::activeId(),
            'pid'             => getmypid(),
            'peak_memory_mb'  => round(memory_get_peak_usage(true) / 1048576, 1),
            'memory_limit'    => ini_get('memory_limit'),
            'elapsed_seconds' => round(microtime(true) - $started, 3),
        ];
    }

    /**
     * The command line, or the request's method and path.
     *
     * Secret-looking option values are replaced, and a URL's query string is left out: either
     * can carry a credential, and this file is read by whoever investigates.
     */
    private static function entry(string $sapi): string
    {
        if ($sapi === 'cli' || $sapi === 'phpdbg') {
            $argv = array_map(
                static fn ($arg): string => (string) preg_replace(self::SECRET_OPTIONS, '$1***', (string) $arg),
                (array) ($_SERVER['argv'] ?? [])
            );

            return 'cli: ' . implode(' ', $argv);
        }

        $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');

        return ($_SERVER['REQUEST_METHOD'] ?? '?') . ' ' . ($path === false ? '' : $path);
    }
}
