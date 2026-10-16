<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Dashboard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Dashboard\Layouts;
use Pramnos\Dashboard\Widget;
use Pramnos\Dashboard\WidgetRegistry;
use Pramnos\Dashboard\Widgets\Figures;
use Pramnos\Dashboard\Widgets\HealthChecks;

/** An application's widget, for everybody signed in, full width, not on a new dashboard. */
final class OrdersWidget extends Widget
{
    /** Its id. */
    public function id(): string { return 'orders'; }

    /** Its heading. */
    public function title(): string { return 'Orders'; }

    /** Its inside. */
    public function render(): string { return '<p>orders</p>'; }

    /** Anybody signed in. */
    public function allowed(?object $user): bool { return $user !== null; }

    /** Full width. */
    public function size(): string { return self::FULL; }

    /** Not on a new dashboard. */
    public function byDefault(): bool { return false; }
}

/** An application's widget with settings: a period (labelled), a market (a plain list), a free title. */
final class SalesWidget extends Widget
{
    /** Its id. */
    public function id(): string { return 'sales'; }

    /** Its heading. */
    public function title(): string { return 'Sales'; }

    /** Its inside, by the period chosen. */
    public function render(): string { return '<p>sales over ' . (string) $this->setting('period') . '</p>'; }

    /** Small. */
    public function size(): string { return self::SMALL; }

    /** What it shows. */
    public function description(): string { return 'Sales: the money in, by period.'; }

    /** What may be set. */
    public function options(): array
    {
        return [
            'period' => ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'],
            'market' => ['all', 'gr', 'cy'],
            'title'  => static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null,
            // A field: a date, labelled, shown only for the longest period
            'from'   => ['label' => 'From', 'input' => 'date', 'when' => ['period' => '90'],
                'clean' => static fn (mixed $v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null],
            // A field of values, labelled
            'chart'  => ['label' => 'Chart', 'values' => ['bar' => 'Bars', 'line' => 'Lines']],
        ];
    }
}

/** A widget whose rule throws: offered to nobody. */
final class BrokenRuleWidget extends Widget
{
    /** Its id. */
    public function id(): string { return 'broken'; }

    /** Its heading. */
    public function title(): string { return 'Broken'; }

    /** Its inside. */
    public function render(): string { return ''; }

    /** A rule that fails. */
    public function allowed(?object $user): bool { throw new \RuntimeException('no'); }
}

/**
 * The widgets a dashboard can show, and the layouts people keep of them.
 *
 * What holds: the framework's widgets come first, then app.php's, then the ones registered in
 * code — a later one under the same id replacing the earlier; `'framework' => false` leaves the
 * framework's out; a class that is not a widget is left out, not fatal; a widget's rule is the
 * only gate, and one that throws offers the widget to nobody; and a layout is cleaned of what
 * its owner may not have, of repeats and of unknown widths whenever it is read or written.
 */
#[CoversClass(WidgetRegistry::class)]
#[CoversClass(Layouts::class)]
#[CoversClass(Widget::class)]
#[CoversClass(HealthChecks::class)]
#[CoversClass(Figures::class)]
#[CoversClass(\Pramnos\Dashboard\Widgets\QueueStatus::class)]
#[CoversClass(\Pramnos\Dashboard\Widgets\EmailActivity::class)]
#[CoversClass(\Pramnos\Dashboard\Widgets\AiSpend::class)]
#[CoversClass(\Pramnos\Dashboard\Widgets\RecentErrors::class)]
class WidgetRegistryTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $info = [];

    protected function setUp(): void
    {
        $this->info = Application::getInstance()->applicationInfo;
        WidgetRegistry::reset();
    }

    protected function tearDown(): void
    {
        Application::getInstance()->applicationInfo = $this->info;
        WidgetRegistry::reset();
        Figures::forget();
    }

    /** A person of a given usertype. */
    private static function user(int $type): object
    {
        return (object) ['userid' => 7, 'usertype' => $type];
    }

