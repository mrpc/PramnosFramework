<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Apps;

/**
 * Decides which {@see AppRegistryInterface} an installation uses, from its config
 * and its enabled features.
 *
 * ## Why this takes the feature list as an argument
 *
 * The obvious implementation asks `FeatureRegistry::isEnabled('authserver')`. That
 * would have been wrong in a way nothing reports. `FeatureRegistry` is populated
 * from `app.php` by `Application::init()`, which the web lifecycle runs and a
 * console command did not — so the daemon verifying a channel signature and the
 * web request that produced it would have read the same `app.php` and reached
 * opposite conclusions about where app keys come from, silently. (The console
 * bootstrap now loads the list, but a security decision should not depend on
 * bootstrap order having been fixed.)
 *
 * So resolution is a pure function of two plain arrays. It gives the same answer
 * in a web request, in a worker, and in a test, and it can be asserted directly.
 */
final class AppSource
{
    public const CONFIG     = 'config';
    public const AUTHSERVER = 'authserver';

    /**
     * Resolve `broadcasting.apps.source` to a concrete source name.
     *
     * @param array<string,mixed> $broadcasting The `broadcasting` config array.
     * @param string[]            $features     `app.php`'s `features` array.
     * @return self::CONFIG|self::AUTHSERVER
     * @throws \RuntimeException When 'authserver' is named explicitly but the
     *         feature is not enabled. That combination is a misconfiguration
     *         rather than something to paper over: falling back to the config
     *         registry would quietly authorize channels against a different
     *         secret than the operator asked for.
     */
    public static function resolve(array $broadcasting, array $features): string
    {
        $apps      = is_array($broadcasting['apps'] ?? null) ? $broadcasting['apps'] : [];
        $requested = strtolower((string) ($apps['source'] ?? 'auto'));

        $authserverEnabled = in_array('authserver', $features, true);

        return match ($requested) {
            'auto', ''      => $authserverEnabled ? self::AUTHSERVER : self::CONFIG,
            self::CONFIG    => self::CONFIG,
            self::AUTHSERVER => $authserverEnabled
                ? self::AUTHSERVER
                : throw new \RuntimeException(
                    "broadcasting.apps.source is 'authserver', but the 'authserver' feature is "
                    . "not enabled in app.php. Add it to features, or set the source to 'config'."
                ),
            default => throw new \RuntimeException(
                "Unknown broadcasting.apps.source '{$requested}'. Valid values: auto, config, authserver."
            ),
        };
    }

    /**
     * Build the registry the resolved source calls for.
     *
     * @param array<string,mixed> $broadcasting
     * @param string[]            $features
     * @param int                 $ttl Cache TTL for the authserver registry. Pass
     *                                 0 from a web request (one lookup then exit)
     *                                 and a positive value from a daemon.
     */
    public static function registry(array $broadcasting, array $features, int $ttl = 0): AppRegistryInterface
    {
        return match (self::resolve($broadcasting, $features)) {
            self::AUTHSERVER => new AuthServerAppRegistry($ttl),
            default          => new ConfigAppRegistry($broadcasting),
        };
    }
}
