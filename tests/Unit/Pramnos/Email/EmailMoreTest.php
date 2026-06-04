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
}
