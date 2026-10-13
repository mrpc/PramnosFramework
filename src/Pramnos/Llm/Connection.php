<?php

declare(strict_types=1);

namespace Pramnos\Llm;

use Pramnos\Application\Settings;

/**
 * One provider, with the key, model and parameters a call goes out with.
 *
 * Built from settings — the installation's chosen provider, or the one a feature names — and
 * adjustable for one call with {@see with()}: an organisation's own key, another model. A
 * connection is a value: `with()` returns a new one and the original is untouched.
 *
 * ## Features
 *
 * An application names its uses of a model in `app.php` (`'llm' => ['features' => [...]]`), and
 * each can have its own provider, model, temperature, token budget and reasoning switch, set on
 * the administration screen. A field left empty follows the feature named in `follows`, then
 * the provider's own setting.
 *
 * ## Keys are encrypted
 *
 * A key is stored through {@see \Pramnos\Security\Encrypter} and read with `maybeDecrypt()`, so a
 * plaintext key saved before encryption was in use still works and converts the next time it is
 * saved. A key that cannot be decrypted reads as no key: sending the ciphertext would get a 401
 * nobody could explain.
 */
class Connection
{
    /** How long a balance or model list is worth keeping, in seconds. */
    public const CACHE_SECONDS = 300;

    /** The fields a feature can set for itself. */
    public const FEATURE_FIELDS = ['provider', 'model', 'temperature', 'max_tokens', 'reasoning'];

    /** The fields {@see with()} takes. */
    private const OVERRIDES = ['api_key', 'base_url', 'model', 'temperature', 'max_tokens', 'reasoning', 'own'];

    /** @var array<string, array{at: int, value: mixed}> */
    private static array $cached = [];

    /** The provider in use. */
    private readonly string $provider;

    /** The provider the feature or the installation chose, before anyone named another. */
    private readonly string $base;

    /**
     * @param string|null          $provider  Named, or the feature's own, or the one selected
     * @param string|null          $feature   One of {@see features()}, whose settings apply
     * @param array<string, mixed> $overrides Fields of {@see with()}
     */
    public function __construct(
        ?string $provider = null,
        private readonly ?string $feature = null,
        private readonly array $overrides = []
    ) {
        $base = $feature !== null ? self::featureValue($feature, 'provider') : '';

        $this->base     = Providers::resolve($base === '' ? null : $base);
        $this->provider = Providers::resolve($provider ?? $this->base);
    }

    /**
     * The same connection with some fields replaced.
     *
     * `api_key`, `base_url`, `model`, `temperature`, `max_tokens`, `reasoning`, and `own` —
     * true when the key belongs to the organisation the call is for rather than the
     * installation, which {@see ownKey()} reports to a `Policy\Limits`.
     *
     * @param array<string, mixed> $overrides
     */
    public function with(array $overrides): static
    {
        return new static(
            $this->provider,
            $this->feature,
            array_replace($this->overrides, array_intersect_key($overrides, array_flip(self::OVERRIDES)))
        );
    }

    /**
     * The same feature, on another provider: a moved answer keeps the settings that still apply.
     */
    public function onProvider(string $provider): static
    {
        return new static($provider, $this->feature);
    }

    /**
     * The application's features, by name.
     *
     * @return array<string, string> Name → what it is
     */
    public static function features(): array
    {
        $declared = Llm::config('features', []);
        $features = [];

        foreach (is_array($declared) ? $declared : [] as $name => $label) {
            // A plain list of names is fine too.
            is_int($name) ? $features[(string) $label] = (string) $label : $features[(string) $name] = (string) $label;
        }

        return $features;
    }

    /** The setting holding one field of one feature. */
    public static function featureSetting(string $feature, string $field): string
    {
        return 'llm_feature_' . $feature . '_' . $field;
    }

    /** What an administrator set for one feature, or `''` to follow. */
    public static function featureValue(string $feature, string $field, int $depth = 0): string
    {
        if (!isset(self::features()[$feature])) {
            return '';
        }

        $own     = trim((string) Settings::getSetting(self::featureSetting($feature, $field), ''));
        $follows = Llm::config('follows', []);
        $next    = is_array($follows) ? (string) ($follows[$feature] ?? '') : '';

        // A follows-chain that loops back on itself stops rather than recursing for ever.
        return $own === '' && $next !== '' && $depth < 5 ? self::featureValue($next, $field, $depth + 1) : $own;
    }

