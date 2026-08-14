<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Theme;

use PHPUnit\Framework\TestCase;
use Pramnos\Theme\MenuWalker;
use Pramnos\Theme\Theme;
use Pramnos\Theme\Widget;
use Pramnos\Theme\WidgetInterface;
use Pramnos\Theme\WidgetRegistry;

/**
 * A widget that writes a body.
 */
class TestBodyWidget extends Widget
{
    /**
     * @param string $body What to render
     */
    public function __construct(private string $body = '<p>hello</p>')
    {
    }

    /**
     * @param array<string, mixed> $args The merged arguments
     * @return string The body
     */
    protected function content(array $args): string
    {
        return $this->body;
    }
}

/**
 * A widget that has nothing to say.
 */
class TestEmptyWidget extends Widget
{
    /**
     * @param array<string, mixed> $args The merged arguments
     * @return string Always empty
     */
    protected function content(array $args): string
    {
        return '   ';
    }
}

/**
 * Something registered as a widget that is not one.
 */
class TestNotAWidget
{
}

/**
 * Widget rendering and menu rendering — the two extension points the theme guide described
 * for years without them existing.
 *
 * Both are built to the same rule: **an application that does not use them pays nothing.** The
 * tests below assert that as a property, not as an intention — a registry that is never
 * constructed, a setting that is never read, and an area that returns before doing any work.
 */
class WidgetsAndMenusTest extends TestCase
{
    /**
     * A theme with no application behind it.
     *
     * @return Theme The theme under test
     */
    private function makeTheme(): Theme
    {
        return new Theme('default', '', $this->createMock(\Pramnos\Application\Application::class));
    }

    // ── Widget ──────────────────────────────────────────────────────────────────

    /**
     * A widget's body is wrapped with what the area asked for.
     */
    public function testAWidgetIsWrappedByTheAreaArguments(): void
    {
        // Arrange
        $widget = new TestBodyWidget('<p>hello</p>');

        // Act
        $html = $widget->render([
            'before_widget' => '<aside class="widget">',
            'after_widget'  => '</aside>',
            'before_title'  => '<h3>',
            'after_title'   => '</h3>',
            'title'         => 'Latest posts',
        ]);

        // Assert
        $this->assertSame(
            '<aside class="widget"><h3>Latest posts</h3><p>hello</p></aside>',
            $html
        );
    }

    /**
     * A widget with no title gets no heading.
     *
     * An empty `<h3></h3>` is worse than no heading: it appears in the document outline and
     * announces a section with no name.
     */
    public function testAWidgetWithoutATitleGetsNoHeading(): void
    {
        // Act
        $html = (new TestBodyWidget())->render([
            'before_title' => '<h3>',
            'after_title'  => '</h3>',
        ]);

        // Assert
        $this->assertStringNotContainsString('<h3>', $html);
    }

    /**
     * A widget with nothing to say renders nothing at all — not an empty wrapper.
     *
     * So a theme can test whether an area produced anything, instead of having to ask each
     * widget in advance whether it intends to.
     */
    public function testAnEmptyWidgetRendersNothing(): void
    {
        // Act
        $html = (new TestEmptyWidget())->render([
            'before_widget' => '<aside>',
            'after_widget'  => '</aside>',
            'title'         => 'Ignored',
        ]);

        // Assert
        $this->assertSame('', $html);
    }

