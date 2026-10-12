<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Response;

/**
 * `send()` does not set a status that a `header('HTTP/…')` line already set.
 *
 * On PHP 8.5, `http_response_code()` after `header('HTTP/1.1 404 Not Found')` has no effect and
 * logs a warning. `send()` set the status unconditionally, so every response whose status a
 * legacy controller had written as a status line logged one.
 */
class ResponseSendKeepsAnEarlierStatusLineTest extends TestCase
{
    /**
     * The same status, already on a status line, is left alone and nothing is logged.
     */
    #[RunInSeparateProcess]
    public function testAStatusAlreadySetByAStatusLineRaisesNoWarning(): void
    {
        // Arrange — the status as a legacy controller writes it
        header('HTTP/1.1 404 Not Found');
        $warnings = [];
        set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        // Act
        ob_start();
        Response::make('gone', 404)->send();
        ob_end_clean();
        restore_error_handler();

        // Assert
        $this->assertSame([], $warnings);
        $this->assertSame(404, http_response_code());
    }
}
