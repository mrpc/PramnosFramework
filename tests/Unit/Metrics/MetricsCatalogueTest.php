<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Metrics\Catalogue;
use Pramnos\Metrics\Dimension;
use Pramnos\Metrics\Metric;
use Pramnos\Metrics\Metrics;
use Pramnos\Metrics\Window;

/**
 * The catalogue without a database: app.php's catalogues and what is registered in code, who
 * sees what, the grains kept, and where sources read.
 */
#[CoversClass(Metrics::class)]
class MetricsCatalogueTest extends TestCase
{
    private array $info;

    protected function setUp(): void
    {
        $this->info = Application::getInstance()->applicationInfo;
        Metrics::reset();
    }

    protected function tearDown(): void
    {
        Application::getInstance()->applicationInfo = $this->info;
        Metrics::reset();
    }

    /**
     * app.php's catalogues are read once: their metrics and dimensions; one that is not a
     * Catalogue is left out; a metric registered in code wins over app.php's of the same name.
     */
    public function testTheCatalogueIsAppsAndCodes(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['metrics'] = ['catalogue' => [CatalogueFixture::class, \stdClass::class, 'No\\Such\\Class']];
        Metrics::register(Metric::flow('orders.count', 'From code'));

        // Act
        $all = Metrics::all();

        // Assert
        $this->assertSame(['orders.count', 'customers.active'], array_keys($all));
        $this->assertSame('From code', $all['orders.count']->label());
        $this->assertSame('Region', Metrics::dimensionOf('region')->label);
        $this->assertSame('plan', Metrics::dimensionOf('plan')->label, 'never declared: its name');
        $this->assertSame(['region'], array_keys(Metrics::dimensions()));
        $this->assertSame('Active customers', Metrics::get('customers.active')->label());
        $this->expectException(\InvalidArgumentException::class);
        Metrics::get('nothing.here');
    }

    /** Who sees what: those their allowed() lets them. */
    public function testWhoSeesWhat(): void
    {
        // Arrange
        Metrics::register(
            Metric::flow('orders.count'),
            Metric::flow('orders.public')->allowed(static fn (?object $u): bool => true),
        );

        // Act & Assert
        $this->assertSame(['orders.public'], array_keys(Metrics::all(null, false)));
        $this->assertCount(2, Metrics::all((object) ['usertype' => 99], false));
    }

    /** The grains kept: app.php's, in the grains' order, unknown ones dropped; all of them otherwise. */
    public function testTheGrainsKept(): void
    {
        // Act & Assert
        $this->assertSame(Window::GRAINS, Metrics::grains());
        Application::getInstance()->applicationInfo['metrics'] = ['grains' => ['month', 'day', 'hour']];
        $this->assertSame(['day', 'month'], Metrics::grains());
        Application::getInstance()->applicationInfo['metrics'] = ['grains' => ['hour']];
        $this->assertSame(Window::GRAINS, Metrics::grains(), 'nothing it knows: all of them');
    }

    /**
     * With `replica` on and no replica configured, sources read the database itself rather than
     * nothing — the drill-down's rows still come.
     */
    public function testWithoutAReplicaSourcesReadTheDatabase(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['metrics'] = ['replica' => true];
        $given = null;
        Metrics::register(Metric::flow('orders.count')->rows(static function (Window $w, array $f, \Pramnos\Database\Database $db) use (&$given): array {
            $given = $db;

            return [['id' => 1]];
        }));

        // Act
        $rows = Metrics::records('orders.count', 'day', '2026-09-17');

        // Assert
        $this->assertSame([['id' => 1]], $rows);
        $this->assertSame(\Pramnos\Database\Database::getInstance(), $given);
    }

    /**
     * A replica of the metrics' own — `['hostname' => …, 'port' => …, 'timeout' => …]` — is the one
     * the sources are given; one that does not answer within its timeout is given up in seconds,
     * and the database read instead, its own wait as it was.
     */
    public function testAReplicaOfTheMetricsOwn(): void
    {
        // Arrange
        $db = \Pramnos\Database\Database::getInstance();
        try {
            if (!$db->connected && !$db->connect(false)) {
                $this->markTestSkipped('The database is not reachable.');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('The database is not reachable: ' . $e->getMessage());
        }
        $given = [];
        Metrics::register(Metric::flow('orders.count')->rows(static function (Window $w, array $f, \Pramnos\Database\Database $db) use (&$given): array {
            $given[] = $db;

            return [['id' => 1]];
        }));

        // Act
        Application::getInstance()->applicationInfo['metrics'] = ['replica' => ['hostname' => $db->server, 'port' => (int) $db->port]];
        Metrics::records('orders.count', 'day', '2026-09-17');
        Application::getInstance()->applicationInfo['metrics'] = ['replica' => ['hostname' => '10.255.255.1', 'port' => (int) $db->port, 'timeout' => 2]];
        $started = microtime(true);
        Metrics::records('orders.count', 'day', '2026-09-17');
        $took = microtime(true) - $started;

        // Assert
        $this->assertNotSame($db, $given[0], 'its own replica');
        $this->assertSame($db->server, $given[0]->server);
        $this->assertSame($db, $given[1], 'not answering: the database itself');
        $this->assertLessThan(10, $took, 'within its timeout');
        $this->assertNull($db->connectTimeout, 'the database\'s own wait, as it was');
    }

    /** A derived metric worked out from itself is refused rather than looping. */
    public function testADerivedLoopIsRefused(): void
    {
        // Arrange
        Metrics::register(Metric::derived('loop.a', 'loop.b + 1'), Metric::derived('loop.b', 'loop.a * 2'));

        // Assert
        $this->expectException(\LogicException::class);

        // Act
        Metrics::value('loop.a', 'month', '2026-09-01');
    }
}

/** A catalogue as an application writes one. */
final class CatalogueFixture implements Catalogue
{
    public function metrics(): iterable
    {
        yield Dimension::of('region', 'Region');
        yield Metric::flow('orders.count', 'From app.php');
        yield Metric::level('customers.active', 'Active customers');
        yield 'not a metric';
    }
}
