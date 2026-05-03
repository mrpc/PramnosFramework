<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Email\Email;

/**
 * Characterization tests for the Email class.
 *
 * Tests lock fluent setter/getter contracts and the error-state methods.
 * Actual sending is NOT tested (requires an SMTP server); only the
 * object's configuration API is exercised.
 */
#[CoversClass(Email::class)]
class EmailCharacterizationTest extends TestCase
{
    private Email $email;

    protected function setUp(): void
    {
        $this->email = new Email();
    }

    // -----------------------------------------------------------------------
    // Fluent setters return $this
    // -----------------------------------------------------------------------

    /**
     * setSubject() stores the subject and returns $this for chaining.
     */
    public function testSetSubjectReturnsSelfAndStoresValue(): void
    {
        $result = $this->email->setSubject('Test Subject');
        $this->assertSame($this->email, $result);
        $this->assertSame('Test Subject', $this->email->subject);
    }

    /**
     * setBody() stores the body and returns $this.
     */
    public function testSetBodyReturnsSelfAndStoresValue(): void
    {
        $result = $this->email->setBody('<p>Hello</p>');
        $this->assertSame($this->email, $result);
        $this->assertSame('<p>Hello</p>', $this->email->body);
    }

    /**
     * setTo() stores the recipient and returns $this.
     */
    public function testSetToReturnsSelfAndStoresValue(): void
    {
        $result = $this->email->setTo('user@example.com');
        $this->assertSame($this->email, $result);
        $this->assertSame('user@example.com', $this->email->to);
    }

    /**
     * setTo() accepts an array of recipients.
     */
    public function testSetToAcceptsArray(): void
    {
        $recipients = ['a@example.com', 'b@example.com'];
        $this->email->setTo($recipients);
        $this->assertSame($recipients, $this->email->to);
    }

    /**
     * setFrom() stores the sender and returns $this.
     */
    public function testSetFromReturnsSelfAndStoresValue(): void
    {
        $result = $this->email->setFrom('sender@example.com');
        $this->assertSame($this->email, $result);
        $this->assertSame('sender@example.com', $this->email->from);
    }

    /**
     * setCc() stores the CC address and returns $this.
     */
    public function testSetCcReturnsSelfAndStoresValue(): void
    {
        $result = $this->email->setCc('cc@example.com');
        $this->assertSame($this->email, $result);
        $this->assertSame('cc@example.com', $this->email->cc);
    }

    /**
     * setBcc() stores the BCC address and returns $this.
     */
    public function testSetBccReturnsSelfAndStoresValue(): void
    {
        $result = $this->email->setBcc('bcc@example.com');
        $this->assertSame($this->email, $result);
        $this->assertSame('bcc@example.com', $this->email->bcc);
    }

    /**
     * setDebug() stores the debug flag and returns $this.
     */
    public function testSetDebugReturnsSelfAndStoresValue(): void
    {
        $result = $this->email->setDebug(true);
        $this->assertSame($this->email, $result);
        $this->assertTrue($this->email->debug);
    }

    /**
     * addHeader() stores the header key/value and returns $this.
     */
    public function testAddHeaderReturnsSelfAndStoresHeader(): void
    {
        $result = $this->email->addHeader('X-Custom', 'value123');
        $this->assertSame($this->email, $result);
        $this->assertSame('value123', $this->email->headers['X-Custom']);
    }

    /**
     * Fluent chain: all setters can be chained in a single expression.
     */
    public function testFluentChaining(): void
    {
        $result = $this->email
            ->setSubject('Chained')
            ->setBody('body')
            ->setTo('to@example.com')
            ->setFrom('from@example.com');

        $this->assertSame($this->email, $result);
        $this->assertSame('Chained', $this->email->subject);
    }

    // -----------------------------------------------------------------------
    // Error state
    // -----------------------------------------------------------------------

    /**
     * A fresh Email object has no error.
     */
    public function testHasErrorFalseByDefault(): void
    {
        $this->assertFalse($this->email->hasError());
    }

    /**
     * getLastError() returns an empty string on a fresh instance.
     */
    public function testGetLastErrorEmptyByDefault(): void
    {
        $this->assertSame('', $this->email->getLastError());
    }

    /**
     * getLastException() returns null on a fresh instance.
     */
    public function testGetLastExceptionNullByDefault(): void
    {
        $this->assertNull($this->email->getLastException());
    }
}
