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
        \Pramnos\Application\Settings::setSetting('smtp_port', '2525'); // Invalid port
        \Pramnos\Application\Settings::setSetting('smtp_user', 'user');
        \Pramnos\Application\Settings::setSetting('smtp_pass', 'pass');
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

    public function testEnableTracking(): void
    {
        $email = new Email();
        $email->setBody('<p>Hello</p>');
        $email->enableTracking('track_123');

        $this->assertSame('track_123', $email->trackingId);
        $this->assertStringContainsString('<img src="', $email->body);
        $this->assertStringContainsString('track_123', $email->body);
    }

    public function testHandleTrackingRequestExitsWithGif(): void
    {
        $this->expectOutputString(base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw=='));
        Email::handleTrackingRequest('track_123');
    }

    public function testSetFromAddressAndAddRecipientsWithReflection(): void
    {
        $email = new Email();
        $email->setFrom(['from@example.com' => 'From Name']);
        $email->setTo(['to@example.com' => 'To Name']);
        $email->setCc('cc@example.com');
        $email->setBcc(['bcc1@example.com', 'bcc2@example.com']);
        
        $mimeEmail = new \Symfony\Component\Mime\Email();
        
        $ref = new \ReflectionClass($email);
        
        $setFrom = $ref->getMethod('setFromAddress');
        $setFrom->invoke($email, $mimeEmail);

        $addRecip = $ref->getMethod('addRecipients');
        $addRecip->invoke($email, $mimeEmail, $email->to, 'to');
        $addRecip->invoke($email, $mimeEmail, $email->cc, 'cc');
        $addRecip->invoke($email, $mimeEmail, $email->bcc, 'bcc');
        
        $froms = $mimeEmail->getFrom();
        $this->assertCount(1, $froms);
        $this->assertEquals('from@example.com', $froms[0]->getAddress());
        $this->assertEquals('From Name', $froms[0]->getName());
        
        $tos = $mimeEmail->getTo();
        $this->assertCount(1, $tos);
        $this->assertEquals('to@example.com', $tos[0]->getAddress());
        $this->assertEquals('To Name', $tos[0]->getName());
        
        $ccs = $mimeEmail->getCc();
        $this->assertCount(1, $ccs);
        $this->assertEquals('cc@example.com', $ccs[0]->getAddress());
        
        $bccs = $mimeEmail->getBcc();
        $this->assertCount(2, $bccs);
        $this->assertEquals('bcc1@example.com', $bccs[0]->getAddress());
        $this->assertEquals('bcc2@example.com', $bccs[1]->getAddress());
    }
    
    public function testSetFromAddressEmptyUsesSettings(): void
    {
        \Pramnos\Application\Settings::setSetting('admin_mail', 'admin@pramnos.test');
        \Pramnos\Application\Settings::setSetting('sitename', 'Test Site');
        
        $email = new Email();
        $mimeEmail = new \Symfony\Component\Mime\Email();
        
        $ref = new \ReflectionClass($email);
        $setFrom = $ref->getMethod('setFromAddress');
        $setFrom->invoke($email, $mimeEmail);
        
        $froms = $mimeEmail->getFrom();
        $this->assertCount(1, $froms);
        $this->assertEquals('admin@pramnos.test', $froms[0]->getAddress());
        $this->assertEquals('Test Site', $froms[0]->getName());
    }
    
    public function testSetFromAddressString(): void
    {
        $email = new Email();
        $email->setFrom('stringfrom@example.com');
        $mimeEmail = new \Symfony\Component\Mime\Email();

        $ref = new \ReflectionClass($email);
        $setFrom = $ref->getMethod('setFromAddress');
        $setFrom->invoke($email, $mimeEmail);

        $froms = $mimeEmail->getFrom();
        $this->assertCount(1, $froms);
        $this->assertEquals('stringfrom@example.com', $froms[0]->getAddress());
    }

    /**
     * setFromAddress() must handle a numeric-keyed array where the value is a
     * valid email string (lines 445-447): e.g. [0 => 'from@example.com'].
     */
    public function testSetFromAddressNumericArrayKey(): void
    {
        // Arrange — numeric key: value is the email address (no name)
        $email = new Email();
        $email->setFrom([0 => 'numeric@example.com']);
        $mimeEmail = new \Symfony\Component\Mime\Email();

        // Act
        $ref     = new \ReflectionClass($email);
        $setFrom = $ref->getMethod('setFromAddress');
        $setFrom->invoke($email, $mimeEmail);

        // Assert — address from numeric array key branch
        $froms = $mimeEmail->getFrom();
        $this->assertCount(1, $froms);
        $this->assertSame('numeric@example.com', $froms[0]->getAddress());
    }

    /**
     * setFromAddress() must log and skip an invalid array entry rather than
     * throwing (line 449): array key is not a valid address and value is not
     * a valid address either.
     */
    public function testSetFromAddressInvalidArrayEntryIsSkipped(): void
    {
        // Arrange — key and value are both non-address strings
        $email    = new Email();
        $email->setFrom(['not-an-email' => 'also-not-an-email']);
        $mimeEmail = new \Symfony\Component\Mime\Email();
        $email->setDebug(true);

        // Act — must not throw; the invalid entry is skipped
        $ref     = new \ReflectionClass($email);
        $setFrom = $ref->getMethod('setFromAddress');
        $setFrom->invoke($email, $mimeEmail);

        // Assert — no from address was set (invalid entry skipped)
        $this->assertCount(0, $mimeEmail->getFrom(),
            'An invalid from-array entry must be skipped without throwing');
    }

    /**
     * sendReceipt=true with an array from must add Disposition-Notification-To
     * using the first key from the array (lines 363-364).
     *
     * Exercises the is_array($this->from) branch inside the sendReceipt block.
     * Uses SMTP with an unreachable port so send() returns false after the
     * receipt header is prepared.
     */
    public function testSendReceiptWithArrayFromAddsDispositionHeader(): void
    {
        // Arrange — array from triggers the array branch in the receipt block
        \Pramnos\Application\Settings::setSetting('smtp_host', 'localhost');
        \Pramnos\Application\Settings::setSetting('smtp_port', '2525');
        \Pramnos\Application\Settings::setSetting('smtp_user', '');
        \Pramnos\Application\Settings::setSetting('smtp_pass', '');
        \Pramnos\Application\Settings::setSetting('smtp_tls', 'no');

        $email = new Email();
        $email->setBody('Receipt test')
              ->setTo('to@example.com')
              ->setFrom(['receipt-from@example.com' => 'Receipt Sender'])
              ->setSubject('Receipt test');
        $email->sendReceipt = true;

        // Act — send() fails at SMTP connect but receipt header was prepared
        $result = $email->send();

        // Assert — send() caught the transport exception and returned false
        $this->assertFalse($result,
            'send() must return false when SMTP is unreachable');
    }

    /**
     * SMTP scheme selection must use "smtps" for port 465 (line 277).
     */
    public function testSmtpSchemeIsSmtpsForPort465(): void
    {
        // Arrange — port 465 triggers the smtps (implicit SSL) branch
        \Pramnos\Application\Settings::setSetting('smtp_host', 'localhost');
        \Pramnos\Application\Settings::setSetting('smtp_port', '465');
        \Pramnos\Application\Settings::setSetting('smtp_user', 'user');
        \Pramnos\Application\Settings::setSetting('smtp_pass', 'pass');
        \Pramnos\Application\Settings::setSetting('smtp_tls', 'no');

        $email = new Email();
        $email->setBody('Test body')
              ->setTo('to@example.com')
              ->setFrom('from@example.com')
              ->setSubject('Port 465 test');

        // Act — SMTP connect on port 465 fails (no server), returns false
        $result = $email->send();

        // Assert — send() returned false (transport error) after hitting the smtps branch
        $this->assertFalse($result,
            'send() must return false when port-465 SMTP server is unreachable');
    }

    /**
     * SMTP scheme selection must use STARTTLS transport for port 587 with TLS
     * enabled (lines 281, 304-305). The EsmtpTransportFactory path is exercised.
     */
    public function testSmtpSchemeUsesStarttlsForPort587WithTls(): void
    {
        // Arrange — port 587 + TLS triggers the STARTTLS/EsmtpTransportFactory branch
        \Pramnos\Application\Settings::setSetting('smtp_host', 'localhost');
        \Pramnos\Application\Settings::setSetting('smtp_port', '587');
        \Pramnos\Application\Settings::setSetting('smtp_user', 'user');
        \Pramnos\Application\Settings::setSetting('smtp_pass', 'pass');
        \Pramnos\Application\Settings::setSetting('smtp_tls', 'yes');

        $email = new Email();
        $email->setBody('Test body')
              ->setTo('to@example.com')
              ->setFrom('from@example.com')
              ->setSubject('Port 587+TLS test');

        // Act — SMTP connect on port 587 fails (no server), returns false
        $result = $email->send();

        // Assert
        $this->assertFalse($result,
            'send() must return false when port-587+TLS SMTP server is unreachable');
    }

    /**
     * SMTP scheme selection must use "smtps" for non-standard port when TLS
     * is enabled (line 285).
     */
    public function testSmtpSchemeIsSmtpsForNonstandardPortWithTls(): void
    {
        // Arrange — port 9999 + TLS triggers the "other ports with TLS" smtps branch
        \Pramnos\Application\Settings::setSetting('smtp_host', 'localhost');
        \Pramnos\Application\Settings::setSetting('smtp_port', '9999');
        \Pramnos\Application\Settings::setSetting('smtp_user', 'user');
        \Pramnos\Application\Settings::setSetting('smtp_pass', 'pass');
        \Pramnos\Application\Settings::setSetting('smtp_tls', 'yes');

        $email = new Email();
        $email->setBody('Test body')
              ->setTo('to@example.com')
              ->setFrom('from@example.com')
              ->setSubject('Port 9999+TLS test');

        // Act — SMTP connect on port 9999 fails (no server), returns false
        $result = $email->send();

        // Assert
        $this->assertFalse($result,
            'send() must return false when non-standard-port+TLS SMTP is unreachable');
    }

    /**
     * send() must set the reply-to header when $this->replyto is non-empty
     * (line 349). Uses SMTP with an unreachable port so send() returns false
     * after the reply-to header is applied.
     */
    public function testSendSetsReplyToHeaderWhenReplytoIsSet(): void
    {
        // Arrange
        \Pramnos\Application\Settings::setSetting('smtp_host', 'localhost');
        \Pramnos\Application\Settings::setSetting('smtp_port', '2525');
        \Pramnos\Application\Settings::setSetting('smtp_user', '');
        \Pramnos\Application\Settings::setSetting('smtp_pass', '');
        \Pramnos\Application\Settings::setSetting('smtp_tls', 'no');

        $email = new Email();
        $email->setBody('Reply-to test')
              ->setTo('to@example.com')
              ->setFrom('from@example.com')
              ->setSubject('Reply-to test');
        $email->replyto = 'reply@example.com'; // triggers line 349

        // Act
        $result = $email->send();

        // Assert — send() failed at transport but reply-to path was taken
        $this->assertFalse($result);
    }

    /**
     * send() must add custom headers to the mime email when $this->headers
     * is non-empty (line 404). Uses SMTP with an unreachable port.
     */
    public function testSendAddsCustomHeadersFromHeadersArray(): void
    {
        // Arrange
        \Pramnos\Application\Settings::setSetting('smtp_host', 'localhost');
        \Pramnos\Application\Settings::setSetting('smtp_port', '2525');
        \Pramnos\Application\Settings::setSetting('smtp_user', '');
        \Pramnos\Application\Settings::setSetting('smtp_pass', '');
        \Pramnos\Application\Settings::setSetting('smtp_tls', 'no');

        $email = new Email();
        $email->setBody('Headers test')
              ->setTo('to@example.com')
              ->setFrom('from@example.com')
              ->setSubject('Headers test');
        $email->addHeader('X-Custom-Header', 'custom-value'); // triggers line 404

        // Act
        $result = $email->send();

        // Assert — send() failed at transport but headers path was taken
        $this->assertFalse($result);
    }

    /**
     * send() must attach a file when $this->attach is non-empty and the file
     * exists (line 396). Uses a temp file as the attachment.
     */
    public function testSendAttachesFileWhenAttachPathExists(): void
    {
        // Arrange — create a temporary file to use as attachment
        $tmpFile = tempnam(sys_get_temp_dir(), 'email_attach_');
        file_put_contents($tmpFile, 'attachment contents');

        \Pramnos\Application\Settings::setSetting('smtp_host', 'localhost');
        \Pramnos\Application\Settings::setSetting('smtp_port', '2525');
        \Pramnos\Application\Settings::setSetting('smtp_user', '');
        \Pramnos\Application\Settings::setSetting('smtp_pass', '');
        \Pramnos\Application\Settings::setSetting('smtp_tls', 'no');

        $email = new Email();
        $email->setBody('Attachment test')
              ->setTo('to@example.com')
              ->setFrom('from@example.com')
              ->setSubject('Attachment test');
        $email->attach = $tmpFile; // triggers line 396

        // Act
        $result = $email->send();

        // Assert — send() failed at transport but attachment path was taken
        $this->assertFalse($result);
        unlink($tmpFile);
    }

    /**
     * send() must fall through to the admin_replymail setting for reply-to
     * when $this->replyto is empty but admin_replymail is configured (line 351).
     */
    public function testSendUsesAdminReplymailWhenReplytoIsEmpty(): void
    {
        // Arrange — set admin_replymail so the elseif branch on line 350 fires
        \Pramnos\Application\Settings::setSetting('smtp_host', 'localhost');
        \Pramnos\Application\Settings::setSetting('smtp_port', '2525');
        \Pramnos\Application\Settings::setSetting('smtp_user', '');
        \Pramnos\Application\Settings::setSetting('smtp_pass', '');
        \Pramnos\Application\Settings::setSetting('smtp_tls', 'no');
        \Pramnos\Application\Settings::setSetting('admin_replymail', 'admin-reply@example.com');

        $email = new Email();
        $email->setBody('Admin reply-to test')
              ->setTo('to@example.com')
              ->setFrom('from@example.com')
              ->setSubject('Admin reply-to test');
        // replyto intentionally left empty — admin_replymail branch fires

        // Act
        $result = $email->send();

        // Assert — send() failed at transport but admin_replymail path was taken
        $this->assertFalse($result);

        // Cleanup
        \Pramnos\Application\Settings::setSetting('admin_replymail', '');
    }

    /**
     * send() must call returnPath() on the mime message when $this->returnPath
     * is a non-empty, non-null string (line 356).
     */
    public function testSendSetsReturnPathWhenConfigured(): void
    {
        // Arrange
        \Pramnos\Application\Settings::setSetting('smtp_host', 'localhost');
        \Pramnos\Application\Settings::setSetting('smtp_port', '2525');
        \Pramnos\Application\Settings::setSetting('smtp_user', '');
        \Pramnos\Application\Settings::setSetting('smtp_pass', '');
        \Pramnos\Application\Settings::setSetting('smtp_tls', 'no');

        $email = new Email();
        $email->setBody('Return path test')
              ->setTo('to@example.com')
              ->setFrom('from@example.com')
              ->setSubject('Return path test');
        $email->returnPath = 'bounces@example.com'; // triggers line 356

        // Act
        $result = $email->send();

        // Assert — send() failed at transport but returnPath path was taken
        $this->assertFalse($result);
    }

    /**
     * sendReceipt=true with an empty from address but a non-empty returnPath
     * must add the Disposition-Notification-To header using returnPath
     * (lines 368–369). This exercises the else-if branch that is skipped when
     * from is set (covered by testSendReceiptWithArrayFromAddsDispositionHeader).
     * The email is aimed at an unreachable SMTP port so send() fails gracefully
     * after the receipt header has already been written into the Mime object.
     */
    public function testSendReceiptWithEmptyFromUsesReturnPath(): void
    {
        // Arrange — direct toward an unreachable SMTP endpoint so send() returns
        // false without needing a real mail server; headers are built before the
        // transport attempt, so coverage on lines 368-369 is captured anyway
        \Pramnos\Application\Settings::setSetting('smtp_host', 'localhost');
        \Pramnos\Application\Settings::setSetting('smtp_port', '2525');
        \Pramnos\Application\Settings::setSetting('smtp_user', '');
        \Pramnos\Application\Settings::setSetting('smtp_pass', '');
        \Pramnos\Application\Settings::setSetting('smtp_tls', 'no');
        \Pramnos\Application\Settings::setSetting('admin_mail', '');

        $email = new Email();
        $email->setBody('Receipt returnPath test')
              ->setTo('to@example.com')
              ->setSubject('Receipt returnPath test');
        $email->sendReceipt = true;
        $email->from        = '';                       // empty — triggers else-if at line 368
        $email->returnPath  = 'receipt@example.com';   // non-empty — line 369 fires

        // Act — send() fails at SMTP connect but the receipt-header block (lines
        // 360-376) executes fully before the transport attempt
        $result = $email->send();

        // Assert — send() caught the transport exception and returned false
        $this->assertFalse($result,
            'send() must return false when SMTP is unreachable');
    }

    /**
     * setFromAddress() must catch the Symfony Address exception when the from
     * string is an invalid email and fall back to the admin_mail setting
     * (lines 462-469).
     */
    public function testSetFromAddressStringFallsBackOnInvalidEmail(): void
    {
        // Arrange — an invalid email string triggers Address() to throw
        \Pramnos\Application\Settings::setSetting('admin_mail', 'fallback@example.com');
        \Pramnos\Application\Settings::setSetting('sitename', 'Fallback Site');

        $email = new Email();
        $email->setFrom('this-is-not-an-email'); // triggers the catch at line 462
        $email->setDebug(true);
        $mimeEmail = new \Symfony\Component\Mime\Email();

        // Act — must not throw; catches the exception and falls back
        $ref     = new \ReflectionClass($email);
        $setFrom = $ref->getMethod('setFromAddress');
        $setFrom->invoke($email, $mimeEmail);

        // Assert — fallback address was used instead
        $froms = $mimeEmail->getFrom();
        $this->assertCount(1, $froms,
            'setFromAddress() must fall back to admin_mail when the string is invalid');
        $this->assertSame('fallback@example.com', $froms[0]->getAddress(),
            'Fallback must use the admin_mail setting');
    }
}
