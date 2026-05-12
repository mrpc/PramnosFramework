<?php

namespace Pramnos\Support;

/**
 * Proxy returned by Faker::unique().
 *
 * Every method call on this proxy forwards to the underlying Faker generator
 * but re-tries until a value is produced that has not been seen before for
 * that method name. The registry is per-method, so unique()->email() and
 * unique()->name() track their values independently.
 *
 * @package    PramnosFramework
 * @subpackage Support
 */
class FakerUniqueProxy
{
    /**
     * Seen values indexed by [method][serialized_value].
     *
     * @var array<string, array<string, bool>>
     */
    private array $seen = [];

    public function __construct(private readonly Faker $generator, private int $maxRetries = 10_000) {}

    /**
     * Forward to the generator, retrying until a unique value is produced.
     *
     * @throws \OverflowException After $maxRetries attempts without a new value.
     */
    public function __call(string $name, array $args): mixed
    {
        for ($i = 0; $i < $this->maxRetries; $i++) {
            $value = $this->generator->$name(...$args);
            $key   = serialize($value);

            if (!isset($this->seen[$name][$key])) {
                $this->seen[$name][$key] = true;
                return $value;
            }
        }

        throw new \OverflowException( // @codeCoverageIgnore
            "Unable to generate a unique value for '{$name}' after {$this->maxRetries} retries" // @codeCoverageIgnore
        ); // @codeCoverageIgnore
    }

    /**
     * Reset uniqueness tracking.
     *
     * @param string $method  Specific method to reset, or '' to reset all.
     */
    public function reset(string $method = ''): void
    {
        if ($method === '') {
            $this->seen = [];
        } else {
            unset($this->seen[$method]);
        }
    }

    /** Expose seen registry for testing. */
    public function getSeen(): array
    {
        return $this->seen;
    }
}
