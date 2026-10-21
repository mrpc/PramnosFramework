<?php

declare(strict_types=1);

namespace Pramnos\Audience;

/**
 * One thing a filter can ask about a person: where it is read from, what type it is, and how
 * the admin shows it.
 *
 * ```php
 * Attribute::column('usertype', 'usertype', Attribute::LIST)
 *     ->label(t('Account type'))->values([1 => t('Parent'), 2 => t('Professional')]);
 *
 * Attribute::column('pillar', 'pillar', Attribute::NUMBER)->from('professionals');   // one-to-one
 * Attribute::many('interests', 'userinterests', 'interestid')->values($labels);       // one-to-many
 * Attribute::expression('validated', 'validated % 2', Attribute::BOOLEAN);           // computed
 * ```
 *
 * A person is one row of the users table ({@see Attributes::TABLE}). A one-to-one side table is
 * LEFT JOINed under its own name, only when a condition uses it, so a person without a row there
 * has an empty value rather than no row. A one-to-many table is never joined: it is asked with
 * EXISTS, so a count stays a count of people.
 */
final class Attribute
{
    public const TEXT    = 'text';
    public const NUMBER  = 'number';
    public const DATE    = 'date';
    public const BOOLEAN = 'boolean';
    public const LIST    = 'list';
    public const MANY    = 'many';

    /** The operators each type offers, in the order the filter builder lists them. */
    public const OPERATORS = [
        self::TEXT    => ['is', 'is_not', 'one_of', 'not_one_of', 'contains', 'not_contains', 'starts_with', 'empty', 'not_empty'],
        self::NUMBER  => ['is', 'is_not', 'gt', 'lt', 'between', 'one_of', 'not_one_of', 'empty', 'not_empty'],
        self::DATE    => ['after', 'before', 'between', 'more_than_days_ago', 'less_than_days_ago', 'empty', 'not_empty'],
        self::BOOLEAN => ['true', 'false'],
        self::LIST    => ['is', 'is_not', 'one_of', 'not_one_of', 'empty', 'not_empty'],
        self::MANY    => ['any_of', 'all_of', 'none_of', 'empty', 'not_empty'],
    ];

    private string $label = '';
    private string $group = '';
    private string $ability = '';
    /** @var array<int|string, string>|\Closure(): array<int|string, string> */
    private array|\Closure $values = [];
    private ?string $table = null;
    private string $foreignKey = 'userid';
    private string $dateFormat = 'unix';

    /**
     * @param string|array<string, string> $source A column, or an SQL expression (one per driver
     *                                             when the two differ: `['mysql' => …, 'postgresql' => …]`)
     */
    private function __construct(
        private readonly string $key,
        private readonly string $type,
        private readonly string|array $source,
        private readonly bool $isExpression = false,
    ) {
        if (!isset(self::OPERATORS[$type])) {
            throw new \InvalidArgumentException("Attribute {$key}: unknown type {$type}.");
        }
    }

    /** A column of the users table, or of a one-to-one table named with {@see from()}. */
    public static function column(string $key, string $column, string $type): self
    {
        return new self($key, $type, $column);
    }

    /**
     * A computed value. The expression is SQL over the users table's columns — the one thing the
     * query builder cannot write for the application — and is never given user input: the value
     * compared with it is always bound.
     *
     * @param string|array<string, string> $sql One expression, or one per driver
     */
    public static function expression(string $key, string|array $sql, string $type): self
    {
        return new self($key, $type, $sql, true);
    }

    /** Values in a one-to-many table — interests, groups: a person has none, one or many. */
    public static function many(string $key, string $table, string $valueColumn, string $foreignKey = 'userid'): self
    {
        $attribute             = new self($key, self::MANY, $valueColumn);
        $attribute->table      = $table;
        $attribute->foreignKey = $foreignKey;

        return $attribute;
    }

    /** Read the column from a one-to-one table, joined on its `$foreignKey` = users.userid. */
    public function from(string $table, string $foreignKey = 'userid'): self
    {
        $this->table      = $table;
        $this->foreignKey = $foreignKey;

        return $this;
    }

    /** The name the admin shows, already translated. */
    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /** The heading the filter builder lists it under. */
    public function group(string $group): self
    {
        $this->group = $group;

        return $this;
    }

    /** Only accounts with this ability see it, or filter by it. */
    public function ability(string $ability): self
    {
        $this->ability = $ability;

        return $this;
    }

    /**
     * The values it takes and their labels — a list's choices, a many's options. A closure is
     * asked only when the labels are needed, so a choice list read from a table costs nothing
     * on a count.
     *
     * @param array<int|string, string>|\Closure(): array<int|string, string> $values
     */
    public function values(array|\Closure $values): self
    {
        $this->values = $values;

        return $this;
    }

    /** How a date is stored: `unix` (seconds, the default), `datetime` or `date`. */
    public function storedAs(string $format): self
    {
        if (!in_array($format, ['unix', 'datetime', 'date'], true)) {
            throw new \InvalidArgumentException("Attribute {$this->key}: unknown date format {$format}.");
        }
        $this->dateFormat = $format;

        return $this;
    }

    /** Its key in a filter. */
    public function key(): string
    {
        return $this->key;
    }

    /** One of the type constants. */
    public function type(): string
    {
        return $this->type;
    }

    /** The label, or the key when none was given. */
    public function labelText(): string
    {
        return $this->label !== '' ? $this->label : $this->key;
    }

    /** The builder heading. */
    public function groupName(): string
    {
        return $this->group;
    }

    /** The ability that guards it; empty when anybody with the screen may use it. */
    public function requiredAbility(): string
    {
        return $this->ability;
    }

    /** @return array<int|string, string> */
    public function valueLabels(): array
    {
        return $this->values instanceof \Closure ? ($this->values)() : $this->values;
    }

    /** The side table, or null for a column of the users table. */
    public function table(): ?string
    {
        return $this->table;
    }

    /** The side table's column that holds users.userid. */
    public function foreignKey(): string
    {
        return $this->foreignKey;
    }

    /** `unix`, `datetime` or `date`. */
    public function dateFormat(): string
    {
        return $this->dateFormat;
    }

    /** Whether the source is an SQL expression rather than a column. */
    public function isExpression(): bool
    {
        return $this->isExpression;
    }

    /** The column a many reads its values from. */
    public function valueColumn(): string
    {
        return (string) (is_array($this->source) ? reset($this->source) : $this->source);
    }

    /**
     * What the WHERE compares: a qualified column, or the expression in parentheses.
     *
     * @param string $driver `mysql` or `postgresql`
     */
    public function sql(string $driver): string
    {
        if ($this->isExpression) {
            $sql = is_array($this->source) ? ($this->source[$driver] ?? reset($this->source)) : $this->source;

            return '(' . $sql . ')';
        }

        return ($this->table ?? Attributes::TABLE) . '.' . $this->source;
    }

    /** @return list<string> */
    public function operators(): array
    {
        return self::OPERATORS[$this->type];
    }

    /** What the filter builder needs to draw it. */
    public function toArray(): array
    {
        return [
            'key'       => $this->key,
            'label'     => $this->labelText(),
            'type'      => $this->type,
            'group'     => $this->group,
            'operators' => $this->operators(),
            'values'    => array_map(
                static fn ($value, $label) => ['value' => $value, 'label' => $label],
                array_keys($labels = $this->valueLabels()),
                $labels
            ),
        ];
    }
}
