<?php

declare(strict_types=1);

namespace Pramnos\Metrics\Widgets;

use Pramnos\Dashboard\Widget;
use Pramnos\Dashboard\Widgets\Figures;
use Pramnos\Metrics\Metrics;
use Pramnos\Metrics\ReportBoard;

/**
 * A card of a report ({@see ReportBoard}): offered on reports' boards only, as many times as a
 * report wants, drawn for the report's period.
 *
 * Its settings are chosen in its form from the catalogue — a metric, its dimensions, filters —
 * through the helpers here, which every report card shares. A card reads only metrics its viewer
 * may see; the others are left out.
 */
abstract class ReportWidget extends Widget
{
    /** On a report's board, nowhere else. */
    public function boards(): ?array
    {
        return [ReportBoard::CATALOGUE];
    }

    /** As many as a report wants. */
    public function repeatable(): bool
    {
        return true;
    }

    /** Placed by the report's owner, never by default. */
    public function byDefault(): bool
    {
        return false;
    }

    /** Anybody signed in: the reports screen keeps its own floor, and each metric its own rule. */
    public function allowed(?object $user): bool
    {
        return $user !== null;
    }

    /** The whole width. */
    public function size(): string
    {
        return self::FULL;
    }

    /** The heading as set, or what the card is ({@see name()}) — in the catalogue, and on a card with none. */
    public function title(): string
    {
        $title = $this->heading();

        return $title !== '' ? $title : Figures::words($this->name());
    }

    /** The heading as set; '' for none — what a report drawn for a mail shows over the card. */
    public function heading(): string
    {
        return trim((string) $this->setting('title'));
    }

    /** What the card is, in a few words: "Table of metrics". */
    abstract protected function name(): string;

    /** The report being drawn: grain, period, comparison, columns, viewer. @return array<string, mixed> */
    protected function report(): array
    {
        return ReportBoard::context();
    }

    /** A heading, up to 120 characters. */
    protected static function titleOption(): array
    {
        return ['label' => 'Heading', 'input' => 'text', 'clean' => static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, 120) : null];
    }

    /**
     * The metrics the reader may see, `[name => label]`, for a select: the report's reader while
     * one is drawn, the person signed in otherwise — every metric with nobody, as when a report is
     * mailed by the scheduler for its owner. Filtered by `$keep` when given.
     *
     * @param (callable(\Pramnos\Metrics\Metric): bool)|null $keep
     * @return array<string, string>
     */
    protected static function metricChoices(?callable $keep = null): array
    {
        $out = [];
        foreach (self::readable() as $name => $metric) {
            if ($keep === null || $keep($metric)) {
                $out[(string) $name] = Figures::words($metric->label());
            }
        }

        return $out;
    }

    /** The metrics the reader may see — see {@see metricChoices()}. @return array<string, \Pramnos\Metrics\Metric> */
    private static function readable(): array
    {
        $user = ReportBoard::context()['user'];

        return $user === null ? Metrics::all() : Metrics::all($user, false);
    }

    /** Each metric's dimensions, as a breakdown select offers them. @return array<string, array<string, string>> */
    protected static function breakdownsBy(): array
    {
        $out = [];
        foreach (self::readable() as $name => $metric) {
            $out[(string) $name] = [];
            foreach ($metric->getDimensions() as $dimension) {
                $out[(string) $name][$dimension] = Figures::words(Metrics::dimensionOf($dimension)->label);
            }
        }

        return $out;
    }

    /**
     * A `filters` field: the dimensions with their values, and each metric's dimensions — those
     * of the field named `$by`, or all of them without one.
     *
     * @return array<string, mixed>
     */
    protected static function filtersField(string $label = 'Filters', ?string $by = 'metric'): array
    {
        $dimensions = [];
        foreach (Metrics::dimensions() as $name => $dimension) {
            $dimensions[(string) $name] = ['label' => Figures::words($dimension->label), 'values' => $dimension->all()];
        }
        $field = ['label' => $label, 'input' => 'filters', 'dimensions' => $dimensions, 'clean' => static fn (mixed $v): ?array => self::cleanFilters($v) ?: null];
        if ($by !== null) {
            $field['by']     = $by;
            $field['dimsBy'] = array_map('array_keys', self::breakdownsBy());
        }

        return $field;
    }

    /**
     * Filters as kept: `[dimension => value]`, from the editor's object or its JSON, or from a
     * list of `{dimension, value}`.
     *
     * @return array<string, string>
     */
    public static function cleanFilters(mixed $value): array
    {
        $value = is_string($value) ? json_decode($value, true) : $value;
        $out   = [];
        foreach (is_array($value) ? $value : [] as $key => $one) {
            if (is_array($one)) {
                $key = (string) ($one['dimension'] ?? '');
                $one = $one['value'] ?? '';
            }
            if (is_string($key) && preg_match('/^[a-z][a-z0-9_]*$/', $key) === 1 && is_scalar($one) && trim((string) $one) !== '') {
                $out[$key] = mb_substr(trim((string) $one), 0, 100);
            }
        }

        return $out;
    }

    /**
     * A list as kept: each item an array, at most `$most`, each cleaned by `$item` (null drops it).
     *
     * @param callable(array<string, mixed>): ?array<string, mixed> $item
     * @return list<array<string, mixed>>|null
     */
    protected static function cleanList(mixed $value, callable $item, int $most = 40): ?array
    {
        $value = is_string($value) ? json_decode($value, true) : $value;
        $out   = [];
        foreach (is_array($value) ? array_slice(array_values($value), 0, $most) : [] as $one) {
            if (is_array($one) && ($kept = $item($one)) !== null) {
                $out[] = $kept;
            }
        }

        return $out === [] ? null : $out;
    }

    /** A note in the card, escaped. */
    protected static function note(string $words): string
    {
        return '<p class="pf-muted">' . htmlspecialchars($words, ENT_QUOTES, 'UTF-8') . '</p>';
    }
}
