<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Email;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Email\Email;

/**
 * Two things about sending that an application moving to PHP 8.5 met.
 *
 * - `'mail' => ['record' => false]` turns off the `mails` row for every send, for an
 *   application that already writes its own and was getting two rows per message.
 * - A send with no `smtp_host` says so, rather than Symfony's "The mailer DSN is invalid.",
 *   which reads as a malformed credential.
 */
class MailRecordingAndMissingHostTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $mailConfig = null;

    protected function setUp(): void
    {
        $this->mailConfig = Application::getInstance()->applicationInfo['mail'] ?? null;
        Settings::clearSettings();
    }

    protected function tearDown(): void
    {
        $app = Application::getInstance();
        if ($this->mailConfig === null) {
            unset($app->applicationInfo['mail']);
        } else {
            $app->applicationInfo['mail'] = $this->mailConfig;
        }
        Settings::clearSettings();
    }

    /**
     * Recording is on unless the application turns it off, and `app.php` turns it off for
     * every message — including the static `sendMail()`, which builds its own `Email`.
     */
    public function testTheApplicationDecidesWhetherSendsAreRecorded(): void
    {
        // Arrange / Act — the default
        $default = new Email();

        // Arrange — the application writes its own rows
        Application::getInstance()->applicationInfo['mail'] = ['record' => false];

        // Act
        $configured = new Email();

        // Assert
        $this->assertTrue($default->recordToMails, 'a complete delivery log by default');
        $this->assertFalse($configured->recordToMails);
    }

    /**
     * With no SMTP host the error names the missing setting.
     */
    public function testASendWithNoHostSaysTheHostIsMissing(): void
    {
        // Arrange — everything but the server
        Settings::setSetting('smtp_host', '', false);
        $email = new Email();
        $email->recordToMails = false;
        $email->subject = 'Hello';
        $email->body    = '<p>Hello</p>';
        $email->to      = 'someone@example.com';
        $email->from    = 'noreply@example.com';

        // Act
        $sent = $email->send();

        // Assert
        $this->assertFalse($sent);
        $this->assertSame('SMTP not configured: smtp_host is empty.', $email->getLastError());
    }
}
