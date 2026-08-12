<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Document\Document;

/**
 * Exposes the theme-loading decisions without constructing a full Application.
 */
class LazyThemeProbe extends Application
{
    /** @var array<int, string> Themes this probe was asked to load */
    public array $loaded = [];

    /** No constructor: these decisions read only $applicationInfo and settings. */
    public function __construct()
    {
    }

    /** @param array<string, mixed> $info */
    public function setInfo(array $info): void
    {
        $this->applicationInfo = $info;
    }

    public function canRenderATheme($document): bool
    {
        return $this->documentCanRenderATheme($document);
    }

    public function isLazy(): bool
    {
        return $this->lazyThemeEnabled();
    }

    public function deferredLoad($document): void
    {
        $this->loadThemeIfDeferred($document);
    }

    public function load($document): void
    {
        $this->loadConfiguredTheme($document);
    }
}

/**
 * A document that records the theme it was asked to load.
 */
class ThemeRecordingDocument
{
    /** @var array<int, string> */
    public array $loaded = [];

    /** @var string */
    public $type = 'html';

    /**
     * Stand in for Document::loadtheme().
     *
     * @param  string $theme
     * @param  string $path
     * @param  mixed  $application
     * @return null
     */
    public function loadtheme($theme = 'default', $path = '', $application = null)
    {
        $this->loaded[] = $theme;

        return null;
    }
}

/**
 * When the configured theme is built, and when it is not.
 *
 * A theme is built to produce HTML. The load ran before the controller, which
 * is before anything knows what the response will be — so a controller that
 * answers with JSON (a datatable endpoint, an autocomplete, a save) built a
 * theme that nothing would ever render. On a page that keeps talking to the
 * server after it loads, that is most of its requests.
 *
 * Deferring the load is opt-in, and that is the part these tests care about
 * most: a controller is entitled to read `$document->themeObject` while it
 * runs, so the default has to stay exactly as it was.
 */
class LazyThemeLoadingTest extends TestCase
{
    /** @var string The document type the suite started with */
    private string $originalType = 'html';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalType = Document::$type;
        Document::$type = 'html';
    }

    protected function tearDown(): void
    {
        Document::$type = $this->originalType;
        Settings::setSetting('lazytheme', false, false);
        parent::tearDown();
    }

    /**
     * By default nothing is deferred.
     *
     * The load stays where it always was, so a controller that reads
     * `themeObject` keeps finding one. Opting in is the application's call,
     * because only it knows what its controllers do.
     */
    public function testNothingIsDeferredByDefault(): void
    {
        // Arrange
        $probe = new LazyThemeProbe();
        $probe->setInfo(['theme' => 'default']);
        $document = new ThemeRecordingDocument();

        // Act
        $probe->deferredLoad($document);

        // Assert
        $this->assertFalse($probe->isLazy());
        $this->assertSame([], $document->loaded, 'The deferred hook did nothing');
    }

    /**
     * The application opts in through its configuration.
     */
    public function testTheApplicationCanOptIn(): void
    {
        // Arrange
        $probe = new LazyThemeProbe();
        $probe->setInfo(['theme' => 'default', 'lazytheme' => true]);

        // Act & Assert
        $this->assertTrue($probe->isLazy());
    }

    /**
     * A setting opts in too, for an installation that cannot edit app.php.
     */
    public function testASettingCanOptIn(): void
    {
        // Arrange
        $probe = new LazyThemeProbe();
        $probe->setInfo(['theme' => 'default']);
        Settings::setSetting('lazytheme', '1', false);

        // Act & Assert
        $this->assertTrue($probe->isLazy());
    }

    /**
     * With deferral on, an HTML response still gets its theme.
     *
     * The saving must not cost the page its styling.
     */
    public function testAnHtmlResponseStillGetsItsTheme(): void
    {
        // Arrange
        $probe = new LazyThemeProbe();
        $probe->setInfo(['theme' => 'mytheme', 'lazytheme' => true]);
        $document = new ThemeRecordingDocument();

        // Act
        $probe->deferredLoad($document);

        // Assert
        $this->assertSame(['mytheme'], $document->loaded);
    }

    /**
     * With deferral on, a JSON response builds no theme at all.
     *
     * The point of the whole exercise.
     */
    public function testAJsonResponseBuildsNoTheme(): void
    {
        // Arrange — the controller asked for a JSON document while it ran
        $probe = new LazyThemeProbe();
        $probe->setInfo(['theme' => 'mytheme', 'lazytheme' => true]);
        $document = new ThemeRecordingDocument();
        Document::$type = 'json';

        // Act
        $probe->deferredLoad($document);

        // Assert
        $this->assertSame([], $document->loaded);
    }

    /**
     * The decision follows the document the controller actually chose.
     *
     * `Factory::getDocument('json')` sets the static default type; the instance
     * the request started with is still the HTML one. Reading the instance's
     * own type would therefore always say "html" and defer nothing — which is
     * exactly the bug this replaced.
     */
    public function testTheDecisionFollowsTheControllersChoiceNotTheInstance(): void
    {
        // Arrange — an HTML instance, but the controller switched the default
        $probe    = new LazyThemeProbe();
        $document = new ThemeRecordingDocument();
        $document->type = 'html';
        Document::$type = 'json';

        // Act & Assert
        $this->assertFalse($probe->canRenderATheme($document));
    }

    /**
     * Every non-rendering type is excluded, and every rendering one is kept.
     */
    public function testWhichTypesCanRenderATheme(): void
    {
        // Arrange
        $probe    = new LazyThemeProbe();
        $document = new ThemeRecordingDocument();

        foreach (['html', 'amp', 'print'] as $type) {
            // Act
            Document::$type = $type;

            // Assert
            $this->assertTrue($probe->canRenderATheme($document), $type . ' renders a page');
        }

        foreach (['json', 'raw', 'rss', 'png'] as $type) {
            // Act
            Document::$type = $type;

            // Assert
            $this->assertFalse($probe->canRenderATheme($document), $type . ' does not');
        }
    }

    /**
     * An application with no theme configured loads nothing, either way.
     */
    public function testNoConfiguredThemeLoadsNothing(): void
    {
        // Arrange
        $probe = new LazyThemeProbe();
        $probe->setInfo(['lazytheme' => true]);
        $document = new ThemeRecordingDocument();

        // Act
        $probe->load($document);
        $probe->deferredLoad($document);

        // Assert
        $this->assertSame([], $document->loaded);
    }

    /**
     * A document that cannot load a theme is left alone rather than fataled on.
     *
     * `loadtheme()` is a method of the document types, not of every object that
     * could be sitting in that variable — a Response-driven request replaces the
     * document mid-flight.
     */
    public function testADocumentWithoutLoadthemeIsSkipped(): void
    {
        // Arrange
        $probe = new LazyThemeProbe();
        $probe->setInfo(['theme' => 'default']);

        // Act — reaching the end without a fatal is the assertion
        $probe->load(new \stdClass());
        $probe->load(null);

        // Assert
        $this->assertTrue(true);
    }
}
