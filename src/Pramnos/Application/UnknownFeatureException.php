<?php

namespace Pramnos\Application;

/**
 * Thrown by FeatureRegistry when an application enables a feature key that
 * has not been registered with the framework.
 *
 * The message includes the unknown key and the list of currently known feature
 * keys so developers can diagnose the problem immediately.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class UnknownFeatureException extends \RuntimeException
{
    /** @var string The feature key that triggered this exception. */
    private string $featureKey;

    /**
     * @param string   $key       The unrecognised feature key.
     * @param string[] $knownKeys All currently registered (known) feature keys.
     */
    public function __construct(string $key, array $knownKeys = [])
    {
        $this->featureKey = $key;

        $hint = empty($knownKeys)
            ? 'No features are currently registered.'
            : 'Known features: ' . implode(', ', $knownKeys) . '.';

        parent::__construct(
            "Unknown feature '{$key}'. {$hint} "
            . "Register the feature with FeatureRegistry::register() before enabling it."
        );
    }

    /**
     * Returns the feature key that caused this exception.
     */
    public function getFeatureKey(): string
    {
        return $this->featureKey;
    }
}
