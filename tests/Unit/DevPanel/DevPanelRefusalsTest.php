<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\DevPanel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\FeatureRegistry;
use Pramnos\DevPanel\DevPanelController;

/**
 * What the DevPanel does when the answer is «no», and the two panels nobody had opened by id.
 *
 * The panel's happy paths are covered in {@see DevPanelPanelContentTest} — the queries each card
 * builds, the windows they respect, the empty states. What had never run is the other half: the
 * refusals, and the two detail screens reached by an id in the query string.
 *
 * Both matter for the same reason, and it is not tidiness. This panel browses the database, reads the
 * log files and runs whatever tools an application registered, so «no» is the important answer — and
 * a refusal that renders half a page, or a detail screen that shows somebody else's row because the
 * id was not found and the code carried on, is the kind of defect that is only visible from the
 * outside once.
 */
#[CoversClass(DevPanelController::class)]
class DevPanelRefusalsTest extends TestCase
{
    /** @var mixed The database singleton as it was before the test */
    private $originalDatabase = null;

    protected function setUp(): void
    {
        // Explicit rather than inherited: one test below turns the feature off, and a suite where the
        // next test's outcome depends on that is a suite whose failures move around.
        FeatureRegistry::reset();
        FeatureRegistry::loadFromConfig(['devpanel']);
    }

    protected function tearDown(): void
    {
        if ($this->originalDatabase !== null) {
            $singleton = &\Pramnos\Framework\Factory::getDatabase();
            $singleton = $this->originalDatabase;
            $this->originalDatabase = null;
        }

        FeatureRegistry::reset();

        $_GET = [];
        $_POST = [];

        parent::tearDown();
    }

    /**
     * Run something that writes, and hand back what it wrote.
     *
     * Down to the level it started at, because these endpoints open buffers of their own — the `raw`
     * document type does — and leaving one open makes PHPUnit call the *next* test risky, which is a
     * failure reported against innocent code.
     *
     * @param callable():void $work
     */
    private function capture(callable $work): string
    {
        $outer = ob_get_level();
        ob_start();

        try {
            $work();
        } finally {
            $written = '';
            while (ob_get_level() > $outer) {
                $written = (string) ob_get_clean() . $written;
            }
        }

        return $written;
    }

    /**
     * A controller whose collaborators outside itself are recorded rather than run.
     *
     * `renderError()` is declared `never`, so it cannot simply record and return — the code after it
     * would run, which is the opposite of what it means. It throws a marker instead, which is also
     * what makes «did it stop there» assertable.
     */
    private function probe(): object
    {
        return new class extends DevPanelController {
            public array $errors = [];

            public array $layouts = [];

            public bool $accessRefused = false;

            /** @var list<string> */
            public array $logFiles = [];

            public function __construct()
            {
            }

            protected function renderError(int $code, string $message): never
            {
                $this->errors[] = ['code' => $code, 'message' => $message];

                throw new \RuntimeException('refused:' . $code);
            }

            protected function guardAccess(): bool
            {
                return $this->accessRefused;
            }

            protected function renderLayout(string $activeTab, string $content): void
            {
                $this->layouts[] = ['tab' => $activeTab, 'content' => $content];
            }

            protected function logFileNames(): array
            {
                return $this->logFiles;
            }

            public static function adminerTabUrl(): ?string
            {
                return 'https://example.test/adminer';
            }

            public function callWithAdminerTab(array $tabs): array
            {
                return static::withAdminerTab($tabs);
            }
        };
    }

    /** A connection that answers every query with no rows. */
    private function useEmptyDatabase(): void
    {
        $db = new class extends \Pramnos\Database\Database {
            public function __construct()
            {
                $this->type = 'mysql';
                $this->prefix = '';
                $this->connected = true;
            }

            public function execute($sql, &...$arguments)
            {
                return new \Pramnos\Tests\Unit\DevPanel\RecordedResult([]);
            }

            public function query(
                $sql,
                $cache = false,
                $cachetime = 60,
                $category = '',
                $dieOnFatalError = false,
                $skipDataFix = false
            ) {
                return new \Pramnos\Tests\Unit\DevPanel\RecordedResult([]);
            }
        };

        $singleton = &\Pramnos\Framework\Factory::getDatabase();
        $this->originalDatabase = $singleton;
        $singleton = $db;
    }

    /**
     * Call one of the private render methods.
     *
     * @param mixed ...$args
     */
    private function render(object $controller, string $method, ...$args): string
    {
        return (string) (new \ReflectionMethod(DevPanelController::class, $method))
            ->invoke($controller, ...$args);
    }

    /**
     * The raw log endpoint refuses with a 404 when the feature is off, and renders nothing else.
     *
     * 404 rather than 403, deliberately: a 403 confirms that the panel exists on this server and is
     * merely closed to you, which is a fact worth not publishing about a tool that browses the
     * database. The assertion is that nothing follows it — `renderError()` is declared `never`, and
     * this is the test that the declaration is honoured rather than merely written.
     */
    public function testTheRawEndpointIsNotFoundWhenTheFeatureIsOff(): void
    {
        // Arrange
        FeatureRegistry::reset();          // …and nothing loaded, so `devpanel` is off
        $probe = $this->probe();

        // Act
        $stopped = false;
        $output = $this->capture(function () use ($probe, &$stopped): void {
            try {
                $probe->raw();
            } catch (\RuntimeException) {
                $stopped = true;
            }
        });

        // Assert
        $this->assertTrue($stopped, 'the endpoint carried on past a refusal declared `never`');
        $this->assertSame(404, $probe->errors[0]['code'] ?? null);
        $this->assertSame('', $output, 'a refused request still wrote a page');
    }

