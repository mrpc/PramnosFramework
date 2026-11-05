<?php

declare(strict_types=1);

namespace Pramnos\Llm\Eval;

/**
 * A {@see Suite}'s scores, and how they compare with a baseline.
 *
 * | Gate | Fails when |
 * |---|---|
 * | `tool_accuracy` | it falls by more than {@see ACCURACY_DROP} (five points of a hundred) |
 * | `argument_fidelity` | it falls by more than {@see ACCURACY_DROP} |
 * | `rounds` | the mean rises by more than {@see ROUNDS_RISE} (a fifth): the same answers costing more |
 * | `skill_used`, `assertions` | it falls by more than {@see ACCURACY_DROP}, over the cases that score it |
 *
 * The baseline is {@see toArray()} written to a file, kept in the repository beside the
 * fixtures: an improvement is taken by writing it again, a regression is seen in review.
 */
final class Report
{
    /** How far accuracy and fidelity may fall, on a scale of 0 to 1. */
    public const ACCURACY_DROP = 0.05;

    /** Scores only some cases have, each gated over the cases that have it. */
    public const MEASURED = ['skill_used', 'assertions'];

    /** How far the mean of rounds may rise, as a share of the baseline's. */
    public const ROUNDS_RISE = 0.20;

    /** @param list<array<string, mixed>> $cases Each case's scores */
    public function __construct(public readonly array $cases)
    {
    }

    /** A report as {@see toArray()} wrote it. */
    public static function fromArray(array $saved): self
    {
        return new self(array_values((array) ($saved['cases'] ?? [])));
    }

    /** The mean of one score over the cases; 0 with none. */
    public function mean(string $score): float
    {
        if ($this->cases === []) {
            return 0.0;
        }

        return array_sum(array_map(static fn (array $c): float => (float) ($c[$score] ?? 0), $this->cases)) / count($this->cases);
    }

    /**
     * What has got worse than in `$baseline`, one line each; empty when nothing has.
     *
     * @return list<string>
     */
    public function regressions(self $baseline): array
    {
        $out = [];
        foreach (['tool_accuracy', 'argument_fidelity'] as $score) {
            $was = $baseline->mean($score);
            $is  = $this->mean($score);
            if ($was - $is > self::ACCURACY_DROP + 1e-9) {
                $out[] = sprintf('%s fell from %.1f%% to %.1f%% (more than %d points).', $score, $was * 100, $is * 100, (int) round(self::ACCURACY_DROP * 100));
            }
        }

        // Over the cases that score them, on both sides: a baseline from before had none.
        foreach (self::MEASURED as $score) {
            if (!$baseline->scored($score) || !$this->scored($score)) {
                continue;
            }
            $was = $baseline->measured($score);
            $is  = $this->measured($score);
            if ($was - $is > self::ACCURACY_DROP + 1e-9) {
                $out[] = sprintf('%s fell from %.1f%% to %.1f%% (more than %d points).', $score, $was * 100, $is * 100, (int) round(self::ACCURACY_DROP * 100));
            }
        }

        $was = $baseline->mean('rounds');
        $is  = $this->mean('rounds');
        if ($was > 0 && ($is - $was) / $was > self::ROUNDS_RISE + 1e-9) {
            $out[] = sprintf('rounds rose from %.2f to %.2f a question (more than %d%%).', $was, $is, (int) round(self::ROUNDS_RISE * 100));
        }

        return $out;
    }

    /** Whether any case scores this. */
    public function scored(string $score): bool
    {
        foreach ($this->cases as $case) {
            if (array_key_exists($score, $case)) {
                return true;
            }
        }

        return false;
    }

    /** The mean of one score over the cases that score it; 0 with none. */
    public function measured(string $score): float
    {
        $values = array_map(static fn (array $c): float => (float) $c[$score], array_values(array_filter($this->cases, static fn (array $c): bool => array_key_exists($score, $c))));

        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    /** @return array{summary: array<string, float>, cases: list<array<string, mixed>>} */
    public function toArray(): array
    {
        $summary = [
            'tool_accuracy'     => round($this->mean('tool_accuracy'), 4),
            'argument_fidelity' => round($this->mean('argument_fidelity'), 4),
            'final_state'       => round($this->mean('final_state'), 4),
            'rounds'            => round($this->mean('rounds'), 4),
        ];
        foreach (self::MEASURED as $score) {
            if ($this->scored($score)) {
                $summary[$score] = round($this->measured($score), 4);
            }
        }

        return ['summary' => $summary, 'cases' => $this->cases];
    }
}