    /** The framework's own, then the application's, then the registered; framework false drops the first. */
    public function testTheOrderAndTheSources(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['dashboard'] = ['widgets' => [OrdersWidget::class, \stdClass::class]];

        // Act
        $all = array_keys(WidgetRegistry::all());
        Application::getInstance()->applicationInfo['dashboard']['framework'] = false;
        $own = array_keys(WidgetRegistry::all());

        // Assert
        $this->assertSame(['users.active', 'api.performance', 'database', 'queue', 'email', 'messages', 'ai.spend', 'health', 'logs.errors', 'vitals', 'orders'], $all, 'a class that is not a widget is left out');
        $this->assertSame(['orders'], $own);
        $this->assertInstanceOf(OrdersWidget::class, WidgetRegistry::get('orders'));
        $this->assertNull(WidgetRegistry::get('nope'));
    }

    /**
     * `framework` as a list of ids takes only those of the framework's cards, in that order — an
     * application that wants the AI spend card need not take the developer tools with it — and an
     * id the framework does not have is left out, not fatal.
     */
    public function testTheFrameworksCardsChosenById(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['dashboard'] = ['framework' => ['logs.errors', 'nope', 'users.active'], 'widgets' => [OrdersWidget::class]];

        // Act
        $ids = array_keys(WidgetRegistry::all());

        // Assert
        $this->assertSame(['logs.errors', 'users.active', 'orders'], $ids);
    }

    /**
     * An application renames the ability a framework card asks for, and moves or removes where it
     * leads: a path in the area, an address as given, `''` for no link; a card it says nothing
     * about keeps the framework's.
     */
    public function testAbilitiesAndLinksAreTheApplications(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['dashboard'] = [
            'abilities' => ['email' => 'admin.logs.emails'],
            'links'     => ['ai.spend' => 'Settings/ai', 'email' => 'https://mail.test/log', 'logs.errors' => ''],
        ];

        // Act
        $ability = Figures::ability('email', 'admin.emails');
        $kept    = Figures::ability('queue', 'admin.queue');
        $moved   = (new \Pramnos\Dashboard\Widgets\AiSpend())->link();
        $away    = (new \Pramnos\Dashboard\Widgets\EmailActivity())->link();
        $none    = (new \Pramnos\Dashboard\Widgets\RecentErrors())->link();
        $default = (new \Pramnos\Dashboard\Widgets\QueueStatus())->link();

        // Assert
        $this->assertSame(['admin.logs.emails', 'admin.queue'], [$ability, $kept]);
        $this->assertSame(adminUrl('Settings/ai'), $moved);
        $this->assertSame('https://mail.test/log', $away);
        $this->assertNull($none);
        $this->assertSame(adminUrl('Queue'), $default);
    }

    /**
     * app.php decides about a card it did not write: `default` keeps it off (or on) a new
     * dashboard, `min_role` offers it only from a role up — on top of the card's own rule, which
     * it can raise and never lower; a card it says nothing about keeps its own.
     */
    public function testCardsAreTunedFromTheConfig(): void
    {
        // Arrange
        WidgetRegistry::register(OrdersWidget::class);
        Application::getInstance()->applicationInfo['dashboard'] = ['cards' => [
            'health' => ['default' => false, 'min_role' => 'root'],
            'orders' => ['default' => true],
            'database' => ['min_role' => 'manager'],
        ]];

        // Act
        $super = array_keys(WidgetRegistry::available(self::user(98)));
        $root  = array_keys(WidgetRegistry::available(self::user(99)));
        $staff = array_keys(WidgetRegistry::available(self::user(10)));
        $new   = array_column(Layouts::defaults(self::user(99)), 'id');

        // Assert
        $this->assertNotContains('health', $super, 'below the role app.php set');
        $this->assertContains('health', $root);
        $this->assertContains('database', $super);
        $this->assertNotContains('database', $staff, 'a lower min_role does not open a card its own rule closes');
        $this->assertContains('orders', $staff, 'its own rule: anybody signed in');
        $this->assertNotContains('health', $new, 'default false: not on a new dashboard');
        $this->assertContains('orders', $new, 'default true over the widget\'s own false');
        $this->assertContains('users.active', $new, 'nothing said: its own default');
    }

