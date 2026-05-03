<?php

namespace Pramnos\Application;

/**
 * Central registry for framework features.
 *
 * ## Concepts
 *
 * **Registered (known) features** are features the framework (or an addon)
 * has declared via FeatureRegistry::register().  A feature must be registered
 * before it can be enabled.
 *
 * **Enabled features** are the subset the application has activated by
 * listing them in its `app.php` `features` array (loaded via loadFromConfig()).
 *
 * The `core` feature is a special built-in that is always enabled and cannot
 * be disabled.
 *
 * ## Built-in feature keys
 *
 * The framework pre-registers the following keys. Their Service Providers and
 * migrations will be wired in as Phase 2 backports are implemented.
 *
 * | Key          | Description                         |
 * |---|---|
 * | `core`       | Core framework (always active)      |
 * | `auth`       | Basic Auth System                   |
 * | `authserver` | OAuth Server                        |
 * | `messaging`  | Messaging System                    |
 * | `queue`      | Queue System                        |
 *
 * ## Usage
 *
 * In app.php:
 * ```php
 * return [
 *     'features' => ['auth', 'queue'],
 * ];
 * ```
 *
 * At bootstrap (called automatically by Application::init()):
 * ```php
 * FeatureRegistry::loadFromConfig($appInfo['features'] ?? []);
 * ```
 *
 * Checking:
 * ```php
 * FeatureRegistry::isEnabled('auth');   // true
 * FeatureRegistry::isEnabled('core');   // always true
 * ```
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @package     PramnosFramework
 * @subpackage  Application
 */
class FeatureRegistry
{
    /**
     * Map of feature key → feature definition array.
     *
     * Definition keys:
     *   description (string)  — human-readable description
     *   provider    (string|null) — FQCN of the ServiceProvider class (null until Phase 2)
     *   migrations  (string[])   — paths to migration directories
     *
     * @var array<string, array{description: string, provider: string|null, migrations: string[]}>
     */
    private static array $known = [];

    /**
     * Set of currently-enabled feature keys (values are the same keys for O(1) lookup).
     *
     * @var array<string, true>
     */
    private static array $enabled = [];

    /**
     * Whether the default built-in features have been registered.
     */
    private static bool $defaultsLoaded = false;

    // =========================================================================
    // Registration
    // =========================================================================

    /**
     * Registers a feature so it can be enabled by applications.
     *
     * Call this before Application::init() for custom features, or let the
     * framework call it during its own bootstrap for built-in ones.
     *
     * @param string          $key         Short identifier, e.g. 'auth'.
     * @param array{
     *     description?: string,
     *     provider?:    string|null,
     *     migrations?:  string[]
     * } $config Optional configuration.
     */
    public static function register(string $key, array $config = []): void
    {
        static::ensureDefaults();
        static::$known[$key] = [
            'description' => $config['description'] ?? '',
            'provider'    => $config['provider']    ?? null,
            'migrations'  => $config['migrations']  ?? [],
        ];
    }

    // =========================================================================
    // Loading from app.php
    // =========================================================================

    /**
     * Enables the features listed in the application's config array.
     *
     * Should be called once at bootstrap (Application::init() calls this
     * automatically). Calling it multiple times is safe — it accumulates
     * rather than replaces.
     *
     * @param string[] $features Feature keys declared in app.php.
     * @throws UnknownFeatureException When a key has not been registered.
     */
    public static function loadFromConfig(array $features): void
    {
        static::ensureDefaults();

        foreach ($features as $key) {
            if (!isset(static::$known[$key])) {
                throw new UnknownFeatureException($key, array_keys(static::$known));
            }
            static::$enabled[$key] = true;
        }
    }

    // =========================================================================
    // Querying
    // =========================================================================

    /**
     * Returns true if the given feature is currently enabled.
     *
     * `core` is always enabled regardless of configuration.
     *
     * @param string $key Feature key to test.
     */
    public static function isEnabled(string $key): bool
    {
        static::ensureDefaults();
        return $key === 'core' || isset(static::$enabled[$key]);
    }

    /**
     * Returns all currently-enabled feature keys, always including 'core'.
     *
     * @return string[]
     */
    public static function getEnabled(): array
    {
        static::ensureDefaults();
        $keys = array_keys(static::$enabled);
        if (!in_array('core', $keys, true)) {
            array_unshift($keys, 'core');
        }
        return $keys;
    }

    /**
     * Returns all registered (known) feature keys.
     *
     * @return string[]
     */
    public static function getKnown(): array
    {
        static::ensureDefaults();
        return array_keys(static::$known);
    }

    /**
     * Returns the FQCN of the ServiceProvider class for a feature, or null
     * when no provider has been wired up yet (expected during Phase 4 before
     * Phase 2 backports are implemented).
     *
     * @param string $key Feature key.
     * @return string|null FQCN or null.
     */
    public static function getProvider(string $key): ?string
    {
        static::ensureDefaults();
        return static::$known[$key]['provider'] ?? null;
    }

    /**
     * Returns the migration directory paths registered for a feature.
     *
     * @param string $key Feature key.
     * @return string[]
     */
    public static function getMigrationPaths(string $key): array
    {
        static::ensureDefaults();
        return static::$known[$key]['migrations'] ?? [];
    }

    /**
     * Returns the full definition array for a registered feature, or null
     * when the key is unknown.
     *
     * @param string $key
     * @return array{description: string, provider: string|null, migrations: string[]}|null
     */
    public static function getDefinition(string $key): ?array
    {
        static::ensureDefaults();
        return static::$known[$key] ?? null;
    }

    // =========================================================================
    // Defaults and state management
    // =========================================================================

    /**
     * Registers the framework's built-in feature definitions.
     *
     * Called lazily the first time any public method is invoked.  Safe to call
     * explicitly (e.g. in tests that need a clean-but-populated registry).
     */
    public static function initDefaults(): void
    {
        static::$defaultsLoaded = true;

        static::register('core', [
            'description' => 'Core framework — always active',
        ]);
        static::register('auth', [
            'description' => 'Basic Authentication and Authorization',
        ]);
        static::register('authserver', [
            'description' => 'OAuth 2.0 Authorization Server',
        ]);
        static::register('messaging', [
            'description' => 'Messaging System (threads and recipients)',
        ]);
        static::register('queue', [
            'description' => 'Background Job Queue',
        ]);
    }

    /**
     * Resets all registry state.
     *
     * Intended for use in tests only.  After a reset, the next public-method
     * call will re-trigger initDefaults() unless the caller registers its own
     * features first.
     */
    public static function reset(): void
    {
        static::$known          = [];
        static::$enabled        = [];
        static::$defaultsLoaded = false;
    }

    /**
     * Ensures the built-in defaults are registered before any lookup or load.
     */
    private static function ensureDefaults(): void
    {
        if (!static::$defaultsLoaded) {
            static::initDefaults();
        }
    }
}
