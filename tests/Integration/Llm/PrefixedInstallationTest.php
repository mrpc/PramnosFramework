<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Llm\Analyst\Analyst;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Tests\Support\ReusesProbeDatabase;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * Every `llm` table on an installation with a table prefix, on MySQL and PostgreSQL.
 *
 * The migrations create `pf_chat_conversations`; a query that names `chat_conversations` reads
 * a table that does not exist. The suite's fixtures have no prefix, so nothing ran into that
 * until an installation did — `/admin/Ai` answered 500. This builds the tables with a prefix and
 * goes through each of them as the screens do.
 */
#[CoversNothing]
class PrefixedInstallationTest extends LlmTestCase
{
    use ReusesProbeDatabase;

    private const PROBE = 'pramnos_llm_prefix_probe';

    private ?Database $previous = null;

    private Database $db;

    /** @return array<string, array{string, string, int, string}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306, 'root'],
            'postgresql' => ['postgresql', 'timescaledb', 5432, 'postgres'],
        ];
    }

    private static function connection(string $type, string $host, int $port, string $user, string $name): Database
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $user;
        $db->password = 'secret';
        $db->database = $name;
        $db->prefix   = 'pf_';

        return $db;
    }

    private function boot(string $type, string $host, int $port, string $user): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        $admin = self::connection($type, $host, $port, $user, TEST_DATABASE);
        try {
            if (!$admin->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }

        $llm = [];
        foreach (glob(ROOT . '/database/migrations/framework/llm/*.php') ?: [] as $file) {
            preg_match('/^class (\w+)/m', (string) file_get_contents($file), $m);
            $llm[] = 'Pramnos\\Framework\\Migrations\\Llm\\' . $m[1];
        }

        $this->db = $this->openProbe($admin, self::PROBE, static fn (string $name): Database => self::connection($type, $host, $port, $user, $name), $llm);
        $admin->close();

        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $this->db;
    }

    protected function tearDown(): void
    {
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        parent::tearDown();
    }

    /**
     * Conversations, memory, queued questions, schedules, agreement and the call log — each
     * written and read back where the migrations put it, under the prefix.
     */
    #[DataProvider('databases')]
    public function testEveryTableIsFoundUnderThePrefix(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $scope = new Scope(7);
        $chat  = (new \Pramnos\Llm\Chat\Conversations(new \Pramnos\Llm\Chat\BaseAssistant()))->as($scope);

        // Act
        (new \Pramnos\Llm\Chat\Memory($scope))->remember('fact', 'Likes tables');
        $askId = (new \Pramnos\Llm\Chat\Asks($chat, new RecordingQueue()))->start($scope, null, 'Hi');
        $made  = (new \Pramnos\Llm\Chat\Routines($chat))->create($scope, ['title' => 'Daily', 'prompt' => 'Report', 'cadence' => 'daily', 'at' => '08:00', 'timezone' => 'UTC']);
        (new \Pramnos\Llm\Policy\StoredConsent())->give(null, 7, 'deepseek');
        (new \Pramnos\Llm\CallLog())->record(['purpose' => 'chat', 'provider' => 'openai', 'model' => 'm', 'http_status' => 200, 'usage' => ['total_tokens' => 5]]);

        // Assert — the tables are the prefixed ones, and each is read back
        $this->assertTrue($this->db->schema()->hasTable('pf_chat_conversations'), 'the migration prefixed it');
        $this->assertSame([], $chat->list());
        $this->assertSame(['Likes tables'], array_column((new \Pramnos\Llm\Chat\Memory($scope))->all(), 'content'));
        $this->assertSame('waiting', (new \Pramnos\Llm\Chat\Asks($chat))->find($scope, $askId)['status']);
        $this->assertSame(['Daily'], array_column((new \Pramnos\Llm\Chat\Routines($chat))->mine($scope), 'title'));
        $this->assertTrue(is_array($made));
        $this->assertTrue((new \Pramnos\Llm\Policy\StoredConsent())->has(null, 7, 'deepseek'));
        $this->assertCount(1, (new \Pramnos\Llm\CallLog())->recent());
        $this->assertSame([], Analyst::conversations(7)->list(), 'the analyst\'s space, under the prefix too');
        $this->assertSame(0, json_decode((Analyst::tools()['llm_spend']['run'])([]), true)['calls'] - 1, 'the analyst reads the prefixed call log');
    }
}
