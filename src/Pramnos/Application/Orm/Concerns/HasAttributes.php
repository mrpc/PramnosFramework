<?php

declare(strict_types=1);

namespace Pramnos\Application\Orm\Concerns;

/**
 * Handles attribute casting, accessors/mutators, and mass-assignment protection.
 *
 * ## Mass Assignment
 * Define `$fillable` (allow-list) or `$guarded` (deny-list).  `fill()` applies
 * the policy before setting values.
 *
 * ## Casting
 * `$casts` maps field names to built-in cast types:
 * `int`, `float`, `bool`, `string`, `array`, `json`, `datetime`.
 *
 * ## Accessors / Mutators
 * Methods named `getXxxAttribute($value)` / `setXxxAttribute($value)` are
 * automatically invoked when reading / writing `$model->xxx`.
 *
 */
trait HasAttributes
{
    /**
     * Allow-list of attributes that may be mass-assigned.
     * An empty array means *nothing* is fillable via fill() when $guarded = [].
     *
     * @var string[]
     */
    protected array $fillable = [];

    /**
     * Deny-list for mass assignment.  `['*']` blocks everything (default).
     * Set to `[]` to disable protection entirely (not recommended).
     *
     * @var string[]
     */
    protected array $guarded = ['*'];

    /**
     * Attribute → cast-type map.
     * Supported types: int, integer, float, double, bool, boolean,
     *                  string, array, json, datetime, date, timestamp.
     *
     * @var array<string, string>
     */
    protected array $casts = [];

    // -------------------------------------------------------------------------
    // Mass Assignment
    // -------------------------------------------------------------------------

    /**
     * Bulk-assign attributes, respecting $fillable/$guarded.
     *
     * @param  array<string, mixed> $attributes
     * @return static
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable((string) $key)) {
                $this->$key = $value;
            }
        }
        return $this;
    }

    public function isFillable(string $key): bool
    {
        // If $fillable is set, only those keys are allowed
        if (!empty($this->fillable)) {
            return in_array($key, $this->fillable, true);
        }
        // If $guarded = ['*'], block everything not in fillable
        if (in_array('*', $this->guarded, true)) {
            return false;
        }
        // Otherwise, anything not in guarded is fillable
        return !in_array($key, $this->guarded, true);
    }

    public function isGuarded(string $key): bool
    {
        return !$this->isFillable($key);
    }

    // -------------------------------------------------------------------------
    // Casting
    // -------------------------------------------------------------------------

    /**
     * Cast an outgoing attribute value to the declared type.
     */
    protected function castAttribute(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match (strtolower($this->casts[$key] ?? '')) {
            'int', 'integer'        => (int) $value,
            'float', 'double'       => (float) $value,
            'bool', 'boolean'       => (bool) $value,
            'string'                => (string) $value,
            'array', 'json'         => is_string($value)
                                          ? (json_decode($value, true) ?? [])
                                          : (array) $value,
            'datetime', 'date'      => $value instanceof \DateTimeInterface
                                          ? $value
                                          : new \DateTimeImmutable((string) $value),
            'timestamp'             => is_numeric($value)
                                          ? (int) $value
                                          : (new \DateTimeImmutable((string) $value))->getTimestamp(),
            default                 => $value,
        };
    }

    /**
     * Prepare an incoming value for storage (reverse of castAttribute).
     */
    protected function decastAttribute(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match (strtolower($this->casts[$key] ?? '')) {
            'array', 'json'    => is_array($value) ? json_encode($value) : $value,
            'datetime', 'date' => $value instanceof \DateTimeInterface
                                     ? $value->format('Y-m-d H:i:s')
                                     : $value,
            'timestamp'        => $value instanceof \DateTimeInterface
                                     ? $value->getTimestamp()
                                     : $value,
            default            => $value,
        };
    }

    /**
     * Return true if $key has a declared cast.
     */
    protected function hasCast(string $key): bool
    {
        return array_key_exists($key, $this->casts);
    }

    // -------------------------------------------------------------------------
    // Accessor / Mutator resolution
    // -------------------------------------------------------------------------

    /**
     * Convert a snake_case field name to a StudlyCase accessor/mutator name.
     * e.g. 'first_name' → 'FirstName' (so 'getFirstNameAttribute')
     */
    protected function studly(string $key): string
    {
        return str_replace('_', '', ucwords($key, '_'));
    }

    /**
     * If a getXxxAttribute() method exists, invoke it and return the result.
     * Returns [$hasAccessor, $value].
     *
     * @return array{bool, mixed}
     */
    protected function getAccessorValue(string $key, mixed $rawValue): array
    {
        $method = 'get' . $this->studly($key) . 'Attribute';
        if (method_exists($this, $method)) {
            return [true, $this->$method($rawValue)];
        }
        return [false, $rawValue];
    }

    /**
     * If a setXxxAttribute() method exists, invoke it and return the result.
     * Returns [$hasMutator, $transformedValue].
     *
     * @return array{bool, mixed}
     */
    protected function getMutatorValue(string $key, mixed $value): array
    {
        $method = 'set' . $this->studly($key) . 'Attribute';
        if (method_exists($this, $method)) {
            return [true, $this->$method($value)];
        }
        return [false, $value];
    }
}
