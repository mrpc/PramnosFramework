<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Logs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\ExceptionHandler;
use Pramnos\Logs\Logger;
use Pramnos\Logs\Trace;

/**
 * A recorded trace names the calls and none of their arguments.
 *
 * `getTraceAsString()` writes the first fifteen characters of every string argument. Symfony
 * Mailer authenticates with `executeCommand(base64(password))`, so a rejected SMTP password
 * reached the log — and a query's bound values, a token being verified, anything a call carried
 * went the same way. These tests throw from a call that carries a canary and look for it.
 */
#[CoversClass(Trace::class)]
class TraceTest extends TestCase
{
    /** A call that carries a secret and fails inside, as an SMTP AUTH command does. */
    private static function authenticate(string $secret): void
    {
        throw new \RuntimeException('535 Authentication failed');
    }

    /** The exception thrown from inside a call that carried the canary. */
    private static function failure(): \RuntimeException
    {
        try {
            self::authenticate('Q2FuYXJ5UGFzc3dvcmQ=');
        } catch (\RuntimeException $exception) {
            return $exception;
        }
        throw new \LogicException('unreachable');
    }

    /**
     * The trace keeps the frames — file, line, class and method — and drops the arguments.
     */
    public function testTheTraceNamesTheCallsAndNotTheirArguments(): void
    {
        // Arrange
        $exception = self::failure();

        // Act
        $trace = Trace::of($exception);

        // Assert
        $this->assertStringContainsString(self::class . '::authenticate()', $trace, 'the call is not named');
        $this->assertStringContainsString(__FILE__ . '(', $trace, 'the file and line are not named');
        $this->assertStringNotContainsString('Q2FuYXJ5', $trace, 'an argument reached the trace');
        $this->assertStringEndsWith('{main}', $trace);
    }

    /**
     * What the framework logs for an exception carries no argument either.
     *
     * The proof that the call sites use it: `ExceptionHandler::log()` is the path an uncaught
     * error takes into the log.
     */
    public function testTheLoggedExceptionCarriesNoArgument(): void
    {
        // Arrange
        $stream = fopen('php://memory', 'r+');
        Logger::setOutputMode(Logger::OUTPUT_STREAM);
        Logger::setStreamTarget($stream);

        try {
            // Act
            ExceptionHandler::log(self::failure());
            rewind($stream);
            $log = (string) stream_get_contents($stream);
        } finally {
            Logger::setStreamTarget(null);
            Logger::setOutputMode(Logger::OUTPUT_FILE);
        }

        // Assert
        $this->assertStringContainsString('535 Authentication failed', $log);
        $this->assertStringContainsString('authenticate()', $log);
        $this->assertStringNotContainsString('Q2FuYXJ5', $log, 'a logged trace carried an argument');
    }
}