    /**
     * A widget title is escaped.
     */
    public function testATitleIsEscaped(): void
    {
        // Act
        $html = (new TestBodyWidget())->render(['title' => 'A & B <script>']);

        // Assert
        $this->assertStringContainsString('A &amp; B &lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    // ── WidgetRegistry ──────────────────────────────────────────────────────────

    /**
     * A registered class name is constructed; a callable receives the stored record.
     *
     * The callable form exists because most widgets need their settings at construction, and
     * a fixed constructor signature would make every widget's shape the registry's business.
     */
    public function testTheRegistryResolvesClassNamesAndFactories(): void
    {
        // Arrange
        $registry = new WidgetRegistry();
        $registry->register('body', TestBodyWidget::class);
        $registry->register('custom', static fn (array $record) => new TestBodyWidget($record['html']));

        // Act
        $fromClass   = $registry->resolve(['type' => 'body']);
        $fromFactory = $registry->resolve(['type' => 'custom', 'html' => '<b>x</b>']);

        // Assert
        $this->assertInstanceOf(WidgetInterface::class, $fromClass);
        $this->assertStringContainsString('<b>x</b>', (string) $fromFactory?->render([]));
        $this->assertTrue($registry->has('body'));
        $this->assertSame(['body', 'custom'], $registry->types());
    }

    /**
     * An unknown type resolves to null and is recorded.
     *
     * Widget records outlive the code that renders them — a plugin is removed, a type is
     * renamed — so this must not be an error. Recording it is what makes a sidebar quietly
     * missing one of its four widgets findable rather than merely survivable.
     */
    public function testAnUnknownTypeIsRecordedRatherThanFatal(): void
    {
        // Arrange
        $registry = new WidgetRegistry();

        // Act
        $result = $registry->resolve(['type' => 'removed-plugin']);
        $registry->resolve(['type' => 'removed-plugin']);

        // Assert
        $this->assertNull($result);
        $this->assertSame(['removed-plugin' => 2], $registry->unresolved());
    }

    /**
     * A record with no type, a missing class, and a factory returning the wrong thing all
     * resolve to null.
     *
     * The last one matters most: trusting it would move the failure to render time, further
     * from the registration that caused it.
     */
    public function testTheRegistryRefusesAnythingItCannotRender(): void
    {
        // Arrange
        $registry = new WidgetRegistry();
        $registry->register('missing', 'App\\Widgets\\DoesNotExist');
        $registry->register('wrong', TestNotAWidget::class);
        $registry->register('wrong-factory', static fn () => new TestNotAWidget());

        // Act & Assert
        $this->assertNull($registry->resolve([]));
        $this->assertNull($registry->resolve(['type' => 'missing']));
        $this->assertNull($registry->resolve(['type' => 'wrong']));
        $this->assertNull($registry->resolve(['type' => 'wrong-factory']));
    }

    /**
     * `reset()` forgets registrations and recorded failures.
     */
    public function testTheRegistryCanBeReset(): void
    {
        // Arrange
        $registry = new WidgetRegistry();
        $registry->register('body', TestBodyWidget::class);
        $registry->resolve(['type' => 'unknown']);

        // Act
        $registry->reset();

        // Assert
        $this->assertSame([], $registry->types());
        $this->assertSame([], $registry->unresolved());
    }

    // ── Theme integration, and the cost of not using any of it ──────────────────

    /**
     * An area with no stored widgets renders nothing, without constructing a registry.
     *
     * This is the assertion that matters for every project that does not use widgets: the
     * cost of an unused feature is one array lookup. The registry is reached through
     * `widgets()`, which builds it lazily, so a null internal registry after rendering proves
     * nothing was built.
     */
    public function testAnEmptyAreaCostsNothing(): void
    {
        // Arrange
        $theme = $this->makeTheme();
        $theme->registerWidgetArea('Sidebar', '', 'sidebar');

        // Act
        $html = $theme->renderWidgetArea('sidebar');

        // Assert
        $this->assertSame('', $html);

        $registry = new \ReflectionProperty(Theme::class, 'widgetRegistry');
        $this->assertNull(
            $registry->getValue($theme),
            'Rendering an empty area must not construct a widget registry.'
        );
    }

    /**
     * An unregistered area renders nothing.
     */
    public function testAnUnknownAreaRendersNothing(): void
    {
        // Act & Assert
        $this->assertSame('', $this->makeTheme()->renderWidgetArea('nowhere'));
    }

    /**
     * Stored widgets are rendered in order, and a stale record is skipped.
     *
     * Until 2026-08-14 `renderWidgetArea()` returned an empty string always: the loop was
     * commented out and referred to a `pramnos_theme_widget` class the framework does not
     * ship. A theme could declare areas and nothing would ever appear in them.
     */
    public function testStoredWidgetsAreRenderedAndStaleOnesSkipped(): void
    {
        // Arrange
        $theme = $this->makeTheme();
        $theme->registerWidgetArea('Sidebar', '', 'sidebar', [
            'before_widget' => '<aside>',
            'after_widget'  => '</aside>',
        ]);
        $theme->widgets()->register('body', static fn (array $r) => new TestBodyWidget($r['html']));

        // Stored records, as an admin screen would have left them
        $widgets = new \ReflectionProperty(Theme::class, 'widgets');
        $widgets->setValue($theme, [
            ['widgetArea' => 'sidebar', 'type' => 'body', 'html' => '<p>one</p>'],
            ['widgetArea' => 'sidebar', 'type' => 'removed', 'html' => '<p>gone</p>'],
            ['widgetArea' => 'sidebar', 'type' => 'body', 'html' => '<p>two</p>'],
            ['widgetArea' => 'other',   'type' => 'body', 'html' => '<p>elsewhere</p>'],
        ]);
        (new \ReflectionProperty(Theme::class, 'widgetsLoaded'))->setValue($theme, true);

        // Act
        $html = $theme->renderWidgetArea('sidebar');

        // Assert — both live widgets, in order, and nothing from the other area
        $this->assertSame('<aside><p>one</p></aside><aside><p>two</p></aside>', $html);
        $this->assertSame(['removed' => 1], $theme->widgets()->unresolved());
    }

    /**
     * Adding a widget does not discard the ones already stored.
     *
     * This is the bug lazy loading introduced, caught by a characterization test and fixed
     * here as well as there. `addWidget()` serialises the **whole** collection back to the
     * setting, so adding to a collection that had not been loaded yet would persist only the
     * new widget and silently drop every existing one. Nothing would report it; the widgets
     * would simply be gone the next time an area rendered.
     */
    public function testAddingAWidgetDoesNotDiscardStoredOnes(): void
    {
        // Arrange — a theme that has not read its widgets yet, with one already stored
        $theme = $this->makeTheme();
        $theme->registerWidgetArea('Sidebar', '', 'sidebar');

        \Pramnos\Application\Settings::setSetting(
            'theme_' . $theme->theme . '_widgets',
            serialize(['existing' => ['widgetId' => 'existing', 'widgetArea' => 'sidebar']])
        );
        (new \ReflectionProperty(Theme::class, 'widgetsLoaded'))->setValue($theme, false);
        (new \ReflectionProperty(Theme::class, 'widgets'))->setValue($theme, []);

        // Act
        $result = $theme->addWidget('sidebar', 'widgetId=added');

        // Assert — both survive
        $this->assertTrue($result);
        $widgets = $theme->getWidgets();
        $this->assertArrayHasKey('existing', $widgets, 'Adding a widget must not drop stored ones.');
        $this->assertArrayHasKey('added', $widgets);
    }

    // ── MenuWalker ──────────────────────────────────────────────────────────────

    /**
     * A flat menu renders as a list of links.
     */
    public function testAFlatMenuRenders(): void
    {
        // Act
        $html = (new MenuWalker())->render([
            ['title' => 'Home', 'url' => '/'],
            ['title' => 'About', 'url' => '/about'],
        ]);

        // Assert
        $this->assertSame(
            '<li><a href="/">Home</a></li><li><a href="/about">About</a></li>',
            $html
        );
    }

    /**
     * Children nest, to any depth.
     */
    public function testChildrenNest(): void
    {
        // Act
        $html = (new MenuWalker())->render([
            [
                'title'    => 'Products',
                'url'      => '/products',
                'children' => [
                    ['title' => 'Widgets', 'url' => '/products/widgets'],
                ],
            ],
        ]);

        // Assert
        $this->assertStringContainsString('<ul><li><a href="/products/widgets">Widgets</a></li></ul>', $html);
    }

    /**
     * Alternative key spellings are accepted.
     *
     * Menu rows come from application tables that predate this class, and renaming a column is
     * not a reasonable price for rendering a list.
     */
    public function testAlternativeKeySpellingsWork(): void
    {
        // Act
        $html = (new MenuWalker())->render([
            ['name' => 'Home', 'link' => '/', 'submenu' => [['label' => 'Sub', 'href' => '/sub']]],
        ]);

        // Assert
        $this->assertStringContainsString('<a href="/">Home</a>', $html);
        $this->assertStringContainsString('<a href="/sub">Sub</a>', $html);
    }

    /**
     * An item with no URL is a span, not an anchor to nowhere.
     *
     * `<a>` with no `href` is focusable-but-inert in some browsers and a broken promise in all
     * of them — a menu heading is not a link.
     */
    public function testAnItemWithNoUrlIsNotALink(): void
    {
        // Act
        $html = (new MenuWalker())->render([['title' => 'Section']]);

        // Assert
        $this->assertSame('<li><span>Section</span></li>', $html);
    }

    /**
     * `target` and `rel` are passed through when set, and escaped.
     *
     * An external menu item wanting `target="_blank" rel="noopener"` should not need a custom
     * walker to get it.
     */
    public function testTargetAndRelArePassedThrough(): void
    {
        // Act
        $html = (new MenuWalker())->render([[
            'title'  => 'Docs',
            'url'    => 'https://example.com',
            'target' => '_blank',
            'rel'    => 'noopener noreferrer',
        ]]);

        // Assert
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    /**
     * An item with no title at all still renders, empty rather than broken.
     *
     * A menu row whose title column is null is a data problem, not a reason to lose the rest
     * of the menu.
     */
    public function testAnItemWithNoTitleRendersEmpty(): void
    {
        // Act
        $html = (new MenuWalker())->render([['url' => '/somewhere']]);

        // Assert
        $this->assertSame('<li><a href="/somewhere"></a></li>', $html);
    }

    /**
     * Titles and URLs are escaped.
     */
    public function testTitlesAndUrlsAreEscaped(): void
    {
        // Act
        $html = (new MenuWalker())->render([
            ['title' => 'A & B', 'url' => '/x?a=1&b=2'],
        ]);

        // Assert
        $this->assertStringContainsString('A &amp; B', $html);
        $this->assertStringContainsString('a=1&amp;b=2', $html);
    }

    /**
     * The legacy `[ACTIVE]` and `[HASSUB]` markers are honoured.
     *
     * They are in the documented defaults of `displayMenu()`, so a theme passing those
     * defaults must get sensible markup — kept for the active item and for one with children,
     * stripped otherwise.
     */
    public function testLegacyConditionalMarkersAreResolved(): void
    {
        // Arrange
        $options = [
            'pretopmenu'  => '<li class="[HASSUB]parent[/HASSUB] [ACTIVE]active[/ACTIVE]">',
            'posttopmenu' => '</li>',
        ];

        // Act
        $html = (new MenuWalker())->render([
            ['title' => 'Plain', 'url' => '/a'],
            ['title' => 'Current', 'url' => '/b', 'active' => true],
            ['title' => 'Parent', 'url' => '/c', 'children' => [['title' => 'Kid', 'url' => '/d']]],
        ], $options);

        // Assert
        $this->assertStringContainsString('<li class=" ">', $html, 'Neither marker applies to the first item.');
        $this->assertStringContainsString('<li class=" active">', $html);
        $this->assertStringContainsString('<li class="parent ">', $html);
    }

    /**
     * A `[URL]` / `[TITLE]` template is filled in.
     */
    public function testLegacyLinkTemplatesAreFilled(): void
    {
        // Act
        $html = (new MenuWalker())->render(
            [['title' => 'Home', 'url' => '/']],
            ['topmenuoption' => '<a class="nav" href="[URL]">[TITLE]</a>']
        );

        // Assert
        $this->assertStringContainsString('<a class="nav" href="/">Home</a>', $html);
    }

    /**
     * An item's own class is added to its wrapper.
     */
    public function testAnItemClassIsAddedToTheWrapper(): void
    {
        // Act
        $html = (new MenuWalker())->render(
            [['title' => 'Home', 'url' => '/', 'class' => 'featured']],
            ['pretopmenu' => '<li class="item">', 'posttopmenu' => '</li>']
        );

        // Assert
        $this->assertStringContainsString('<li class="item featured">', $html);
    }

    /**
     * An empty menu renders nothing, container and all.
     *
     * A `<nav>` wrapping nothing is worse than no nav: it is a landmark with no content.
     */
    public function testAnEmptyMenuRendersNothing(): void
    {
        // Act & Assert
        $this->assertSame(
            '',
            (new MenuWalker())->render([], ['premenu' => '<nav>', 'postmenu' => '</nav>'])
        );
    }

    /**
     * Non-array entries are skipped rather than crashing the menu.
     */
    public function testMalformedItemsAreSkipped(): void
    {
        // Act
        $html = (new MenuWalker())->render([['title' => 'Home', 'url' => '/'], 'nonsense', 42]);

        // Assert
        $this->assertSame('<li><a href="/">Home</a></li>', $html);
    }

    /**
     * A theme uses the default walker, and will use a replacement when given one.
     */
    public function testAThemeCanBeGivenADifferentWalker(): void
    {
        // Arrange
        $theme  = $this->makeTheme();
        $walker = new class extends MenuWalker {
            /**
             * @param array<int, array<string, mixed>> $items   Items
             * @param array<string, mixed>             $options Options
             * @return string A fixed string, to prove it was used
             */
            public function render(array $items, array $options = []): string
            {
                return 'custom-walker';
            }
        };

        // Act & Assert — the default first
        $this->assertInstanceOf(MenuWalker::class, $theme->menuWalker());

        $theme->setMenuWalker($walker);
        $theme->register_nav_menu('primary', 'Primary', 1);
        $theme->setMenuItemsProvider(static fn () => [['title' => 'Home', 'url' => '/']]);

        $this->assertSame(
            'custom-walker',
            $theme->displayMenu('primary', ['echo' => false, 'container' => ''])
        );
    }

    /**
     * A provider that returns something other than an array is treated as having nothing.
     *
     * So a provider that fails to find a menu can return `null` or `false` and the page still
     * renders.
     */
    public function testAProviderReturningNothingRendersNothing(): void
    {
        // Arrange
        $theme = $this->makeTheme();
        $theme->register_nav_menu('primary', 'Primary', 1);
        $theme->setMenuItemsProvider(static fn () => null);

        // Act & Assert
        $this->assertSame('', $theme->displayMenu('primary', ['echo' => false]));

        // And the provider can be removed again
        $theme->setMenuItemsProvider(null);
        $this->assertSame('', $theme->displayMenu('primary', ['echo' => false]));
    }
}
