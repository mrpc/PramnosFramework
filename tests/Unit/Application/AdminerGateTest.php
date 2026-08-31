<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\Adminer;

/**
 * Who may open `/adminer`, and what happens to everybody else.
 *
 * The gate is the whole of the authorisation on this route: the database connection is supplied
 * from configuration, so **reaching the page is reaching the database**. There is no second
 * credential to stop a mistake here, which is why the guide lists what holds it and why this
 * asserts every clause of it separately.
 *
 * Four states, and the last two are the ones a mistake would hide:
 *
 *   - not signed in → refused;
 *   - signed in below the floor, in production → refused;
 *   - **usertype ≥ 99, anywhere** → allowed, with or without the `devpanel` feature, because this
 *     is the owner's tool and not part of that panel;
 *   - **development, below 99** → allowed only if `devpanel` is enabled, because the floor it
 *     borrows belongs to that panel and a floor configured for a panel the installation does not
 *     have is a number with nothing behind it.
 *
 * And the refusal is a **404**, not a 403: a 403 confirms the route exists, and this is the one
 * URL on the site where that is worth withholding.
 */
#[CoversClass(Adminer::class)]
class AdminerGateTest extends TestCase
{
    private ?array $savedInstances = null;

    private mixed $savedDebug = null;

    protected function setUp(): void
    {
        \Pramnos\Http\RequestIdentity::reset();
        $this->savedDebug = getenv('APP_DEBUG');
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        \Pramnos\Http\RequestIdentity::reset();
        $_SESSION = [];

        /*
         * The feature registry is process-wide, and this test empties it.
         *
         * Restored to what the suite runs with, rather than left empty: a later test asking
         * `isEnabled('auth')` would otherwise get false from a registry this one cleared, and
         * fail for a reason that has nothing to do with it.
         */
        \Pramnos\Application\FeatureRegistry::reset();
        \Pramnos\Application\FeatureRegistry::loadFromConfig(
            ['auth', 'authserver', 'devpanel', 'queue', 'messaging', 'cache']
        );

        // `APP_DEBUG` decides whether this is a development environment for *every* test after
        // this one, so it goes back exactly as it was — unset if it was unset.
        if ($this->savedDebug === false) {
            putenv('APP_DEBUG');
            unset($_ENV['APP_DEBUG']);
        } else {
            putenv('APP_DEBUG=' . $this->savedDebug);
            $_ENV['APP_DEBUG'] = $this->savedDebug;
        }

        if ($this->savedInstances !== null) {
            (new \ReflectionProperty(Application::class, 'appInstances'))
                ->setValue(null, $this->savedInstances);
            $this->savedInstances = null;
        }
    }

    /**
     * The real gate, with only the refusal and the package lookup observable.
     *
     * Everything `mayOpen()` reads is driven for real rather than stubbed: the signed-in account
     * through `RequestIdentity` and `$_SESSION` (it checks **both** — `getCurrentUser()` and
     * `staticIsLogged()` are independent, and an API-sealed request with no session is
     * deliberately refused here, because this is a browser tool), the environment through
     * `APP_DEBUG`, and the feature through `FeatureRegistry`. A stub would have asserted my own
     * stub; this asserts the gate.
     */
    private function gate(): object
    {
        return new class extends Adminer {
            public array $audited = [];

            public int $notFounds = 0;

            public function __construct()
            {
            }

            public function mayOpenNow(): bool
            {
                return $this->mayOpen();
            }

            protected function audit(string $outcome): void
            {
                $this->audited[] = $outcome;
            }

            protected function notFound(): void
            {
                $this->notFounds++;
            }
        };
    }

    /** `APP_DEBUG`, which is what `isDeveloperEnvironment()` reads. */
    private function environment(bool $developer): void
    {
        putenv('APP_DEBUG=' . ($developer ? '1' : '0'));
        $_ENV['APP_DEBUG'] = $developer ? '1' : '0';
    }

    /**
     * Which features the installation has — reset first, because `loadFromConfig()` only adds.
     *
     * It never clears, so `loadFromConfig([])` is a no-op and whatever an earlier test enabled is
     * still enabled. Which is invisible in production, where it runs once at boot, and is exactly
     * how this test first reported the gate opening without the DevPanel: the feature was on,
     * left there by something else entirely.
     */
    private function features(bool $devpanel): void
    {
        \Pramnos\Application\FeatureRegistry::reset();

        if ($devpanel) {
            \Pramnos\Application\FeatureRegistry::loadFromConfig(['devpanel']);
        } else {
            // Touch the registry so the defaults are registered without enabling anything.
            \Pramnos\Application\FeatureRegistry::getKnown();
        }
    }

    /**
     * Sign somebody in, both ways the gate asks.
     *
     * `getCurrentUser()` and `staticIsLogged()` are independent questions and `mayOpen()` needs
     * yes to both — so a test that set only one would pass or fail for the wrong reason. It also
     * records a real decision: an API-sealed request with no session is refused here on purpose,
     * because this is a browser tool.
     */
    private function signIn(int $usertype): void
    {
        \Pramnos\Http\RequestIdentity::seal(
            new class ($usertype) {
                public int $userid = 42;

                public function __construct(public int $usertype)
                {
                }
            },
            'test'
        );

        $_SESSION['logged'] = true;
        $_SESSION['uid']    = 42;
    }

