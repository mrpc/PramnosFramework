<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\DevPanel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\DevPanel\DevPanelController;

/**
 * Opening the DevPanel on a deployment that is not a development one.
 *
 * The environment used to be the only key: `APP_DEBUG` or the `DEVELOPMENT`
 * constant, and nothing else. That lock is right about what it protects — the
 * panel browses the database, reads the cache and dumps the container — and wrong
 * as the *only* key, because `APP_DEBUG` also turns on error display, changes what
 * a page carries, and opens the toolbar for every visitor. Turning all of that on
 * to reach one screen is a much larger statement than anybody means to make.
 *
 * So the second key is a name in `app.php`: versioned, reviewed, visible in the
 * deployment rather than in a table. The same arrangement `Adminer` already has —
 * in the more dangerous of the two tools.
 */
#[CoversClass(DevPanelController::class)]
class DevPanelProductionAccessTest extends TestCase
{
    /** @var array<string, mixed>|null The devpanel block as the application had it */
    private $savedConfig = null;

    private ?string $savedAppDebug = null;

    protected function setUp(): void
    {
        $app = Application::getInstance();
        $this->savedConfig = $app->applicationInfo['devpanel'] ?? null;

        // Not a development environment, which is the whole point of this class.
        $this->savedAppDebug = getenv('APP_DEBUG') === false ? null : (string) getenv('APP_DEBUG');
        putenv('APP_DEBUG=0');
        $_ENV['APP_DEBUG'] = '0';
    }

