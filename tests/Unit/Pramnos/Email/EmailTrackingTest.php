<?php

namespace Tests\Unit\Pramnos\Email;

use PHPUnit\Framework\TestCase;
use Pramnos\Email\Email;

class EmailTrackingTest extends TestCase
{
    public function testEnableTrackingGeneratesIdIfNull(): void
    {
        $email = new Email();
        $email->setTo(['recipient@example.com' => 'Recipient Name', 'user@example.com']);
        $email->setSubject('Tracking Test');
        
        $email->enableTracking(null);
        
        $this->assertNotEmpty($email->trackingId);
        $this->assertStringContainsString('email_', $email->trackingId);
        $this->assertStringContainsString($email->trackingId, $email->body);
    }
    
    public function testEnableTrackingWithDatabaseExceptionIsCaught(): void
    {
        // This will attempt to use the Database, which might throw an exception if disconnected
        // But logTrackingEmail catches it and logs it, so this shouldn't throw to the caller
        $email = new Email();
        $email->enableTracking('test_tracking_id');
        $this->assertEquals('test_tracking_id', $email->trackingId);
    }
}
