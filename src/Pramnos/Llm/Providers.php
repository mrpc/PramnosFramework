<?php

declare(strict_types=1);

namespace Pramnos\Llm;

use Pramnos\Application\Settings;

/**
 * The language-model providers an installation can be pointed at.
 *
 * ## Everything provider-specific is here
 *
 * Which endpoint, which settings hold the key and the model, what the token budget is called,
 * whether reasoning can be switched off, whether a balance can be read at all. Adding a provider
 * is an entry rather than a branch in the call path — the difference between "we support two"
 * and "we support two and the second one is a special case everywhere".
 *
 * The built-in ones are all **OpenAI-compatible `/chat/completions`** endpoints, which is why one
 * transport covers them and no SDK is needed. An entry names another transport with
 * `'transport' => SomeTransport::class` for a provider that speaks something else.
 *
 * ## An application adds and overrides
 *
 * ```php
 * // app/app.php
 * 'llm' => [
 *     'default'   => 'deepseek',
 *     'providers' => [
 *         'openai'        => ['default_model' => 'gpt-4.1-mini'],      // merged over the built-in
 *         'openai_shared' => [ ...a whole entry... ],                   // a provider of its own
 *     ],
 * ],
 * ```
 *
 * Each provider carries its own full set of setting names, so two can stay configured at once
 * and switching between them does not mean retyping a key.
 */
final class Providers
{
    /** The provider used when neither the application nor the administrator chose one. */
    public const DEFAULT_PROVIDER = 'openai';

    /** Which provider the installation uses unless a caller names another. */
    public const SELECTED_SETTING = 'llm_provider';

    /** How long to wait for a completion, in seconds. Long enough for a reasoning model. */
    public const TIMEOUT_SETTING = 'llm_timeout';

    /** Whether calls are written to the log at all. */
    public const LOG_SETTING = 'llm_log';

    /** The fields every provider keeps in settings, and the screen edits. */
    public const FIELDS = ['api_key', 'base_url', 'model', 'temperature', 'max_tokens', 'reasoning'];

    /** @var array<string, array<string, mixed>> */
    public const BUILT_IN = [
        'deepseek' => [
            'label'         => 'DeepSeek',
            'base_url'      => 'https://api.deepseek.com',
            'api_key_url'   => 'https://platform.deepseek.com/',
            'models_path'   => '/models',
            // The one figure that is not an estimate: DeepSeek publishes what is left.
            'balance_path'  => '/user/balance',
            'token_param'   => 'max_tokens',
            // `thinking: {type: disabled}` is how DeepSeek switches reasoning off.
            'reasoning_param' => 'thinking',
            'supports_temperature' => true,
            'model_pattern' => '/^deepseek/i',
            'default_model' => 'deepseek-chat',
            // Established outside the EU with no adequacy decision: a Policy\Consent may ask first.
            'requires_consent' => true,
            'place'            => "the People's Republic of China",
            'context_window' => 128000,
            'models'        => [
                'deepseek-chat'     => 'DeepSeek Chat — fast and cheap',
                'deepseek-reasoner' => 'DeepSeek Reasoner — thinks first, costs more and is slower',
            ],
        ],
        'openai' => [
            'label'         => 'OpenAI',
            'base_url'      => 'https://api.openai.com/v1',
            'api_key_url'   => 'https://platform.openai.com/api-keys',
            'models_path'   => '/models',
            // No balance endpoint: what OpenAI billed comes from its cost report (Billing\OpenAiCosts).
            'balance_path'  => null,
            'token_param'   => 'max_completion_tokens',
            // The o-series and GPT-5 onwards reason, take `reasoning_effort` and refuse a
            // temperature; GPT-4o refuses the effort.
            'reasoning_param' => 'effort',
            'reasoning_models' => '/^(o[0-9]|gpt-([5-9]|[1-9][0-9]))/i',
            // With tools a reasoning model is asked for no effort: some call tools only at `none`.
            'efforts'       => ['off' => 'none', 'on' => 'low', 'with_tools' => 'none'],
            'supports_temperature' => true,
            'model_pattern' => '/^(gpt|o[0-9])/i',
            'default_model' => 'gpt-4o-mini',
            'context_window' => 128000,
            'models'        => [
                'gpt-4o-mini' => 'GPT-4o mini — the cheap one',
                'gpt-6-luna'  => 'GPT-6 Luna — cheaper still, with a cheaper cache',
                'gpt-4o'      => 'GPT-4o — more capable, more expensive',
            ],
        ],
        /*
         * Google's Gemini, through its OpenAI-compatible endpoint. Gemini 3 always thinks; `low`
         * is the least every one of them takes, and Google asks for the temperature to stay at
         * its default, so none is sent with an effort.
         */
        'gemini' => [
            'label'         => 'Gemini',
            'base_url'      => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'api_key_url'   => 'https://aistudio.google.com/apikey',
            'models_path'   => '/models',
            'balance_path'  => null,
            'token_param'   => 'max_tokens',
            'reasoning_param' => 'effort',
            'reasoning_models' => '/^gemini/i',
            'efforts'       => ['off' => 'low', 'on' => null],
            'supports_temperature' => true,
            // Its chat models: not the image, audio, embedding or video ones.
            'model_pattern' => '/^(models\/)?gemini-(?!.*(image|tts|embedding|live|audio))/i',
            'default_model' => 'gemini-3.1-flash-lite',
            'context_window' => 128000,
            'models'        => [
                'gemini-3.1-flash-lite' => 'Gemini 3.1 Flash-Lite — the cheap one; reads PDFs and images',
                'gemini-3.8-flash'      => 'Gemini 3.8 Flash — stronger, three times the price',
            ],
        ],
        /*
         * Mistral AI, in France. It caches only what a request asks it to, by key: without one the
         * cached input — billed at a tenth — is never hit.
         */
        'mistral' => [
            'label'         => 'Mistral',
            'base_url'      => 'https://api.mistral.ai/v1',
            'api_key_url'   => 'https://console.mistral.ai/api-keys',
            'models_path'   => '/models',
            'balance_path'  => null,
            'token_param'   => 'max_tokens',
            'reasoning_param' => null,
            'supports_temperature' => true,
            'cache_key_param' => 'prompt_cache_key',
            'model_pattern' => '/^(mistral-(small|medium|large)|ministral)/i',
            'default_model' => 'mistral-small-2603',
            'context_window' => 128000,
            'models'        => [
                'mistral-small-2603'   => 'Mistral Small 4 — cheap, in the EU',
                'mistral-large-latest' => 'Mistral Large',
            ],
        ],
    ];

