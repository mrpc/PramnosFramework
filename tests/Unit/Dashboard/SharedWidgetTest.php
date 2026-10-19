<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Dashboard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\DashboardController;
use Pramnos\Cache\Adapter\ArrayAdapter;
use Pramnos\Cache\Cache;
use Pramnos\Console\Commands\DashboardPrecompute;
use Pramnos\Dashboard\Widget;
use Pramnos\Dashboard\WidgetData;
use Pramnos\Dashboard\WidgetRegistry;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

/** A shared card whose figures are slow — counted — and drawn with the words of whoever looks. */
final class SlowSharedCard extends Widget
{
    /** How many times its figures were worked out, by any instance. */
    public static int $computed = 0;

    /** Whether working them out fails. */
    public static bool $fails = false;

    public function id(): string
    {
        return 'test.slow';
    }

    public function title(): string
    {
        return 'Slow';
    }

    public function shared(): bool
    {
        return true;
    }

    public function options(): array
    {
        return ['period' => ['7', '30'], 'title' => static fn (mixed $v): ?string => is_string($v) ? $v : null, 'chart' => ['bar', 'line']];
    }

    /** The figures: the period it was asked for, and how many times they were worked out. */
    public function data(): array
    {
        if (self::$fails) {
            throw new \RuntimeException('the source is down');
        }

        return ['period' => (int) $this->setting('period'), 'run' => ++self::$computed];
    }

    public function present(array $data): string
    {
        return 'period ' . $data['period'] . ', run ' . $data['run'];
    }

    /** The controllers its figures were worked out with. */
    public static array $controllers = [];

    /** Whether it spreads a setting into the other period as well, as a card of variants would. */
    public static bool $spreads = false;

    public function setController(?\Pramnos\Application\Controller $controller): static
    {
        self::$controllers[] = $controller;

        return parent::setController($controller);
    }

    public function precomputeSettings(array $settings): array
    {
        return self::$spreads ? [$settings, ['period' => $settings['period'] === '7' ? '30' : '7']] : parent::precomputeSettings($settings);
    }
}

/** An application's own dashboard controller, as app.php names it for the cards. */
final class AppDashboardController extends \Pramnos\Application\Controller
{
}

/** The dashboard's controller, without an application, with cardFor() reachable. */
final class SharedCardController extends DashboardController
{
    public function __construct()
    {
    }

    /** @param array{id: string, size: string, settings?: array<string, mixed>} $item */
    public function card_(Widget $widget, array $item): array
    {
        return $this->cardFor($widget, $item);
    }
}

/**
 * Shared dashboard cards (FW-167): a slow card's figures, the same for everybody who may see it,
 * worked out once and kept under the card's data key — by the first dashboard that needs them, or
 * ahead of anybody by `dashboard:precompute` — and drawn for each person from what was kept.
 */
#[CoversClass(Widget::class)]
#[CoversClass(WidgetData::class)]
#[CoversClass(DashboardController::class)]
#[CoversClass(DashboardPrecompute::class)]
final class SharedWidgetTest extends TestCase
{
    private ?array $savedInstances = null;

    protected function setUp(): void
    {
        $cache = new Cache();
        $cache->caching = true;
        (new \ReflectionProperty(Cache::class, 'adapter'))->setValue($cache, new ArrayAdapter());
        WidgetData::useCache($cache);
        SlowSharedCard::$computed    = 0;
        SlowSharedCard::$fails       = false;
        SlowSharedCard::$spreads     = false;
        SlowSharedCard::$controllers = [];
        WidgetRegistry::reset();
    }

