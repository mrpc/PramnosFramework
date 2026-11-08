<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Theme\NeutralLayout;

/**
 * The Ask screens of the `bootstrap` theme, whatever Bootstrap the host administration loads.
 *
 * Why it matters: those screens are drawn inside an application's own administration. One on
 * Bootstrap 3 has no `d-flex`, `gap-*`, `card-body` or `form-select`, and its `.container` is 1170px
 * wide beside a 250px menu: the screens ran off the right edge, cards lost their padding and a
 * select shrank to a 20px strip. So each screen prints the framework's own layout rules and takes
 * its layout from them, keeping to the Bootstrap classes both versions have.
 */
#[CoversClass(NeutralLayout::class)]
class NeutralLayoutTest extends TestCase
{
    /** The screens: settings, the application's skills, My skills, memories, scheduled, everybody's, reviews, a review, a call, Ask. */
    private const VIEWS = ['ai/ai', 'ai/skillcatalog', 'chat/skills', 'ai/memories', 'chat/scheduled', 'ai/readonly', 'ai/reviews', 'ai/review', 'ai/call', 'chat/chat'];

    /** Layout classes only Bootstrap 5 has. */
    private const ONLY_FIVE = '/class="[^"]*\b(d-flex|flex-column|flex-wrap|gap-\d|py-\d|px-\d|card-body|container)(?=[\s"])/';

    /**
     * Each screen prints the rules once, before its content, and uses none of Bootstrap 5's layout
     * classes — nor `container`, a fixed width on Bootstrap 3 — in its markup or its class map; a
     * Bootstrap 5 `form-select` always comes with Bootstrap 3's `form-control`.
     */
    public function testEachScreenCarriesItsOwnLayout(): void
    {
        foreach (self::VIEWS as $view) {
            // Arrange
            $source = (string) file_get_contents(dirname(__DIR__, 3) . '/scaffolding/themes/bootstrap/views/' . $view . '.tpl.php');
            preg_match_all("/'[a-z]+' => '([^']*)'/", $source, $map);
            $classes = 'class="' . implode('" class="', $map[1]) . '"';

            // Assert
            $this->assertSame(1, substr_count($source, 'NeutralLayout::style()'), $view . ': prints the rules once');
            $this->assertDoesNotMatchRegularExpression(self::ONLY_FIVE, $source, $view . ': in its markup');
            $this->assertDoesNotMatchRegularExpression(self::ONLY_FIVE, $classes, $view . ': in its class map');
            $this->assertSame(substr_count($source, 'form-select '), substr_count($source, 'form-control input-sm form-select '), $view . ': a select is a form-control too');
        }
    }

    /**
     * The rules: a page as wide as its room, whose wide tables scroll inside it; a card with
     * padding; rows and fields; a select as wide as its choices.
     */
    public function testTheRules(): void
    {
        // Act
        $style = NeutralLayout::style();

        // Assert
        $this->assertStringStartsWith('<style data-pf-layout>', $style);
        foreach (['.pf-l-page{', 'max-width:100%', '.pf-l-page table{display:block;max-width:100%;overflow-x:auto}', '.pf-l-card{', 'padding:12px 14px',
            '.pf-l-row{display:flex;flex-wrap:wrap', '.pf-l-field{', 'select.pf-l-select{display:inline-block;width:auto'] as $rule) {
            $this->assertStringContainsString($rule, $style);
        }
    }

    /** Every word the application's skills page shows has its Greek. */
    public function testTheSkillCataloguePageIsInGreek(): void
    {
        // Arrange
        $greek  = require dirname(__DIR__, 3) . '/resources/language/el.php';
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/scaffolding/themes/bootstrap/views/ai/skillcatalog.tpl.php');
        preg_match_all("/\\\$t\\('((?:[^'\\\\]|\\\\.)*)'/", $source, $words);
        preg_match_all("/\\\$t\\(\\\$on \\? '([^']*)' : '([^']*)'\\)/", $source, $choices);

        // Act
        $words   = array_map('stripslashes', array_merge($words[1], $choices[1], $choices[2]));
        $missing = array_values(array_filter(array_unique($words), static fn (string $w): bool => !isset($greek[$w])));

        // Assert
        $this->assertNotSame([], $words);
        $this->assertSame([], $missing);
    }
}
