<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;

/**
 * Covers the advice `init` prints when a Docker step fails.
 *
 * A failed pull or build prints dozens of lines of daemon output whose actual
 * cause is one line somewhere in the middle — and every cause recognised here
 * has a fix that has nothing to do with the project being scaffolded. Without
 * this, the reader is left with "it broke" and a wall of text.
 */
class InitDockerFailureHintsTest extends TestCase
{
    /**
     * The credential-helper failure, verbatim from a WSL machine.
     *
     * This is the common one: docker-credential-desktop.exe lives on the
     * Windows side and stops answering after a sleep/resume, so **every** pull
     * fails — including public images that need no credentials at all.
     */
    public function testCredentialFailureExplainsTheRealCause(): void
    {
        // Arrange — the output the user actually saw
        $log = <<<'LOG'
   Image redis:latest Pulling
  <3>WSL (422273 - ) ERROR: UtilAcceptVsock:273: accept4 failed 110
  error getting credentials - err: exit status 1, out: ``
LOG;

        // Act
        $hints = implode("\n", Init::dockerFailureHints($log));

        // Assert — names the cause, says it is not the project, gives the fix
        $this->assertStringContainsString('could not read its stored credentials', $hints);
        $this->assertStringContainsString('not a problem with this project', $hints);
        $this->assertStringContainsString('~/.docker/config.json', $hints);
        $this->assertStringContainsString('docker-compose up -d --build', $hints, 'and how to resume');

        // ...and the WSL-specific note only when the log shows it
        $this->assertStringContainsString('UtilAcceptVsock', $hints);
    }

    /**
     * The same failure on a non-WSL machine gets the same fix without the WSL
     * paragraph, which would be noise there.
     */
    public function testCredentialFailureWithoutWslOmitsTheWslNote(): void
    {
        // Arrange
        $log = 'error getting credentials - err: exit status 1, out: ``';

        // Act
        $hints = implode("\n", Init::dockerFailureHints($log));

        // Assert
        $this->assertStringContainsString('~/.docker/config.json', $hints);
        $this->assertStringNotContainsString('UtilAcceptVsock', $hints);
    }

    /**
     * A stopped daemon is a different problem with a different fix.
     */
    public function testDaemonNotRunningIsRecognised(): void
    {
        // Arrange
        $log = 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock. '
             . 'Is the docker daemon running?';

        // Act
        $hints = implode("\n", Init::dockerFailureHints($log));

        // Assert
        $this->assertStringContainsString('daemon is not reachable', $hints);
        $this->assertStringNotContainsString('credentials', $hints, 'do not offer an unrelated fix');
    }

    /**
     * A port taken between the availability check and the container starting is
     * a race init cannot prevent — only explain, including the second port most
     * people do not know about.
     */
    public function testPortConflictMentionsBothPublishedPorts(): void
    {
        // Arrange
        $log = 'Bind for 0.0.0.0:8081 failed: port is already allocated';

        // Act
        $hints = implode("\n", Init::dockerFailureHints($log));

        // Assert
        $this->assertStringContainsString('--docker-port', $hints);
        $this->assertStringContainsString('database tool', $hints, 'the port above is easy to miss');
    }

    /**
     * A full disk surfaces as a build failure with an unhelpful exit code.
     */
    public function testOutOfSpaceIsRecognised(): void
    {
        // Arrange
        $log = 'failed to solve: no space left on device';

        // Act
        $hints = implode("\n", Init::dockerFailureHints($log));

        // Assert
        $this->assertStringContainsString('out of disk space', $hints);
        $this->assertStringContainsString('docker system prune', $hints);
    }

    /**
     * An unrecognised failure gets no advice at all.
     *
     * A guess dressed as advice sends the reader down the wrong path, which is
     * worse than the raw output they already have.
     */
    public function testUnknownFailureGetsNoAdvice(): void
    {
        // Arrange
        $log = 'something entirely unexpected happened';

        // Act + Assert
        $this->assertSame([], Init::dockerFailureHints($log));
    }
}
