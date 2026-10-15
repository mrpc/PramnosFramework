<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Dashboard;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One dashboard card as each theme draws it — alone, for DashboardController::card(), and inside
 * the dashboard, which draws every card through the same file.
 *
 * What holds: the card carries what pf-utils.js reads — its id, its width, its settings as JSON —
 * and everything a person or a widget wrote is escaped; a card with options has the gear and a
 * form of them with what was chosen selected, and one without has neither; and the dashboard's
 * cards are this file's, so the two can never draw a card differently.
 */
class DashboardCardViewTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function themes(): array
    {
        return ['tailwind' => ['tailwind'], 'bootstrap' => ['bootstrap'], 'plain-css' => ['plain-css']];
    }

    /**
     * A template drawn with `$this` as a view holding `$vars`, whose insert() draws a partial
     * beside it.
     *
     * @param array<string, mixed> $vars
     * @param array<string, mixed> $local Variables of the template's own scope
     */
    private static function draw(string $file, array $vars, array $local = []): string
    {
        $view = new class ($vars, dirname($file)) {
            /** @param array<string, mixed> $vars */
            public function __construct(private array $vars, private string $dir)
            {
            }

            /** A view's variable, or null. */
            public function __get(string $name): mixed
            {
                return $this->vars[$name] ?? null;
            }

            /** Whether a view's variable is set. */
            public function __isset(string $name): bool
            {
                return isset($this->vars[$name]);
            }

            /**
             * A partial, drawn in its own scope with `$data`.
             *
             * @param array<string, mixed> $data
             */
            public function insert(string $template, array $data = []): void
            {
                (function (string $__file, array $__data): void {
                    extract($__data);
                    include $__file;
                })->call($this, $this->dir . '/' . $template . '.html.php', $data);
            }
        };

        ob_start();
        (function (string $__file, array $__local): void {
            extract($__local);
            include $__file;
        })->call($view, $file, $local);

        return (string) ob_get_clean();
    }

    /** The theme's dashboard views. */
    private static function dir(string $theme): string
    {
        return ROOT . '/scaffolding/themes/' . $theme . '/views/dashboard';
    }

    /**
     * A card with options: its id, width and settings for the script, escaped; the gear; the form
     * with the chosen value selected and a free value filled in.
     */
    #[DataProvider('themes')]
    public function testACardWithSettings(string $theme): void
    {
        // Arrange
        $widget = [
            'id' => 'sales', 'size' => 'l', 'title' => 'Sales <b>', 'link' => null,
            'settings' => ['period' => '30', 'title' => '"Ours"'],
            'options'  => ['period' => ['7' => 'Last 7 days', '30' => 'Last 30 days'], 'title' => null],
            'html'     => '<p>drawn</p>',
        ];

        // Act
        $html = self::draw(self::dir($theme) . '/card.html.php', ['widget' => $widget]);

        // Assert
        $this->assertStringContainsString('data-pf-widget="sales" data-size="l"', $html);
        $this->assertStringContainsString('data-settings="{&quot;period&quot;:&quot;30&quot;,&quot;title&quot;:&quot;\&quot;Ours\&quot;&quot;}"', $html, 'the settings as JSON, escaped');
        $this->assertStringContainsString('Sales &lt;b&gt;', $html);
        $this->assertStringContainsString('data-pf-widget-settings-toggle', $html);
        $this->assertMatchesRegularExpression('#<option value="30" selected>Last 30 days</option>#', $html);
        $this->assertStringContainsString('name="title" value="&quot;Ours&quot;"', $html);
        $this->assertStringContainsString('<p>drawn</p>', $html, 'the widget\'s own HTML as it drew it');
        $this->assertStringNotContainsString('pf-dashboard-full', $html);
    }

    /** A full card without options: no gear, no form; nothing drawn says so; settings are `{}`. */
    #[DataProvider('themes')]
    public function testACardWithout(string $theme): void
    {
        // Arrange
        $widget = ['id' => 'health', 'size' => 'full', 'title' => 'Health', 'link' => '/admin/Health', 'settings' => [], 'options' => [], 'html' => ''];

        // Act
        $html = self::draw(self::dir($theme) . '/card.html.php', ['widget' => $widget]);

        // Assert
        $this->assertStringNotContainsString('data-pf-widget-settings', $html);
        $this->assertStringContainsString('data-settings="{}"', $html, 'an object, not a list');
        $this->assertStringContainsString('pf-dashboard-full', $html);
        $this->assertStringContainsString('Nothing to show today.', $html);
        $this->assertStringContainsString('href="/admin/Health"', $html);
    }

    /**
     * The dashboard draws its cards through card.html.php, names where one card is drawn alone,
     * and offers each card it can add with what it shows.
     */
    #[DataProvider('themes')]
    public function testTheDashboardDrawsItsCardsThroughTheCard(string $theme): void
    {
        // Arrange
        $vars = [
            'widgets'   => [['id' => 'sales', 'size' => 's', 'title' => 'Sales', 'link' => null, 'settings' => [], 'options' => [], 'html' => '<p>one</p>']],
            'addable'   => ['health' => 'Health'],
            'catalogue' => ['health' => ['title' => 'Health', 'description' => 'Every check <now>']],
            'saveUrl'   => '/admin/Dashboard/savelayout',
            'cardUrl'   => '/admin/Dashboard/card',
            'resetUrl'  => '/admin/Dashboard/resetlayout',
        ];

        // Act
        $html = self::draw(self::dir($theme) . '/admin_dashboard.html.php', $vars);

        // Assert
        $this->assertStringContainsString('data-pf-widget="sales" data-size="s"', $html);
        $this->assertStringContainsString('<p>one</p>', $html);
        $this->assertStringContainsString('data-card="/admin/Dashboard/card"', $html);
        $this->assertStringContainsString('title="Every check &lt;now&gt;"', $html);
    }
}
