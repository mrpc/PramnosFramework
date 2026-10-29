<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\Health;
use Pramnos\Dashboard\Widgets\Figures;
use Pramnos\Dashboard\Widgets\HealthChecks;
use Pramnos\Health\HealthCheck;
use Pramnos\Health\HealthCheckResult;
use Pramnos\Health\HealthRegistry;

/**
 * A health check's name leads to the screen that says more than its one-line verdict.
 *
 * "Database: OK" answers whether it is up; the next question is always about the database
 * itself, and the health page left it to be found in the menu. The framework links its own
 * checks, only for somebody who may open the screen, and app.php links, moves or removes any.
 */
#[CoversClass(Health::class)]
#[CoversClass(HealthChecks::class)]
class HealthCheckLinksTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedInstances = [];

    private ?string $savedLastUsed = null;

    protected function setUp(): void
    {
        $ref = new \ReflectionClass(Application::class);
        $this->savedInstances = $ref->getProperty('appInstances')->getValue();
        $this->savedLastUsed  = $ref->getProperty('lastUsedApplication')->getValue();
        HealthRegistry::reset();
        Figures::forget();

        // Rendering a view looks for an application's own copy first, under INCLUDES.
        if (!defined('INCLUDES')) {
            define('INCLUDES', 'src');
        }
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(Application::class);
        $ref->getProperty('appInstances')->setValue(null, $this->savedInstances);
        $ref->getProperty('lastUsedApplication')->setValue(null, $this->savedLastUsed);
        HealthRegistry::reset();
        Figures::forget();
    }

    /** An application whose app.php says `$health`, made the current one. */
    private function application(array $health = []): void
    {
        $ref = new \ReflectionClass(Application::class);
        $app = $ref->newInstanceWithoutConstructor();
        $app->applicationInfo = ['health' => $health];
        $ref->getProperty('appInstances')->setValue(null, ['default' => $app]);
        $ref->getProperty('lastUsedApplication')->setValue(null, null);
    }

    /** A superuser: opens every framework screen in the default usertype mode. */
    private static function superuser(): object
    {
        return (object) ['userid' => 5, 'usertype' => 99];
    }

    /**
     * The framework's checks with a screen get its link; the rest get none.
     *
     * `hypertables` goes to the database page too, because its TimescaleDB section is where
     * hypertables are listed.
     */
    public function testTheFrameworksChecksLeadToTheirScreens(): void
    {
        // Arrange
        $this->application();

        // Act
        $links = Health::checkLinks(['database', 'hypertables', 'cache', 'disk_space'], self::superuser());

        // Assert
        $this->assertSame(['database', 'hypertables', 'cache'], array_keys($links));
        $this->assertStringEndsWith('Dashboard/database', $links['database']);
        $this->assertStringEndsWith('Dashboard/database', $links['hypertables']);
        $this->assertStringEndsWith('Dashboard/cache', $links['cache']);
    }

    /**
     * Somebody who may not open the dashboard is not shown a link into it: a link that answers
     * "access denied" reads as a broken page.
     */
    public function testNoLinkToAScreenThePersonCannotOpen(): void
    {
        // Arrange
        $this->application();

        // Act & Assert
        $this->assertSame([], Health::checkLinks(['database'], null), 'nobody signed in');
        $this->assertSame([], Health::checkLinks(['database'],
            (object) ['userid' => 5, 'usertype' => 1]), 'below the floor');
    }

    /**
     * app.php links an application's own check, moves a framework one, removes one with `''`,
     * and passes a full address through as it is.
     */
    public function testAppPhpLinksMovesAndRemoves(): void
    {
        // Arrange
        $this->application(['links' => [
            'my_service' => 'Services',
            'database'   => 'https://grafana.example.test/d/db',
            'cache'      => '',
        ]]);

        // Act
        $links = Health::checkLinks(['my_service', 'database', 'cache', 'hypertables'], self::superuser());

        // Assert
        $this->assertStringEndsWith('Services', $links['my_service']);
        $this->assertSame('https://grafana.example.test/d/db', $links['database'], 'used as it is');
        $this->assertArrayNotHasKey('cache', $links, "'' removes the framework's link");
        $this->assertStringEndsWith('Dashboard/database', $links['hypertables'], 'the rest keep theirs');
    }

    /**
     * The dashboard's Health card links the same names, escaped, and leaves the others as text.
     */
    public function testTheDashboardCardLinksTheSameNames(): void
    {
        // Arrange — a check that leads somewhere (via app.php, so no session is needed) and one
        // that does not
        $this->application(['links' => ['my_service' => 'Services?a=1&b=2']]);
        foreach (['my_service', 'plain_check'] as $name) {
            HealthRegistry::register(new class ($name) implements HealthCheck {
                public function __construct(private string $n) {}
                public function getName(): string { return $this->n; }
                public function run(): HealthCheckResult { return HealthCheckResult::ok($this->n); }
            });
        }

        // Act
        $html = (new HealthChecks())->render();

        // Assert
        $this->assertMatchesRegularExpression('#<a href="[^"]*Services\?a=1&amp;b=2">my_service</a>#', $html);
        $this->assertStringContainsString('<span class="pf-dashboard-health-name">plain_check</span>', $html);
    }

    /**
     * The health page draws the link on the check's name, in each scaffolded theme's view.
     */
    public function testTheHealthPageLinksTheName(): void
    {
        // Arrange — the scaffolded views, as HealthControllerTest renders them
        $this->application(['links' => ['my_service' => 'Services']]);
        HealthRegistry::register(new class implements HealthCheck {
            public function getName(): string { return 'my_service'; }
            public function run(): HealthCheckResult { return HealthCheckResult::ok('my_service'); }
        });
        $doc = \Pramnos\Framework\Factory::getDocument('html');
        $doc->themeObject = new class {
            public $fullpath = '';
            public function allowsViewOverrides() { return true; }
        };

        try {
            foreach (['plain-css', 'bootstrap', 'tailwind'] as $theme) {
                $doc->themeObject->fullpath = ROOT . DIRECTORY_SEPARATOR . 'scaffolding'
                    . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . $theme;

                // Act
                $html = (string) (new Health())->display();

                // Assert — every scaffolded theme draws it
                $this->assertMatchesRegularExpression('#<a href="[^"]*Services">my_service</a>#', $html, $theme);
            }
        } finally {
            $doc->themeObject = null;
        }
    }
}
