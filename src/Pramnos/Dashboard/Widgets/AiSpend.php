<?php

declare(strict_types=1);

namespace Pramnos\Dashboard\Widgets;

use Pramnos\Dashboard\Widget;
use Pramnos\Html\StatTile;

/**
 * What the language models cost over the last 30 days: the cost, the calls, the calls that failed.
 * Offered only where a provider's key is set — before that there is nothing to spend — and opens
 * the AI settings, where the log is. Read through the analyst's `LlmSpendTool`.
 */
final class AiSpend extends Widget
{
    use \Pramnos\Dashboard\HasPeriod;

    /** The last 30 days, unless set otherwise. */
    public function defaultPeriod(): string
    {
        return '30';
    }

    /** The period, as each person sets it. */
    public function options(): array
    {
        return $this->periodOptions();
    }

    public function id(): string
    {
        return 'ai.spend';
    }

    public function title(): string
    {
        return 'AI spend';
    }

    /** What it shows, for the list cards are chosen from. */
    public function description(): string
    {
        return 'Language-model calls in the period chosen: what they cost, how many, how many failed.';
    }

    /** Where the AI feature is on and has a key, for whoever may open its screen. */
    public function allowed(?object $user): bool
    {
        return \Pramnos\Application\FeatureRegistry::isEnabled('llm')
            && Figures::opens($user, Figures::ability($this->id(), 'admin.ai'))
            && \Pramnos\Llm\Analyst\Analyst::available();
    }

    public function link(): ?string
    {
        return Figures::link($this->id(), 'Ai/settings');
    }

    public function render(): string
    {
        $spend = (new \Pramnos\Llm\Analyst\Tools\LlmSpendTool())->execute($this->window());
        if (!is_array($spend) || ($spend['available'] ?? true) === false) {
            return '';
        }
        $calls  = (int) ($spend['calls'] ?? 0);
        $failed = (int) ($spend['failed'] ?? 0);

        return StatTile::grid([
            StatTile::render(Figures::words('Cost'), '$' . number_format((float) ($spend['cost'] ?? 0), 2), number_format((int) ($spend['tokens'] ?? 0)) . ' ' . Figures::words('tokens'), $this->link()),
            StatTile::render(Figures::words('Calls'), number_format($calls), $this->periodLabel()),
            StatTile::render(Figures::words('Failed'), number_format($failed), state: $calls > 0 && $failed / $calls >= 0.05 ? StatTile::WARNING : null),
        ]);
    }
}
