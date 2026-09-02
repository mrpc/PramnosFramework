<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\LoginFlow;
use Pramnos\Auth\NewSignInAlert;

/**
 * Which of the two readings decides that a sign-in needs a second look.
 *
 * `new_device` asks the fingerprint question alone: have we seen this browser on this account
 * before. `suspicious` asks a narrower one, and accepts only signals that are hard to explain
 * innocently — a country the account has never used, two places at once, a country change too soon
 * to have travelled, a success straight after a run of failures.
 *
 * Four statements, never executed, and the branch is a configuration switch: the two readings have
 * very different false-positive rates, and an installation choosing `suspicious` is choosing not to
 * challenge somebody who bought a new laptop. Reading the wrong one would either challenge nobody
 * or challenge everybody who travels.
 */
#[CoversClass(LoginFlow::class)]
class QualifiesForDemandTest extends TestCase
{
    private mixed $savedTrigger = null;

    private mixed $savedDatabase = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        \Pramnos\Application\Settings::loadSettings(
            ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php'
        );

        $this->savedTrigger = \Pramnos\Application\Settings::getSetting(NewSignInAlert::TRIGGER_SETTING);

        // Both readings query, so the lookup needs somewhere to look — and the singleton is put
        // back in tearDown, because leaving it swapped hands every later test this connection.
        $saved = &\Pramnos\Database\Database::getInstance();
        $this->savedDatabase = $saved;
        $saved = null;

        $database = \Pramnos\Framework\Factory::getDatabase();

        try {
            if (!$database->connected) {
                $database->connect();
            }
        } catch (\Throwable $exception) {
            $this->markTestSkipped('The database is not reachable.');
        }

        if (!$database->connected) {
            $this->markTestSkipped('The database is not reachable.');
        }

        \Pramnos\Application\Application::getInstance()->database = $database;
    }

    protected function tearDown(): void
    {
        if ($this->savedTrigger === null || $this->savedTrigger === '') {
            \Pramnos\Application\Settings::deleteSetting(NewSignInAlert::TRIGGER_SETTING);
        } else {
            \Pramnos\Application\Settings::setSetting(
                NewSignInAlert::TRIGGER_SETTING,
                $this->savedTrigger,
                false
            );
        }

        $restore = &\Pramnos\Database\Database::getInstance();
        $restore = $this->savedDatabase;

        parent::tearDown();
    }

    /** Exposes the seam the login flow consults. */
    private function flow(): object
    {
        return new class extends LoginFlow {
            public function __construct() {}

            public function exposeQualifiesForDemand(int $userId): bool
            {
                return $this->qualifiesForDemand($userId);
            }
        };
    }

    /**
     * With `suspicious`, the risk reading answers — and an account with no history is not
     * suspicious.
     *
     * The point of choosing this trigger: it does not challenge somebody for using a new browser.
     * An account the log has never seen has nothing hard to explain about it, so the honest answer
     * is `false`.
     */
    public function testWithSuspiciousAnAccountWithNoHistoryIsNotChallenged(): void
    {
        // Arrange
        \Pramnos\Application\Settings::setSetting(NewSignInAlert::TRIGGER_SETTING, 'suspicious', false);

        // Act
        $qualifies = $this->flow()->exposeQualifiesForDemand(987654);

        // Assert
        $this->assertFalse(
            $qualifies,
            'an account with no activity at all was treated as suspicious'
        );
    }

    /**
     * With `new_device`, an account with no history is **not** challenged either.
     *
     * Which I expected to go the other way, and it is the better answer: `isNew()` returns `false`
     * when the account has no known fingerprints at all, because the *first* sign-in on an account
     * is not a new-device event. There is nothing to compare against, and alerting somebody about
     * their own first login is noise that teaches them to ignore the next one.
     *
     * So the two readings agree on a fresh account and differ on an established one — which is the
     * shape that makes the setting worth having rather than a switch between "always" and "never".
     */
    public function testWithNewDeviceAnAccountWithNoHistoryIsNotChallenged(): void
    {
        // Arrange
        \Pramnos\Application\Settings::setSetting(NewSignInAlert::TRIGGER_SETTING, 'new_device', false);

        // Act
        $qualifies = $this->flow()->exposeQualifiesForDemand(987654);

        // Assert
        $this->assertFalse(
            $qualifies,
            'a first sign-in was reported as a new device, which alerts somebody about themselves'
        );
    }

    /**
     * An unrecognised trigger falls back to `new_device`.
     *
     * `NewSignInAlert::trigger()` whitelists the two it knows, so a mistyped `suspicous` becomes
     * `new_device` rather than something undefined. Asserted as "it answers, and does not raise" —
     * on a fresh account both readings agree, so the verdict cannot distinguish them here; what it
     * can show is that an unknown value does not fall through to an unhandled branch.
     */
    public function testAnUnrecognisedTriggerStillAnswers(): void
    {
        // Arrange
        \Pramnos\Application\Settings::setSetting(NewSignInAlert::TRIGGER_SETTING, 'suspicous', false);

        // Act
        $qualifies = $this->flow()->exposeQualifiesForDemand(987654);

        // Assert
        $this->assertIsBool($qualifies);
        $this->assertSame(
            'new_device',
            NewSignInAlert::trigger(),
            'an unrecognised trigger should read as new_device rather than as itself'
        );
    }
}
