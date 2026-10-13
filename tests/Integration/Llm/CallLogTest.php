<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Llm\AddTokensToLlmCalls;
use Pramnos\Framework\Migrations\Llm\CreateLlmCallsTable;
use Pramnos\Llm\CallLog;
use Pramnos\Llm\Pricing;

/**
 * `llm_calls` for real, on MySQL and PostgreSQL.
 *
 * The summaries are what an administrator reads to answer "what is this costing", so they are
 * asserted against rows written by the log itself — the failures and the unpriced calls
 * included, because a total that silently leaves them out is the figure that misleads.
 */
#[CoversClass(CallLog::class)]
#[CoversClass(CreateLlmCallsTable::class)]
#[CoversClass(AddTokensToLlmCalls::class)]
#[CoversClass(\Pramnos\Console\Commands\LlmPrune::class)]
class CallLogTest extends TestCase
{
    private Database $db;

    private ?Database $previousSingleton = null;

    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306],
            'postgresql' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    /** Connect, make the database the framework's, and build the table from its migration. */
    private function connect(string $type, string $host, int $port): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        $this->db           = new Database();
        $this->db->type     = $type;
        $this->db->server   = $host;
        $this->db->port     = $port;
        $this->db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $this->db->password = 'secret';
        $this->db->database = TEST_DATABASE;

        try {
            if (!$this->db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }

        $this->previousSingleton = Factory::getDatabase();
        $singleton               = &Factory::getDatabase();
        $singleton               = $this->db;

        $this->migration(CreateLlmCallsTable::class)->down();
        $this->migration(CreateLlmCallsTable::class)->up();
        Settings::setSetting('llm_log', '1', false);
    }

    /** A migration over this test's database. */
    private function migration(string $class): \Pramnos\Database\Migration
    {
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $this->db;

        return new $class($app);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->connected) {
            $this->db->schema()->dropTableIfExists('llm_calls');
        }
        if ($this->previousSingleton !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previousSingleton;
        }
        Settings::setSetting('llm_log', '1', false);
    }

    /**
     * A call is written with its cost frozen and its tokens counted, and read back in full.
     */
    #[DataProvider('databases')]
    public function testACallIsRecordedAndReadBack(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $log   = new CallLog(new Pricing(['m' => ['cache_hit' => 0, 'cache_miss' => 1, 'output' => 2]]));
        $usage = ['prompt_tokens' => 1000, 'completion_tokens' => 500, 'total_tokens' => 1500];

        // Act
        $log->record([
            'purpose' => 'chat', 'provider' => 'openai', 'model' => 'm', 'userid' => 7, 'organization_id' => 3,
            'messages' => [['role' => 'user', 'content' => 'Γεια']], 'usage' => $usage,
            'http_status' => 200, 'finish_reason' => 'stop', 'reply' => 'Hello', 'duration_ms' => 120,
            'reasoning' => false, 'temperature' => 0.7, 'max_tokens' => 2000,
        ]);
        $row = $log->recent(1)[0];

        // Assert — 1,000 × $1 + 500 × $2 per million
        $this->assertEqualsWithDelta(0.002, (float) $row['cost'], 1e-9);
        $this->assertSame(1500, (int) $row['tokens']);
        $this->assertSame($usage, $row['usage']);
        $this->assertNull($row['error'], 'a good call has no error');
        $full = $log->find((int) $row['call_id']);
        $this->assertSame('Γεια', $full['messages'][0]['content']);
        $this->assertNull($log->find(999999));
    }

    /**
     * The summaries count the failures and the unpriced calls, not only the good ones.
     */
    #[DataProvider('databases')]
    public function testTheSummariesCountEverything(string $type, string $host, int $port): void
    {
        // Arrange — a priced call, an unpriced one, a refused one, one that never returned
        $this->connect($type, $host, $port);
        $log   = new CallLog(new Pricing(['m' => ['cache_hit' => 0, 'cache_miss' => 1, 'output' => 1]]));
        $usage = ['prompt_tokens' => 1_000_000, 'completion_tokens' => 0, 'total_tokens' => 1_000_000];
        $log->record(['provider' => 'openai', 'model' => 'm', 'usage' => $usage, 'http_status' => 200, 'duration_ms' => 100]);
        $log->record(['provider' => 'deepseek', 'model' => 'unpriced', 'usage' => $usage, 'http_status' => 200, 'duration_ms' => 300]);
        $log->record(['provider' => 'openai', 'model' => 'm', 'http_status' => 401, 'error' => 'refused the key']);
        $log->record(['provider' => 'openai', 'model' => 'm', 'error' => 'Nothing answered']);

        // Act
        $summary = $log->summary();
        $byModel = $log->byModel();

        // Assert
        // A failed call to a priced model cost nothing; only the unpriced model's call is unknown
        $this->assertSame(['calls' => 4, 'failed' => 2, 'tokens' => 2_000_000, 'cost' => 1.0, 'unpriced' => 1, 'ms' => 100], $summary);
        $this->assertSame('m', $byModel[0]['model']);
        $this->assertSame(3, $byModel[0]['calls']);
        $this->assertSame(1, $byModel[1]['unpriced']);
        $this->assertCount(2, $log->recent(10, true), 'the problems only');
        $this->assertSame(1.0, $log->spentOn('openai', time() - 3600));
        $this->assertSame(0.0, $log->spentOn('mistral', time() - 3600));
    }

    /**
     * Pruning drops the old rows and keeps the recent ones.
     */
    #[DataProvider('databases')]
    public function testPruningDropsOnlyOldRows(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $log = new CallLog();
        $log->record(['provider' => 'openai', 'model' => 'm', 'http_status' => 200]);
        $this->db->queryBuilder()->table('llm_calls')->insert(['provider' => 'openai', 'model' => 'old',
            'created_at' => date('Y-m-d H:i:s', time() - 100 * 86400)]);

        // Act
        $kept = $log->prune();

        // Assert
        $this->assertSame(90, $kept);
        $this->assertSame(['m'], array_column($log->recent(10), 'model'));
    }

    /**
     * With logging off nothing is written; with no table, recording does not throw.
     */
    #[DataProvider('databases')]
    public function testRecordingNeverBreaksTheCall(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $log = new CallLog();

        // Act — switched off
        Settings::setSetting('llm_log', '0', false);
        $log->record(['provider' => 'openai', 'model' => 'm']);
        Settings::setSetting('llm_log', '1', false);
        $this->assertSame([], $log->recent());

        // Act — no table at all
        $this->db->schema()->dropTableIfExists('llm_calls');
        $log->record(['provider' => 'openai', 'model' => 'm']);

        // Assert — nothing thrown, and the readers answer empty
        $this->assertSame([], $log->recent());
        $this->assertSame(0, $log->summary()['calls']);
        $this->assertNull($log->find(1));
        $this->assertSame(0.0, $log->spentOn('openai', 0));
    }

    /**
     * A table that existed before the framework's keeps its rows and gains `tokens`, filled
     * from each row's usage.
     */
    #[DataProvider('databases')]
    public function testAnExistingTableGainsTokens(string $type, string $host, int $port): void
    {
        // Arrange — the table as an application had it: no `tokens`
        $this->connect($type, $host, $port);
        $this->db->schema()->alterTable('llm_calls', function ($table) {
            $table->dropColumn('tokens');
        });
        $this->db->queryBuilder()->table('llm_calls')->insert(['provider' => 'openai', 'model' => 'm',
            'usage' => json_encode(['total_tokens' => 321]), 'created_at' => date('Y-m-d H:i:s')]);
        $this->db->queryBuilder()->table('llm_calls')->insert(['provider' => 'openai', 'model' => 'm',
            'created_at' => date('Y-m-d H:i:s')]);

        // Act — the create migration leaves it alone, the second one adds the column
        $this->migration(CreateLlmCallsTable::class)->up();
        $this->migration(AddTokensToLlmCalls::class)->up();
        $this->migration(AddTokensToLlmCalls::class)->up();

        // Assert
        $tokens = array_map(static fn ($v): ?int => $v === null ? null : (int) $v, array_column((new CallLog())->recent(), 'tokens'));
        $this->assertSame([null, 321], $tokens);

        // And down takes it away again
        $this->migration(AddTokensToLlmCalls::class)->down();
        $this->assertFalse($this->db->schema()->hasColumn('llm_calls', 'tokens'));
    }

    /**
     * llm:prune keeps the window it is told, and succeeds where there is no table at all —
     * it runs every night on installations without the feature.
     */
    #[DataProvider('databases')]
    public function testThePruneCommand(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $this->db->queryBuilder()->table('llm_calls')->insert(['provider' => 'openai', 'model' => 'old',
            'created_at' => date('Y-m-d H:i:s', time() - 40 * 86400)]);
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \Pramnos\Console\Commands\LlmPrune());

        // Act
        $pruned = $tester->execute(['--days' => '30']);
        $said   = $tester->getDisplay();
        $this->db->schema()->dropTableIfExists('llm_calls');
        $absent = $tester->execute([]);

        // Assert
        $this->assertSame(0, $pruned);
        $this->assertStringContainsString('Kept the last 30 days', $said);
        $this->assertSame(0, $absent);
        $this->assertStringContainsString('nothing to prune', $tester->getDisplay());
    }

    /**
     * The windows hold in absolute time whatever PHP's time zone is, against rows written by
     * the framework and rows written before it in UTC with an offset.
     *
     * A time written without an offset is read in the session's zone; with PHP far from UTC,
     * new rows would land hours off the old ones and every window would cut in the wrong place.
     */
    #[DataProvider('databases')]
    public function testTheWindowsHoldInAnyTimeZone(string $type, string $host, int $port): void
    {
        // Arrange — PHP thirteen hours from UTC, and a row written the old way 25 hours ago
        $zone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Auckland');

        try {
            $this->connect($type, $host, $port);
            $log = new CallLog();
            $log->record(['provider' => 'openai', 'model' => 'now']);
            $this->db->queryBuilder()->table('llm_calls')->insert(['provider' => 'openai', 'model' => 'old',
                'created_at' => gmdate('Y-m-d H:i:sP', time() - 25 * 3600)]);

            // Act
            $day      = $log->summary(24)['calls'];
            $twoDays  = $log->summary(26)['calls'];
            $sinceNow = $log->spentOn('openai', time() - 60);

            // Assert — the new row is now, the old one 25 hours ago, in either zone
            $this->assertSame(1, $day, 'the old row is outside 24 hours');
            $this->assertSame(2, $twoDays, 'and inside 26');
            $this->assertSame(0.0, $sinceNow);
            $this->assertSame(1, $log->prune(1), 'pruning to a day');
            $this->assertSame(['now'], array_column($log->recent(), 'model'));
        } finally {
            date_default_timezone_set($zone);
        }
    }
}
