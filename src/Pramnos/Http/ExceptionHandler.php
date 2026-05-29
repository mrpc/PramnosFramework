<?php

namespace Pramnos\Http;

use Pramnos\Logs\Logger;

/**
 * Centralised exception renderer and logger.
 *
 * Replaces the ad-hoc try/catch output in Application::exec() with a single,
 * testable class that produces a proper Response object.
 *
 * Two output formats:
 *   - 'html' — debug: full stack trace page; production: minimal friendly page.
 *   - 'json' — always an {"error": ..., "code": ...} envelope; debug adds class/trace.
 *
 * Usage — inside Application::exec():
 *   $format = $doc->getType() === 'json' ? 'json' : 'html';
 *   $debug  = defined('DEVELOPMENT') && DEVELOPMENT === true;
 *   ExceptionHandler::log($exception);
 *   ExceptionHandler::render($exception, $format, $debug)->send();
 *   $this->close();
 *
 * Usage — standalone (API middleware, CLI, etc.):
 *   set_exception_handler(function (\Throwable $e) {
 *       ExceptionHandler::log($e);
 *       ExceptionHandler::render($e, 'json', false)->send();
 *       exit(1);
 *   });
 *
 * HTTP status mapping: uses the exception code when it is a valid 4xx/5xx;
 * falls back to 500 for anything else (0, negative, non-HTTP codes).
 *
 */
class ExceptionHandler
{
    // ──────────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Build a Response that represents the exception.
     *
     * @param \Throwable $exception
     * @param string     $format  'html' or 'json'
     * @param bool       $debug   true → include stack trace / class name
     */
    public static function render(
        \Throwable $exception,
        string     $format = 'html',
        bool       $debug  = false
    ): Response {
        $status = static::httpStatus($exception);

        if ($format === 'json') {
            return static::renderJson($exception, $status, $debug);
        }
        return static::renderHtml($exception, $status, $debug);
    }

    /**
     * Write the exception to the application error log via Logger::error().
     * Always logs — not just SQL errors.
     *
     * @param string $logFile  Passed to Logger (default 'pramnosframework').
     */
    public static function log(
        \Throwable $exception,
        string     $logFile = 'pramnosframework'
    ): void {
        Logger::error(
            $exception->getMessage()
            . "\nFile: " . $exception->getFile()
            . ' → ' . $exception->getLine()
            . "\nTrace:\n" . $exception->getTraceAsString(),
            ['exception_class' => get_class($exception)],
            $logFile
        );
    }

    /**
     * Sniff the preferred response format from the Accept header.
     * Returns 'json' only when the client explicitly prefers JSON over HTML.
     * Useful when Document is not available (CLI, early-bootstrap exceptions).
     */
    public static function detectFormat(): string
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $wantsJson = str_contains($accept, 'application/json');
        $wantsHtml = str_contains($accept, 'text/html');

        return ($wantsJson && !$wantsHtml) ? 'json' : 'html';
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Resolve a safe HTTP status from the exception code.
     * Accepts 4xx and 5xx; everything else maps to 500.
     */
    protected static function httpStatus(\Throwable $exception): int
    {
        $code = (int) $exception->getCode();
        return ($code >= 400 && $code < 600) ? $code : 500;
    }

    protected static function renderJson(
        \Throwable $exception,
        int        $status,
        bool       $debug
    ): Response {
        $payload = [
            'error' => $exception->getMessage(),
            'code'  => $status,
        ];

        if ($debug) {
            $payload['exception'] = get_class($exception);
            $payload['file']      = $exception->getFile();
            $payload['line']      = $exception->getLine();
            $payload['trace']     = array_filter(
                explode("\n", $exception->getTraceAsString())
            );
        }

        return Response::json($payload, $status);
    }

    protected static function renderHtml(
        \Throwable $exception,
        int        $status,
        bool       $debug
    ): Response {
        $body = $debug
            ? static::buildDebugHtml($exception, $status)
            : static::buildFriendlyHtml($status);

        return Response::make($body, $status);
    }

    protected static function buildDebugHtml(\Throwable $exception, int $status): string
    {
        $class   = htmlspecialchars(get_class($exception), ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
        $file    = htmlspecialchars($exception->getFile(), ENT_QUOTES, 'UTF-8');
        $line    = (int) $exception->getLine();
        $trace   = htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="UTF-8">
            <title>Exception {$status}</title>
            <style>
            body { font-family: monospace; padding: 2em; background: #fff; color: #111; }
            h1   { color: #c00; }
            pre  { background: #f4f4f4; padding: 1em; overflow: auto; border: 1px solid #ddd; }
            </style>
            </head>
            <body>
            <h1>Unhandled Exception ({$status})</h1>
            <p><strong>Class:</strong> {$class}</p>
            <p><strong>Message:</strong> {$message}</p>
            <p><strong>File:</strong> {$file} &nbsp; <strong>Line:</strong> {$line}</p>
            <pre>{$trace}</pre>
            </body>
            </html>
            HTML;
    }

    protected static function buildFriendlyHtml(int $status): string
    {
        $title = match (true) {
            $status === 400 => 'Bad Request',
            $status === 401 => 'Unauthorized',
            $status === 403 => 'Forbidden',
            $status === 404 => 'Not Found',
            $status === 405 => 'Method Not Allowed',
            $status === 422 => 'Unprocessable Entity',
            $status === 429 => 'Too Many Requests',
            $status === 503 => 'Service Unavailable',
            $status >= 500  => 'Internal Server Error',
            default         => 'Error',
        };

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="UTF-8">
            <title>{$title}</title>
            </head>
            <body>
            <h1>{$status} — {$title}</h1>
            </body>
            </html>
            HTML;
    }
}
