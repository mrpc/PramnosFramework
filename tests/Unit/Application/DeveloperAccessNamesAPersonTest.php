<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\DeveloperAccess;

/**
 * Two of the three developer tools could not be given to a person.
 *
 * `/devpanel` honoured `userids`; `/adminer` and `/debugbar` had only a usertype floor. So
 * `'devpanel' => ['userids' => [2]]` closed the panel to one account exactly as the guide
 * describes, and no value of `adminer_min_usertype` or `grant_min_usertype` did the same
 * for the other two.
 *
 * **A usertype is a role, and on a real installation it is a role granted to other
 * organisations.** One production database had 9 accounts at usertype 99 across six of
 * them — each able to open a full database client against a live 418 GB database — and 64
 * accounts at 90 or above, each able to issue itself a toolbar grant carrying the query log
 * of a live request, because `grant_min_usertype` was never set and the default is 90.
 *
 * That installation set both floors to 100 and **turned two of the tools off**, because off
 * was the only reachable state narrower than nine organisations. The account that should
 * have had them got them through shell access instead, which is luck rather than design.
 *
 * The asymmetry was known and half-closed: `allowedOnAnyDeployment()` cites Adminer as the
 * arrangement it is copying — *"the more dangerous of the two tools"* — and then the panel
 * gained the narrower gate and Adminer did not.
 */
#[CoversClass(DeveloperAccess::class)]
class DeveloperAccessNamesAPersonTest extends TestCase
{
    /** @var array<string,mixed>|null */
    private $savedDevpanel = null;

    /** @var array<string,mixed>|null */
    private $savedDebug = null;

    private ?string $savedAppDebug = null;

    protected function setUp(): void
    {
        $app = Application::getInstance();
        $this->savedDevpanel = $app->applicationInfo['devpanel'] ?? null;
        $this->savedDebug    = $app->applicationInfo['debug'] ?? null;

        // Not a development environment: on one, every gate opens anyway and none of this
        // is being asked.
        $this->savedAppDebug = getenv('APP_DEBUG') === false ? null : (string) getenv('APP_DEBUG');
        putenv('APP_DEBUG=0');
        $_ENV['APP_DEBUG'] = '0';
    }

