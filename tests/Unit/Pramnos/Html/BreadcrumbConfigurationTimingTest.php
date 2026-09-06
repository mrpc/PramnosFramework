<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Html\Breadcrumb;
use Pramnos\Html\ComponentClasses;

/**
 * The breadcrumb can see its own configuration.
 *
 * `Breadcrumb::__construct()` seeds `listClass` from `ComponentClasses::get()`, which
 * reads `component_classes` out of `applicationInfo`. The application constructor built
 * the breadcrumb twenty-eight lines before that array existed — so `currentInstance()`
 * answered null, the `?->` swallowed it, and `pf-breadcrumb` won whatever `app.php`
 * said.
 *
 * Reported by an application that lost its breadcrumb styling on upgrade, found the
 * documented setting, set it, and saw no change. The failure was silent in both
 * directions: nothing said the key had been ignored, and nothing suggested that *when*
 * it was read was the reason. A key that is spelled correctly and read too early looks
 * exactly like a key that is not read at all.
 */
#[CoversClass(Breadcrumb::class)]
#[CoversClass(ComponentClasses::class)]
#[CoversClass(Application::class)]
class BreadcrumbConfigurationTimingTest extends TestCase
{
    /** @var array<string, mixed>|null The block as the application had it */
    private $saved = null;

    private bool $hadKey = false;

    protected function setUp(): void
    {
        $app = Application::getInstance();
        $this->hadKey = array_key_exists('component_classes', $app->applicationInfo);
        $this->saved  = $this->hadKey ? $app->applicationInfo['component_classes'] : null;
    }

    protected function tearDown(): void
    {
        $app = Application::getInstance();

        if ($this->hadKey) {
            $app->applicationInfo['component_classes'] = $this->saved;
        } else {
            unset($app->applicationInfo['component_classes']);
        }

        parent::tearDown();
    }

    /**
     * A configured class reaches the rendered markup.
     *
     * The reproduction from the filing, in three lines: set the key, add two crumbs, read
     * the `<ol>`. It rendered `pf-breadcrumb`.
     */
    public function testTheConfiguredClassIsRendered(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['component_classes']
            = array('breadcrumb' => 'breadcrumb');

        // Act
        $breadcrumb = new Breadcrumb();
        $breadcrumb->addItem('Home', '/');
        $breadcrumb->addItem('Here', '/here');

        // Assert
        $this->assertSame('breadcrumb', $breadcrumb->listClass);
        $this->assertStringContainsString('<ol class="breadcrumb">', $breadcrumb->render());
    }

    /**
     * With nothing configured the framework's own hook is still what you get.
     *
     * The control. A "fix" that read the key correctly and lost the default would break
     * every installation that never configured one — which is all of them until this key
     * existed.
     */
    public function testTheDefaultSurvives(): void
    {
        // Arrange
        unset(Application::getInstance()->applicationInfo['component_classes']);

        // Act + Assert
        $this->assertSame('pf-breadcrumb', (new Breadcrumb())->listClass);
    }

    /**
     * The application builds its breadcrumb **after** it reads `app.php`, not before.
     *
     * Read from the source rather than executed: constructing an `Application` boots a
     * database, a session and a request, and what is worth pinning is one line of ordering.
     * Without this assertion the two tests above stay green with the constructor back in
     * its old position — they build their own `Breadcrumb` after `applicationInfo` exists,
     * which is exactly the condition the application constructor failed to meet.
     */
    public function testTheApplicationConstructorBuildsItAfterLoadingTheConfiguration(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            (new \ReflectionClass(Application::class))->getFileName()
        );

        $constructor = substr($source, (int) strpos($source, 'public function __construct'));

        // Act
        $loads = strpos($constructor, '$this->applicationInfo = self::loadApplicationInfo');
        $builds = strpos($constructor, '$this->breadcrumbs = new');

        // Assert
        $this->assertIsInt($loads, 'the configuration is no longer loaded in the constructor');
        $this->assertIsInt($builds, 'the breadcrumb is no longer built in the constructor');
        $this->assertGreaterThan(
            $loads,
            $builds,
            'the breadcrumb is built before app.php is read, so component_classes cannot reach it'
        );
    }

    /**
     * Nothing else seeds itself from the configuration during boot.
     *
     * The filing's second note, answered rather than assumed: `Pagination` reads two keys in
     * *its* constructor too, and is safe only because nothing builds one that early. This
     * fails if that changes — a component seeded in the application constructor has the same
     * bug, and it is invisible in the diff that introduces it.
     */
    public function testNoOtherComponentIsSeededInTheApplicationConstructor(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            (new \ReflectionClass(Application::class))->getFileName()
        );

        $constructor = substr($source, (int) strpos($source, 'public function __construct'));
        $constructor = substr($constructor, 0, (int) strpos($constructor, "\n    }\n"));

        // Act — every component this constructor builds
        preg_match_all('/new \\\\Pramnos\\\\Html\\\\(\w+)/', $constructor, $matches);

        // Assert
        $this->assertSame(
            array('Breadcrumb'),
            array_values(array_unique($matches[1])),
            'a new Html component is built in the application constructor; '
            . 'check it does not read ComponentClasses before app.php is loaded'
        );
    }
}