    /**
     * The framework's cards say their tile labels in the page's language: every label goes
     * through Figures::words(), which asks the translator.
     */
    public function testTheCardsWordsAreTranslated(): void
    {
        // Arrange
        \Pramnos\Translator\Language::setInstance(new class extends \Pramnos\Translator\Language {
            public function __construct()
            {
            }

            /** One word, in Greek. */
            public function _($string = '', $args = '')
            {
                return $string === 'Cost' ? 'Κόστος' : $string;
            }
        });

        try {
            // Act
            $word = Figures::words('Cost');
            $same = Figures::words('Calls');
        } finally {
            \Pramnos\Translator\Language::setInstance(null);
        }

        // Assert
        $this->assertSame(['Κόστος', 'Calls'], [$word, $same]);
        foreach (glob(dirname(__DIR__, 3) . '/src/Pramnos/Dashboard/Widgets/*.php') ?: [] as $file) {
            $this->assertDoesNotMatchRegularExpression("/StatTile::render\\('/", (string) file_get_contents($file), basename($file) . ': a label said in English only');
        }
    }

    /** A widget registered under a taken id replaces it, so an application can redraw a framework card. */
    public function testRegisteringATakenIdReplacesIt(): void
    {
        // Arrange
        $mine = new class extends Widget {
            /** The framework's id. */
            public function id(): string { return 'health'; }
            /** Its heading. */
            public function title(): string { return 'Our health'; }
            /** Its inside. */
            public function render(): string { return 'ours'; }
        };

        // Act
        WidgetRegistry::register($mine);
        WidgetRegistry::register(OrdersWidget::class);

        // Assert
        $this->assertSame('Our health', WidgetRegistry::get('health')->title());
        $this->assertInstanceOf(OrdersWidget::class, WidgetRegistry::get('orders'));
    }

    /**
     * Who may have what: the framework's widgets need the dashboard's level (80), an application's
     * says for itself, nobody signed in gets none, and a rule that throws offers its widget to nobody.
     */
    public function testAvailableFollowsEachWidgetsRule(): void
    {
        // Arrange
        WidgetRegistry::register(OrdersWidget::class);
        WidgetRegistry::register(BrokenRuleWidget::class);

        // Act & Assert
        $this->assertSame(['users.active', 'api.performance', 'database', 'health', 'orders'], array_keys(WidgetRegistry::available(self::user(90))));
        $this->assertSame(['orders'], array_keys(WidgetRegistry::available(self::user(10))));
        $this->assertSame([], array_keys(WidgetRegistry::available(null)));
    }

    /**
     * The cards that summarise a screen of their own — queue, email, AI spend, errors — are offered
     * only to whoever may open that screen, at the framework screens' floor (98), never at the
     * dashboard's (80); the queue's only where the queue is installed, the AI's only where a key is
     * set; and the errors card, which reads the log files, is not on a new dashboard.
     */
    public function testTheScreensCardsFollowTheirScreens(): void
    {
        // Arrange
        $super   = self::user(98);
        $manager = self::user(90);
        $cards   = [new \Pramnos\Dashboard\Widgets\QueueStatus(), new \Pramnos\Dashboard\Widgets\EmailActivity(), new \Pramnos\Dashboard\Widgets\AiSpend(), new \Pramnos\Dashboard\Widgets\RecentErrors()];

        // Act
        $forSuper   = array_map(static fn (Widget $w): bool => $w->allowed($super), $cards);
        $forManager = array_map(static fn (Widget $w): bool => $w->allowed($manager), $cards);

        // Assert
        $this->assertSame([\Pramnos\Application\FeatureRegistry::isEnabled('queue'), true, false, true], $forSuper, 'no key here: no AI spend card');
        $this->assertSame([false, false, false, false], $forManager, 'below the screens\' floor: none of them');
        $this->assertSame([true, true, true, false], array_map(static fn (Widget $w): bool => $w->byDefault(), $cards));
        $this->assertSame(['Queue', 'Emails', 'Ai/settings', 'logs'], array_map(static fn (Widget $w): string => (string) preg_replace('#^.*/(Queue|Emails|Ai/settings|logs)$#', '$1', (string) $w->link()), $cards));
        foreach ($cards as $card) {
            // Each says what it is and what it shows, in the catalogue
            $this->assertNotSame('', $card->title(), $card->id());
            $this->assertNotSame('', $card->description(), $card->id());
        }
    }