    protected function tearDown(): void
    {
        $app = Application::getInstance();

        foreach (['devpanel' => $this->savedDevpanel, 'debug' => $this->savedDebug] as $key => $saved) {
            if ($saved === null) {
                unset($app->applicationInfo[$key]);
            } else {
                $app->applicationInfo[$key] = $saved;
            }
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
     * Ask one tool about one user, with a given configuration.
     *
     * @param array<string,mixed> $devpanel The `devpanel` block
     * @param array<string,mixed> $debug    The `debug` block
     */
    private function ask(
        string $tool,
        int $defaultFloor,
        int $usertype,
        int $userid,
        array $devpanel = array(),
        array $debug = array()
    ): bool {
        $app = Application::getInstance();
        $app->applicationInfo['devpanel'] = $devpanel;
        $app->applicationInfo['debug']    = $debug;

        if ($userid > 0) {
            $user = new \Pramnos\User\User();
            $user->userid   = $userid;
            $user->usertype = $usertype;
            $app->currentUser   = $user;
            $_SESSION['logged'] = true;
            $_SESSION['uid']    = $userid;
        } else {
            $app->currentUser = null;
            unset($_SESSION['logged'], $_SESSION['uid']);
        }

        return DeveloperAccess::permits($tool, $defaultFloor);
    }

    /**
     * The reported scenario, for each of the three tools: a floor out of reach, one name.
     *
     * This is the state that could not be expressed. `production_min_usertype => 100` means
     * nobody qualifies by role — the framework warns above 100, so it is the highest
     * reachable "off" — and the named account still gets in.
     *
     * @param string $tool  The tool
     * @param int    $floor Its own default floor
     */
    #[DataProvider('allThreeTools')]
    public function testAFloorOutOfReachPlusOneNameGivesExactlyThatPerson(string $tool, int $floor): void
    {
        // Arrange — nobody by role, one person by name
        $devpanel = [
            'production_min_usertype' => 100,
            'adminer_min_usertype'    => 100,
            'usertypes'               => [],
            'userids'                 => [2],
        ];
        $debug = ['grant_min_usertype' => 100, 'grant_userids' => [2]];

        // Assert — the named account, and a usertype-99 partner administrator
        $this->assertTrue(
            $this->ask($tool, $floor, 10, 2, $devpanel, $debug),
            $tool . ' still cannot be given to a named person'
        );
        $this->assertFalse(
            $this->ask($tool, $floor, 99, 4321, $devpanel, $debug),
            $tool . ' opened for a role rather than a person'
        );
    }

    /**
     * @return array<string,array{string,int}>
     */
    public static function allThreeTools(): array
    {
        return [
            'devpanel' => [DeveloperAccess::DEVPANEL, 99],
            'adminer'  => [DeveloperAccess::ADMINER, 99],
            'debugbar' => [DeveloperAccess::DEBUGBAR, 90],
        ];
    }

    /**
     * One line covers all three: `devpanel.userids` reaches Adminer and the toolbar.
     *
     * The filing's own suggestion, and the reason it is the default direction: an
     * installation that wants one person to have everything should not have to write the
     * same list three times, because the third one is the one that gets forgotten.
     *
     * @param string $tool  The tool
     * @param int    $floor Its own default floor
     */
    #[DataProvider('allThreeTools')]
    public function testThePanelsUseridsReachesEveryTool(string $tool, int $floor): void
    {
        // Arrange — named once, in the panel's block only
        $devpanel = [
            'production_min_usertype' => 100,
            'adminer_min_usertype'    => 100,
            'userids'                 => [2],
        ];
        $debug = ['grant_min_usertype' => 100];

        // Assert
        $this->assertTrue($this->ask($tool, $floor, 10, 2, $devpanel, $debug));
    }

    /**
     * A tool's own list wins over the panel's, so Adminer can be narrower.
     *
     * The other half of the shape: an installation that wants the panel open to a team and
     * the database client to one person has to be able to say that, or the fallback is a
     * widening nobody asked for.
     */
    public function testAToolsOwnListWinsOverThePanels(): void
    {
        // Arrange — the panel names two people, Adminer names one of them
        $devpanel = [
            'production_min_usertype' => 100,
            'adminer_min_usertype'    => 100,
            'userids'                 => [2, 3],
            'adminer_userids'         => [2],
        ];

        // Assert
        $this->assertTrue($this->ask(DeveloperAccess::ADMINER, 99, 10, 2, $devpanel));
        $this->assertFalse(
            $this->ask(DeveloperAccess::ADMINER, 99, 10, 3, $devpanel),
            'a narrower Adminer list was widened by the panel again'
        );
        // and the panel still has both
        $this->assertTrue($this->ask(DeveloperAccess::DEVPANEL, 99, 10, 3, $devpanel));
    }

    /**
     * The fallback is one-way: narrowing a tool cannot widen the panel.
     *
     * The direction that would be a security bug rather than an inconvenience. Naming
     * somebody for Adminer must not hand them the panel, which browses the database, reads
     * the cache and dumps the container.
     */
    public function testNamingSomebodyForAToolDoesNotGiveThemThePanel(): void
    {
        // Arrange — named for Adminer only
        $devpanel = [
            'production_min_usertype' => 100,
            'adminer_userids'         => [2],
        ];

        // Assert
        $this->assertTrue($this->ask(DeveloperAccess::ADMINER, 99, 10, 2, $devpanel));
        $this->assertFalse(
            $this->ask(DeveloperAccess::DEVPANEL, 99, 10, 2, $devpanel),
            'an Adminer list widened the DevPanel'
        );
    }

    /**
     * A usertype list is honoured too, for the case where the role really is the answer.
     *
     * `usertypes` exists for an installation whose developers share one non-root type, and
     * it was the panel's alone in the same way `userids` was.
     */
    public function testAUsertypeListIsHonouredByEveryTool(): void
    {
        // Arrange
        $devpanel = [
            'production_min_usertype' => 100,
            'adminer_min_usertype'    => 100,
            'usertypes'               => [42],
        ];
        $debug = ['grant_min_usertype' => 100];

        // Assert
        foreach (self::allThreeTools() as [$tool, $floor]) {
            $this->assertTrue(
                $this->ask($tool, $floor, 42, 7, $devpanel, $debug),
                $tool . ' ignored the usertype list'
            );
            $this->assertFalse(
                $this->ask($tool, $floor, 41, 7, $devpanel, $debug),
                $tool . ' opened for a usertype that is not on the list'
            );
        }
    }

    /**
     * With nothing configured, every tool behaves exactly as it did.
     *
     * The guarantee that makes this safe to ship: an installation that sets none of the new
     * keys must see no change at all. Adminer's 99, the toolbar's 90, the panel's 99.
     */
    public function testAnInstallationThatConfiguresNothingIsUnchanged(): void
    {
        // Assert — at the floor
        $this->assertTrue($this->ask(DeveloperAccess::ADMINER, 99, 99, 7));
        $this->assertTrue($this->ask(DeveloperAccess::DEBUGBAR, 90, 90, 7));
        $this->assertTrue($this->ask(DeveloperAccess::DEVPANEL, 99, 99, 7));

        // and below it
        $this->assertFalse($this->ask(DeveloperAccess::ADMINER, 99, 98, 7));
        $this->assertFalse($this->ask(DeveloperAccess::DEBUGBAR, 90, 89, 7));
        $this->assertFalse($this->ask(DeveloperAccess::DEVPANEL, 99, 98, 7));
    }

    /**
     * A configured floor of `0` means "not set", not "nobody".
     *
     * Which is what both existing gates already did — `rootFloor()` and `minUserType()`
     * both treat a falsy value as absent. An installation with a stray zero would otherwise
     * lose a tool it thought it had left alone, on an upgrade.
     */
    public function testAZeroFloorFallsBackToTheDefaultRatherThanClosing(): void
    {
        // Arrange
        $devpanel = ['adminer_min_usertype' => 0];

        // Assert
        $this->assertTrue($this->ask(DeveloperAccess::ADMINER, 99, 99, 7, $devpanel));
        $this->assertFalse($this->ask(DeveloperAccess::ADMINER, 99, 98, 7, $devpanel));
    }

    /**
     * Nobody anonymous, and nothing without a session.
     *
     * `getCurrentUser()` answers **false** rather than null for an anonymous visitor, which
     * is the shape three separate gates in this framework have had to be corrected for.
     *
     * @param string $tool  The tool
     * @param int    $floor Its own default floor
     */
    #[DataProvider('allThreeTools')]
    public function testAnAnonymousVisitorIsRefused(string $tool, int $floor): void
    {
        // Arrange — a userid list that would otherwise match nothing, and no user
        $devpanel = ['userids' => [0]];

        // Assert
        $this->assertFalse($this->ask($tool, $floor, 0, 0, $devpanel));
    }

    /**
     * A user id of zero is never on a list, however the list is written.
     *
     * `array_map('intval', …)` turns a stray `null` or `''` in the configuration into `0`,
     * and an anonymous visitor's id is also `0`. The two must not meet.
     */
    public function testAStrayEntryCannotMatchAnonymousZero(): void
    {
        // Arrange — a list that becomes [0] after casting
        $devpanel = ['production_min_usertype' => 100, 'userids' => ['', null]];

        // Assert — a real signed-in user is still refused, and there is no id 0 to match
        $this->assertFalse($this->ask(DeveloperAccess::DEVPANEL, 99, 10, 5, $devpanel));
    }

    /**
     * A "logged in" session over a user with no id is refused, even against a zero list.
     *
     * The state the `$userid < 1` guard exists for, and reaching it took two attempts.
     * `Session::staticIsLogged()` requires `$_SESSION['uid'] > 1`, so a zero-id session is
     * already refused a line earlier and removing the guard reddened nothing — the first
     * version of this test proved the wrong thing.
     *
     * What does reach it is the suite's own `$unittesting_logged` override, which makes
     * `staticIsLogged()` answer true whatever the session holds. That is not an artificial
     * path: it is the one place in this framework where "signed in" is decided without
     * consulting the id, and a gate that trusts it has to check the id itself.
     *
     * It matters because the list is `intval`-cast — a stray `''` or `null` in the
     * configuration becomes `0`, and so does an unidentified user.
     */
    public function testALoggedSessionWithNoUserIdCannotMatchAZeroOnTheList(): void
    {
        // Arrange
        $app = Application::getInstance();
        $app->applicationInfo['devpanel'] = [
            'production_min_usertype' => 100,
            'userids'                 => ['', null],   // becomes [0, 0]
        ];

        $user = new \Pramnos\User\User();
        $user->userid   = 0;
        $user->usertype = 10;
        $app->currentUser = $user;

        if (!defined('UNITTESTING')) {
            define('UNITTESTING', true);
        }
        global $unittesting_logged;
        $wasLogged = $unittesting_logged ?? null;
        $unittesting_logged = true;

        try {
            // Act & Assert
            $this->assertFalse(
                DeveloperAccess::permits(DeveloperAccess::DEVPANEL, 99),
                'a zero user id matched a zero on the list'
            );
        } finally {
            $unittesting_logged = $wasLogged;
        }
    }

    /**
     * An unregistered tool name is refused outright, not defaulted.
     *
     * The first version of the resolver read every key as absent and fell through — to the
     * caller's floor, and then to the panel's `userids`, because the fallback only excludes
     * the panel itself. So a **typo in a tool name inherited the panel's permissions**, and
     * a mistake that opens a gate is not a mistake anybody notices.
     *
     * This test is what found it. Fail closed: there are three callers and they pass
     * constants, so an unknown name can only be a typo or a fourth tool somebody forgot to
     * register, and both want finding.
     */
    public function testAnUnregisteredToolIsRefusedOutright(): void
    {
        // Arrange — a userid list and a root usertype, under a tool name that does not exist
        $devpanel = ['production_min_usertype' => 100, 'userids' => [2]];

        // Assert — neither the list nor the floor opens it
        $this->assertFalse($this->ask('nosuchtool', 99, 10, 2, $devpanel));
        $this->assertFalse(
            $this->ask('nosuchtool', 99, 100, 2, $devpanel),
            'an unregistered tool opened on the strength of a usertype floor'
        );
    }
}
