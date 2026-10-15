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

    /** The framework's four, then the application's, then the registered; framework false drops the first. */
    public function testTheOrderAndTheSources(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['dashboard'] = ['widgets' => [OrdersWidget::class, \stdClass::class]];

        // Act
        $all = array_keys(WidgetRegistry::all());
        Application::getInstance()->applicationInfo['dashboard']['framework'] = false;
        $own = array_keys(WidgetRegistry::all());

        // Assert
        $this->assertSame(['users.active', 'api.performance', 'database', 'health', 'orders'], $all, 'a class that is not a widget is left out');
        $this->assertSame(['orders'], $own);
        $this->assertInstanceOf(OrdersWidget::class, WidgetRegistry::get('orders'));
        $this->assertNull(WidgetRegistry::get('nope'));
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
            ['id' => 'users.active', 'size' => 'half'],
            ['id' => 'api.performance', 'size' => 'half'],
            ['id' => 'database', 'size' => 'half'],
            ['id' => 'health', 'size' => 'full'],
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
            ['id' => 'orders', 'size' => 'half'],
            ['id' => 'health', 'size' => 'full'],
            ['id' => 'database', 'size' => 'half'],
        ], $admin);
        $this->assertSame([['id' => 'orders', 'size' => 'half']], $staff, 'what they may not have is gone');
        $this->assertSame([], Layouts::clean('not a list', self::user(90)));
    }

    /** A widget's defaults: half width, on a new dashboard, no link, and nobody signed out. */
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
        $this->assertSame(Widget::HALF, $widget->size());
        $this->assertTrue($widget->byDefault());
        $this->assertNull($widget->link());
        $this->assertFalse($widget->allowed(null));
        $this->assertFalse($widget->allowed(self::user(79)));
        $this->assertTrue($widget->allowed(self::user(80)));
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
