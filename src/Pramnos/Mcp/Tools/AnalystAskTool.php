<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Tools;

use Pramnos\Llm\Analyst\ConsoleAsk;
use Pramnos\Mcp\McpToolInterface;

/**
 * `ask` — a question to the analyst, as a root or super administrator, from `mcp:call` or a local
 * `mcp:serve`: its answer, the tools it called and what it cost ({@see ConsoleAsk}).
 *
 * Only on the server the console builds — never offered on the public endpoint — and only as
 * root or a super administrator (`as`), since every call is a paid answer.
 */
final class AnalystAskTool implements McpToolInterface
{
    public function name(): string
    {
        return 'ask';
    }

    public function description(): string
    {
        return 'Ask the analyst of /admin/Ai a question, as a root or super administrator (`as`, an account id), '
            . 'and get its answer, the tools it called with their arguments, its cost and the conversation. '
            . 'For checking a fix to a tool or the help; every call is a paid answer. `conversation_id` continues one.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'question'        => ['type' => 'string', 'description' => 'The question'],
                'as'              => ['type' => 'integer', 'description' => 'The root or super administrator asking: an account id'],
                'conversation_id' => ['type' => 'integer', 'description' => 'A conversation of theirs to continue'],
            ],
            'required'   => ['question', 'as'],
        ];
    }

    /** @return array<string, mixed> As {@see ConsoleAsk::ask()} answers */
    public function execute(array $input): mixed
    {
        $conversation = (int) ($input['conversation_id'] ?? 0);

        return ConsoleAsk::ask((int) ($input['as'] ?? 0), (string) ($input['question'] ?? ''),
            $conversation > 0 ? $conversation : null, \Pramnos\User\Roles::SUPER);
    }
}
