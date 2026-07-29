<?php

namespace Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\SignalStop;

/**
 * Unit tests for the cooperative graceful-stop primitive.
 *
 * The flag/callback logic is tested directly; a separate test installs the real
 * handlers and raises SIGTERM at this process (harmlessly — the handler only sets
 * the flag), guarded on pcntl/posix availability.
 */
class SignalStopTest extends TestCase
{
    /**
     * A fresh SignalStop has not been asked to stop.
     */
    public function testNotRequestedByDefault(): void
    {
        $this->assertFalse((new SignalStop())->requested());
    }

    /**
     * stop() raises the flag; reset() clears it (for loop reuse / tests).
     */
    public function testStopAndReset(): void
    {
        $stop = new SignalStop();

        $stop->stop();
        $this->assertTrue($stop->requested());

        $stop->reset();
        $this->assertFalse($stop->requested());
    }

    /**
     * The onStop callback runs exactly once, on the first stop only, with the
     * signal number that triggered it.
     */
    public function testOnStopCallbackFiresOnceWithSignal(): void
    {
        $calls = [];
        $stop = new SignalStop([], function (?int $signal) use (&$calls): void {
            $calls[] = $signal;
        });

        $stop->stop(15);
        $stop->stop(2); // second stop must not fire the callback again

        $this->assertSame([15], $calls);
    }

    /**
     * install() traps SIGTERM: raising it at our own process sets the flag rather
     * than terminating (the handler is cooperative).
     */
    public function testInstalledHandlerCatchesSignal(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix not available');
        }

        $stop = new SignalStop();
        $stop->install();
        $this->assertFalse($stop->requested());

        posix_kill(getmypid(), SIGTERM);
        pcntl_signal_dispatch();

        $this->assertTrue($stop->requested(), 'a trapped SIGTERM must request a stop, not kill the process');
    }
}