    /** Would the gate open, in this environment with these features? */
    private function openable(bool $developer, bool $devpanel): bool
    {
        $this->environment($developer);
        $this->features($devpanel);

        return $this->gate()->mayOpenNow();
    }

    /** Nobody signed in: refused, whatever the environment. */
    public function testAnAnonymousVisitorIsRefused(): void
    {
        // Assert
        $this->assertFalse($this->openable(developer: false, devpanel: false));
        $this->assertFalse($this->openable(developer: true, devpanel: true));
    }

    /**
     * Root opens it anywhere, with or without the DevPanel.
     *
     * The half asked for deliberately: fixing data on a live server is a real thing an owner
     * does, and a tool that only works in development means they do it in `psql` with no undo —
     * or leave `adminer.php` in the web root for ever, which is what this route replaces.
     */
    public function testRootOpensItInProductionAndWithoutTheDevPanel(): void
    {
        // Arrange
        $this->signIn(99);

        // Assert
        $this->assertTrue($this->openable(developer: false, devpanel: false));
        $this->assertTrue($this->openable(developer: false, devpanel: true));
    }

    /** An administrator below the root floor is refused in production. */
    public function testAnAdministratorIsRefusedInProduction(): void
    {
        // Arrange
        $this->signIn(90);

        // Assert
        $this->assertFalse(
            $this->openable(developer: false, devpanel: true),
            'usertype 90 opened a database browser on a production server'
        );
    }

    /**
     * In development, below root, the DevPanel's floor decides — and the feature must be on.
     *
     * The clause that is easy to get wrong, because it reads as belt-and-braces and is not: a
     * floor configured for a panel the installation does not have is a number with nothing
     * behind it, and letting a usertype-90 account through on the strength of it would be a gate
     * configured by accident.
     */
    public function testInDevelopmentTheFloorAppliesOnlyWithTheDevPanel(): void
    {
        // Arrange
        $this->signIn(90);

        // Assert
        $this->assertTrue($this->openable(developer: true, devpanel: true));
        $this->assertFalse(
            $this->openable(developer: true, devpanel: false),
            'the DevPanel floor was honoured without the DevPanel'
        );
    }

    /** And below that floor, development or not. */
    public function testBelowTheDevPanelFloorIsRefusedEvenInDevelopment(): void
    {
        // Arrange
        $this->signIn(50);

        // Assert
        $this->assertFalse($this->openable(developer: true, devpanel: true));
    }

    /**
     * A refusal is audited **before** the 404.
     *
     * The page says nothing on purpose, so the log is the only place a refusal is visible — and
     * a run of them from one address is the shape of somebody trying the door. Recorded first,
     * because whatever ends the request must not be able to skip it.
     */
    public function testARefusalIsAuditedAndAnswered404(): void
    {
        // Arrange — nobody signed in.
        $this->environment(false);
        $this->features(false);
        $gate = $this->gate();

        // Act
        $gate->display();

        // Assert
        $this->assertSame(['refused'], $gate->audited);
        $this->assertSame(1, $gate->notFounds, 'a refusal must answer 404, not 403');
    }

    /**
     * With the package absent, an allowed visitor also gets a 404 — and no audit of a refusal.
     *
     * Two different problems that look identical from outside: "you are not allowed" and "this
     * installation has no Adminer". The log is where they are told apart, so the refusal audit
     * must **not** fire for the second — otherwise a missing package reads as somebody trying
     * the door.
     */
    public function testAMissingPackageIs404WithoutARefusalAudit(): void
    {
        // Arrange
        $this->signIn(99);
        $this->environment(false);
        $this->features(false);
        $gate = $this->gate();

        // Act
        $gate->display();

        // Assert
        $this->assertSame(1, $gate->notFounds);
        $this->assertSame(
            [],
            $gate->audited,
            'a missing package was recorded as a refused attempt, which is a different event'
        );
    }

    /**
     * `isAvailable()` is false when the package is absent, whoever is asking.
     *
     * The DevPanel and the debug toolbar ask this before drawing a link. Both would rather show
     * nothing than an entry that answers 404 — a tool that appears and refuses reads as broken
     * rather than absent.
     */
    public function testIsAvailableIsFalseWithoutThePackage(): void
    {
        // Arrange
        $this->signIn(99);

        // Assert — the real class, whose locate() finds nothing in this checkout.
        $this->assertFalse(Adminer::isAvailable());
    }

    /** The root floor is 99, not the administrator's 90. */
    public function testTheRootFloorIsNotTheAdministratorFloor(): void
    {
        // Assert
        $this->assertSame(99, Adminer::ROOT_USERTYPE);
        $this->assertGreaterThan(90, Adminer::ROOT_USERTYPE);
    }
}
