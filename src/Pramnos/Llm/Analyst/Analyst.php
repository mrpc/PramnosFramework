<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst;

use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Llm;
use Pramnos\Llm\Tools;
use Pramnos\Mcp\McpToolInterface;

/**
 * The administrators' analyst: questions about the whole installation, answered from its own
 * tables by tools that only read.
 *
 * An administrator's analyst conversations are theirs, in the `admin` space — never in their
 * Ask AI list, nor the other way round — and outside any organisation: the analyst reads the
 * installation, so the call is made with `organization_id` **null**, deliberately. A router
 * must keep that null (test with `array_key_exists`, not `??`), or the call becomes the
 * administrator's own organisation's, with its provider, key and plan.
 *
 * ```php
 * 'llm' => [
 *     'analyst'     => ['assistant' => App\Llm\Analyst::class],     // extends AnalystAssistant
 *     'admin_tools' => [App\Llm\PostsPublishedTool::class],         // McpToolInterface, read-only
 * ],
 * ```
 */
final class Analyst
{
    /** The assistant the application configured, or the framework's. */
    public static function assistant(): AnalystAssistant
    {
        $config = Llm::config('analyst', []);
        $class  = is_array($config) && is_string($config['assistant'] ?? null) ? $config['assistant'] : AnalystAssistant::class;

        return is_a($class, AnalystAssistant::class, true) ? new $class() : new AnalystAssistant();
    }

    /** An administrator's analyst: their own, in the `admin` space, outside any organisation. */
    public static function scope(int $adminId): Scope
    {
        return new Scope($adminId, null, Scope::ADMIN);
    }

    /** An administrator's analyst conversations. */
    public static function conversations(int $adminId): Conversations
    {
        return (new Conversations(self::assistant()))->as(self::scope($adminId));
    }

    /**
     * The framework's tools and the application's `admin_tools`.
     *
     * @return list<McpToolInterface>
     */
    public static function mcpTools(): array
    {
        $tools = [
            new \Pramnos\Llm\Analyst\Tools\UsersTool(),
            new \Pramnos\Llm\Analyst\Tools\UserTool(),
            new \Pramnos\Llm\Analyst\Tools\SessionsTool(),
            new \Pramnos\Llm\Analyst\Tools\OrganizationsTool(),
            new \Pramnos\Llm\Analyst\Tools\MailingListsTool(),
            new \Pramnos\Llm\Analyst\Tools\EmailTool(),
            new \Pramnos\Llm\Analyst\Tools\PushTool(),
            new \Pramnos\Llm\Analyst\Tools\QueueTool(),
            new \Pramnos\Llm\Analyst\Tools\LlmSpendTool(),
            new \Pramnos\Mcp\Tools\LogAnalyticsTool(),
            new \Pramnos\Mcp\Tools\LogErrorsTool(),
            new \Pramnos\Mcp\Tools\StatusTool(\Pramnos\Application\Application::getInstance()),
        ];

        foreach ((array) Llm::config('admin_tools', []) as $tool) {
            $tool = is_string($tool) && class_exists($tool) ? new $tool() : $tool;
            if ($tool instanceof McpToolInterface) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    /**
     * The tools as the model takes them: every result through {@see Redactor}, and one person's
     * account tagged `personal` for the data rules.
     *
     * The `user` tool redacts its own answer, sparing only the email it was asked for.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function tools(): array
    {
        $tools = Tools::fromMcp(self::mcpTools());

        foreach ($tools as $name => $tool) {
            if ($name !== 'user') {
                $run                 = $tool['run'];
                $tools[$name]['run'] = static fn (array $arguments): string => Redactor::clean((string) $run($arguments));
            }
        }

        Tools::tag($tools, 'user', 'personal');

        return $tools;
    }
}