    public function feature(): ?string
    {
        return $this->feature;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return Providers::config($this->provider);
    }

    public function label(): string
    {
        return (string) $this->config()['label'];
    }

    /** Whether the key is the organisation's own rather than the installation's. */
    public function ownKey(): bool
    {
        return !empty($this->overrides['own']);
    }

    /** The key, decrypted, or `''`. */
    public function apiKey(): string
    {
        if (isset($this->overrides['api_key'])) {
            return (string) $this->overrides['api_key'];
        }

        $stored = (string) Settings::getSetting((string) Providers::settingKey($this->provider, 'api_key'), '');

        try {
            return \Pramnos\Security\Encrypter::maybeDecrypt(trim($stored));
        } catch (\Throwable) {
            return '';
        }
    }

    public function baseUrl(): string
    {
        $url = trim((string) ($this->overrides['base_url'] ?? ''));

        if ($url === '') {
            $url = trim((string) Settings::getSetting((string) Providers::settingKey($this->provider, 'base_url'), ''));
        }

        return rtrim($url !== '' ? $url : (string) ($this->config()['base_url'] ?? ''), '/');
    }

    public function model(): string
    {
        if (trim((string) ($this->overrides['model'] ?? '')) !== '') {
            return trim((string) $this->overrides['model']);
        }

        if (($feature = $this->fromFeature('model')) !== '') {
            return $feature;
        }

        $stored = trim((string) Settings::getSetting((string) Providers::settingKey($this->provider, 'model'), ''));

        return $stored !== '' ? $stored : (string) $this->config()['default_model'];
    }

    public function temperature(): float
    {
        $value = $this->overrides['temperature'] ?? $this->fromFeature('temperature');
        $value = $value !== '' ? $value : Settings::getSetting((string) Providers::settingKey($this->provider, 'temperature'), '');

        return $value === '' || $value === null || $value === false ? 0.7 : (float) $value;
    }

    /**
     * The output budget. Enough by default for a reasoning model's thinking **and** an answer:
     * a budget that covers only the answer is spent on the thinking and returns nothing.
     */
    public function maxTokens(): int
    {
        $value = (int) ($this->overrides['max_tokens'] ?? 0);
        $value = $value > 0 ? $value : (int) ($this->fromFeature('max_tokens') ?: Settings::getSetting((string) Providers::settingKey($this->provider, 'max_tokens'), 0));

        return $value > 0 ? $value : 2000;
    }

    /**
     * Whether the model may think first. Off by default, and that is a cost decision:
     * reasoning tokens are billed, and most of what an application asks does not need them.
     */
    public function reasoning(): bool
    {
        if (isset($this->overrides['reasoning'])) {
            return (bool) $this->overrides['reasoning'];
        }

        if (($feature = $this->fromFeature('reasoning')) !== '') {
            return $feature === '1';
        }

        return (string) Settings::getSetting((string) Providers::settingKey($this->provider, 'reasoning'), '0') === '1';
    }

