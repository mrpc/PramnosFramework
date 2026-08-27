<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Loginlockout;

/**
 * Characterization tests for Pramnos\Auth\Loginlockout.
 *
 * These tests document the observable, database-independent contract of the
 * Loginlockout class (window arithmetic and progressive-duration logic) before
 * the schema migration from Unix-integer timestamps to TIMESTAMPTZ.
 *
 * Database-dependent behaviour (insert/read/update round-trips) is covered by
 * the integration suites:
 *   tests/Integration/Database/LoginlockoutMySQLTest.php
 *   tests/Integration/Database/LoginlockoutPostgreSQLTest.php
 *
 * These characterization tests must remain green both before and after the
 * timestamp storage migration, because the public API contract does not change.
 */
class LoginlockoutCharacterizationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // calculateDuration (protected — tested via reflection)
    // -------------------------------------------------------------------------

    /**
     * calculateDuration returns 0 when fewer than 3 attempts have been made.
     *
     * The first two failures must not trigger any lockout; the counter
     * must be allowed to grow before any penalty is applied.
     */
    public function testCalculateDurationReturnsZeroBeforeFirstThreshold(): void
    {
        // Arrange
        $lockout = new Loginlockout();
        $method  = new \ReflectionMethod($lockout, 'calculateDuration');


        // Act + Assert
        $this->assertSame(0, $method->invoke($lockout, 1),
            'Single failed attempt must not lock the account');
        $this->assertSame(0, $method->invoke($lockout, 2),
            'Two failed attempts must not lock the account');
    }

    /**
     * calculateDuration returns 60 s on reaching the 3-attempt threshold.
     *
     * This is the first lockout tier: a 60-second cooldown after 3 failures,
     * giving legitimate users a short pause before retrying.
     */
    public function testCalculateDurationReturns60sAtThreeAttempts(): void
    {
        // Arrange
        $lockout = new Loginlockout();
        $method  = new \ReflectionMethod($lockout, 'calculateDuration');


        // Act
        $duration = $method->invoke($lockout, 3);

        // Assert
        $this->assertSame(60, $duration,
            '3 failed attempts must trigger the first lockout tier (60 s)');
    }

    /**
     * calculateDuration returns 300 s at 5 attempts, 900 s at 7, 3600 s at 10.
     *
     * Progressive lockout escalates with each threshold tier. Accounts that
     * trigger repeated failures face exponentially longer penalties.
     */
    public function testCalculateDurationProgressiveEscalation(): void
    {
        // Arrange
        $lockout = new Loginlockout();
        $method  = new \ReflectionMethod($lockout, 'calculateDuration');


        // Act + Assert — each tier activates once its minimum count is reached
        $this->assertSame(300,  $method->invoke($lockout, 5),  '5 attempts → 300 s');
        $this->assertSame(900,  $method->invoke($lockout, 7),  '7 attempts → 900 s');
        $this->assertSame(3600, $method->invoke($lockout, 10), '10 attempts → 3600 s');
        // Counts beyond the last threshold keep the maximum duration
        $this->assertSame(3600, $method->invoke($lockout, 20), '20 attempts → still 3600 s');
    }

    /**
     * calculateDuration picks the highest-matching threshold, not the first.
     *
     * With 8 attempts the thresholds at 3, 5, and 7 all match; the result
     * must be the duration from the 7-attempt threshold (900 s), not 60 s.
     */
    public function testCalculateDurationPicksHighestMatchingThreshold(): void
    {
        // Arrange
        $lockout = new Loginlockout();
        $method  = new \ReflectionMethod($lockout, 'calculateDuration');


        // Act
        $duration = $method->invoke($lockout, 8);

        // Assert
        $this->assertSame(900, $duration,
            '8 attempts must match the 7-attempt threshold (900 s), not an earlier tier');
    }

    // -------------------------------------------------------------------------
    // DEFAULT_WINDOW_SECONDS / DEFAULT_STEPS constants
    // -------------------------------------------------------------------------

    /**
     * DEFAULT_WINDOW_SECONDS is 900 s (15 minutes).
     *
     * The sliding window determines how long failures accumulate before the
     * counter resets. Changing this constant would silently alter lockout behaviour
     * for all callers; it must remain stable.
     */
    public function testDefaultWindowSecondsIs900(): void
    {
        // Assert
        $this->assertSame(900, Loginlockout::DEFAULT_WINDOW_SECONDS,
            'Default sliding window must be 900 s (15 minutes)');
    }

    /**
     * DEFAULT_STEPS defines exactly four progressive tiers.
     *
     * The specific thresholds (3, 5, 7, 10) and durations (60, 300, 900, 3600)
     * are documented here so that any future change is intentional, not accidental.
     */
    public function testDefaultStepsHasFourTiers(): void
    {
        // Assert
        $expected = [
            3  => 60,
            5  => 300,
            7  => 900,
            10 => 3600,
        ];

        $this->assertSame($expected, Loginlockout::DEFAULT_STEPS,
            'DEFAULT_STEPS must define the four documented lockout tiers');
    }

    // -------------------------------------------------------------------------
    // The configured ladder, which used to be read nowhere
    // -------------------------------------------------------------------------

    /**
     * A configured ladder replaces the defaults.
     *
     * `calculateDuration()` read `self::DEFAULT_STEPS` and nothing else, so the
     * settings screen's whole progressive-lockout section configured nothing: the
     * editor, its validation and its "adjusted to safe defaults" warning all
     * operated on a value no lockout ever consulted. An operator tightened the
     * ladder, the page confirmed the save, and every account kept locking on the
     * shipped 3/5/7/10.
     */
    public function testAConfiguredLadderIsWhatIsApplied(): void
    {
        // Arrange — one failure locks, for two minutes
        \Pramnos\Application\Settings::setSetting('loginlockoutsteps', '{"1":120}');

        try {
            $lockout = new Loginlockout();
            $method  = new \ReflectionMethod($lockout, 'calculateDuration');

            // Act & Assert
            $this->assertSame(120, $method->invoke($lockout, 1),
                'the configured ladder must be the one applied');
            // …and the shipped thresholds no longer apply on their own
            $this->assertSame(120, $method->invoke($lockout, 3));
        } finally {
            \Pramnos\Application\Settings::setSetting('loginlockoutsteps', '');
        }
    }

    /**
     * An unusable setting falls back to the defaults, never to no lockout.
     *
     * A malformed value must not be a way to switch brute-force protection off —
     * which is what returning an empty ladder would be.
     */
    public function testAnUnusableLadderFallsBackToTheDefaults(): void
    {
        foreach (['not json at all', '{}', '{"0":0}', '[]'] as $stored) {
            // Arrange
            \Pramnos\Application\Settings::setSetting('loginlockoutsteps', $stored);

            try {
                $lockout = new Loginlockout();
                $method  = new \ReflectionMethod($lockout, 'calculateDuration');

                // Act & Assert
                $this->assertSame(60, $method->invoke($lockout, 3),
                    "stored as [$stored], the defaults must still apply");
            } finally {
                \Pramnos\Application\Settings::setSetting('loginlockoutsteps', '');
            }
        }
    }

    /**
     * The sliding window is configurable, and cannot be set to nothing.
     *
     * The window decides when a failure count starts over, and it was read
     * nowhere. Clamped to the range the settings screen validates, so a value
     * stored before that validation existed cannot produce a window of zero —
     * which would reset the count on every attempt and remove the lockout.
     */
    public function testTheWindowIsConfigurableWithinSafeBounds(): void
    {
        $lockout = new Loginlockout();
        $method  = new \ReflectionMethod($lockout, 'windowSeconds');

        foreach (['3600' => 3600, '0' => 900, '30' => 900, '999999' => 900, '' => 900] as $stored => $expected) {
            // Arrange
            \Pramnos\Application\Settings::setSetting('loginlockoutwindowseconds', (string) $stored);

            try {
                // Act & Assert
                $this->assertSame($expected, $method->invoke($lockout),
                    "stored as [$stored]");
            } finally {
                \Pramnos\Application\Settings::setSetting('loginlockoutwindowseconds', '');
            }
        }
    }
}
