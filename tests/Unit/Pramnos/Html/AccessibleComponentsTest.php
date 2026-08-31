<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\Form\Field;
use Pramnos\Html\Form\FieldStyles;
use Pramnos\Html\Pagination;

/**
 * Two components, and the part of each that only one kind of reader ever notices.
 *
 * Both were *nearly* right, which is why the gaps lasted: every page link already carried an
 * `aria-label`, every field already had a real `<label>`. What was missing in each case was a
 * **relationship** — between a control and the text about it, between a run of links and the
 * page's regions. A relationship is invisible when you can see the layout, because the layout
 * is the relationship.
 */
#[CoversClass(Field::class)]
#[CoversClass(Pagination::class)]
class AccessibleComponentsTest extends TestCase
{
    /**
     * A field's description is joined to its control, not merely near it.
     *
     * `<small>Your work address</small>` under an input is a relationship a sighted reader infers
     * from position. Somebody using a screen reader hears the label and the control and never the
     * sentence — so the field that needed the most explanation gets none.
     */
    public function testADescriptionIsAnnouncedAsPartOfTheField(): void
    {
        // Arrange
        $field = new Field('email', 'email', 'Email', null, 'Your work address');

        // Act
        $html = $field->render(FieldStyles::for('plain'));

        // Assert
        $this->assertStringContainsString('id="email-description"', $html);
        $this->assertStringContainsString('aria-describedby="email-description"', $html);
    }

    /**
     * A field with a validation message is marked invalid and says why.
     *
     * Two halves that are useless apart: `aria-invalid` alone announces "invalid" and leaves the
     * person to guess, and a message alone is loose text somewhere on the page.
     */
    public function testAnErrorIsBothMarkedAndExplained(): void
    {
        // Arrange
        $field        = new Field('email', 'email', 'Email');
        $field->error = 'That address is already registered.';

        // Act
        $html = $field->render(FieldStyles::for('plain'));

        // Assert
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('id="email-error"', $html);
        $this->assertStringContainsString('aria-describedby="email-error"', $html);
        $this->assertStringContainsString('role="alert"', $html,
            'a message that appears after a failed submit has to interrupt to be heard');
    }

    /**
     * With both, the error is read first.
     *
     * `aria-describedby` is read in the order the ids are listed. A field that is wrong should
     * say so before it explains what it wanted; the other way round, the correction arrives
     * after the instruction it corrects.
     */
    public function testTheErrorIsAnnouncedBeforeTheDescription(): void
    {
        // Arrange
        $field        = new Field('email', 'email', 'Email', null, 'Your work address');
        $field->error = 'That address is already registered.';

        // Act
        $html = $field->render(FieldStyles::for('plain'));

        // Assert
        $this->assertStringContainsString('aria-describedby="email-error email-description"', $html);
    }

    /**
     * A field with neither gets no association at all.
     *
     * Which is most fields. An `aria-describedby` pointing at an id that is not on the page is
     * worse than the attribute's absence: the reader announces the field and then nothing, and
     * the silence looks like its own bug.
     */
    public function testAFieldWithNothingToSayPointsAtNothing(): void
    {
        // Act
        $html = (new Field('name', 'text', 'Name'))->render(FieldStyles::for('plain'));

        // Assert
        $this->assertStringNotContainsString('aria-describedby', $html);
        $this->assertStringNotContainsString('aria-invalid', $html);
    }

    /**
     * A dropdown gets the same treatment as a text field.
     *
     * It renders down a different path in this class, which is exactly how one of two paths ends
     * up with the accessible version and the other does not.
     */
    public function testASelectIsAssociatedTheSameWay(): void
    {
        // Arrange
        $field = new Field('country', 'select', 'Country', ['gr' => 'Greece'], 'Where you live');
        $field->error = 'Pick one.';

        // Act
        $html = $field->render(FieldStyles::for('plain'));

        // Assert
        $this->assertStringContainsString('aria-describedby="country-error country-description"', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
    }

    /**
     * Pagination is a navigation landmark.
     *
     * Every link in it already said which page it was, and the current one said it was current.
     * None of that helps somebody who never reaches the links: without a `<nav>` this is an
     * anonymous run of anchors that region-by-region navigation passes straight over. The
     * breadcrumb beside it has always been a landmark; the difference was an inconsistency.
     */
    public function testPaginationIsALandmark(): void
    {
        // Act
        $html = (new Pagination(5, 2, '/list'))->render();

        // Assert
        $this->assertStringContainsString('<nav aria-label="Pagination">', $html);
        $this->assertStringEndsWith('</nav>', $html);
    }

    /**
     * And does not nest two landmarks when the caller asked for a `nav` themselves.
     *
     * `containerElement` is public. A caller who set it to `nav` gets the label on their own
     * element rather than a second region wrapped around it — nested navigation landmarks
     * announce the region twice and neither one is the answer.
     */
    public function testItDoesNotNestTwoNavigationLandmarks(): void
    {
        // Arrange
        $pagination = new Pagination(5, 2, '/list');
        $pagination->containerElement = 'nav';

        // Act
        $html = $pagination->render();

        // Assert
        $this->assertSame(1, substr_count($html, '<nav'));
        $this->assertStringContainsString('aria-label="Pagination"', $html);
    }

    /**
     * The number row can be turned off, leaving previous and next.
     *
     * Not a niche case: in search results the page count is large and moves with the filters, so
     * a reader on page 7 of 40 cannot say what page 7 is — and after narrowing the query it is a
     * different page 7. Two links are the whole meaningful interface there.
     *
     * Requested by an application whose own 421-line pager was kept alive by the absence of this
     * one switch (FW-048).
     */
    public function testThePageNumbersCanBeTurnedOff(): void
    {
        // Arrange
        $pagination = new Pagination(20, 7, '/list');
        $pagination->displayPageNumbers = false;

        // Act
        $html = $pagination->render();

        // Assert
        /*
         * Asserted on the link *text*, not on `aria-label`.
         *
         * The next button legitimately carries `aria-label="Page 8"` — it goes to page 8 and says
         * so, which is the accessible name it should have. What must be gone is the row of links
         * whose text is a bare number.
         */
        $this->assertStringNotContainsString('>8</a>', $html, 'the number row must be gone');
        $this->assertStringNotContainsString('>7</', $html, 'including the current page');
        $this->assertStringContainsString('&laquo;', $html,
            'and the previous/next buttons must remain — that is the point of the switch');
        $this->assertStringContainsString('<nav aria-label="Pagination">', $html,
            'still a landmark, because there is still something in it');
    }

    /**
     * With nothing left to show, nothing is rendered — landmark included.
     *
     * Turn the numbers off and both button pairs off and every branch is skipped, leaving an
     * empty `<nav>`. An empty landmark is worse than none: it appears in a reader's list of
     * regions and leads nowhere. The same rule as a single page, arrived at differently.
     */
    public function testAPagerWithNothingToShowRendersNothing(): void
    {
        // Arrange
        $pagination = new Pagination(20, 7, '/list');
        $pagination->displayPageNumbers  = false;
        $pagination->displayNextPrevious = false;
        $pagination->displayFirstLast    = false;

        // Assert
        $this->assertSame('', $pagination->render());
    }

    /**
     * One page renders nothing, landmark included.
     *
     * An empty landmark is worse than none: it appears in the region list and leads nowhere.
     */
    public function testASinglePageIsStillNothing(): void
    {
        // Assert
        $this->assertSame('', (new Pagination(1, 1, '/list'))->render());
    }
}
