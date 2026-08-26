<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\Select;

/**
 * A standalone `<select>`, for a filter with no form around it.
 *
 * Requested as FW-024 with a count: 37 files in a consuming application render a select
 * outside any form, 35 of them through `addOption()`. The commonest shape is a footer
 * filter in a `Datatable`, where the rendered string is passed to `addColumn()` as markup
 * — so `Html\Form\Field`, whose `render()` requires a form's style preset, does not serve
 * it.
 *
 * Two of these tests are about what the implementation being replaced did **not** do:
 * it concatenated labels and values into markup unescaped, and it compared the current
 * value with `==`, whose answer for `0 == ''` changed between PHP 7 and 8.
 */
#[CoversClass(Select::class)]
class SelectTest extends TestCase
{
    /**
     * The pattern the request was made for, rendered.
     *
     * Name, options in the order added, and the option matching the current value marked
     * selected — the whole contract 35 files depend on.
     */
    public function testTheDocumentedFilterPatternRenders(): void
    {
        // Arrange
        $select = new Select('statusSelect', 1);
        $select->addOption('All', '');
        $select->addOption('Active', 1);
        $select->addOption('Inactive', 0);

        // Act
        $html = $select->render();

        // Assert
        $this->assertStringContainsString('<select name="statusSelect">', $html);
        $this->assertStringContainsString('<option value="1" selected>Active</option>', $html);
        $this->assertStringContainsString('<option value="">All</option>', $html);
        $this->assertStringContainsString('<option value="0">Inactive</option>', $html);
    }

    /**
     * The label is the first argument, and reversing it would be silent.
     *
     * The legacy order, kept deliberately. `(value, label)` is the more obvious signature
     * and adopting it would compile, run, and render every one of 35 files' dropdowns
     * with labels and values swapped — no error anywhere.
     */
    public function testTheLabelComesFirst(): void
    {
        // Arrange & Act
        $html = (new Select('f'))->addOption('Human readable', 'machine-value')->render();

        // Assert
        $this->assertStringContainsString('value="machine-value">Human readable<', $html);
    }

    /**
     * An option with no value uses its label as the value.
     *
     * The legacy default, and what a list of plain strings means.
     */
    public function testAnOptionWithNoValueUsesItsLabel(): void
    {
        // Act
        $html = (new Select('f'))->addOption('Greece')->render();

        // Assert
        $this->assertStringContainsString('<option value="Greece">Greece</option>', $html);
    }