    /** What an entry holds when it does not say. */
    private const DEFAULTS = [
        'transport'            => Transport\OpenAiCompatible::class,
        'models_path'          => '/models',
        'balance_path'         => null,
        'token_param'          => 'max_tokens',
        'reasoning_param'      => null,
        'supports_temperature' => true,
        'model_pattern'        => null,
        'default_model'        => '',
        'requires_consent'     => false,
        'context_window'       => 128000,
        'models'               => [],
    ];

    /**
     * Every provider: the built-in ones, with the application's entries merged over them.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $configured = Llm::config('providers');
        $providers  = self::BUILT_IN;

        foreach (is_array($configured) ? $configured : [] as $id => $entry) {
            if (is_array($entry)) {
                $providers[(string) $id] = array_replace($providers[(string) $id] ?? [], $entry);
            }
        }

        foreach ($providers as $id => $entry) {
            $entry += self::DEFAULTS;
            $entry['label'] ??= ucfirst((string) $id);
            // Setting names are derived, so an entry never has to spell them out.
            $entry['settings'] = array_replace(self::settingNames((string) $id), (array) ($entry['settings'] ?? []));
            $providers[$id] = $entry;
        }

        return $providers;
    }

    public static function isKnown(string $provider): bool
    {
        return isset(self::all()[$provider]);
    }

    /**
     * One provider's entry; an unknown name is the default's.
     *
     * @return array<string, mixed>
     */
    public static function config(string $provider): array
    {
        $all = self::all();

        return $all[$provider] ?? $all[self::defaultProvider()] ?? reset($all);
    }

    /** The provider an application declared as its default, or the framework's. */
    public static function defaultProvider(): string
    {
        $declared = trim((string) Llm::config('default', ''));

        return $declared !== '' && isset(self::all()[$declared]) ? $declared : self::DEFAULT_PROVIDER;
    }

    /**
     * The provider in use: the one named, the one an administrator chose, or the default.
     *
     * An unknown name falls back rather than failing: a settings row holding a provider a later
     * release removed would otherwise take the whole feature down.
     */
    public static function resolve(?string $provider = null): string
    {
        $named = trim((string) $provider);

        if ($named !== '' && self::isKnown($named)) {
            return $named;
        }

        $chosen = trim((string) Settings::getSetting(self::SELECTED_SETTING, ''));

        return self::isKnown($chosen) ? $chosen : self::defaultProvider();
    }

    /** The name of the setting holding one of a provider's fields. */
    public static function settingKey(string $provider, string $field): ?string
    {
        return self::config($provider)['settings'][$field] ?? null;
    }

    /**
     * Every setting name this module owns — to keep them out of anything that prints settings.
     *
     * @return list<string>
     */
    public static function allSettingKeys(): array
    {
        $keys = [self::SELECTED_SETTING, self::TIMEOUT_SETTING, self::LOG_SETTING, Pricing::SETTING,
            Billing\OpenAiCosts::ADMIN_KEY, Billing\OpenAiCosts::TOPUPS, Billing\OpenAiCosts::PROJECT];

        foreach (self::all() as $provider) {
            $keys = [...$keys, ...array_values($provider['settings'])];
        }

        foreach (array_keys(Connection::features()) as $feature) {
            foreach (Connection::FEATURE_FIELDS as $field) {
                $keys[] = Connection::featureSetting($feature, $field);
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Whether a model id from a provider's catalogue is one worth offering.
     *
     * `/models` returns embeddings, moderation and whatever else the account can reach; a
     * dropdown of ninety entries where four can hold a conversation is one somebody picks
     * wrongly from once.
     */
    public static function isChatModel(string $provider, string $modelId): bool
    {
        $pattern = self::config($provider)['model_pattern'] ?? null;

        return $pattern === null || preg_match((string) $pattern, $modelId) === 1;
    }

    /** @return array<string, string> */
    private static function settingNames(string $provider): array
    {
        $names = [];

        foreach (self::FIELDS as $field) {
            $names[$field] = 'llm_' . $provider . '_' . $field;
        }

        return $names;
    }
}
