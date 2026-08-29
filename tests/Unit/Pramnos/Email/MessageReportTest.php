<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Email\MessageReport;
use Pramnos\Email\Tracking;

/**
 * Everything knowable about one message that was sent.
 *
 * The `mails` table stores the rendered HTML, so the message can be read back — and every finding
 * here comes from that body rather than from the sending code. The distinction is the point: a
 * template that lost its unsubscribe link and one that kept it are identical from the caller's
 * side, and only differ in what was actually sent.
 */
#[CoversClass(MessageReport::class)]
class MessageReportTest extends TestCase
{
    /** @param array<string, mixed>|null $tracking */
    private function report(string $body, ?array $tracking = null): MessageReport
    {
        return new MessageReport(
            ['content' => $body, 'status' => 1, 'tomail' => 'a@example.com', 'date' => 1000],
            $tracking
        );
    }

    /**
     * A pixel in the body is reported, with the id it carries.
     */
    public function testThePixelIsFoundInTheBody(): void
    {
        // Act
        $tracking = $this->report(
            '<p>Hi</p>' . Tracking::pixel('abc123')
        )->tracking();

        // Assert
        $this->assertTrue($tracking['pixel']);
        $this->assertSame('abc123', $tracking['pixelId']);
    }

    /**
     * A tracked message whose body has no pixel is called out.
     *
     * The two can disagree, and when they do the row measures nothing. That is invisible from
     * anywhere else: the database says the message is tracked and the numbers stay at zero
     * forever, which reads as "nobody opened it".
     */
    public function testATrackedMessageWithNoPixelIsCalledOut(): void
    {
        // Act
        $tracking = $this->report('<p>No pixel here.</p>', [
            'tracking_id' => 'abc', 'list' => 'newsletter',
            'opens' => 0, 'proxy_opens' => 0, 'clicks' => 0,
        ])->tracking();

        // Assert
        $this->assertFalse($tracking['pixel']);
        $this->assertTrue($tracking['recorded']);
        $this->assertStringContainsString('no pixel', $tracking['note']);
    }

    /**
     * A pixel with no row behind it is called out too.
     *
     * The other direction, and worse: a remote image in somebody's mail that records nothing at
     * all.
     */
    public function testAPixelWithNoRowIsCalledOut(): void
    {
        // Act
        $tracking = $this->report('<p>Hi</p>' . Tracking::pixel('abc'))->tracking();

        // Assert
        $this->assertTrue($tracking['pixel']);
        $this->assertFalse($tracking['recorded']);
        $this->assertStringContainsString('nothing it reports can be recorded', $tracking['note']);
    }

    /**
     * Prefetches without a real open are explained rather than left to be misread.
     */
    public function testProxyOnlyOpensAreExplained(): void
    {
        // Act
        $tracking = $this->report('<p>Hi</p>' . Tracking::pixel('abc'), [
            'tracking_id' => 'abc', 'opens' => 0, 'proxy_opens' => 12, 'clicks' => 0,
        ])->tracking();

        // Assert
        $this->assertStringContainsString('not somebody reading it', $tracking['note']);
    }

    /**
     * The Gmail actions in the body are read back.
     */
    public function testTheActionsAreReadBack(): void
    {
        // Arrange
        $body = '<html><head>'
            . \Pramnos\Html\Seo::jsonLd(
                \Pramnos\Email\Actions::confirm('Confirm address', 'https://example.com/c')
            )
            . \Pramnos\Html\Seo::jsonLd(
                \Pramnos\Email\Actions::sender('Acme', 'https://example.com/logo.png')
            )
            . '</head><body><p>Hi</p></body></html>';

        // Act
        $blocks = $this->report($body)->structuredData();

        // Assert
        $this->assertCount(2, $blocks);
        $this->assertSame('EmailMessage', $blocks[0]['type']);
        $this->assertSame('ConfirmAction', $blocks[0]['actions'][0]['action']);
        $this->assertSame('Confirm address', $blocks[0]['actions'][0]['name']);
        $this->assertSame('https://example.com/c', $blocks[0]['actions'][0]['url']);
        $this->assertSame('Organization', $blocks[1]['type']);
        $this->assertSame('Acme', $blocks[1]['name']);
    }

    /**
     * An RSVP's three handlers are all listed.
     *
     * The schema allows one action or several, and a screen that only handled the single case
     * would show an RSVP as an empty block.
     */
    public function testSeveralActionsInOneBlockAreAllListed(): void
    {
        // Arrange
        $body = \Pramnos\Html\Seo::jsonLd(\Pramnos\Email\Actions::rsvp([
            'yes' => 'https://example.com/y',
            'no'  => 'https://example.com/n',
        ]));

        // Act
        $blocks = $this->report($body)->structuredData();

        // Assert
        $this->assertCount(2, $blocks[0]['actions']);
    }

