<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;

/**
 * A real database for the conversation tests: MySQL or PostgreSQL, made the framework's, with
 * every `llm` table built by its migration and dropped afterwards.
 */
trait ChatDatabase
{
    private Database $db;

    private ?Database $previousSingleton = null;

    /**
     * The databases this class has built the `llm` tables in, and has not changed since.
     *
     * Built once a class and database, emptied before each test after that: dropping and
     * migrating nine tables before every test was a tenth of the whole suite's time. A test that
     * changes the tables says so with {@see llmSchemaChanged()}, and the next one builds again.
     *
     * @var array<string, true>
     */
    private static array $llmBuilt = [];

    /** The tables the `llm` migrations create, child tables first. */
    private const LLM_TABLES = ['pramnos.llm_tool_runs', 'chat_shares', 'chat_artifacts', 'chat_messages', 'chat_conversations', 'chat_memory', 'chat_asks', 'ai_consents', 'ai_task_subscriptions', 'ai_task_runs', 'ai_routines', 'llm_calls'];

    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306],
            'postgresql' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    private function connectDatabase(string $type, string $host, int $port): void
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

        // `users` too: a task's policy loads the account it runs for, and a class that ran
        // earlier may have left the shared database without it. And `userdetails`, which loading
        // an account reads beside it (the page's pinned artifacts load the reader).
        \Pramnos\Framework\Testing\Schema::ensure([
            \Pramnos\Framework\Migrations\Auth\CreateUsersTable::class,
            \Pramnos\Framework\Migrations\Auth\CreateUserdetailsTable::class,
        ], $this->db);

        $key = static::class . '|' . $type;
        if (isset(self::$llmBuilt[$key])) {
            // Child tables first, as LLM_TABLES lists them, so no foreign key refuses a delete.
            foreach (self::LLM_TABLES as $table) {
                $this->db->queryBuilder()->table($table)->delete();
            }

            return;
        }
        $this->dropTables();
        foreach ($this->migrations() as $class) {
            $this->migration($class)->up();
        }
        self::$llmBuilt[$key] = true;
    }

    /** This test changed the `llm` tables: the next one builds them again. */
    private function llmSchemaChanged(): void
    {
        unset(self::$llmBuilt[static::class . '|' . $this->db->type]);
    }

    /** The tables go with the class, so none is left for a class that expects none. */
    public static function tearDownAfterClass(): void
    {
        foreach (array_keys(self::$llmBuilt) as $key) {
            if (!str_starts_with($key, static::class . '|')) {
                continue;
            }
            unset(self::$llmBuilt[$key]);
            [$type, $host, $port] = self::databases()[substr($key, strlen(static::class) + 1)];
            $db = new Database();
            $db->type     = $type;
            $db->server   = $host;
            $db->port     = $port;
            $db->user     = $type === 'mysql' ? 'root' : 'postgres';
            $db->password = 'secret';
            $db->database = TEST_DATABASE;
            try {
                $db->connect(false);
                foreach (self::LLM_TABLES as $table) {
                    $db->schema()->dropTableIfExists($table);
                }
                $db->close();
            } catch (\Throwable) {
                // Not reachable now: it was when the tables were made, and the next class drops them.
            }
        }
        parent::tearDownAfterClass();
    }

    /** @return list<class-string> Every `llm` migration, in order */
    private function migrations(): array
    {
        $classes = [];
        foreach (glob(ROOT . '/database/migrations/framework/llm/*.php') ?: [] as $file) {
            preg_match('/^class (\w+)/m', (string) file_get_contents($file), $m);
            $classes[] = 'Pramnos\\Framework\\Migrations\\Llm\\' . $m[1];
        }

        return $classes;
    }

    private function migration(string $class): \Pramnos\Database\Migration
    {
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $this->db;

        return new $class($app);
    }

    private function dropTables(): void
    {
        foreach (self::LLM_TABLES as $table) {
            $this->db->schema()->dropTableIfExists($table);
        }
    }

    private function disconnectDatabase(): void
    {
        if ($this->previousSingleton !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previousSingleton;
            $this->previousSingleton = null;
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $table): array
    {
        return $this->db->queryBuilder()->table($table)->getAll();
    }
}