    /**
     * A signed-out visitor with no debug grant gets nothing back at all.
     *
     * `guardAccess()` has already rendered whatever it renders, so the endpoint's own job here is to
     * stop — returning `null` **without** writing a log page underneath it. The two conditions are
     * `or` in effect: a granted debug session skips the gate, which is how the toolbar's own log link
     * works for somebody who is not signed in as an administrator.
     */
    public function testTheRawEndpointWritesNothingWhenAccessIsRefused(): void
    {
        // Arrange
        $probe = $this->probe();
        $probe->accessRefused = true;

        // Act
        $returned = null;
        $output = $this->capture(function () use ($probe, &$returned): void {
            $returned = $probe->raw();
        });

        // Assert
        $this->assertNull($returned);
        $this->assertSame('', $output, 'a log page was written for a refused request');
        $this->assertSame([], $probe->errors, 'the endpoint refused twice');
    }

    /**
     * A file name that is not one of the known files is refused by name comparison, not by path.
     *
     * There is exactly one directory to read from and it is not the caller's to choose, so the query
     * string is compared against the list rather than joined to a path. `../../.env` therefore fails
     * the `in_array()` and never reaches a filesystem call — which is why this asserts the *message*
     * rather than the absence of a crash: a path-traversal attempt and a typo produce the same answer,
     * and that is the point.
     */
    public function testAnUnknownLogFileIsRefusedWithoutTouchingTheFilesystem(): void
    {
        // Arrange
        $probe = $this->probe();
        $probe->logFiles = ['php_error.log'];
        $_GET['file'] = '../../.env';
        \Pramnos\Http\Request::resetInstance();

        // Act
        $output = $this->capture(fn () => $probe->raw());

        // Assert
        $this->assertStringContainsString('Invalid or no log file', $output);
        $this->assertStringNotContainsString('.env', $output, 'the refusal quoted the name back');
    }

    /**
     * A token id nobody has is a warning and a way back, not a blank screen.
     *
     * The branch exists because the alternative is worse than an error: with `$tokenInfo` left null
     * and the code carrying on, the screen would render a detail page with every field empty — which
     * reads as «this token exists and has no activity» rather than «there is no such token».
     */
    public function testAnUnknownTokenSaysSoAndOffersAWayBack(): void
    {
        // Arrange
        $this->useEmptyDatabase();
        $probe = $this->probe();

        // Act
        $html = $this->render($probe, 'renderTokenDetail', 4242);

        // Assert
        $this->assertStringContainsString('4242', $html);
        $this->assertStringContainsString('not found', $html);
        $this->assertStringContainsString('?action=users', $html, 'no way back to the list');
    }

    /**
     * And the same for a user id.
     *
     * Its own test rather than a data provider over the two, because the two screens are separate
     * methods with separate queries — a provider would assert that one of them behaves correctly
     * twice.
     */
    public function testAnUnknownUserSaysSoAndOffersAWayBack(): void
    {
        // Arrange
        $this->useEmptyDatabase();
        $probe = $this->probe();

        // Act
        $html = $this->render($probe, 'renderUserLog', 909);

        // Assert
        $this->assertStringContainsString('909', $html);
        $this->assertStringContainsString('not found', $html);
        $this->assertStringContainsString('?action=users', $html);
    }

    /**
     * The Adminer tab is inserted after the Database tab, because that is where it belongs.
     *
     * Order is the whole content of this method: Adminer is the other way to look at the database, so
     * it reads as a sibling of that tab and as noise anywhere else. The insertion is done by rebuilding
     * the array rather than by `array_splice`, because the keys are meaningful — they are the `action`
     * values the tabs link to.
     */
    public function testTheAdminerTabSitsBesideTheDatabaseTab(): void
    {
        // Arrange
        $probe = $this->probe();

        // Act
        $tabs = $probe->callWithAdminerTab([
            'display' => 'Overview',
            'db'      => 'Database',
            'users'   => 'Users',
        ]);

        // Assert
        $this->assertSame(['display', 'db', 'adminer', 'users'], array_keys($tabs));
        $this->assertSame('Adminer', $tabs['adminer']);
    }

    /**
     * With no Database tab to sit beside, it is appended rather than dropped.
     *
     * The fallback matters more than it looks: a panel assembled without the `db` tab — an
     * installation that hid it, a future rearrangement — would otherwise lose the Adminer entry
     * entirely, and the tool would be reachable only by typing its URL.
     */
    public function testWithNoDatabaseTabTheAdminerTabIsAppended(): void
    {
        // Arrange
        $probe = $this->probe();

        // Act
        $tabs = $probe->callWithAdminerTab(['display' => 'Overview', 'logs' => 'Logs']);

        // Assert
        $this->assertSame(['display', 'logs', 'adminer'], array_keys($tabs));
    }
}
