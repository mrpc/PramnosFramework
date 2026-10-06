<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Framework\Testing;

use PHPUnit\Event\EventFacadeIsSealedException;
use PHPUnit\Event\Telemetry\Duration;
use PHPUnit\Event\Telemetry\GarbageCollectorStatus;
use PHPUnit\Event\Telemetry\HRTime;
use PHPUnit\Event\Telemetry\Info;
use PHPUnit\Event\Telemetry\MemoryUsage;
use PHPUnit\Event\Telemetry\Snapshot;
use PHPUnit\Event\TestSuite\Started;
use PHPUnit\Event\TestSuite\TestSuiteForTestClass;
use PHPUnit\Event\TestSuite\TestSuiteWithName;
use PHPUnit\Event\Code\TestCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use Pramnos\Application\NavItem;
use Pramnos\Application\NavRegistry;
use Pramnos\Application\NavSection;
use Pramnos\Framework\Testing\ProcessStateIsolation;

/**
 * `ProcessStateIsolation` puts the process back as it was recorded, before every test class.
 *
 * What a class adds must be gone for the next one, and what the bootstrap set up must still be
 * there — both halves, because an extension that only emptied things would break every project
 * whose bootstrap registers a driver or initialises the application. Each test records the
 * state as it finds it and leaves it restored, so it does not become the leak it tests for.
 */
#[CoversClass(ProcessStateIsolation::class)]
class ProcessStateIsolationTest extends TestCase
{
    /**
     * A real `TestSuite\Started` event, for a test class's suite or for another kind.
     *
     * PHPUnit's event classes are final, so the real thing is built rather than stubbed.
     */
    private function started(bool $forTestClass = true): Started
    {
        $noMemory = MemoryUsage::fromBytes(0);
        $noTime   = Duration::fromSecondsAndNanoseconds(0, 0);
        $snapshot = new Snapshot(
            HRTime::fromSecondsAndNanoseconds(0, 0),
            $noMemory,
            $noMemory,
            new GarbageCollectorStatus(0, 0, 0, 0, null, null, null, null, null, null, null, null)
        );
        $suite = $forTestClass
            ? new TestSuiteForTestClass(self::class, 0, TestCollection::fromArray([]), __FILE__, 1)
            : new TestSuiteWithName('a directory', 0, TestCollection::fromArray([]));

        return new Started(new Info($snapshot, $noTime, $noMemory, $noTime, $noMemory), $suite);
    }

    /**
     * A registration made after the record is gone; one made before it stays.
     *
     * The second half is what makes this safe for a project: a bootstrap that registers a menu
     * item, a feature or a driver must find it in every class, not only the first.
     */
    public function testWhatAClassAddedIsGoneAndWhatTheBootstrapSetUpStays(): void
    {
        // Arrange — one item "from the bootstrap", recorded; one "from a class", after
        $saved = NavRegistry::all();
        NavRegistry::reset();
        NavRegistry::register(new NavItem('boot.item', 'Boot', '/boot', NavSection::Main, 1));
        $isolation = new ProcessStateIsolation();
        $isolation->record();
        NavRegistry::register(new NavItem('class.item', 'Class', '/class', NavSection::Main, 2));

        try {
            // Act
            $isolation->notify($this->started());

            // Assert
            $ids = array_map(static fn ($item) => $item->id, NavRegistry::all());
            $this->assertContains('boot.item', $ids, 'what the bootstrap registered was lost');
            $this->assertNotContains('class.item', $ids, 'what a class registered survived it');
        } finally {
            NavRegistry::reset();
            foreach ($saved as $item) {
                NavRegistry::register($item);
            }
        }
    }

    /**
     * The superglobals a class filled are back to the recorded ones.
     *
     * A `$_GET['_option']` left by one class was the route argument another class read, and a
     * `visitorid` cookie made a session tracking test record somebody else.
     */
    public function testTheSuperglobalsAreRestored(): void
    {
        // Arrange
        $saved = [$_SESSION ?? [], $_GET, $_POST, $_REQUEST, $_COOKIE];
        $_SESSION = ['kept' => 1];
        $_GET     = [];
        $_COOKIE  = [];
        $isolation = new ProcessStateIsolation();
        $isolation->record();
        $_SESSION['uid']     = 4501;
        $_GET['_option']     = '6';
        $_POST['x']          = 'y';
        $_REQUEST['x']       = 'y';
        $_COOKIE['visitorid'] = 'zz';

        try {
            // Act
            $isolation->notify($this->started());

            // Assert
            $this->assertSame(['kept' => 1], $_SESSION);
            $this->assertSame([], $_GET);
            $this->assertArrayNotHasKey('x', $_POST);
            $this->assertArrayNotHasKey('x', $_REQUEST);
            $this->assertSame([], $_COOKIE);
        } finally {
            [$_SESSION, $_GET, $_POST, $_REQUEST, $_COOKIE] = $saved;
        }
    }

