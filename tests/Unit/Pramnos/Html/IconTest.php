<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\TestCase;
use Pramnos\Html\Icon;

/**
 * The action icons a list's rows end in.
 *
 * `View Edit Deactivate` repeated on every row spends more width on words than on data,
 * and after the first row the words carry no information. Icons do the same job in a
 * fraction of the space — *if* they are still labelled, which is the part this pins.
 */
class IconTest extends TestCase
{
    /**
     * An icon link is labelled twice: for a screen reader and for a pointer.
     *
     * An icon-only control with neither `aria-label` nor `title` is a control only its
     * author can use — the pencil is obvious to whoever chose it and to nobody else.
     */
    public function testAnIconLinkIsAlwaysLabelled(): void
    {
        // Act
        $html = Icon::link('/admin/users/edit/5', 'edit', 'Edit this user');

        // Assert
        $this->assertStringContainsString('aria-label="Edit this user"', $html);
        $this->assertStringContainsString('title="Edit this user"', $html);
        $this->assertStringContainsString('href="/admin/users/edit/5"', $html);
    }

    /**
     * The SVG itself is hidden from assistive technology.
     *
     * The label is on the anchor, so an icon that also announces itself is read twice.
     */
    public function testTheGlyphIsHiddenFromScreenReaders(): void
    {
        // Act
        $html = Icon::svg('view');

        // Assert
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('focusable="false"', $html);
    }

    /**
     * It inherits colour and size rather than declaring its own.
     *
     * `currentColor` and a `1em` box mean one markup works in a table cell, in a button
     * and in the dark theme, without the framework knowing any of their palettes.
     */
    public function testItInheritsColourAndSize(): void
    {
        // Act
        $html = Icon::svg('delete');

        // Assert
        $this->assertStringContainsString('stroke="currentColor"', $html);
        $this->assertStringContainsString('width="1em"', $html);
        $this->assertStringContainsString('viewBox="0 0 24 24"', $html);
    }

    /**
     * Extra attributes pass through, and are escaped.
     *
     * `data-confirm` is the one that matters: a delete action without it is a delete
     * action one stray click away.
     */
    public function testExtraAttributesPassThroughEscaped(): void
    {
        // Act
        $html = Icon::link('/x', 'delete', 'Delete', [
            'data-confirm' => 'Delete "this" record?',
            'class'        => 'pf-action-danger',
        ]);

        // Assert
        $this->assertStringContainsString('class="pf-action pf-action-danger"', $html);
        $this->assertStringContainsString('data-confirm="Delete &quot;this&quot; record?"', $html);
    }

    /**
     * An unknown name renders nothing rather than a broken glyph.
     *
     * A new action is legible with a labelled empty link; a missing-image box beside four
     * working icons reads as a broken page.
     */
    public function testAnUnknownIconRendersNothing(): void
    {
        // Act & Assert
        $this->assertSame('', Icon::svg('no-such-icon'));
        $this->assertStringContainsString('aria-label="Something"', Icon::link('/x', 'no-such-icon', 'Something'));
    }

    /**
     * The set covers the actions the framework's own screens offer.
     *
     * Listed rather than counted: a name disappearing is what breaks a row action, and it
     * disappears silently — see {@see testAnUnknownIconRendersNothing()}.
     */
    public function testTheSetCoversTheAdminActions(): void
    {
        // Act
        $names = Icon::names();

        // Assert
        foreach ([
            'view', 'edit', 'delete', 'deactivate', 'activate', 'members',
            'tokens', 'sessions', 'lock', 'unlock', 'password', 'log', 'send', 'retry',
        ] as $needed) {
            $this->assertContains($needed, $names, $needed . ' is used by an admin screen');
        }
    }

    /**
     * A URL is escaped, because a row builds it from data.
     */
    public function testTheUrlIsEscaped(): void
    {
        // Act
        $html = Icon::link('/admin/users/view/5?q="x"', 'view', 'View');

        // Assert
        $this->assertStringContainsString('&quot;x&quot;', $html);
        $this->assertStringNotContainsString('?q="x"', $html);
    }
}
