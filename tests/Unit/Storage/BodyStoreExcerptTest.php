<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Storage\BodyStore;

/**
 * The summary a listing shows, and the list of tables the sweep must consult.
 *
 * Both are small pieces of the store that decide whether the rest of it is safe or usable: the
 * excerpt is what stops an inbox costing one decompression per row, and the table list is what
 * stops a garbage collection deleting bodies somebody can still open.
 */
#[CoversClass(BodyStore::class)]
class BodyStoreExcerptTest extends TestCase
{
    /**
     * Markup out, one readable line left.
     *
     * A body is HTML nearly always, and a preview line rendered from raw HTML is either escaped
     * tags in the middle of a sentence or an injection, depending on how the view escapes.
     */
    public function testItReducesMarkupToOneReadableLine(): void
    {
        // Act
        $excerpt = BodyStore::excerpt(
            "<h1>Τίτλος</h1>\n<p>Πρώτη   παράγραφος.</p>\n<p>Δεύτερη.</p>"
        );

        // Assert
        $this->assertSame('Τίτλος Πρώτη παράγραφος. Δεύτερη.', $excerpt);
    }

    /**
     * Entities come back as the characters they stand for.
     *
     * A body written by a mail template is full of them, and `&amp;nbsp;` in a preview is how a
     * reader learns that something between the body and the screen is not decoding.
     */
    public function testItDecodesEntities(): void
    {
        // Assert
        $this->assertSame('Café & bar', BodyStore::excerpt('<p>Caf&eacute; &amp; bar</p>'));
    }

    /**
     * It is cut to fit the column, counting characters rather than bytes.
     *
     * `excerpt` is a `varchar(255)`. Cutting by bytes splits a Greek character in half and the
     * insert fails — or worse, succeeds with a broken sequence in it.
     */
    public function testItIsCutToFitTheColumn(): void
    {
        // Act
        $excerpt = BodyStore::excerpt('<p>' . str_repeat('α', 400) . '</p>');

        // Assert
        $this->assertSame(255, mb_strlen($excerpt));
        $this->assertSame($excerpt, mb_convert_encoding($excerpt, 'UTF-8', 'UTF-8'),
            'cut on a character boundary, not a byte one');
    }

    /**
     * An empty body produces an empty summary rather than whitespace.
     */
    public function testAnEmptyBodyGivesAnEmptyExcerpt(): void
    {
        // Assert
        $this->assertSame('', BodyStore::excerpt(''));
        $this->assertSame('', BodyStore::excerpt("<p>\n  </p>"));
    }

    /**
     * Both tables that name a file are on the list the sweep consults.
     *
     * `orphans()` deletes files no row references. It was written when `mails` was the only such
     * table; sharing the store with `messages` without adding it here would make every message
     * body look unreferenced, and `--gc` would delete all of them.
     */
    public function testEveryTableThatNamesAFileIsOnTheList(): void
    {
        // Assert
        $this->assertArrayHasKey('#PREFIX#mails', BodyStore::REFERENCED_BY);
        $this->assertArrayHasKey('#PREFIX#messages', BodyStore::REFERENCED_BY);
        $this->assertSame(['bodypath'], BodyStore::REFERENCED_BY['#PREFIX#messages']);

        // `path` is the column applications used before this store existed. An installation that
        // points the store at that same directory — which is why the root is configurable — gets
        // years of history deleted in one sweep if it is not on this list.
        $this->assertContains('path', BodyStore::REFERENCED_BY['#PREFIX#mails']);
    }

    /**
     * `mails` is the table whose absence stops the sweep.
     *
     * The two ways a table can be missing need different answers. `messages` belongs to a feature
     * an installation can switch off, and skipping it costs nothing. `mails` missing means the
     * installation is broken — and reading "nothing is referenced" out of that hands the caller
     * the whole archive to delete.
     */
    public function testTheRequiredTableIsTheOneTheStoreWasBuiltFor(): void
    {
        // Assert
        $this->assertSame('#PREFIX#mails', BodyStore::REQUIRED_TABLE);
        $this->assertArrayHasKey(BodyStore::REQUIRED_TABLE, BodyStore::REFERENCED_BY);
    }

    /**
     * The old name still works.
     *
     * `Pramnos\Email\BodyStore` is in applications that were written before the store served two
     * tables. Moving the implementation is not a reason to break them.
     */
    public function testTheOldClassNameStillResolves(): void
    {
        // Assert
        $this->assertInstanceOf(
            BodyStore::class,
            new \Pramnos\Email\BodyStore(),
            'Email\BodyStore must keep working as the class it always was'
        );
    }
}
