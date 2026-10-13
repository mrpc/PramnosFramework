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

    /**
     * The "Ask" link for the admin header, beside the search — empty for anybody who may not
     * open the page, and when the `llm` feature is off.
     *
     * ```php
     * <?php echo \Pramnos\Llm\Analyst\Analyst::headerButton(); ?>
     * ```
     */
    public static function headerButton(string $before = '', string $after = ''): string
    {
        $user = \Pramnos\User\User::getCurrentUser();

        if (!\Pramnos\Application\FeatureRegistry::isEnabled('llm')
            || !\Pramnos\Auth\AdminAccess::allows(is_object($user) ? $user : null, 'admin.ai', self::floor())) {
            return '';
        }

        return $before . '<a class="pf-ask" href="' . htmlspecialchars(adminUrl('Ai'), ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars((string) (function_exists('t') ? t('Ask') : 'Ask'), ENT_QUOTES, 'UTF-8') . '</a>' . $after;
    }

    /**
     * The usertype that opens Ask: the application's `Admin\Controllers\Ai::$requiredUserType`
     * when it declares one — the same floor its controller applies — or the area's.
     */
    public static function floor(): int
    {
        $app       = \Pramnos\Application\Application::getInstance();
        $namespace = trim((string) ($app->applicationInfo['namespace'] ?? ''), '\\');
        $class     = $namespace . '\\Admin\\Controllers\\Ai';

        if ($namespace !== '' && class_exists($class)) {
            $defaults = (new \ReflectionClass($class))->getDefaultProperties();
            $declared = (new \ReflectionProperty($class, 'requiredUserType'))->getDeclaringClass()->getName();

            if ($declared !== \Pramnos\Application\Controllers\AiController::class && isset($defaults['requiredUserType'])) {
                return (int) $defaults['requiredUserType'];
            }
        }

        return \Pramnos\Auth\AdminAccess::defaultUsertype();
    }

    /**
     * The zone the analyst counts days in: `'llm' => ['analyst' => ['timezone' => 'Europe/Athens']]`,
     * or the application's — PHP's default zone, which the application sets. An unknown name is UTC.
     */
    public static function timezone(): string
    {
        $config = Llm::config('analyst', []);
        $zone   = is_array($config) && is_string($config['timezone'] ?? null) && $config['timezone'] !== ''
            ? $config['timezone']
            : date_default_timezone_get();

        return in_array($zone, \DateTimeZone::listIdentifiers(), true) || $zone === 'UTC' ? $zone : 'UTC';
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
