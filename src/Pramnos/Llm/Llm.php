<?php

declare(strict_types=1);

namespace Pramnos\Llm;

/**
 * Where an application plugs into the language-model module.
 *
 * Everything the framework cannot decide for an application is a policy it supplies, in
 * `app/app.php`, and each one defaults to "allow, and record nothing":
 *
 * ```php
 * 'llm' => [
 *     'default'  => 'deepseek',
 *     'features' => ['chat' => 'Ask AI', 'writer' => 'The article writer'],
 *     'follows'  => ['writer' => 'chat'],          // left empty, `writer` follows `chat`
 *     'limits'   => App\Llm\PlanLimits::class,      // Policy\Limits
 *     'consent'  => App\Llm\Consent::class,         // Policy\Consent
 *     'router'   => App\Llm\Router::class,          // Policy\Router
 *     'rules'    => [App\Llm\GoogleData::class],    // Policy\DataRule, one per tag
 *     'admin_sections' => [App\Llm\PlanSection::class],  // Admin\Section, on /admin/Ai
 * ],
 * ```
 *
 * A class name is constructed once per process with no arguments. Tests and code that needs to
 * set one at runtime use {@see use()}, and {@see reset()} puts everything back.
 */
final class Llm
{
    /** @var array<string, object|list<object>> Policies set at runtime, by kind */
    private static array $set = [];

    /** @var array<string, object|list<object>> Policies built from configuration, by kind */
    private static array $built = [];

    /** @var list<callable(array<string, mixed>): void> */
    private static array $listeners = [];

    /** The default of every policy, so a caller never checks for null. */
    private const DEFAULTS = [
        'limits'  => Policy\NoLimits::class,
        'consent' => Policy\NoConsent::class,
        'router'  => Policy\DefaultRouter::class,
        'tool_gate' => Policy\OpenGate::class,
        'cascade' => Policy\NoCascade::class,
    ];

    /**
     * A value from `app.php`'s `llm` section.
     *
     * @param mixed $default What to answer when it is not there
     */
    public static function config(string $key, mixed $default = null): mixed
    {
        $application = \Pramnos\Application\Application::currentInstance();
        $section     = is_object($application) ? ($application->applicationInfo['llm'] ?? []) : [];

        return is_array($section) && array_key_exists($key, $section) ? $section[$key] : $default;
    }

    /** What decides whether a call may spend, and records what it spent. */
    public static function limits(): Policy\Limits
    {
        return self::one('limits');
    }

    /** What decides whether data may go to a provider at all. */
    public static function consent(): Policy\Consent
    {
        return self::one('consent');
    }

    /** What fills in a call's context and picks its connection. */
    public static function router(): Policy\Router
    {
        return self::one('router');
    }

    /** Which tier of model answers a question: `'llm' => ['cascade' => …, 'tiers' => […]]`. */
    public static function cascade(): Policy\Cascade
    {
        return self::one('cascade');
    }

    /** What decides, before it runs, whether a tool call may: `'llm' => ['tool_gate' => …]`. */
    public static function gate(): Policy\ToolGate
    {
        return self::one('tool_gate');
    }

    /**
     * The data rules, by the tag each one governs.
     *
     * @return array<string, Policy\DataRule>
     */
    public static function rules(): array
    {
        $rules = [];

        foreach (self::many('rules') as $rule) {
            if ($rule instanceof Policy\DataRule) {
                $rules[$rule->tag()] = $rule;
            }
        }

        return $rules;
    }

    /**
     * The application's own sections of the administration screen.
     *
     * A {@see Admin\Card} only shows; a {@see Admin\Section} also saves.
     *
     * @return list<Admin\Card>
     */
    public static function adminSections(): array
    {
        return array_values(array_filter(
            self::many('admin_sections'),
            static fn (object $section): bool => $section instanceof Admin\Card
        ));
    }

    /**
     * Set a policy at runtime, over whatever `app.php` says.
     *
     * @param string              $kind   `limits`, `consent`, `router`, `tool_gate`, `cascade`, `rules` or `admin_sections`
     * @param object|list<object> $policy One object, or a list for `rules` and `admin_sections`
     */
    public static function use(string $kind, object|array $policy): void
    {
        self::$set[$kind] = $policy;
    }

    /**
     * Be told about every call, after it is logged — what it was for, and its usage and cost.
     *
     * For what an application keeps per call beside the log: what an article cost to write,
     * what a report spent. A listener that throws is logged and does not end the answer.
     *
     * @param callable(array<string, mixed>): void $listener Receives the log entry
     */
    public static function listen(callable $listener): void
    {
        self::$listeners[] = $listener;
    }

    /** @param array<string, mixed> $entry */
    public static function notify(array $entry): void
    {
        foreach (self::$listeners as $listener) {
            try {
                $listener($entry);
            } catch (\Throwable $why) {
                \Pramnos\Logs\Logger::log('llm listener: ' . $why->getMessage());
            }
        }
    }

    /** Forget every policy and listener — for tests, and after configuration changes. */
    public static function reset(): void
    {
        self::$set       = [];
        self::$built     = [];
        self::$listeners = [];
        Connection::forget();
    }

    /** One policy of a kind: the one set, the one configured, or the default. */
    private static function one(string $kind): object
    {
        if (isset(self::$set[$kind]) && is_object(self::$set[$kind])) {
            return self::$set[$kind];
        }

        if (!isset(self::$built[$kind])) {
            $class = self::config($kind);
            $class = is_string($class) && class_exists($class) ? $class : self::DEFAULTS[$kind];

            self::$built[$kind] = new $class();
        }

        return self::$built[$kind];
    }

    /** @return list<object> */
    private static function many(string $kind): array
    {
        if (isset(self::$set[$kind])) {
            return array_values(is_array(self::$set[$kind]) ? self::$set[$kind] : [self::$set[$kind]]);
        }

        if (!isset(self::$built[$kind])) {
            $built = [];

            foreach ((array) self::config($kind, []) as $class) {
                if (is_string($class) && class_exists($class)) {
                    $built[] = new $class();
                }
            }

            self::$built[$kind] = $built;
        }

        return self::$built[$kind];
    }
}
