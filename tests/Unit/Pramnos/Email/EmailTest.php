<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Email\Email;

/**
 * Unit tests for Pramnos\Email\Email.
 *
 * Focuses on:
 *   - Constructor property defaults
 *   - Fluent setter methods (setSubject, setBody, setTo, setFrom, setCc, setBcc, setDebug)
 *   - addHeader() — stores and accumulates custom headers
 *   - getInstance() — static singleton
 *   - Error tracking state (getLastError, getLastException, hasError)
 *   - getPriorityForSymfony() — maps all five priority levels to Symfony constants
 *
 * send() and sendWithSymfonyMailer() require a real SMTP server; they are
 * covered by integration tests and intentionally excluded from this suite.
 */
#[CoversClass(Email::class)]
class EmailTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Minimal anonymous subclass that exposes the protected
     * getPriorityForSymfony() method for white-box priority testing.
     */
    private function makeEmail(): Email
    {
        return new class() extends Email {
            public function exposePriority(): int
            {
                return $this->getPriorityForSymfony();
            }
        };
    }

    // =========================================================================
    // Constructor / property defaults
    // =========================================================================

    /**
     * Constructor sets sensible defaults for all public properties.
     * Priority 3 is "normal" (the middle of the 1–5 scale).
     */
    public function testConstructorSetsDefaultPropertyValues(): void
    {
        // Arrange / Act
        $email = new Email();

        // Assert — critical initial state
        $this->assertSame(3,     $email->priority);
        $this->assertSame('',    $email->subject);
        $this->assertSame('',    $email->body);
        $this->assertSame('',    $email->to);
        $this->assertSame('',    $email->from);
        $this->assertSame('',    $email->cc);
        $this->assertSame('',    $email->bcc);
        $this->assertSame('',    $email->replyto);
        $this->assertFalse($email->sendReceipt);
        $this->assertFalse($email->debug);
        $this->assertFalse($email->batch);
        $this->assertSame([], $email->headers);
    }

    // =========================================================================
    // getInstance() — singleton pattern
    // =========================================================================

    /**
     * getInstance() returns the same object on every call (static singleton).
     */
    public function testGetInstanceReturnsSameObjectOnMultipleCalls(): void
    {
        // Arrange / Act
        $first  = Email::getInstance();
        $second = Email::getInstance();

        // Assert — identity, not just equality
        $this->assertSame($first, $second);
    }

    /**
     * getInstance() returns an Email object.
     */
    public function testGetInstanceReturnsEmailInstance(): void
    {
        // Assert
        $this->assertInstanceOf(Email::class, Email::getInstance());
    }

    // =========================================================================
    // setSubject()
    // =========================================================================

    /**
     * setSubject() stores the subject string and returns $this for chaining.
     */
    public function testSetSubjectStoresSubjectAndReturnsSelf(): void
    {
        // Arrange
        $email = new Email();

        // Act
        $result = $email->setSubject('Hello World');

        // Assert
        $this->assertSame($email, $result);
        $this->assertSame('Hello World', $email->subject);
    }

    // =========================================================================
    // setBody()
    // =========================================================================

    /**
     * setBody() stores the HTML body and returns $this.
     */
    public function testSetBodyStoresBodyAndReturnsSelf(): void
    {
        // Arrange
        $email = new Email();

        // Act
        $result = $email->setBody('<p>Test body</p>');

        // Assert
        $this->assertSame($email, $result);
        $this->assertSame('<p>Test body</p>', $email->body);
    }

    // =========================================================================
    // setTo()
    // =========================================================================

    /**
     * setTo() accepts a plain email address string.
     */
    public function testSetToAcceptsStringRecipient(): void
    {
        // Arrange
        $email = new Email();

        // Act
        $result = $email->setTo('user@example.com');

        // Assert
        $this->assertSame($email, $result);
        $this->assertSame('user@example.com', $email->to);
    }

    /**
     * setTo() accepts an email→name array (SwiftMailer-style format).
     */
    public function testSetToAcceptsArrayRecipient(): void
    {
        // Arrange
        $email = new Email();
        $recipients = ['user1@example.com' => 'User 1', 'user2@example.com' => 'User 2'];

        // Act
        $email->setTo($recipients);

        // Assert
        $this->assertSame($recipients, $email->to);
    }

    // =========================================================================
    // setFrom()
    // =========================================================================

    /**
     * setFrom() stores the sender address and returns $this.
     */
    public function testSetFromStoresSenderAndReturnsSelf(): void
    {
        // Arrange
        $email = new Email();

        // Act
        $result = $email->setFrom('sender@example.com');

        // Assert
        $this->assertSame($email, $result);
        $this->assertSame('sender@example.com', $email->from);
    }

    // =========================================================================
    // setCc() / setBcc()
    // =========================================================================

    /**
     * setCc() stores the CC address and returns $this.
     */
    public function testSetCcStoresCcAndReturnsSelf(): void
    {
        // Arrange
        $email = new Email();

        // Act
        $result = $email->setCc('cc@example.com');

        // Assert
        $this->assertSame($email, $result);
        $this->assertSame('cc@example.com', $email->cc);
    }

    /**
     * setBcc() stores the BCC address and returns $this.
     */
    public function testSetBccStoresBccAndReturnsSelf(): void
    {
        // Arrange
        $email = new Email();

        // Act
        $result = $email->setBcc('bcc@example.com');

        // Assert
        $this->assertSame($email, $result);
        $this->assertSame('bcc@example.com', $email->bcc);
    }

    // =========================================================================
    // setDebug()
    // =========================================================================

    /**
     * setDebug(true) enables debug logging.
     */
    public function testSetDebugEnablesDebugFlag(): void
    {
        // Arrange
        $email = new Email();

        // Act
        $result = $email->setDebug(true);

        // Assert
        $this->assertSame($email, $result);
        $this->assertTrue($email->debug);
    }

    /**
     * setDebug() with no argument defaults to true.
     */
    public function testSetDebugDefaultsToTrue(): void
    {
        // Arrange
        $email = new Email();

        // Act
        $email->setDebug();

        // Assert
        $this->assertTrue($email->debug);
    }

    /**
     * setDebug(false) disables debug logging.
     */
    public function testSetDebugCanDisableFlag(): void
    {
        // Arrange
        $email = new Email();
        $email->setDebug(true);

        // Act
        $email->setDebug(false);

        // Assert
        $this->assertFalse($email->debug);
    }

    // =========================================================================
    // addHeader()
    // =========================================================================

    /**
     * addHeader() stores a header key/value pair and returns $this.
     */
    public function testAddHeaderStoresHeaderAndReturnsSelf(): void
    {
        // Arrange
        $email = new Email();

        // Act
        $result = $email->addHeader('X-Custom', 'value123');

        // Assert
        $this->assertSame($email, $result);
        $this->assertArrayHasKey('X-Custom', $email->headers);
        $this->assertSame('value123', $email->headers['X-Custom']);
    }

    /**
     * Multiple addHeader() calls accumulate independent headers.
     */
    public function testAddHeaderMultipleHeadersAccumulate(): void
    {
        // Arrange
        $email = new Email();

        // Act
        $email->addHeader('X-Header-A', 'valueA');
        $email->addHeader('X-Header-B', 'valueB');

        // Assert
        $this->assertCount(2, $email->headers);
        $this->assertSame('valueA', $email->headers['X-Header-A']);
        $this->assertSame('valueB', $email->headers['X-Header-B']);
    }

    /**
     * addHeader() with the same key overwrites the previous value.
     */
    public function testAddHeaderOverwritesDuplicateKey(): void
    {
        // Arrange
        $email = new Email();

        // Act
        $email->addHeader('X-Idempotent', 'first');
        $email->addHeader('X-Idempotent', 'second');

        // Assert — last value wins (associative array key)
        $this->assertSame('second', $email->headers['X-Idempotent']);
        $this->assertCount(1, $email->headers);
    }

    // =========================================================================
    // Error tracking — initial state (before any send())
    // =========================================================================

    /**
     * getLastError() returns '' before any send attempt.
     */
    public function testGetLastErrorReturnsEmptyStringInitially(): void
    {
        // Arrange
        $email = new Email();

        // Assert — no error recorded yet
        $this->assertSame('', $email->getLastError());
    }

    /**
     * getLastException() returns null before any send attempt.
     */
    public function testGetLastExceptionReturnsNullInitially(): void
    {
        // Arrange
        $email = new Email();

        // Assert
        $this->assertNull($email->getLastException());
    }

    /**
     * hasError() returns false when lastError is empty.
     */
    public function testHasErrorReturnsFalseInitially(): void
    {
        // Arrange
        $email = new Email();

        // Assert — !empty('') → false
        $this->assertFalse($email->hasError());
    }

    // =========================================================================
    // getPriorityForSymfony() — maps 1–5 to Symfony Email priority constants
    // =========================================================================

    /** @return array<string,array{int,int}> */
    public static function priorityProvider(): array
    {
        return [
            'priority 1 → HIGHEST' => [1, \Symfony\Component\Mime\Email::PRIORITY_HIGHEST],
            'priority 2 → HIGH'    => [2, \Symfony\Component\Mime\Email::PRIORITY_HIGH],
            'priority 3 → NORMAL'  => [3, \Symfony\Component\Mime\Email::PRIORITY_NORMAL],
            'priority 4 → LOW'     => [4, \Symfony\Component\Mime\Email::PRIORITY_LOW],
            'priority 5 → LOWEST'  => [5, \Symfony\Component\Mime\Email::PRIORITY_LOWEST],
            'out-of-range → NORMAL' => [0, \Symfony\Component\Mime\Email::PRIORITY_NORMAL],
        ];
    }

    /**
     * getPriorityForSymfony() translates the integer $priority property
     * (1 = highest urgency, 5 = lowest) to the matching Symfony constant.
     * Values outside 1–5 fall through to the default (NORMAL).
     *
     * @param int $input    The Email::$priority value to set
     * @param int $expected The expected Symfony priority constant value
     */
    #[DataProvider('priorityProvider')]
    public function testGetPriorityForSymfonyMapsAllCases(int $input, int $expected): void
    {
        // Arrange
        $email = $this->makeEmail();
        $email->priority = $input;

        // Act — calls the protected method via the expose wrapper
        $result = $email->exposePriority();

        // Assert
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Fluent chaining end-to-end
    // =========================================================================

    /**
     * All fluent setters can be chained in a single expression and each one
     * stores its value correctly — the chain always returns the same instance.
     */
    public function testFluentChainingStoresAllValues(): void
    {
        // Arrange
        $email = new Email();

        // Act — full chain
        $result = $email
            ->setSubject('Newsletter')
            ->setBody('<p>Welcome!</p>')
            ->setTo('reader@example.com')
            ->setFrom('noreply@example.com')
            ->setCc('copy@example.com')
            ->setBcc('hidden@example.com')
            ->setDebug(false);

        // Assert — same instance returned throughout
        $this->assertSame($email, $result);
        // Assert — all values stored
        $this->assertSame('Newsletter',          $email->subject);
        $this->assertSame('<p>Welcome!</p>',     $email->body);
        $this->assertSame('reader@example.com',  $email->to);
        $this->assertSame('noreply@example.com', $email->from);
        $this->assertSame('copy@example.com',    $email->cc);
        $this->assertSame('hidden@example.com',  $email->bcc);
        $this->assertFalse($email->debug);
    }

    /**
     * Test sending email with empty body.
     * 
     * Verifies that the send method returns false early and does not proceed
     * if the email body has not been set or is empty.
     */
    public function testSendReturnsFalseOnEmptyBody(): void
    {
        $email = new Email();
        $email->setBody('');
        $this->assertFalse($email->send());
    }

    /**
     * Test successful execution of the send logic including Exception catch.
     * 
     * Verifies that when the send method builds the mailer and attempts to send,
     * it properly initializes the settings, assigns variables, and if the mailer
     * fails (e.g. invalid settings mocked), it gracefully catches the TransportException
     * and sets the internal error state rather than bubbling up a fatal error.
     */
    public function testSendWithSymfonyMailerExecutesAndCatchesException(): void
    {
        $email = new Email();
        // Set invalid SMTP settings to ensure it fails at transport level
        \Pramnos\Application\Settings::setSetting('smtp_host', 'localhost');
        \Pramnos\Application\Settings::setSetting('smtp_port', '99999'); // Invalid port
        \Pramnos\Application\Settings::setSetting('smtp_tls', 'no');
        
        $email->setBody('Test body')
            ->setTo('test@example.com')
            ->setFrom('from@example.com')
            ->setSubject('Test subject');
        $email->attach = '/non/existent/file.txt';
        $email->priority = 1;
        $email->sendReceipt = true;
        $email->unsubscribe = 'mailto:unsub@example.com';
        $email->organization = 'Test Org';
        $email->abuse = 'abuse@example.com';
            
        // Because port is invalid or no SMTP server is running,
        // Symfony Mailer will throw a TransportException during send(),
        // which will be caught by Email::send() and return false.
        $this->assertFalse($email->send());
        
        // Assert that the error message was set
        $this->assertNotEmpty($email->getLastError());
    }
}
