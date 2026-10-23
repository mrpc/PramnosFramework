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
     * The analyst's name, wherever the administration shows it — the header's button, the side
     * panel, the Ask page, the conversations: `'llm' => ['analyst' => ['name' => 'Naya']]`, or
     * `Ask`. The model is told it too.
     */
    public static function name(): string
    {
        $config = Llm::config('analyst', []);
        $name   = is_array($config) && is_string($config['name'] ?? null) ? trim($config['name']) : '';

        return $name !== '' ? mb_substr($name, 0, 40) : 'Ask';
    }

    /**
     * The words that call on the analyst after `@` in a conversation, lower-case: `ask` always,
     * its name, and `'llm' => ['analyst' => ['mentions' => ['naya']]]` — each a word of letters,
     * digits and `_`.
     *
     * @return list<string>
     */
    public static function mentions(): array
    {
        $config = Llm::config('analyst', []);
        $words  = array_merge(['ask', self::name()], is_array($config) ? (array) ($config['mentions'] ?? []) : []);
        $words  = array_map(static fn ($word): string => mb_strtolower(trim((string) $word)), $words);

        return array_values(array_unique(array_filter($words, static fn (string $word): bool => preg_match('/^[\p{L}\p{N}_]+$/u', $word) === 1)));
    }

    /**
     * The pattern that finds a call on the analyst in a message: `@` and one of
     * {@see mentions()} as a word of its own, ignoring case — and `$then`, a pattern of what may
     * follow it, where the call is to be taken out with what follows.
     */
    public static function mentionPattern(string $then = ''): string
    {
        return '/(?<![\w@])@(?:' . implode('|', array_map(static fn (string $word): string => preg_quote($word, '/'), self::mentions())) . ')\b' . $then . '/iu';
    }

    /** The analyst's picture, `'llm' => ['analyst' => ['avatar' => '/img/ai.svg']]`, or null for none. */
    public static function avatar(): ?string
    {
        $config = Llm::config('analyst', []);
        $url    = is_array($config) && is_string($config['avatar'] ?? null) ? trim($config['avatar']) : '';

        return $url !== '' ? $url : null;
    }

    /**
     * The admin header's "Ask" — beside the search — and the side panel it opens: Ask about the
     * page you are on, and that page's help. See {@see \Pramnos\Assist\Panel}.
     *
     * Without the analyst — the `llm` feature off, or below its floor — it is "Help" where the
     * page has help, and nothing where it has none. For somebody signed out, the site's help on
     * a page outside the administration, where the page has one; nothing anywhere else.
     *
     * ```php
     * <?php echo \Pramnos\Llm\Analyst\Analyst::headerButton(); ?>
     * ```
     */
    public static function headerButton(string $before = '', string $after = ''): string
    {
        $user = \Pramnos\User\User::getCurrentUser();

        if (!is_object($user) || (int) ($user->userid ?? 0) <= 1) {
            // A visitor on the site still has the site's help for the page, where it has some.
            return \Pramnos\Assist\Help::level() === \Pramnos\Assist\HelpBook::SITE
                ? \Pramnos\Assist\Panel::render(false, '', $before, $after)
                : '';
        }

        return \Pramnos\Assist\Panel::render(self::canAsk(), adminUrl('Ai'), $before, $after);
    }

    /**
     * Whether the signed-in person may ask the analyst here: the `llm` feature, a key for its
     * connection, and `admin.ai` at the analyst's floor. For a theme that draws its own Ask button
     * with `Panel::opener('ask')` beside `Panel::template(Analyst::canAsk(), adminUrl('Ai'))`.
     */
    public static function canAsk(): bool
    {
        $user = \Pramnos\User\User::getCurrentUser();

        return is_object($user) && (int) ($user->userid ?? 0) > 1
            && \Pramnos\Application\FeatureRegistry::isEnabled('llm')
            && self::available()
            && \Pramnos\Auth\AdminAccess::allows($user, 'admin.ai', self::floor());
    }

    /**
     * Whether the analyst can answer: a key for its connection. Without
     * one there is no Ask in the header or the side panel, and `/admin/Ai` opens the settings,
     * where the key is set.
     */
    public static function available(): bool
    {
        try {
            return self::assistant()->connection(self::scope(0))->isConfigured();
        } catch (\Throwable) {
            return false;
        }
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
     * The framework's tools — with the audience tools where their tables are, behind the screens'
     * abilities — and the application's `admin_tools`; and, where the application
     * turned them on, `schema` and `sql` (`'sql' => true`) and `export_csv` (`'export' => true`,
     * for the administrator asking).
     *
     * @return list<McpToolInterface>
     */
    public static function mcpTools(int $adminId = 0, string $area = ''): array
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

        // Audiences, where their tables are: read everything, write drafts only.
        foreach ([
            \Pramnos\Llm\Analyst\Tools\AudienceSegmentsTool::class    => ['segments', 'pramnos.segment_members'],
            \Pramnos\Llm\Analyst\Tools\AudienceWhyTool::class         => ['segments'],
            \Pramnos\Llm\Analyst\Tools\AudienceDraftTool::class       => ['segments'],
            \Pramnos\Llm\Analyst\Tools\AudienceRulesTool::class       => ['rules', 'pramnos.rule_runs', 'pramnos.rule_log'],
            \Pramnos\Llm\Analyst\Tools\AudienceExperimentsTool::class => ['experiments', 'pramnos.experiment_assignments'],
        ] as $class => $tables) {
            if (\Pramnos\Audience\Installed::has(...$tables)) {
                $tools[] = new $class($adminId);
            }
        }

        // The installation's help pages, with the `help` feature.
        if (\Pramnos\Assist\Help::enabled()) {
            $tools[] = new \Pramnos\Llm\Analyst\Tools\HelpTool($area);
        }

        $analyst = Llm::config('analyst', []);
        $sql     = is_array($analyst) && !empty($analyst['sql']);
        $export  = is_array($analyst) && !empty($analyst['export']) && $adminId > 0;
        if ($sql || $export) {
            $tools[] = new \Pramnos\Llm\Analyst\Tools\SchemaTool();
        }
        if ($sql) {
            $tools[] = new \Pramnos\Llm\Analyst\Tools\SqlTool();
        }
        if ($export) {
            $tools[] = new \Pramnos\Llm\Analyst\Tools\ExportCsvTool($adminId, $area);
        }

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
    public static function tools(int $adminId = 0, string $area = ''): array
    {
        $tools = Tools::fromMcp(self::mcpTools($adminId, $area));

        foreach ($tools as $name => $tool) {
            if (!in_array($name, Redactor::SELF_REDACTING, true)) {
                $run                 = $tool['run'];
                $tools[$name]['run'] = static fn (array $arguments): string => Redactor::clean((string) $run($arguments));
            }
        }

        // A statement of the model's own can read anybody's row.
        foreach (['user', 'sql', 'export_csv', 'audience_why'] as $name) {
            Tools::tag($tools, $name, 'personal');
        }

        return $tools;
    }
}