    /**
     * A new dashboard: what this person may have and is on by default, at its own width; the
     * application's card that is off by default is not there.
     */
    public function testTheDefaultLayout(): void
    {
        // Arrange
        WidgetRegistry::register(OrdersWidget::class);

        // Act
        $admin = Layouts::defaults(self::user(90));
        $staff = Layouts::defaults(self::user(10));

        // Assert
        $this->assertSame([
            ['id' => 'users.active', 'size' => 'm', 'settings' => []],
            ['id' => 'api.performance', 'size' => 'm', 'settings' => []],
            ['id' => 'database', 'size' => 'm', 'settings' => []],
            ['id' => 'health', 'size' => 'full', 'settings' => []],
        ], $admin);
        $this->assertSame([], $staff);
    }

    /**
     * A layout as sent is cleaned: an unknown widget, one the person may not have and a repeat are
     * dropped; an unknown width becomes the widget's own; an id alone is accepted; a non-list is
     * an empty layout.
     */
    public function testALayoutIsCleaned(): void
    {
        // Arrange
        WidgetRegistry::register(OrdersWidget::class);
        $sent = [
            ['id' => 'orders', 'size' => 'half'],
            ['id' => 'health', 'size' => 'enormous'],
            ['id' => 'orders', 'size' => 'full'],
            ['id' => 'nope', 'size' => 'half'],
            'database',
            42,
        ];

        // Act
        $admin = Layouts::clean($sent, self::user(90));
        $staff = Layouts::clean($sent, self::user(10));

        // Assert
        $this->assertSame([
            ['id' => 'orders', 'size' => 'm', 'settings' => []],
            ['id' => 'health', 'size' => 'full', 'settings' => []],
            ['id' => 'database', 'size' => 'm', 'settings' => []],
        ], $admin);
        $this->assertSame([['id' => 'orders', 'size' => 'm', 'settings' => []]], $staff, 'what they may not have is gone');
        $this->assertSame([], Layouts::clean('not a list', self::user(90)));
    }

    /** A widget's defaults: medium width, nothing to set, on a new dashboard, no link, and nobody signed out. */
    public function testAWidgetsDefaults(): void
    {
        // Arrange
        $widget = new class extends Widget {
            /** Its id. */
            public function id(): string { return 'plain'; }
            /** Its heading. */
            public function title(): string { return 'Plain'; }
            /** Its inside. */
            public function render(): string { return ''; }
        };

        // Assert
        $this->assertSame(Widget::MEDIUM, $widget->size());
        $this->assertSame([], $widget->options());
        $this->assertSame('', $widget->description());
        $this->assertNull($widget->configure(['anything' => 1])->setting('anything'), 'nothing declared, nothing kept');
        $this->assertTrue($widget->byDefault());
        $this->assertNull($widget->link());
        $this->assertFalse($widget->allowed(null));
        $this->assertFalse($widget->allowed(self::user(79)));
        $this->assertTrue($widget->allowed(self::user(80)));
    }

