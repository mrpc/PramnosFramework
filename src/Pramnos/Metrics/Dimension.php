<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

/**
 * Something a metric is broken down by — a region, a plan, a sales channel — declared once and
 * shared by every metric that names it.
 *
 * ```php
 * Metrics::dimension(Dimension::of('region', 'Region')->values(['north' => 'North', 'south' => 'South']));
 * Metrics::dimension(Dimension::of('plan', 'Plan')->values(fn (): array => Plan::labels()));
 * ```
 *
 * The values are what the screens and the assistant offer and how a stored value is named; a
 * value a source returns that is not listed is kept and shown as it is.
 */
final class Dimension
{
    /** @var array<string, string>|\Closure(): array<string, string> */
    private array|\Closure $values = [];

    private function __construct(public readonly string $name, public readonly string $label)
    {
    }

    /** A dimension by name — the key a source's rows carry — and the words for it. */
    public static function of(string $name, string $label = ''): self
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,40}$/', $name)) {
            throw new \InvalidArgumentException('A dimension\'s name is lower-case letters, digits and _: "' . $name . '" is not.');
        }

        return new self($name, $label !== '' ? $label : $name);
    }

    /**
     * The values it takes, `[value => label]`, or a function answering them when asked.
     *
     * @param array<string, string>|\Closure(): array<string, string> $values
     */
    public function values(array|\Closure $values): self
    {
        $this->values = $values;

        return $this;
    }

    /** @return array<string, string> The values, `[value => label]` */
    public function all(): array
    {
        $values = $this->values instanceof \Closure ? ($this->values)() : $this->values;

        return array_map('strval', is_array($values) ? $values : []);
    }

    /** One value's words: its label, or the value itself. */
    public function labelOf(string $value): string
    {
        return $this->all()[$value] ?? $value;
    }
}
