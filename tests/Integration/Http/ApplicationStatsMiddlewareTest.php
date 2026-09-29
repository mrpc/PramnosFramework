<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Framework\Testing\Schema;
use Pramnos\Http\Middleware\ApplicationStatsMiddleware;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Http\TooManyRequestsException;

/**
 * Rows in `applications.application_stats`, which nothing used to write.
 *
 * The table is pre-aggregated — one row per application per minute, unique on `(time, appid)` —
 * so what matters is that several requests become one row whose counters add up, on both
 * backends: the upsert is `ON DUPLICATE KEY UPDATE` on one and `ON CONFLICT DO UPDATE` on the
 * other, and they evaluate the update differently.
 */
#[CoversClass(ApplicationStatsMiddleware::class)]
class ApplicationStatsMiddlewareTest extends BaseTestCase
{
    private $db;

    private int $appId = 0;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        Schema::table('applications', $this->db);
        $this->runMigrations([
            \Pramnos\Framework\Migrations\AuthServer\CreateApplicationsSchema::class,
            \Pramnos\Framework\Migrations\Applications\CreateApplicationSettingsTable::class,
            \Pramnos\Framework\Migrations\Applications\CreateApplicationStatsTable::class,
        ], $this->db);

        $name = 'Stats client ' . bin2hex(random_bytes(4));
        $this->db->queryBuilder()->table('applications')->insert(['name' => $name, 'status' => 1]);
        $this->appId = (int) $this->db->queryBuilder()->table('applications')
            ->select(['appid'])->where('name', $name)->first()->fields['appid'];
        unset($_SERVER['CONTENT_LENGTH']);
    }

    protected function tearDown(): void
    {
        $this->db->queryBuilder()->table('applications.application_stats')->where('appid', $this->appId)->delete();
        $this->db->queryBuilder()->table('applications')->where('appid', $this->appId)->delete();
        unset($_SERVER['CONTENT_LENGTH']);

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    /** A recorder that attributes every request to the fixture application. */
    private function recorder(): ApplicationStatsMiddleware
    {
        return new ApplicationStatsMiddleware(fn (string $key): int => $this->appId, $this->db);
    }

    /** @return list<array<string, mixed>> The fixture application's rows. */
    private function rows(): array
    {
        $result = $this->db->queryBuilder()->table('applications.application_stats')
            ->where('appid', $this->appId)->get();
        $rows = [];
        while ($result->fetch()) {
            $rows[] = (array) $result->fields;
        }

        return $rows;
    }

    /**
     * Three requests in one minute are one row whose counters add up.
     *
     * A 200, a 404 and a 500: the status buckets, successes and failures, bytes, and a running
     * average that has to read `total_requests` before it is incremented — the part MySQL and
     * PostgreSQL evaluate differently.
     */
    public function testRequestsInOneMinuteAddUpInOneRow(): void
    {
        // Arrange
        $recorder = $this->recorder();
        $request  = $this->createMock(Request::class);
        $_SERVER['CONTENT_LENGTH'] = '10';
        // Clear of a minute boundary, which would legitimately split the three into two rows.
        if ((int) date('s') >= 58) {
            sleep(3);
        }

        // Act
        $recorder->handle($request, static fn () => Response::json(['a' => 1], 200));
        $recorder->handle($request, static fn () => Response::json(['error' => 'x'], 404));
        $recorder->handle($request, static fn () => Response::json(['error' => 'y'], 500));

        // Assert
        $rows = $this->rows();
        $this->assertCount(1, $rows, 'requests in one minute were not folded into one row');
        $row = $rows[0];
        $this->assertSame(3, (int) $row['total_requests']);
        $this->assertSame(1, (int) $row['successful_requests']);
        $this->assertSame(2, (int) $row['failed_requests']);
        $this->assertSame([1, 0, 1, 1], [
            (int) $row['status_2xx'], (int) $row['status_3xx'], (int) $row['status_4xx'], (int) $row['status_5xx'],
        ]);
        $this->assertSame(30, (int) $row['bytes_received']);
        $this->assertGreaterThan(0, (int) $row['bytes_sent']);
        $this->assertLessThanOrEqual((float) $row['max_response_time'], (float) $row['avg_response_time']);
        $this->assertGreaterThanOrEqual((float) $row['min_response_time'], (float) $row['avg_response_time']);
    }

    /**
     * A rate-limit refusal is counted as one, and still reaches the client.
     */
    public function testARateLimitRefusalIsCountedAndRethrown(): void
    {
        // Arrange
        $recorder = $this->recorder();
        $thrown   = null;

        // Act
        try {
            $recorder->handle($this->createMock(Request::class), static function (): never {
                throw new TooManyRequestsException('slow down', 30);
            });
        } catch (TooManyRequestsException $exception) {
            $thrown = $exception;
        }

        // Assert
        $this->assertNotNull($thrown, 'the refusal was swallowed');
        $row = $this->rows()[0] ?? [];
        $this->assertSame(1, (int) ($row['rate_limited_requests'] ?? 0));
        $this->assertSame(1, (int) ($row['status_4xx'] ?? 0));
    }

    /**
     * Any other failure is counted by its code, or as a 500, and re-thrown.
     */
    public function testAFailureIsCountedByItsCode(): void
    {
        // Arrange
        $recorder = $this->recorder();

        // Act
        foreach ([new \RuntimeException('boom'), new \Exception('gone', 404)] as $failure) {
            try {
                $recorder->handle($this->createMock(Request::class), static fn () => throw $failure);
            } catch (\Throwable) {
                // Re-thrown, as asserted by the count below reaching both.
            }
        }

        // Assert
        $row = $this->rows()[0] ?? [];
        $this->assertSame(2, (int) ($row['failed_requests'] ?? 0));
        $this->assertSame([1, 1], [(int) $row['status_4xx'], (int) $row['status_5xx']]);
    }

    /**
     * A request with no application behind it writes nothing, and a broken write breaks nothing.
     *
     * The site's own key, or a request with none, is the application calling itself. And a write
     * that fails must not turn a served request into an error.
     */
    public function testNoApplicationAndABrokenWriteAreSilent(): void
    {
        // Arrange
        $unattributed = new ApplicationStatsMiddleware(static fn (): int => 0, $this->db);
        $broken       = new ApplicationStatsMiddleware(static fn (): int => throw new \RuntimeException('down'), $this->db);

        // Act
        $first  = $unattributed->handle($this->createMock(Request::class), static fn (): string => 'served');
        $second = $broken->handle($this->createMock(Request::class), static fn (): array => ['served']);

        // Assert
        $this->assertSame('served', $first);
        $this->assertSame(['served'], $second);
        $this->assertSame([], $this->rows());
    }

    /**
     * The default resolver finds the application by its key, and finds none for no key.
     */
    public function testTheDefaultResolverReadsTheApplicationsTable(): void
    {
        // Arrange
        $key = bin2hex(random_bytes(16));
        $this->db->queryBuilder()->table('applications')->where('appid', $this->appId)->update(['apikey' => $key]);

        // Act & Assert
        $this->assertSame($this->appId, ApplicationStatsMiddleware::applicationIdOf($key));
        $this->assertSame(0, ApplicationStatsMiddleware::applicationIdOf(''));
    }
}