    /**
     * An environment variable a class set or changed with putenv() is put back.
     *
     * `APP_DEBUG=1` left by one class made every class after it a development environment, and
     * a test asserting that a settings row cannot open the developer panel failed.
     */
    public function testTheEnvironmentIsRestored(): void
    {
        // Arrange — one variable recorded, one added after
        putenv('PRAMNOS_ISOLATION_KEPT=before');
        putenv('PRAMNOS_ISOLATION_ADDED');
        $isolation = new ProcessStateIsolation();
        $isolation->record();
        putenv('PRAMNOS_ISOLATION_KEPT=changed');
        putenv('PRAMNOS_ISOLATION_ADDED=1');

        try {
            // Act
            $isolation->notify($this->started());

            // Assert
            $this->assertSame('before', getenv('PRAMNOS_ISOLATION_KEPT'));
            $this->assertFalse(getenv('PRAMNOS_ISOLATION_ADDED'), 'a variable a class added survived it');
        } finally {
            putenv('PRAMNOS_ISOLATION_KEPT');
            putenv('PRAMNOS_ISOLATION_ADDED');
        }
    }

    /**
     * A callback a class adds to the recorded `Auth` does not reach the next class.
     *
     * The record holds a copy, and each restore hands out a fresh copy of it: one test's
     * `afterLogin()` closure once asserted inside another class's sign-in.
     */
    public function testTheAuthSingletonIsACopyOfTheRecordedOne(): void
    {
        // Arrange
        $auth     = &\Pramnos\Auth\Auth::getInstance();
        $previous = $auth;
        $auth     = new \Pramnos\Auth\Auth();
        $isolation = new ProcessStateIsolation();
        $isolation->record();
        \Pramnos\Auth\Auth::getInstance()->afterLogin(static function (): void {
        });

        try {
            // Act
            $isolation->notify($this->started());

            // Assert — a different object, without the callback
            $restored  = \Pramnos\Auth\Auth::getInstance();
            $callbacks = (new \ReflectionProperty($restored, 'afterLoginCallbacks'))->getValue($restored);
            $this->assertSame([], $callbacks, 'the callback a class added reached the next one');
        } finally {
            $auth = &\Pramnos\Auth\Auth::getInstance();
            $auth = $previous;
        }
    }

    /**
     * A connection a class made the default is replaced by the recorded one.
     *
     * A PostgreSQL class left its connection as the default, and the MySQL class after it sent
     * backticks there.
     */
    public function testTheDefaultConnectionIsTheRecordedOne(): void
    {
        // Arrange
        $connection = &\Pramnos\Database\Database::getInstance();
        $previous   = $connection;
        $isolation  = new ProcessStateIsolation();
        $isolation->record();
        $connection = new \Pramnos\Database\Database();
        $connection->type = 'postgresql';

        try {
            // Act
            $isolation->notify($this->started());

            // Assert
            $this->assertSame($previous, \Pramnos\Database\Database::getInstance());
        } finally {
            $connection = &\Pramnos\Database\Database::getInstance();
            $connection = $previous;
        }
    }

    /**
     * Only a test class's suite triggers a restore — not a directory or a whole run.
     *
     * Restoring at the start of the run's own suite would be harmless; restoring at every
     * data-provider suite inside a class would take a class's shared state away from its tests.
     */
    public function testOnlyATestClassSuiteTriggersARestore(): void
    {
        // Arrange
        $saved = $_GET;
        $_GET  = [];
        $isolation = new ProcessStateIsolation();
        $isolation->record();
        $_GET['kept'] = '1';

        try {
            // Act
            $isolation->notify($this->started(false));

            // Assert
            $this->assertSame('1', $_GET['kept'] ?? null);
        } finally {
            $_GET = $saved;
        }
    }

    /**
     * `bootstrap()` records the state and then subscribes itself.
     *
     * Inside a running suite PHPUnit has sealed its event facade, so the attempt to subscribe
     * throws — which is the proof that it reached the registration. The record is taken first,
     * so a restore afterwards puts back what was there at that moment.
     */
    public function testBootstrapRecordsAndThenSubscribes(): void
    {
        // Arrange
        $saved = $_GET;
        $_GET  = ['at' => 'bootstrap'];
        $isolation = new ProcessStateIsolation();
        $sealed    = false;

        // Act
        try {
            $isolation->bootstrap(
                (new \PHPUnit\TextUI\Configuration\Builder())->build([]),
                new Facade(),
                ParameterCollection::fromArray([])
            );
        } catch (EventFacadeIsSealedException) {
            $sealed = true;
        }
        $_GET = ['later' => 'class'];
        $isolation->notify($this->started());

        // Assert
        try {
            $this->assertTrue($sealed, 'bootstrap() did not try to register a subscriber');
            $this->assertSame(['at' => 'bootstrap'], $_GET);
        } finally {
            $_GET = $saved;
        }
    }
}
