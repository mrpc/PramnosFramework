<?php

declare(strict_types=1);

namespace Pramnos\Metrics\Widgets;

use Pramnos\Dashboard\Widgets\Figures;

/**
 * Words on a report — what the numbers mean, what to look at — written in markdown and shown as
 * the assistant's answers are: escaped, then headings, lists, emphasis, links and tables.
 *
 * Settings: `title`, `text`.
 */
class TextWidget extends ReportWidget
{
    public function id(): string
    {
        return 'report.text';
    }

    protected function name(): string
    {
        return 'Text';
    }

    public function description(): string
    {
        return Figures::words('Words of your own, in markdown');
    }

    public function options(): array
    {
        return [
            'title' => self::titleOption(),
            'text'  => ['label' => 'Text (markdown)', 'input' => 'textarea', 'clean' => static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? mb_substr($v, 0, 10000) : null],
        ];
    }

    public function render(): string
    {
        $text = (string) $this->setting('text');

        return $text === '' ? self::note(Figures::words('Write the text in the card\'s settings.')) : \Pramnos\Llm\Chat\Markdown::toHtml($text);
    }
}
