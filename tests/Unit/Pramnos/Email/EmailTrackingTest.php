<?php

namespace Tests\Unit\Pramnos\Email;

use PHPUnit\Framework\TestCase;
use Pramnos\Email\Email;

/**
 * `Email::enableTracking()` — what it does, and the one thing it deliberately stopped doing.
 *
 * It used to append the tracking pixel to the body **the moment it was called**. That put a
 * remote image into every message that asked for tracking, regardless of whether the recipient
 * had consented to anything, whether the installation had tracking switched on, and whether the
 * message was a newsletter or a password reset. The pixel also pointed at a route that did not
 * exist, and the row it recorded went into a table no migration created — so it measured nothing
 * while doing the one thing it should never do without consent.
 *
 * The pixel is now added when the message is *sent*, and only if the message belongs to a list
 * and the installation has tracking on. The id is still generated here, because an application
 * may store it beside its own record and there is no reason for the gates to cost anybody that.
 */
class EmailTrackingTest extends TestCase
{
    /**
     * An id exists as soon as tracking is asked for.
     */
    public function testEnableTrackingGeneratesIdIfNull(): void
    {
        $email = new Email();
        $email->setTo(['recipient@example.com' => 'Recipient Name', 'user@example.com']);
        $email->setSubject('Tracking Test');

        $email->enableTracking(null);

        $this->assertNotEmpty($email->trackingId);
        $this->assertStringContainsString('email_', $email->trackingId);
    }

    /**
     * A caller's own id is kept.
     */
    public function testEnableTrackingKeepsACallersOwnId(): void
    {
        $email = new Email();
        $email->enableTracking('test_tracking_id');

        $this->assertEquals('test_tracking_id', $email->trackingId);
    }

    /**
     * Asking for tracking does not put anything in the message.
     *
     * The correction. A remote image in somebody's mail is not something to add on the strength
     * of a method call — it needs the installation's setting, and it needs the message to belong
     * to a list the reader subscribed to. Both are checked at send time, because that is when
     * both are known.
     */
    public function testEnableTrackingDoesNotTouchTheBody(): void
    {
        $email = new Email();
        $email->setSubject('Tracking Test');
        $email->body = '<p>Hello.</p>';

        $email->enableTracking(null);

        $this->assertSame('<p>Hello.</p>', $email->body);
        $this->assertStringNotContainsString('<img', $email->body);
        $this->assertStringNotContainsString($email->trackingId, $email->body);
    }

    /**
     * It is chainable, and it does not throw without a database.
     *
     * Nothing is written here any more — the row is created at send time, inside a try — so a
     * disconnected database cannot break composing a message.
     */
    public function testEnableTrackingIsSafeWithoutADatabase(): void
    {
        $email = new Email();

        $this->assertSame($email, $email->enableTracking('test_tracking_id'));
    }
}
