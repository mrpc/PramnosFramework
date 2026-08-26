<?php

declare(strict_types=1);

namespace Tests\Unit\Testing;

use PDOException;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestEnvironment;

/**
 * Exposes the protected retry so it can be driven without a live server.
 */
class RetryProbe extends TestEnvironment
{
    public static function retry(callable $create, int $attempts = 10): void
    {
        self::retryWhileTemplateBusy($create, $attempts, 0);
    }

    public static function busy(PDOException $e): bool
    {
        return self::isTemplateBusy($e);
    }
}

/**
 * The PostgreSQL test database is copied from template1, and PostgreSQL refuses
 * to copy a template that has any session attached. On TimescaleDB one always
 * does — the extension runs a background-worker scheduler per database — so the
 * copy failed with SQLSTATE 55006 at random and the suite could not bootstrap.
 */
class TemplateBusyRetryTest extends TestCase
{
    /**
     * Build the exception PDO raises when the template is in use.
     */
    private function busyError(): PDOException
    {
        $e = new PDOException(
            'SQLSTATE[55006]: Object in use: 7 ERROR:  source database '
            . '"template1" is being accessed by other users'
        );
        $e->errorInfo = ['55006', 7, 'source database "template1" is being accessed'];

        return $e;
    }

    /**
     * A busy template is retried until the copy gets through.
     *
     * Terminating the template's sessions cannot be enough on its own: the
     * scheduler reconnects on its own schedule and can be back before the next
     * statement runs. Only retrying the terminate-and-copy pair converges.
     */
    public function testABusyTemplateIsRetriedUntilTheCopySucceeds(): void
    {
        // Arrange
        $calls = 0;
        $create = function () use (&$calls) {
            $calls++;
            if ($calls < 3) {
                throw $this->busyError();
            }
        };

        // Act
        RetryProbe::retry($create);

        // Assert
        $this->assertSame(3, $calls);
    }

    /**
     * A template that never frees up still fails, rather than looping forever.
     *
     * A wedged bootstrap that hangs is worse than one that reports the error.
     */
    public function testAPermanentlyBusyTemplateGivesUp(): void
    {
        // Arrange
        $calls = 0;
        $create = function () use (&$calls) {
            $calls++;
            throw $this->busyError();
        };

        // Assert
        $this->expectException(PDOException::class);

        // Act
        try {
            RetryProbe::retry($create, 4);
        } finally {
            $this->assertSame(4, $calls);
        }
    }

    /**
     * Any other database error is rethrown on the first attempt.
     *
     * Retrying a bad password or a missing role would only delay the report and
     * hide which attempt actually failed.
     */
    public function testAnUnrelatedErrorIsNotRetried(): void
    {
        // Arrange
        $calls = 0;
        $create = function () use (&$calls) {
            $calls++;
            throw new PDOException('SQLSTATE[28P01]: Invalid password');
        };

        // Act
        try {
            RetryProbe::retry($create);
            $this->fail('Expected the authentication error to surface');
        } catch (PDOException $e) {
            // Assert
            $this->assertStringContainsString('28P01', $e->getMessage());
            $this->assertSame(1, $calls);
        }
    }

    /**
     * The error is recognised from either place PDO reports SQLSTATE.
     *
     * exec() populates both the exception code and errorInfo[0]; depending on how
     * the exception reaches us, only one may carry the state.
     */
    public function testTheBusyTemplateErrorIsRecognisedFromCodeOrErrorInfo(): void
    {
        // Arrange
        $viaErrorInfo = $this->busyError();

        // Act & Assert
        $this->assertTrue(RetryProbe::busy($viaErrorInfo));
        $this->assertFalse(RetryProbe::busy(new PDOException('SQLSTATE[42P04]: duplicate')));
    }
}