    /**
     * Labels and values are escaped.
     *
     * The implementation this replaces did not escape either, and its documented use is a
     * filter populated from a database column. A label of `<script>` was markup.
     */
    public function testLabelsAndValuesAreEscaped(): void
    {
        // Arrange
        $select = new Select('evil');
        $select->addOption('<script>alert(1)</script>', '" onmouseover="alert(1)');

        // Act
        $html = $select->render();

        // Assert — nothing that can close an attribute or open a tag survives.
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('onmouseover="alert', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&quot; onmouseover=', $html);
    }

    /**
     * The current value is compared as a string, not with `==`.
     *
     * The reason this needs a test of its own: the legacy used `==`, and `0 == ''` is
     * `true` on PHP 7 and `false` on PHP 8. A select with an "All" option of `''` and a
     * current value of `0` therefore selected a different option depending on the
     * interpreter — which is exactly the kind of difference a version migration finds the
     * hard way.
     *
     * @param mixed  $current
     * @param string $expected The value that must end up selected
     */
    #[DataProvider('comparisonProvider')]
    public function testTheCurrentValueIsComparedAsAString($current, string $expected): void
    {
        // Arrange
        $select = new Select('f', $current);
        $select->addOption('All', '');
        $select->addOption('Zero', 0);
        $select->addOption('One', 1);

        // Act
        $html = $select->render();

        // Assert
        $this->assertStringContainsString('value="' . $expected . '" selected', $html);
        $this->assertSame(1, substr_count($html, 'selected'), 'exactly one option may be selected');
    }

    /** @return array<string, array{mixed, string}> */
    public static function comparisonProvider(): array
    {
        return [
            'integer zero selects zero, not the empty option' => [0, '0'],
            'string zero selects zero'                        => ['0', '0'],
            'empty string selects the empty option'           => ['', ''],
            'integer one selects one'                         => [1, '1'],
            'string one selects one'                          => ['1', '1'],
        ];
    }

    /**
     * `multiple` appends `[]` to the name and can select several options.
     *
     * The `[]` is not cosmetic: without it PHP keeps only the last submitted value, so a
     * multiple select silently behaves like a single one.
     */
    public function testMultipleAppendsBracketsAndSelectsSeveral(): void
    {
        // Arrange
        $select = new Select('tags', [2, 3]);
        $select->multiple = true;
        $select->addOptions([1 => 'one', 2 => 'two', 3 => 'three']);

        // Act
        $html = $select->render();

        // Assert
        $this->assertStringContainsString('name="tags[]" multiple', $html);
        $this->assertStringContainsString('value="2" selected', $html);
        $this->assertStringContainsString('value="3" selected', $html);
        $this->assertStringNotContainsString('value="1" selected', $html);
    }

    /**
     * An array current value matches by string, not loosely.
     *
     * `in_array('a', [0])` was `true` on PHP 7. Casting both sides and comparing strictly
     * means `'1'` still matches `1` — which callers rely on, since a value out of a form
     * is always a string — while `'a'` does not match `0`.
     */
    public function testAnArrayValueMatchesByStringNotLoosely(): void
    {
        // Arrange
        $select = new Select('f', ['1']);
        $select->multiple = true;
        $select->addOption('One', 1);
        $select->addOption('Alpha', 'a');
        $select->addOption('Zero', 0);

        // Act
        $html = $select->render();

        // Assert
        $this->assertStringContainsString('value="1" selected', $html);
        $this->assertSame(1, substr_count($html, 'selected'));
    }

    /**
     * `$selected` forces an option on regardless of the current value.
     *
     * For a list where the selection is not a value comparison — the third argument the
     * legacy signature carries.
     */
    public function testAnOptionCanBeForcedSelected(): void
    {
        // Act
        $html = (new Select('f', 'other'))->addOption('Pinned', 'pinned', true)->render();

        // Assert
        $this->assertStringContainsString('value="pinned" selected', $html);
    }

    /**
     * No `id` is emitted unless one is set.
     *
     * Not defaulted to the name: two selects for one field on a page is ordinary — a
     * filter above a table and another below it — and duplicate ids are invalid HTML that
     * breaks `<label for>` and `getElementById` without any visible error.
     */
    public function testNoIdIsInventedButOneCanBeSet(): void
    {
        // Act
        $without = (new Select('f'))->render();

        $with = new Select('f');
        $with->id = 'my-select';

        // Assert
        $this->assertStringNotContainsString('id=', $without);
        $this->assertStringContainsString('id="my-select"', $with->render());
    }

    /**
     * `required` and extra attributes reach the tag.
     */
    public function testRequiredAndExtraAttributesAreEmitted(): void
    {
        // Arrange
        $select = new Select('f');
        $select->required = true;
        $select->extraAttributes = 'data-role="filter"';

        // Act
        $html = $select->render();

        // Assert
        $this->assertStringContainsString(' required', $html);
        $this->assertStringContainsString('data-role="filter"', $html);
    }

    /**
     * An empty select is still valid markup.
     *
     * A filter whose options come from a query that returned nothing. Rendering a broken
     * tag, or nothing at all, would both be worse than an empty one.
     */
    public function testAnEmptySelectIsStillValid(): void
    {
        // Act
        $select = new Select('f');

        // Assert
        $this->assertFalse($select->hasOptions());
        $this->assertSame('<select name="f"></select>', $select->render());
    }

    /**
     * `echo $select` renders it.
     *
     * The legacy class did, and call sites use it. Without `__toString()` the alternative
     * is a fatal, which is not what somebody echoing a select meant.
     */
    public function testItRendersWhenCastToString(): void
    {
        // Arrange
        $select = (new Select('f'))->addOption('One', 1);

        // Act & Assert
        $this->assertSame($select->render(), (string) $select);
    }

    /**
     * `addOption()` and `addOptions()` chain.
     *
     * The legacy returned `$this`, and the call sites chain.
     */
    public function testTheAddersChain(): void
    {
        // Act
        $select = (new Select('f'))->addOption('One', 1)->addOptions([2 => 'two']);

        // Assert
        $this->assertInstanceOf(Select::class, $select);
        $this->assertStringContainsString('value="2">two<', $select->render());
    }
}