    /**
     * Four widths, the older `half` read as medium and anything else refused; and a card's
     * settings kept as its options allow — a list's value or its first, a function's answer, or
     * nothing when the function refuses — in a layout as in the widget itself.
     */
    public function testWidthsAndSettings(): void
    {
        // Arrange
        WidgetRegistry::register(SalesWidget::class);
        $user = self::user(90);

        // Act
        $sizes  = array_map([Widget::class, 'normalSize'], ['s', 'm', 'l', 'full', 'half', 'xl', null]);
        $chosen = Layouts::clean([['id' => 'sales', 'size' => 'l', 'settings' => ['period' => '30', 'market' => 'gr', 'title' => '  Ours  ', 'extra' => 'x']]], $user);
        $wrong  = Layouts::clean([['id' => 'sales', 'settings' => ['period' => '365', 'title' => '']]], $user);
        $none   = Layouts::clean([['id' => 'sales', 'settings' => 'not settings']], $user);
        $widget = (new SalesWidget())->configure(['period' => '90']);

        // Assert
        $this->assertSame(['s', 'm', 'l', 'full', 'm', null, null], $sizes);
        $this->assertSame([['id' => 'sales', 'size' => 'l', 'settings' => ['period' => '30', 'market' => 'gr', 'title' => 'Ours', 'chart' => 'bar']]], $chosen, 'undeclared dropped');
        $this->assertSame(['period' => '7', 'market' => 'all', 'chart' => 'bar'], $wrong[0]['settings'], 'an unknown value is the first; a refused one is absent');
        $this->assertSame('s', $wrong[0]['size'], 'the widget\'s own width');
        $this->assertSame(['period' => '7', 'market' => 'all', 'chart' => 'bar'], $none[0]['settings']);
        $this->assertSame('90', $widget->setting('period'));
        $this->assertSame('Sales: the money in, by period.', $widget->description());
    }

    /**
     * An option as a field: a list is a select of its values, a function a text field, a field
     * its own input, label and the settings it is shown for — and a date it refuses is absent.
     */
    public function testOptionsAsFields(): void
    {
        // Arrange
        $options = (new SalesWidget())->options();

        // Act
        $period = Widget::field($options['period']);
        $market = Widget::field($options['market']);
        $title  = Widget::field($options['title']);
        $from   = Widget::field($options['from']);
        $chart  = Widget::field($options['chart']);
        $kept   = Widget::cleanSettings(new SalesWidget(), ['from' => '2026-10-01', 'chart' => 'line']);
        $bad    = Widget::cleanSettings(new SalesWidget(), ['from' => 'yesterday', 'title' => '']);

        // Assert
        $this->assertSame(['select', null, ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'], []], [$period['input'], $period['label'], $period['values'], $period['when']]);
        $this->assertSame(['all' => 'all', 'gr' => 'gr', 'cy' => 'cy'], $market['values'], 'a plain list, valued by itself');
        $this->assertSame(['text', []], [$title['input'], $title['values']]);
        $this->assertSame(['date', 'From', ['period' => '90']], [$from['input'], $from['label'], $from['when']]);
        $this->assertSame(['select', 'Chart', ['bar' => 'Bars', 'line' => 'Lines']], [$chart['input'], $chart['label'], $chart['values']]);
        $this->assertSame(['2026-10-01', 'line'], [$kept['from'], $kept['chart']]);
        $this->assertArrayNotHasKey('from', $bad, 'a date it refuses');
        $this->assertArrayNotHasKey('title', $bad, 'an empty free value is no value');
    }

    /**
     * The health card lists each check with its status, the message only when it is not ok, all
     * escaped; with no checks it has nothing to show.
     */
    public function testTheHealthCard(): void
    {
        // Arrange — the figures as the health checks would answer
        $once = new \ReflectionProperty(Figures::class, 'once');
        $once->setValue(null, ['health' => [
            'cache'    => ['status' => 'ok', 'message' => 'fine'],
            'disk <1>' => ['status' => 'degraded', 'message' => 'Low on <space>'],
        ]]);

        // Act
        $html = (new HealthChecks())->render();
        $once->setValue(null, ['health' => []]);
        $none = (new HealthChecks())->render();

        // Assert
        $this->assertStringContainsString('<span class="pf-dashboard-health-name">cache</span><span class="pf-dashboard-status pf-dashboard-status--ok">OK</span>', $html);
        $this->assertStringContainsString('disk &lt;1&gt;', $html);
        $this->assertStringContainsString('<span class="pf-dashboard-health-message">Low on &lt;space&gt;</span>', $html);
        $this->assertStringNotContainsString('fine', $html, 'an ok check says nothing more');
        $this->assertSame('', $none);
        $this->assertSame(HealthChecks::FULL, (new HealthChecks())->size());
        $this->assertSame('1.5 MB', Figures::bytes(1572864));
        $this->assertSame('2.0 KB', Figures::bytes(2048));
    }
}