    protected function tearDown(): void
    {
        $app = Application::getInstance();

        if ($this->savedConfig === null) {
            unset($app->applicationInfo['devpanel']);
        } else {
            $app->applicationInfo['devpanel'] = $this->savedConfig;
        }

        if ($this->savedAppDebug === null) {
            putenv('APP_DEBUG');
            unset($_ENV['APP_DEBUG']);
        } else {
            putenv('APP_DEBUG=' . $this->savedAppDebug);
            $_ENV['APP_DEBUG'] = $this->savedAppDebug;
        }

        $app->currentUser = null;
        unset($_SESSION['logged'], $_SESSION['uid']);

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $config The `devpanel` block for this test
     */
    private function ask(int $usertype, int $userid, array $config = array()): bool
    {
        $app = Application::getInstance();
        $app->applicationInfo['devpanel'] = $config;

        if ($userid > 0) {
            $user = new \Pramnos\User\User();
            $user->userid   = $userid;
            $user->usertype = $usertype;
            $app->currentUser = $user;
            $_SESSION['logged'] = true;
            $_SESSION['uid']    = $userid;
        } else {
            $app->currentUser = null;
            unset($_SESSION['logged'], $_SESSION['uid']);
        }

        $controller = (new \ReflectionClass(DevPanelController::class))
            ->newInstanceWithoutConstructor();

        return (bool) (new \ReflectionMethod(DevPanelController::class, 'allowedOnAnyDeployment'))
            ->invoke($controller);
    }

    /**
     * Root opens it anywhere, out of the box.
     *
     * 99 is `Adminer::ROOT_USERTYPE`, which already opens a **full database client** on a
     * live server. An owner who could do that and not open the panel whose tab links to it
     * was looking at an inconsistency, not a protection.
     */
    public function testRootOpensItOnAnyDeploymentByDefault(): void
    {
        // Assert
        $this->assertTrue($this->ask(99, 7));
        $this->assertTrue($this->ask(100, 7));
    }

    /**
     * An ordinary administrator does not, and that is the part that must not slip.
     *
     * 90 is the panel's development floor. On a development machine it is enough; here it
     * is not, and nothing about this change should make it so.
     */
    public function testAnOrdinaryAdministratorIsStillRefused(): void
    {
        // Assert
        $this->assertFalse($this->ask(90, 7));
        $this->assertFalse($this->ask(95, 7));
    }

    /**
     * A named user type opens it, whatever the floor says.
     */
    public function testANamedUserTypeIsAllowed(): void
    {
        // Assert
        $this->assertTrue($this->ask(95, 7, array('usertypes' => array(95))));
        $this->assertFalse($this->ask(94, 7, array('usertypes' => array(95))));
    }

    /**
     * A named person opens it, whatever their user type.
     *
     * The narrowest grant the config can express, and the reason the list is of ids: "this
     * developer, on this deployment" is a sentence about a person, and it belongs in a file
     * a reviewer reads.
     */
    public function testANamedUserIdIsAllowedWhateverTheirType(): void
    {
        // Assert
        $this->assertTrue($this->ask(10, 7, array('userids' => array(7))));
        $this->assertFalse($this->ask(10, 8, array('userids' => array(7))));
    }

    /**
     * The lists are read as integers, so string config values still match.
     *
     * `app.php` is hand-written and `['7']` is what a hand writes. A silent no-match there
     * would look like the config being ignored.
     */
    public function testStringsInTheConfigStillMatch(): void
    {
        // Assert
        $this->assertTrue($this->ask(10, 7, array('userids' => array('7'))));
        $this->assertTrue($this->ask(95, 7, array('usertypes' => array('95'))));
    }

    /**
     * Restricting to named people only is done by raising the floor out of reach.
     *
     * The lists widen and never narrow, so this is the one arrangement that means "these
     * people and nobody else" — worth a test because it is the configuration somebody will
     * actually want and it is not obvious from the keys alone.
     */
    public function testRaisingTheFloorRestrictsToTheNamedList(): void
    {
        // Arrange
        $onlyThem = array('production_min_usertype' => 9999, 'userids' => array(7));

        // Assert
        $this->assertTrue($this->ask(10, 7, $onlyThem));
        $this->assertFalse($this->ask(99, 8, $onlyThem), 'Root got in past an explicit list');
    }

    /**
     * Nobody signed in is refused, and asking does not raise a warning on the way.
     *
     * `getCurrentUser()` answers **false** for an anonymous visitor, not null — the slip
     * `Adminer::mayOpen()` documents. Reading a property off it lands on the right answer
     * and leaves "attempt to read property on bool" in the log of the one route where a
     * log entry is the only trace of somebody trying the door.
     */
    public function testAnonymousIsRefusedWithoutAWarning(): void
    {
        // Arrange — turn warnings into failures for the duration
        $raised = null;
        set_error_handler(function (int $no, string $message) use (&$raised): bool {
            $raised = $message;
            return true;
        });

        try {
            // Act
            $allowed = $this->ask(99, 0, array('userids' => array(7)));
        } finally {
            restore_error_handler();
        }

        // Assert
        $this->assertFalse($allowed);
        $this->assertNull($raised, 'reading the visitor raised: ' . (string) $raised);
    }

    /**
     * The gate is actually wired into `guardAccess()`, not merely present.
     *
     * This is the assertion the rest of the class does not make. Every test above calls
     * `allowedOnAnyDeployment()` directly, so removing the two lines that call it from
     * `guardAccess()` leaves all of them green — the method would be correct, complete,
     * and reached by nothing.
     *
     * Driven through `guardAccess()` with the environment saying "not a development
     * deployment": Root passes, and an ordinary administrator meets the 403 that used to
     * be everybody's answer.
     */
    public function testTheBypassIsReachedThroughGuardAccess(): void
    {
        // Arrange
        \Pramnos\Application\FeatureRegistry::loadFromConfig(array('devpanel'));

        try {
            // Act + Assert — Root is let through, so guardAccess() reports "not denied"
            $this->assertFalse(
                $this->guard(99, 7),
                'Root was refused on a live deployment, so the bypass is not wired in'
            );

            // and an ordinary administrator still meets the environment check
            ob_start();
            $refused = false;

            try {
                $this->guard(90, 8);
            } catch (\RuntimeException $exception) {
                $refused = str_contains($exception->getMessage(), '403');
            }

            $html = (string) ob_get_clean();

            $this->assertTrue($refused, 'usertype 90 was let through on a live deployment');
            $this->assertStringContainsString('development environment', $html);
        } finally {
            \Pramnos\Application\FeatureRegistry::reset();
        }
    }

    /**
     * `guardAccess()` on a controller whose `terminate()` does not end the process.
     *
     * @return bool True when access was denied, which is what the method reports.
     */
    private function guard(int $usertype, int $userid, array $config = array()): bool
    {
        $app = Application::getInstance();
        $app->applicationInfo['devpanel'] = $config;

        $user = new \Pramnos\User\User();
        $user->userid   = $userid;
        $user->usertype = $usertype;
        $app->currentUser = $user;
        $_SESSION['logged'] = true;
        $_SESSION['uid']    = $userid;

        $controller = new class extends DevPanelController {
            public function __construct()
            {
                // The real constructor registers actions against an application; none of
                // that is what this asserts.
            }

            /** `exit` would take the test runner with it. */
            protected function terminate(): void
            {
            }

            public function exposeGuard(): bool
            {
                return $this->guardAccess();
            }
        };

        return $controller->exposeGuard();
    }

    /**
     * With nothing configured and nobody special, the answer is no.
     *
     * The control: a gate that answered yes by default would pass every test above.
     */
    public function testTheDefaultAnswerIsNo(): void
    {
        // Assert
        $this->assertFalse($this->ask(1, 7));
        $this->assertFalse($this->ask(0, 0));
    }
}