    public function timeout(): int
    {
        $stored = (int) Settings::getSetting(Providers::TIMEOUT_SETTING, 0);

        return $stored > 0 ? min(300, $stored) : 60;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function supportsBalance(): bool
    {
        return ($this->config()['balance_path'] ?? null) !== null;
    }

    /**
     * What the provider says is left; null when it could not be read or is not published —
     * {@see supportsBalance()} tells those apart.
     *
     * @return array<string, mixed>|null
     */
    public function balance(bool $fresh = false): ?array
    {
        $path = $this->config()['balance_path'] ?? null;

        if ($path === null || !$this->isConfigured()) {
            return null;
        }

        return $this->cached('balance', $fresh, function () use ($path): ?array {
            [$status, $payload] = $this->get((string) $path);

            return $status === 200 && is_array($payload) ? $payload : null;
        });
    }

    /**
     * Whether there is credit, as far as anybody can tell: false is the provider saying the
     * account is empty, true that there is something, null that nobody can tell — which a
     * caller deciding whether to show a feature should read as yes.
     */
    public function hasCredit(): ?bool
    {
        $balance = $this->supportsBalance() ? $this->balance() : null;

        if ($balance === null || !isset($balance['balance_infos'])) {
            return null;
        }

        if (isset($balance['is_available'])) {
            return (bool) $balance['is_available'];
        }

        foreach ((array) $balance['balance_infos'] as $info) {
            if ((float) ($info['total_balance'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The chat models this connection can reach; the curated list when it cannot ask.
     *
     * @return array<string, string> Model id → what it is
     */
    public function models(bool $fresh = false): array
    {
        $curated = (array) ($this->config()['models'] ?? []);

        if (!$this->isConfigured()) {
            return $curated;
        }

        return $this->cached('models', $fresh, function () use ($curated): ?array {
            [$status, $payload] = $this->get((string) $this->config()['models_path']);

            // Null is not kept: a failed read is asked again next time, not served for minutes.
            if ($status !== 200 || !is_array($payload['data'] ?? null)) {
                return null;
            }

            $found = [];

            foreach ($payload['data'] as $model) {
                $id = (string) ($model['id'] ?? '');

                if ($id !== '' && Providers::isChatModel($this->provider, $id)) {
                    // The curated sentence where there is one: the catalogue says nothing about use.
                    $found[$id] = $curated[$id] ?? $id;
                }
            }

            ksort($found);

            return $found === [] ? null : $found;
        }) ?? $curated;
    }

    /**
     * Ask the provider whether the key works, and say what went wrong if not — a "Test" button
     * that answered only yes or no would send somebody to re-paste a key that is fine when the
     * base URL has a typo in it.
     *
     * @return array{ok: bool, says: string}
     */
    public function check(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'says' => 'No API key saved for ' . $this->label() . ' yet.'];
        }

        [$status, $payload] = $this->get((string) $this->config()['models_path']);

        return match (true) {
            $status === 200 => ['ok' => true, 'says' => $this->label() . ' answered. The key works.'],
            $status === 401 || $status === 403 => ['ok' => false, 'says' => $this->label()
                . ' refused the key (HTTP ' . $status . '). Check it has not been revoked, and that it is this provider\'s.'],
            $status === 0 => ['ok' => false, 'says' => 'Nothing answered at ' . $this->baseUrl()
                . '. Check the address and that this server can reach it.'],
            default => ['ok' => false, 'says' => $this->label() . ' answered HTTP ' . $status . '. '
                . (string) ($payload['error']['message'] ?? 'No reason given.')],
        };
    }

    /** The transport this provider speaks. */
    public function transport(): Transport\Transport
    {
        $class = (string) ($this->config()['transport'] ?? Transport\OpenAiCompatible::class);

        return is_a($class, Transport\Transport::class, true) ? new $class() : new Transport\OpenAiCompatible();
    }

    /** Forget what was read, after settings change. */
    public static function forget(): void
    {
        self::$cached = [];
    }

    /**
     * One GET against the provider, through its transport.
     *
     * @return array{0: int, 1: array<string, mixed>|null}
     */
    protected function get(string $path): array
    {
        return $this->transport()->get($this, $path);
    }

    /**
     * A feature's own value for a field.
     *
     * A model or reasoning switch set for one provider means nothing to another: an answer moved
     * elsewhere must not ask its new provider for the old one's model.
     */
    private function fromFeature(string $field): string
    {
        if ($this->feature === null) {
            return '';
        }

        if (in_array($field, ['model', 'reasoning'], true) && $this->provider !== $this->base) {
            return '';
        }

        return self::featureValue($this->feature, $field);
    }

    /** A value read from the provider, kept for {@see CACHE_SECONDS}. */
    private function cached(string $what, bool $fresh, callable $read): mixed
    {
        $key = $what . ':' . $this->provider . ':' . md5($this->baseUrl() . $this->apiKey());

        if (!$fresh && isset(self::$cached[$key]) && self::$cached[$key]['at'] > time() - self::CACHE_SECONDS) {
            return self::$cached[$key]['value'];
        }

        $value = $read();

        if ($value !== null) {
            self::$cached[$key] = ['at' => time(), 'value' => $value];
        }

        return $value;
    }
}
