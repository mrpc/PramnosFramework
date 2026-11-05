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

    /**
     * Fields: a date with its own label, hidden until the setting it waits for has its value; a
     * select of labelled values; and with the period chosen, the date shown.
     */
    #[DataProvider('themes')]
    public function testFields(string $theme): void
    {
        // Arrange
        $widget = [
            'id' => 'sales', 'size' => 'm', 'title' => 'Sales', 'link' => null, 'settings' => ['period' => '30'],
            'options' => ['period' => ['30' => '30 days', 'custom' => 'Custom'], 'from' => null],
            'fields'  => [
                'period' => ['input' => 'select', 'label' => 'Περίοδος', 'values' => ['30' => '30 days', 'custom' => 'Custom'], 'when' => []],
                'from'   => ['input' => 'date', 'label' => 'Από', 'values' => [], 'when' => ['period' => 'custom']],
            ],
            'html' => '',
        ];

        // Act
        $hidden = self::draw(self::dir($theme) . '/card.html.php', ['widget' => $widget]);
        $widget['settings'] = ['period' => 'custom', 'from' => '2026-10-01'];
        $shown  = self::draw(self::dir($theme) . '/card.html.php', ['widget' => $widget]);

        // Assert
        $this->assertStringContainsString('Περίοδος', $hidden, 'its own label');
        $this->assertMatchesRegularExpression('#data-pf-when="\{&quot;period&quot;:&quot;custom&quot;\}" hidden>Από#', $hidden, 'waits for custom');
        $this->assertStringContainsString('type="date" name="from"', $hidden);
        $this->assertMatchesRegularExpression('#data-pf-when="[^"]+">Από#', $shown, 'custom chosen: shown');
        $this->assertStringContainsString('value="2026-10-01"', $shown);
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
            'catalogue' => ['health' => ['title' => 'Health', 'description' => 'Every check <now>', 'placed' => false], 'sales' => ['title' => 'Sales', 'description' => '', 'placed' => true]],
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
        $this->assertStringContainsString('data-pf-dashboard-catalogue-dialog', $html);
        $this->assertStringContainsString('Every check &lt;now&gt;', $html, 'each card with what it shows');
        $this->assertMatchesRegularExpression('#data-pf-dashboard-add="health">#', $html, 'offered');
        $this->assertMatchesRegularExpression('#data-pf-dashboard-add="sales" hidden>#', $html, 'on the board: not offered');
        $this->assertStringContainsString('data-pf-dashboard-say="', $html, 'the script\'s words, from the page');
    }

    /**
     * A group of tabs: one frame the script reads as a group, a tab per card with the theme's
     * active class named on the strip, the active panel drawn and shown, the other waiting to be
     * drawn and hidden — and each panel carrying its card's settings form above its body.
     */
    #[DataProvider('themes')]
    public function testAGroupOfTabs(string $theme): void
    {
        // Arrange
        $card = static fn (string $id, ?string $html, array $options = []): array => [
            'id' => $id, 'size' => 'full', 'title' => ucfirst($id), 'link' => null, 'settings' => $options !== [] ? ['period' => '30'] : [],
            'options' => $options, 'html' => $html,
        ];
        $widget = ['group' => true, 'id' => 'tabs:t1', 'size' => 'full', 'title' => 'Ops', 'active' => 'sales',
            'tabs' => [$card('sales', '<p>drawn</p>', ['period' => ['7' => '7 days', '30' => '30 days']]), $card('map', null)]];

        // Act
        $html = self::draw(self::dir($theme) . '/tabs.html.php', ['widget' => $widget]);

        // Assert
        $this->assertMatchesRegularExpression('#data-pf-widget="tabs:t1" data-pf-widget-group data-size="full"\s+data-active="sales"#', $html);
        $this->assertMatchesRegularExpression('#data-pf-tabs data-active-class="[a-z-]+"#', $html);
        $this->assertStringContainsString('data-pf-tab="map"', $html);
        $this->assertMatchesRegularExpression('#data-pf-tab-panel="map"[^>]* hidden data-pf-tab-lazy>#', $html, 'not drawn yet, and hidden');
        $this->assertStringContainsString('<p>drawn</p>', $html);
        $this->assertStringContainsString('data-pf-tab-settings-toggle', $html, 'a tab has settings: the group\'s gear');
        $this->assertMatchesRegularExpression('#data-pf-tab-panel="sales".*data-pf-widget-settings.*<option value="30" selected>.*data-pf-tab-body#s', $html, 'its form above its body');
        $this->assertStringContainsString('pf-dashboard-full', $html);
    }

    /**
     * The presets and dashboards dialog: its tabs as the person may use them, the user types it is
     * given, and an organisation choice — under the application's own word — only where there are
     * organisations; the picker pointed at the people endpoint.
     */
    #[DataProvider('themes')]
    public function testThePresetsDialog(string $theme): void
    {
        // Arrange
        $vars = ['presets' => [['id' => 'app:Ops', 'name' => 'Ops', 'kind' => 'app', 'mine' => false, 'visibility' => 'everyone', 'audience' => null, 'default_for_role' => null]],
            'presetUrls' => ['apply' => '/a', 'save' => '/s', 'delete' => '/d', 'default' => '/f', 'copy' => '/c', 'list' => '/l'],
            'managesPresets' => true, 'roles' => [90 => 'Administrator', 99 => 'Root'], 'organizations' => [], 'organizationLabel' => 'Utility',
            'peopleUrl' => '/people', 'csrfField' => '<input type="hidden" name="_csrf_token" value="t">', 'userBoard' => null, 'boardUrls' => ['new' => '/n']];

        // Act
        $without = self::draw(self::dir($theme) . '/presets_dialog.html.php', $vars);
        $with    = self::draw(self::dir($theme) . '/presets_dialog.html.php', ['organizations' => ['u7' => 'North'], 'managesPresets' => false] + $vars);

        // Assert
        $this->assertStringContainsString('data-pf-presets-tab="copy"', $without, 'an administrator copies to a user');
        $this->assertStringNotContainsString('data-pf-presets-tab="mine"', $without, 'not on one\'s own board');
        $this->assertStringContainsString('<option value="90">Administrator</option>', $without);
        $this->assertStringNotContainsString('value="organization"', $without, 'no organisations: no such choice');
        $this->assertStringContainsString('data-url="/people"', $without);
        $this->assertStringContainsString('value="organization">Members of: Utility</option>', $with, 'the application\'s own word');
        $this->assertStringContainsString('<option value="u7">North</option>', $with);
        $this->assertStringNotContainsString('data-pf-presets-tab="copy"', $with, 'not an administrator');
    }
}
