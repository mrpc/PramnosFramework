<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Summary;
use Pramnos\Mcp\McpToolInterface;

/**
 * What was said in a conversation the person reads, summed up: «what was said in #sales today?».
 * The same summary, and the same rights, as the conversation's own Summary ({@see Summary}).
 */
final class ConversationSummaryTool implements McpToolInterface
{
    /**
     * @param int         $adminId Who is asking; 0 is nobody signed in
     * @param object|null $as      The person asking, where the caller has them already
     */
    public function __construct(private readonly int $adminId = 0, private readonly ?object $as = null)
    {
    }

    /** The tool's name. */
    public function name(): string
    {
        return 'conversation_summary';
    }

    /** What the model is told the tool does. */
    public function description(): string
    {
        return 'A summary of what was said in a team conversation the person reads: conversation is its id or its #name. '
            . 'hours: the last N hours (24 by default); or unread: true for what the person has not read. '
            . 'Answers summary (markdown citing [#message id]), count of messages read, from/to (unix), cut (older ones left out).';
    }

    /** @return array<string, mixed> */
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'conversation' => ['type' => 'string', 'description' => 'Its id, or its #name.'],
            'hours'        => ['type' => 'integer', 'description' => 'The last N hours; 24 by default.'],
            'unread'       => ['type' => 'boolean', 'description' => 'What the person has not read, instead of hours.'],
        ], 'required' => ['conversation']];
    }

    /** @param array<string, mixed> $input */
    public function execute(array $input): mixed
    {
        $user = $this->as ?? ($this->adminId > 0 ? new \Pramnos\User\User($this->adminId) : null);
        if ($user === null || !Conversations::installed()) {
            return 'Error: the conversations are not open to you.';
        }
        $named = trim((string) ($input['conversation'] ?? ''));
        $id    = ctype_digit($named) ? (int) $named : (int) (Conversations::bySlug(ltrim($named, '#'))['id'] ?? 0);
        try {
            return Summary::of($id, $user, !empty($input['unread']) ? 'unread' : ['hours' => (int) ($input['hours'] ?? 24) ?: 24],
                (string) ($user->language ?? ''));
        } catch (\DomainException) {
            return 'Error: there is no such conversation you read.';
        } catch (\InvalidArgumentException $e) {
            return 'Error: ' . $e->getMessage();
        }
    }
}
