<?php

namespace Tests\Unit\Pramnos\Email;

use PHPUnit\Framework\TestCase;
use Pramnos\Email\Email;

class EmailMoreTest extends TestCase
{
    public function testSetFromAddressWithArray(): void
    {
        $email = new Email();
        $email->setFrom(['test@example.com' => 'Test User']);
        $this->assertEquals(['test@example.com' => 'Test User'], $email->from);
        
        $email->setFrom(['test2@example.com']);
        $this->assertEquals(['test2@example.com'], $email->from);
    }
    
    public function testAddRecipientsArray(): void
    {
        $email = new Email();
        $email->setTo(['test@example.com' => 'Test', 'test2@example.com' => 'Test 2']);
        $this->assertEquals(['test@example.com' => 'Test', 'test2@example.com' => 'Test 2'], $email->to);
    }

    public function testSendMailStatic(): void
    {
        // Mock the SMTP settings to avoid real sending
        \Pramnos\Application\Settings::setSetting('smtp_host', 'localhost');
        \Pramnos\Application\Settings::setSetting('smtp_port', '2525'); 
        \Pramnos\Application\Settings::setSetting('smtp_user', 'user'); 
        \Pramnos\Application\Settings::setSetting('smtp_pass', 'pass'); 
        
        $result = Email::sendMail('Subject', 'Body', 'to@example.com');
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
    }

    public function testSendWithPort465Smtps(): void
    {
        \Pramnos\Application\Settings::setSetting('smtp_host', 'localhost');
        \Pramnos\Application\Settings::setSetting('smtp_port', '465'); 
        \Pramnos\Application\Settings::setSetting('smtp_tls', 'yes');
        
        $email = new Email();
        $email->setTo('to@example.com');
        $email->setBody('body');
        $email->addHeader('X-My-Header', 'val');
        
        $this->assertFalse($email->send());
    }

    public function testSendWithPort587Tls(): void
    {
        \Pramnos\Application\Settings::setSetting('smtp_host', 'localhost');
        \Pramnos\Application\Settings::setSetting('smtp_port', '587'); 
        \Pramnos\Application\Settings::setSetting('smtp_tls', 'yes');
        
        $email = new Email();
        $email->setTo('to@example.com');
        $email->setBody('body');
        
        $this->assertFalse($email->send());
    }

    public function testSendWithOtherPortTls(): void
    {
        \Pramnos\Application\Settings::setSetting('smtp_host', 'localhost');
        \Pramnos\Application\Settings::setSetting('smtp_port', '2525'); 
        \Pramnos\Application\Settings::setSetting('smtp_tls', 'yes');
        
        $email = new Email();
        $email->setTo('to@example.com');
        $email->setBody('body');
        
        $this->assertFalse($email->send());
    }

    /**
     * A send applies the wrapper once, and records what it sent.
     *
     * Two things could go wrong and neither would be visible in an inbox: the mailer and
     * the audit log could wrap independently, so the `mails` row would hold a different
     * document than the one delivered, and a second `send()` on the same object could nest
     * the shell inside itself. Both are asserted here rather than in the theme's own tests,
     * because both are about this class holding one rendered copy.
     */
    public function testSendWrapsTheBodyOnceAndRecordsThatCopy(): void
    {
        // Arrange — the mailer and the log are the seams, so no SMTP server is involved
        $email = new class () extends Email {
            public string $delivered = '';

            public string $recorded = '';

            protected function sendWithSymfonyMailer()
            {
                $this->delivered = (string) ($this->renderedBody ?? $this->body);

                return true;
            }

            protected function recordMail(bool $success): void
            {
                $this->recorded = (string) ($this->renderedBody ?? $this->body);
            }
        };
        $email->setTo('to@example.com');
        $email->setSubject('Your code');
        $email->setBody('<p>123456</p>');
        $email->setTemplate('default');

        // Act
        $email->send();
        $email->send();

        // Assert
        $this->assertStringContainsString('<p>123456</p>', $email->delivered);
        $this->assertStringContainsString('<!DOCTYPE html>', $email->delivered,
            'the bundled wrapper must have been applied');
        $this->assertSame(1, substr_count($email->delivered, '<!DOCTYPE html>'),
            'and a second send must not wrap the wrapped body again');
        $this->assertSame($email->delivered, $email->recorded,
            'the audit log has to hold the document that was delivered');
    }

    /**
     * And an unconfigured installation sends exactly the body it was given.
     */
    public function testSendLeavesTheBodyAloneWithNoWrapperConfigured(): void
    {
        // Arrange
        \Pramnos\Application\Settings::setSetting(\Pramnos\Email\EmailTheme::SETTING, '', false);
        $email = new class () extends Email {
            public string $delivered = '';

            protected function sendWithSymfonyMailer()
            {
                $this->delivered = (string) ($this->renderedBody ?? $this->body);

                return true;
            }

            protected function recordMail(bool $success): void
            {
            }
        };
        $email->setTo('to@example.com');
        $email->setBody('<p>123456</p>');

        // Act
        $email->send();

        // Assert
        $this->assertSame('<p>123456</p>', $email->delivered);
    }
}
