<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Logs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\ClientVitals as ClientVitalsController;
use Pramnos\Dashboard\Widgets\WebVitals;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Logs\ClientVitals;

/**
 * Core Web Vitals from real visitors, kept and read back — on MySQL and PostgreSQL.
 *
 * What holds: a page view's report is kept one row per metric, with its page group from
 * `vitals.groups`, its device, delivery and service worker, and whether somebody was signed in —
 * the server decides that; what is not a metric, or not a number, is left out, and what is not
 * one of the known values is kept empty; the summary is the 75th percentile per page group and
 * device, rated by web.dev's thresholds; old rows go; the endpoint keeps a same-origin POST and
 * refuses the rest; nothing is printed or offered until a sample is set; and the dashboard card
 * draws the table.
 */
#[CoversClass(ClientVitals::class)]
#[CoversClass(ClientVitalsController::class)]
#[CoversClass(WebVitals::class)]
class ClientVitalsTest extends TestCase
{
    private ?Database $db = null;

    private ?Database $previous = null;

    /** @var array<string, mixed> */
    private array $info = [];

    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306],
            'postgresql' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    protected function setUp(): void
    {
        $this->info = Application::getInstance()->applicationInfo;
    }

    protected function tearDown(): void
    {
        Application::getInstance()->applicationInfo = $this->info;
        if ($this->db !== null) {
            $this->db->schema()->dropTableIfExists('client_vitals');
        }
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_HOST']);
    }

    /** A connection made the framework's, with a fresh client_vitals; the config given. */
    private function connect(string $type, string $host, int $port, array $vitals = []): void
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $db;
        $this->db       = $db;
        $db->schema()->dropTableIfExists('client_vitals');
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $db;
        (new \Pramnos\Framework\Migrations\Core\CreateClientVitalsTable($app))->up();
        Application::getInstance()->applicationInfo['vitals'] = $vitals;
    }

    /** One page view's report. */
    private static function report(string $path, array $metrics, array $more = []): array
    {
        $list = [];
        foreach ($metrics as $name => $value) {
            $list[] = ['name' => $name, 'value' => $value, 'rating' => 'good', 'navigation' => 'navigate'];
        }

        return $more + ['metrics' => $list, 'path' => $path, 'device' => 'mobile', 'delivery' => 'navigational-prefetch',
            'worker' => 'controlled', 'workerMs' => 12, 'source' => 'preload'];
    }

    /**
     * A report is kept one row per metric, with what it depends on and the page group its path
     * matched; what is not a metric or not a number is left out, and an unknown device, worker
     * or rating is kept empty.
     */
    #[DataProvider('databases')]
    public function testAReportIsKeptPerMetric(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port, ['sample' => 1, 'groups' => ['jobs' => '#^/(el|en)/jobs#', 'bad' => '#[#']]);
        $vitals = new ClientVitals();

        // Act
        $kept  = $vitals->record(self::report('/el/jobs/42', ['LCP' => 2100, 'CLS' => 0.031, 'FID' => 5, 'INP' => 'fast']), true);
        $other = $vitals->record(self::report('/about', ['LCP' => 900], ['device' => 'fridge', 'worker' => 'yes', 'source' => '<b>x</b>']), false);
        $rows  = $this->db->queryBuilder()->table('#PREFIX#client_vitals')->orderBy('id')->get();
        $all   = [];
        while (($row = $rows->fetch()) !== null) {
            $all[] = $row;
        }

        // Assert
        $this->assertSame([2, 1], [$kept, $other]);
        $this->assertSame(['LCP', 'CLS', 'LCP'], array_column($all, 'metric'));
        $this->assertSame(['jobs', 'jobs', 'other'], array_column($all, 'page_group'));
        $this->assertEqualsWithDelta(0.031, (float) $all[1]['value'], 0.0001);
        $this->assertSame(['mobile', 'navigational-prefetch', 'controlled', 12, 'preload'], [$all[0]['device'], $all[0]['delivery'], $all[0]['worker'], (int) $all[0]['worker_ms'], $all[0]['source']]);
        $this->assertTrue(in_array($all[0]['logged_in'], [true, 1, '1', 't'], true));
        $this->assertSame(['', '', 'bxb'], [$all[2]['device'], $all[2]['worker'], $all[2]['source']], 'unknown values kept empty, text cleaned');
    }

    /**
     * The summary is the 75th percentile per page group and device, rated by the thresholds, from
     * the last days only; old rows are pruned.
     */
    #[DataProvider('databases')]
    public function testTheSummaryIsTheSeventyFifthPercentile(string $type, string $host, int $port): void
    {
        // Arrange — four mobile views of the jobs pages, and one from a month ago
        $this->connect($type, $host, $port, ['sample' => 1, 'groups' => ['jobs' => '#^/jobs#'], 'keep_days' => 14]);
        $vitals = new ClientVitals();
        foreach ([1000, 2000, 3000, 5000] as $lcp) {
            $vitals->record(self::report('/jobs', ['LCP' => $lcp, 'INP' => 600]), false);
        }
        $vitals->record(self::report('/jobs', ['LCP' => 99999]), false);
        $this->db->queryBuilder()->table('#PREFIX#client_vitals')->where('value', 99999)->update(['created_at' => time() - 30 * 86400]);

        // Act
        $summary = $vitals->summary();
        $pruned  = $vitals->prune();

        // Assert
        $this->assertSame(['p75' => 3000.0, 'count' => 4, 'rating' => 'needs-improvement'], $summary['jobs']['mobile']['LCP']);
        $this->assertSame('poor', $summary['jobs']['mobile']['INP']['rating']);
        $this->assertSame(1, $pruned, 'the month-old row');
        $this->assertSame(['good', 'needs-improvement', 'poor', 'good'], [ClientVitals::rate('CLS', 0.1), ClientVitals::rate('CLS', 0.2), ClientVitals::rate('TTFB', 2000), ClientVitals::rate('XYZ', 5)]);
    }

    /**
     * The endpoint keeps a POST from this site's pages, and answers 204 whatever — signed in or
     * not decided by the session; a GET, another site's POST, or a body too large is refused
     * and keeps nothing.
     */
    #[DataProvider('databases')]
    public function testTheEndpoint(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port, ['sample' => 1]);
        $endpoint = new class (self::report('/x', ['TTFB' => 300])) extends ClientVitalsController {
            public function __construct(private array $body)
            {
            }

            /** The body the browser sent. */
            protected function report(): array
            {
                return $this->body;
            }

            /** No limit in a test. */
            protected function overLimit(): bool
            {
                return false;
            }
        };
        $status = static fn ($response): int => $response->getStatusCode();

        // Act
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $get = $status($endpoint->display());
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_HOST']      = 'site.test';
        $_SERVER['HTTP_ORIGIN']    = 'https://evil.test';
        $foreign = $status($endpoint->display());
        $_SERVER['HTTP_ORIGIN']    = 'https://site.test';
        $_SERVER['CONTENT_LENGTH'] = (string) (ClientVitalsController::MAX_BODY + 1);
        $large = $status($endpoint->display());
        $_SERVER['CONTENT_LENGTH'] = '100';
        $kept = $status($endpoint->display());
        unset($_SERVER['CONTENT_LENGTH']);
        $this->db->schema()->dropTableIfExists('client_vitals');
        $noTable = $status($endpoint->display());

        // Assert
        $this->assertSame([405, 403, 413, 204, 204], [$get, $foreign, $large, $kept, $noTable]);
        $this->connect($type, $host, $port, ['sample' => 1]);
        $this->assertSame(0, $this->db->queryBuilder()->table('#PREFIX#client_vitals')->count(), 'a fresh table');
    }

    /**
     * Nothing is printed, and the card is not offered, until a sample is set; then the page gets
     * the meta with the endpoint and the sample, the library and the script; and the card draws
     * the table — or says nothing was measured.
     */
    #[DataProvider('databases')]
    public function testTheTagAndTheCard(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port, []);
        $root = (object) ['userid' => 7, 'usertype' => 99];
        $card = new WebVitals();

        // Act
        $off      = [ClientVitals::tag(), $card->allowed($root)];
        Application::getInstance()->applicationInfo['vitals'] = ['sample' => 2];
        $tag      = ClientVitals::tag();
        $offered  = $card->allowed($root);
        $empty    = $card->render();
        (new ClientVitals())->record(self::report('/p', ['LCP' => 1800, 'CLS' => 0.3]), false);
        $drawn    = $card->render();

        // Assert
        $this->assertSame(['', false], $off);
        $this->assertStringContainsString('<meta name="pf-vitals" content="' . sURL . 'ClientVitals" data-sample="1">', $tag, 'the sample capped at 1');
        $this->assertStringContainsString('web-vitals.attribution.iife.js', $tag);
        $this->assertStringContainsString('assets/js/pf-vitals.js', $tag);
        $this->assertTrue($offered);
        $this->assertFalse($card->byDefault());
        $this->assertSame(['vitals', WebVitals::LARGE], [$card->id(), $card->size()]);
        $this->assertNotSame('', $card->title() . $card->description());
        $this->assertStringContainsString('Nothing measured yet', $empty);
        $this->assertStringContainsString('pf-dashboard-status--good', $drawn, 'LCP 1,800 ms');
        $this->assertStringContainsString('pf-dashboard-status--poor', $drawn, 'CLS 0.30');
        $this->assertStringContainsString('<td>—</td>', $drawn, 'INP not measured');
    }
}
