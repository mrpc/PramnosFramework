<?php

declare(strict_types=1);

namespace Pramnos\Health\Checks;

use Pramnos\Health\HealthCheck;
use Pramnos\Health\HealthCheckResult;

/**
 * Whether the conversation tables are the shape the framework writes to.
 *
 * An application that had its own `chat_*` tables keeps them — the framework's migrations skip
 * a table that exists — so a column the framework needs can be missing, or refuse a value it
 * writes: `space`, which keeps an administrator's analyst apart from their Ask AI, and a nullable
 * `organization_id`, without which a conversation outside any organisation cannot be written
 * and `/admin/Ai` answers its first question with a 500. **Degraded**, naming the table and the
 * column, until the migrations have run. Nothing to say with the `llm` feature off.
 */
class LlmTablesCheck implements HealthCheck
{
    /** The tables, and whether each carries `space`. */
    private const TABLES = [
        'chat_conversations' => true,
        'chat_messages'      => false,
        'chat_memory'        => true,
        'chat_asks'          => true,
        'ai_consents'        => false,
        'ai_routines'        => true,
        'ai_task_runs'       => false,
        'ai_task_subscriptions' => false,
        'chat_artifacts'     => true,
        'chat_shares'        => true,
    ];

    /** Columns added to a table after it was first written, which the code reads. */
    private const ADDED = [
        'ai_routines' => ['kind', 'locked_until'],
    ];

    public function getName(): string
    {
        return 'llm_tables';
    }

    public function run(): HealthCheckResult
    {
        if (!\Pramnos\Application\FeatureRegistry::isEnabled('llm')) {
            return HealthCheckResult::ok($this->getName(), 'The llm feature is off');
        }

        try {
            $schema = \Pramnos\Framework\Factory::getDatabase()->schema();
        } catch (\Throwable $ex) {
            return HealthCheckResult::down($this->getName(), 'Cannot read the schema', ['error' => $ex->getMessage()]);
        }

        $problems = [];

        foreach (self::TABLES as $table => $spaced) {
            $columns = $schema->columnDetails('#PREFIX#' . $table);

            if ($columns === []) {
                $problems[] = $table . ': missing';
                continue;
            }
            if (isset($columns['organization_id']) && !$columns['organization_id']['nullable']) {
                $problems[] = $table . '.organization_id: NOT NULL';
            }
            if ($spaced && !isset($columns['space'])) {
                $problems[] = $table . '.space: missing';
            }
            foreach (self::ADDED[$table] ?? [] as $column) {
                if (!isset($columns[$column])) {
                    $problems[] = $table . '.' . $column . ': missing';
                }
            }
        }

        return $problems === []
            ? HealthCheckResult::ok($this->getName(), 'The conversation tables are as the framework writes them')
            : HealthCheckResult::degraded(
                $this->getName(),
                'The conversation tables need the framework\'s migrations — run `php bin/pramnos migrate`',
                ['problems' => $problems]
            );
    }
}
