<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Html;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * An alert in the Tailwind views lays its text out as one block.
 *
 * daisyUI's `.alert` is a grid that gives each child a column of its own — meant for an icon
 * beside its message. An alert written as `<strong>Title</strong> text <p>more</p>` therefore
 * became three narrow columns, the middle text breaking word by word: the Services screen's
 * "The orchestrator is not running" read that way at 1300px. Such an alert carries `block` (or
 * `flex-col`), which these views already use where they got it right.
 */
#[CoversNothing]
final class TailwindAlertLayoutTest extends TestCase
{
    /**
     * Every alert whose content is more than one node — text beside an element, or a paragraph —
     * is a block, not daisyUI's grid.
     */
    public function testAnAlertWithTextAndElementsIsABlock(): void
    {
        // Arrange
        $root  = dirname(__DIR__, 3) . '/scaffolding/themes/tailwind/views';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $seen  = 0;
        $wrong = [];

        // Act
        foreach ($files as $file) {
            if (!str_ends_with((string) $file, '.php')) {
                continue;
            }
            // The markup only: PHP in it is replaced by a word, as the page would print one.
            $html = (string) preg_replace('/<\?(?:php|=).*?\?>/s', 'x', (string) file_get_contents((string) $file));
            $dom  = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');
            libxml_clear_errors();
            foreach ((new \DOMXPath($dom))->query('//*[contains(concat(" ", normalize-space(@class), " "), " alert ")]') as $alert) {
                $seen++;
                $nodes = 0;
                $mixed = false;
                foreach ($alert->childNodes as $child) {
                    if ($child instanceof \DOMText) {
                        if (trim($child->textContent) !== '') {
                            $nodes++;
                            $mixed = true;
                        }
                    } elseif ($child instanceof \DOMElement) {
                        $nodes++;
                        $mixed = $mixed || in_array($child->tagName, ['p', 'div', 'ul', 'form', 'table'], true);
                    }
                }
                $classes = ' ' . $alert->getAttribute('class') . ' ';
                if ($nodes > 1 && $mixed && !str_contains($classes, ' block ') && !str_contains($classes, ' flex-col ')) {
                    $wrong[] = substr((string) $file, strlen($root) + 1) . ': ' . trim(substr($alert->textContent, 0, 60));
                }
            }
        }

        // Assert
        $this->assertGreaterThan(0, $seen, 'the sweep found nothing to check');
        $this->assertSame([], $wrong, 'alerts laid out in columns: add `block`');
    }
}
