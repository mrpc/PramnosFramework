<?php

declare(strict_types=1);

namespace Pramnos\Debug\Collectors;

/**
 * Collects exceptions and PHP errors that occur during the request.
 *
 * Integration points:
 *   - Application::exec() catch blocks (routing / controller errors)
 *   - View::getTpl() catch block (template render errors)
 *   - set_error_handler installed by DebugBarServiceProvider (PHP errors)
 *   - set_exception_handler installed by DebugBarServiceProvider (unhandled)
 *
 * RedirectException is deliberately excluded — it is a control-flow
 * mechanism, not a genuine error condition.
 *
 * @package PramnosFramework
 */
class ExceptionsCollector implements CollectorInterface
{
    /** @var list<array{type: string, class: string, message: string, file: string, line: int}> */
    private array $items = [];

    public function name(): string
    {
        return 'exceptions';
    }

    public function record(\Throwable $e): void
    {
        $this->items[] = [
            'type'    => 'exception',
            'class'   => get_class($e),
            'message' => $e->getMessage(),
            'file'    => $this->shortPath($e->getFile()),
            'line'    => $e->getLine(),
        ];
    }

    public function recordPhpError(int $errno, string $errstr, string $errfile, int $errline): void
    {
        static $levelMap = [
            E_ERROR   => 'E_ERROR',   E_WARNING   => 'E_WARNING',
            E_NOTICE  => 'E_NOTICE',  E_DEPRECATED => 'E_DEPRECATED',
            E_USER_ERROR => 'E_USER_ERROR', E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
        ];
        $this->items[] = [
            'type'    => 'php_error',
            'class'   => $levelMap[$errno] ?? "E_{$errno}",
            'message' => $errstr,
            'file'    => $this->shortPath($errfile),
            'line'    => $errline,
        ];
    }

    public function collect(): array
    {
        return [
            'count' => count($this->items),
            'items' => $this->items,
        ];
    }

    private function shortPath(string $path): string
    {
        $root = defined('ROOT') ? ROOT : '';
        return $root !== '' ? str_replace($root, '', $path) : basename($path);
    }
}
