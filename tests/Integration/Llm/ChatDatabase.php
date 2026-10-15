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

    /** The tables the `llm` migrations create, child tables first. */
    private const LLM_TABLES = ['chat_messages', 'chat_conversations', 'chat_memory', 'chat_asks', 'ai_consents', 'ai_task_subscriptions', 'ai_task_runs', 'ai_routines', 'llm_calls'];

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

        $this->dropTables();
        foreach ($this->migrations() as $class) {
            $this->migration($class)->up();
        }
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
        if (isset($this->db) && $this->db->connected) {
            $this->dropTables();
        }
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
