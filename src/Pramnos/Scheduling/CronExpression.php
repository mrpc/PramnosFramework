<?php

namespace Pramnos\Scheduling;

/**
 * Parses and evaluates standard 5-field cron expressions.
 *
 * Field order: minute hour day-of-month month day-of-week
 *
 * Supports:
 *   - `*`        — any value
 *   - `5`        — specific value
 *   - `1-5`      — inclusive range
 *   - `*\/5`     — step over the full range
 *   - `1-15\/3`  — step within a range
 *   - `1,3,5`    — comma-separated list (each item may itself be a range or step)
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @package     PramnosFramework
 * @subpackage  Scheduling
 */
class CronExpression
{
    /** Minimum and maximum for each cron field index. */
    private const FIELD_RANGES = [
        0 => [0, 59],   // minute
        1 => [0, 23],   // hour
        2 => [1, 31],   // day-of-month
        3 => [1, 12],   // month
        4 => [0, 6],    // day-of-week (0 = Sunday)
    ];

    private readonly string $expression;

    /** @var string[] The five raw field strings. */
    private readonly array $fields;

    /**
     * @param string $expression A 5-field cron expression, e.g. '0 2 * * *'.
     * @throws \InvalidArgumentException When the expression does not have exactly 5 fields.
     */
    public function __construct(string $expression)
    {
        $parts = preg_split('/\s+/', trim($expression));
        if (count($parts) !== 5) {
            throw new \InvalidArgumentException(
                "Cron expression must have exactly 5 fields, got: '{$expression}'"
            );
        }

        $this->expression = $expression;
        $this->fields     = $parts;
    }

    /**
     * Returns the original expression string.
     */
    public function getExpression(): string
    {
        return $this->expression;
    }

    /**
     * Returns a new CronExpression with the minute and hour fields replaced by
     * the given time string ('HH:MM' or 'H:MM').
     *
     * Used to implement fluent ->at('02:30') chaining on daily/weekly tasks.
     *
     * @param string $time 'HH:MM' or 'H:MM'
     */
    public function withTime(string $time): self
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            throw new \InvalidArgumentException("Invalid time string '{$time}'. Expected HH:MM.");
        }

        $hour   = (int) $m[1];
        $minute = (int) $m[2];

        if ($hour > 23 || $minute > 59) {
            throw new \InvalidArgumentException("Time '{$time}' is out of range (00:00–23:59).");
        }

        $fields    = $this->fields;
        $fields[0] = (string) $minute;
        $fields[1] = (string) $hour;

        return new self(implode(' ', $fields));
    }

    /**
     * Returns true when the expression is due at the given moment.
     *
     * Evaluation is based on the date/time components of $time, rounded to the
     * nearest minute (seconds are ignored).
     */
    public function isDue(\DateTimeInterface $time): bool
    {
        $values = [
            0 => (int) $time->format('i'),  // minute
            1 => (int) $time->format('G'),  // hour (0-23)
            2 => (int) $time->format('j'),  // day of month
            3 => (int) $time->format('n'),  // month
            4 => (int) $time->format('w'),  // day of week (0=Sun)
        ];

        foreach ($this->fields as $index => $field) {
            [$min, $max] = self::FIELD_RANGES[$index];
            if (!$this->fieldMatches($field, $values[$index], $min, $max)) {
                return false;
            }
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Internal parsing
    // -------------------------------------------------------------------------

    private function fieldMatches(string $field, int $value, int $min, int $max): bool
    {
        // Comma-separated list: each part is evaluated independently
        if (str_contains($field, ',')) {
            foreach (explode(',', $field) as $part) {
                if ($this->fieldMatches(trim($part), $value, $min, $max)) {
                    return true;
                }
            }
            return false;
        }

        // Step: */N  or  start-end/N
        if (str_contains($field, '/')) {
            [$rangeStr, $stepStr] = explode('/', $field, 2);
            $step = (int) $stepStr;
            if ($step < 1) {
                return false;
            }

            if ($rangeStr === '*') {
                $start = $min;
                $end   = $max;
            } elseif (str_contains($rangeStr, '-')) {
                [$start, $end] = $this->parseRange($rangeStr);
            } else {
                $start = (int) $rangeStr;
                $end   = $max;
            }

            for ($v = $start; $v <= $end; $v += $step) {
                if ($v === $value) {
                    return true;
                }
            }
            return false;
        }

        // Range: N-M
        if (str_contains($field, '-')) {
            [$start, $end] = $this->parseRange($field);
            return $value >= $start && $value <= $end;
        }

        // Wildcard
        if ($field === '*') {
            return true;
        }

        // Exact value
        return (int) $field === $value;
    }

    /** @return int[] [start, end] */
    private function parseRange(string $range): array
    {
        [$start, $end] = explode('-', $range, 2);
        return [(int) $start, (int) $end];
    }
}