    protected function tearDown(): void
    {
        WidgetData::useCache(null);
        WidgetRegistry::reset();
        if ($this->savedInstances !== null) {
            (new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances'))->setValue(null, $this->savedInstances);
        }
    }

    /** An application with these `dashboard` settings in app.php. */
    private function application(array $dashboard): void
    {
        $app = new class extends \Pramnos\Application\Application {
            public function __construct()
            {
            }
        };
        $app->applicationInfo = ['dashboard' => $dashboard];
        $ref = new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances');
        $this->savedInstances ??= $ref->getValue() ?? [];
        $ref->setValue(null, ['default' => $app]);
    }

    /**
     * The data key is the card and what its figures depend on: two people whose settings differ
     * only in the title or the chart share an entry; a different period is another one. A card
     * that draws itself is drawn by its render(); a shared one, by default, is present(data()).
     */
    public function testTheDataKeyAndTheDefaultRender(): void
    {
        // Arrange
        $a = (new SlowSharedCard())->configure(['period' => '30', 'title' => 'Mine', 'chart' => 'bar']);
        $b = (new SlowSharedCard())->configure(['period' => '30', 'title' => 'Theirs', 'chart' => 'line']);
        $c = (new SlowSharedCard())->configure(['period' => '7']);
        $plain = new class extends Widget {
            public function id(): string
            {
                return 'plain';
            }

            public function title(): string
            {
                return 'Plain';
            }
        };

        // Act + Assert
        $this->assertSame($a->dataKey(), $b->dataKey(), 'only the drawing differs');
        $this->assertNotSame($a->dataKey(), $c->dataKey());
        $this->assertStringStartsWith('test.slow:', $a->dataKey());
        $this->assertSame('period 30, run 1', $a->render(), 'render() is present(data())');
        $this->assertFalse($plain->shared());
        $this->assertSame([], $plain->data());
        $this->assertSame('', $plain->render(), 'a card that defines none of it draws nothing');
    }

    /**
     * The first read works the figures out and keeps them; the next, for anybody with the same
     * key, is the kept entry — when it was worked out included; a refresh works them out again;
     * an entry that is not one is worked out anew. How long it is kept is app.php's.
     */
    public function testTheDataIsWorkedOutOnceAndKept(): void
    {
        // Arrange
        $card = (new SlowSharedCard())->configure(['period' => '30']);

        // Act
        $first  = WidgetData::read($card);
        $again  = WidgetData::read((new SlowSharedCard())->configure(['period' => '30', 'title' => 'Other']));
        $fresh  = WidgetData::refresh($card);
        $cache  = new Cache();
        $cache->caching = true;
        (new \ReflectionProperty(Cache::class, 'adapter'))->setValue($cache, new ArrayAdapter());
        WidgetData::useCache($cache);
        $cache->save('not json', 'widget.' . $card->dataKey());
        $mended = WidgetData::read($card);
        $default = WidgetData::ttl();
        $this->application(['data_ttl' => 600]);

        // Assert
        $this->assertSame(['period' => 30, 'run' => 1], $first['data']);
        $this->assertEqualsWithDelta(time(), $first['at'], 2);
        $this->assertSame($first, $again, 'kept, and handed to the next');
        $this->assertSame(2, $fresh['data']['run'], 'a refresh works them out again');
        $this->assertSame(3, $mended['data']['run'], 'an entry that is not one is worked out anew');
        $this->assertSame(WidgetData::TTL, $default);
        $this->assertSame(600, WidgetData::ttl());
    }

    /**
     * The dashboard draws a shared card from the kept figures — not working them out per person —
     * and says when they were worked out; a card that draws itself has no such moment. A shared
     * card whose figures cannot be worked out is the short note, and no moment either.
     */
    public function testTheDashboardDrawsFromWhatWasKept(): void
    {
        // Arrange
        $controller = new SharedCardController();
        $item       = ['id' => 'test.slow', 'size' => 'm', 'settings' => ['period' => '7']];
        $plain      = new class extends Widget {
            public function id(): string
            {
                return 'plain';
            }

            public function title(): string
            {
                return 'Plain';
            }

            public function render(): string
            {
                return 'drawn now';
            }
        };

        // Act
        $one    = $controller->card_(new SlowSharedCard(), $item);
        $two    = $controller->card_(new SlowSharedCard(), $item);
        $drawn  = $controller->card_($plain, ['id' => 'plain', 'size' => 'm']);
        SlowSharedCard::$fails = true;
        $broken = $controller->card_(new SlowSharedCard(), ['id' => 'test.slow', 'size' => 'm', 'settings' => ['period' => '30']]);

        // Assert
        $this->assertSame('period 7, run 1', $one['html']);
        $this->assertSame('period 7, run 1', $two['html'], 'the second person gets the kept figures');
        $this->assertIsInt($one['at']);
        $this->assertSame('drawn now', $drawn['html']);
        $this->assertNull($drawn['at']);
        $this->assertStringContainsString('could not be drawn', $broken['html']);
        $this->assertNull($broken['at'], 'not the previous card\'s moment');
    }

    /**
     * `dashboard:precompute` works out every shared card once with its default settings and once
     * for each different setting somebody saved — a card nobody may have that is not shared is
     * left alone — and says how long each took. A card that fails is named and the command exits
     * 1; layouts that cannot be read leave the defaults, and say so.
     */
    public function testThePrecomputeCommand(): void
    {
        // Arrange
        WidgetRegistry::register(SlowSharedCard::class);
        $this->application(['framework' => false]);
        $command = static fn (?array $layouts): DashboardPrecompute => new class ($layouts) extends DashboardPrecompute {
            public function __construct(private ?array $given)
            {
                parent::__construct();
            }

            /** The layouts given, or a store that cannot be read. */
            protected function savedLayouts(string $area): array
            {
                return $this->given ?? throw new \RuntimeException('no table');
            }
        };
        $run = static function (DashboardPrecompute $command): array {
            $console = new ConsoleApplication();
            $console->add($command);
            $tester = new CommandTester($console->find('dashboard:precompute'));
            $code   = $tester->execute(['--area' => 'admin']);

            return [$code, $tester->getDisplay()];
        };
        $saved = [[['id' => 'test.slow', 'settings' => ['period' => '30', 'title' => 'A']], ['id' => 'unknown']], [['id' => 'test.slow', 'settings' => ['period' => '30', 'chart' => 'line']]]];

        // Act
        [$code, $display] = $run($command($saved));
        $computed = SlowSharedCard::$computed;
        $kept = WidgetData::read((new SlowSharedCard())->configure(['period' => '30']));
        [$noTable, $noTableDisplay] = $run($command(null));
        SlowSharedCard::$fails = true;
        [$failed, $failedDisplay] = $run($command([]));

        // Assert
        $this->assertSame(0, $code);
        $this->assertSame(2, $computed, 'the default (7) and the one saved setting (30), each once');
        $this->assertStringContainsString('2 card(s) worked out.', $display);
        $this->assertSame(2, $kept['data']['run'], 'what the dashboard then reads');
        $this->assertSame(0, $noTable);
        $this->assertStringContainsString('could not be read, the defaults only', $noTableDisplay);
        $this->assertSame(1, $failed);
        $this->assertStringContainsString('the source is down', $failedDisplay);
        $this->assertStringContainsString('0 card(s) worked out, 1 failed.', $failedDisplay);
    }

    /**
     * The framework's slow cards are shared, and each draws from figures it is handed — without
     * reading anything itself; an unavailable source is an empty card. (The messages card, whose
     * channels need a database, is in Integration\Dashboard\MessagesTest.)
     */
    public function testTheFrameworksSlowCardsAreShared(): void
    {
        // Arrange
        $cards = [
            new \Pramnos\Dashboard\Widgets\RecentErrors(),
            new \Pramnos\Dashboard\Widgets\AiSpend(),
            new \Pramnos\Dashboard\Widgets\Messages(),
            new \Pramnos\Dashboard\Widgets\ActiveUsers(),
            new \Pramnos\Dashboard\Widgets\DatabaseOverview(),
            new \Pramnos\Dashboard\Widgets\QueueStatus(),
            new \Pramnos\Dashboard\Widgets\ApiPerformance(),
        ];
        // Act
        $errors   = $cards[0]->present(['count' => 2, 'top' => [['message' => 'Disk <full>', 'count' => 2]]]);
        $spend    = $cards[1]->configure([])->present(['cost' => 1.5, 'tokens' => 900, 'calls' => 10, 'failed' => 1]);
        $noSpend  = $cards[1]->present(['available' => false]);
        $queue    = $cards[5]->configure([])->present(['by_status' => ['pending' => 3], 'failed' => [['count' => 1]], 'completed' => 7]);
        $noQueue  = $cards[5]->present(['available' => false]);
        $users    = $cards[3]->present(['now' => 5, 'last_24h' => 9, 'last_7d' => 30]);
        $api      = $cards[6]->present(['total_requests' => 100, 'error_rate' => 0.02]);

        // Assert
        foreach ($cards as $card) {
            $this->assertTrue($card->shared(), $card->id() . ' is shared');
        }
        $this->assertStringContainsString('Disk &lt;full&gt;', $errors, 'escaped when drawn');
        $this->assertStringContainsString('$1.50', $spend);
        $this->assertSame('', $noSpend);
        $this->assertStringContainsString('3', $queue);
        $this->assertSame('', $noQueue);
        $this->assertStringContainsString('30', $users);
        $this->assertStringContainsString('2.0%', $api);
    }

    /**
     * Precomputing is the application's (FW-170): `--app` makes that application current for the
     * run — its cards, not the console's — and puts the console's back after. Each card is handed
     * a controller of it, as the dashboard hands its own; and a card's own variants of a setting,
     * nobody having saved them, are worked out too.
     */
    public function testPrecomputingIsTheApplicationsWithItsVariants(): void
    {
        // Arrange — the console's application has no cards of its own; another one has
        $this->application(['framework' => false]);
        $console = \Pramnos\Application\Application::currentInstance();
        $other   = new class extends \Pramnos\Application\Application {
            public function __construct()
            {
            }
        };
        $other->applicationInfo = ['dashboard' => ['framework' => false, 'widgets' => [SlowSharedCard::class], 'controller' => AppDashboardController::class]];
        $ref = new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances');
        $ref->setValue(null, $ref->getValue() + ['backoffice' => $other]);
        SlowSharedCard::$spreads = true;
        $command = new class extends DashboardPrecompute {
            protected function savedLayouts(string $area): array
            {
                return [];
            }
        };
        $consoleApp = new ConsoleApplication();
        $consoleApp->add($command);
        $tester = new CommandTester($consoleApp->find('dashboard:precompute'));

        // Act
        $without = $tester->execute([]);
        $plain   = $tester->getDisplay();
        $with    = $tester->execute(['--app' => 'backoffice']);
        $display = $tester->getDisplay();

        // Assert
        $this->assertSame(0, $without);
        $this->assertStringContainsString('0 card(s) worked out.', $plain, 'the console\'s application has none');
        $this->assertSame(0, $with);
        $this->assertStringContainsString('2 card(s) worked out.', $display, 'the default (7) and its variant (30)');
        $this->assertSame(2, SlowSharedCard::$computed);
        $this->assertNotEmpty(SlowSharedCard::$controllers);
        $this->assertContainsOnlyInstancesOf(AppDashboardController::class, SlowSharedCard::$controllers, 'each card has the application\'s own (FW-173)');
        $this->assertSame($console, \Pramnos\Application\Application::currentInstance(), 'the console\'s application is current again');
    }
}