    /**
     * A block that is not valid JSON is reported, not skipped.
     *
     * Gmail ignores it silently, which is precisely why this screen must not.
     */
    public function testAnUnreadableBlockIsReported(): void
    {
        // Act
        $blocks = $this->report(
            '<script type="application/ld+json">{not json</script>'
        )->structuredData();

        // Assert
        $this->assertSame('unreadable', $blocks[0]['type']);
        $this->assertStringContainsString('{not json', $blocks[0]['raw']);
    }

    /**
     * A wrapped link is unwrapped to the address the reader actually reaches.
     *
     * The address in the markup is a tracking URL; the destination is inside the signed token.
     * "Where does this button actually go" is the question somebody has when a campaign points
     * at the wrong page, and it cannot be answered by reading the markup.
     */
    public function testAWrappedLinkIsUnwrapped(): void
    {
        // Arrange
        $body = '<a href="' . Tracking::link('abc', 'https://example.com/offer?id=9') . '">Go</a>';

        // Act
        $links = $this->report($body)->links();

        // Assert
        $this->assertTrue($links[0]['wrapped']);
        $this->assertSame('https://example.com/offer?id=9', $links[0]['destination']);
        $this->assertArrayNotHasKey('broken', $links[0]);
    }

    /**
     * A wrapped link whose token no longer verifies is marked broken.
     *
     * The signing key changed, or it expired. The reader gets the front page instead of the
     * offer, and nothing else would ever say so.
     */
    public function testABrokenWrappedLinkIsMarked(): void
    {
        // Arrange
        $body = '<a href="https://example.com' . Tracking::CLICK_PATH . '?c=rubbish">Go</a>';

        // Act
        $links = $this->report($body)->links();

        // Assert
        $this->assertTrue($links[0]['wrapped']);
        $this->assertTrue($links[0]['broken']);
    }

    /**
     * The same link twice is one row with a count.
     */
    public function testARepeatedLinkIsCounted(): void
    {
        // Act
        $links = $this->report(
            '<a href="https://example.com/x">a</a><a href="https://example.com/x">b</a>'
        )->links();

        // Assert
        $this->assertCount(1, $links);
        $this->assertSame(2, $links[0]['count']);
    }

    /**
     * A message with no way out says so — and says when that is correct.
     *
     * Missing on transactional mail is right; missing on a newsletter is a problem. The screen
     * cannot tell which this is, so it says both rather than raising a false alarm.
     */
    public function testAMissingUnsubscribeIsExplainedBothWays(): void
    {
        // Act
        $without = $this->report('<p>Hi</p>')->unsubscribe();
        $with    = $this->report(
            '<a href="https://example.com/unsubscribe?u=x">Unsubscribe</a>'
        )->unsubscribe();

        // Assert
        $this->assertArrayNotHasKey('visibleLink', $without);
        $this->assertStringContainsString('transactional', $without['note']);
        $this->assertTrue($with['visibleLink']);
        $this->assertStringContainsString('unsubscribe?u=x', $with['url']);
    }

    /**
     * The delivery summary omits an error on a message that was sent.
     *
     * `extrainfo` holds the transport's words on a failure and is empty on a success — and an
     * empty "error" row on a delivered message reads as a problem.
     */
    public function testAnErrorIsOnlyShownOnAFailure(): void
    {
        // Arrange
        $failed = new MessageReport([
            'content' => '<p>Hi</p>', 'status' => 0, 'extrainfo' => 'Connection refused',
        ]);
        $sent = new MessageReport([
            'content' => '<p>Hi</p>', 'status' => 1, 'extrainfo' => '',
        ]);

        // Assert
        $this->assertSame('failed', $failed->delivery()['status']);
        $this->assertSame('Connection refused', $failed->delivery()['error']);
        $this->assertSame('sent', $sent->delivery()['status']);
        $this->assertArrayNotHasKey('error', $sent->delivery());
    }

    /**
     * The plain-text half is rendered, not described.
     *
     * It is the part nobody looks at, and the part that used to arrive as the stylesheet with
     * every link removed.
     */
    public function testThePlainTextIsRendered(): void
    {
        // Act
        $text = $this->report(
            '<style>.x{}</style><p>Please <a href="https://example.com/c">confirm</a>.</p>'
        )->plainText();

        // Assert
        $this->assertSame('Please confirm <https://example.com/c>.', $text);
    }
}
