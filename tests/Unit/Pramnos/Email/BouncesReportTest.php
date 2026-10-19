<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Email\Bounces;
use Pramnos\Email\Email;

/** A message whose bounce store cannot be read. */
class UnreadableBouncesEmail extends Email
{
    /** The store's answer for every address: it is away. */
    public function askBounced(string $address): ?string
    {
        return $this->bounced($address);
    }
}

/**
 * Reading delivery reports (RFC 3464) into the failures they describe — which recipients, hard or
 * soft, and why — and what a send does when the bounce store cannot answer.
 */
#[CoversClass(Bounces::class)]
#[CoversClass(Email::class)]
#[CoversClass(\Pramnos\Console\Commands\MailFlush::class)]
final class BouncesReportTest extends TestCase
{
    /**
     * Every failed recipient of a report is read, a delayed one is not; the status decides hard
     * or soft — a full or disabled mailbox is soft though permanent — folded fields are unfolded
     * and the address is taken out of its brackets.
     */
    public function testAReportIsReadIntoItsFailures(): void
    {
        // Arrange
        $report = "Content-Type: multipart/report; report-type=delivery-status; boundary=x\n\n--x\n"
            . "Content-Type: message/delivery-status\n\nReporting-MTA: dns; mx.example\n\n"
            . "Final-Recipient: rfc822; <Gone@Example.com>\nAction: failed\nStatus: 5.1.1\n"
            . "Diagnostic-Code: smtp; 550 5.1.1 The email account that you tried to reach\n does not exist\n\n"
            . "Final-Recipient: rfc822; full@example.com\nAction: failed\nStatus: 5.2.2 (mailbox full)\n\n"
            . "Final-Recipient: rfc822; off@example.com\nAction: failed\nStatus: 5.2.1\n\n"
            . "Final-Recipient: rfc822; later@example.com\nAction: delayed\nStatus: 4.4.7\n\n"
            . "Final-Recipient: rfc822; busy@example.com\nAction: failed\nStatus: 4.4.1\n\n"
            . "Final-Recipient: rfc822; not an address\nAction: failed\nStatus: 5.1.1\n\n--x--\n";

        // Act
        $failures = Bounces::parseReport($report);

        // Assert
        $this->assertSame(['gone@example.com', 'full@example.com', 'off@example.com', 'busy@example.com'], array_column($failures, 'email'));
        $this->assertSame([false, true, true, true], array_column($failures, 'soft'));
        $this->assertSame('5.2.2', $failures[1]['status'], 'the code, without the comment after it');
        $this->assertSame('550 5.1.1 The email account that you tried to reach does not exist', $failures[0]['reason'], 'unfolded');
        $this->assertSame('5.2.2', $failures[1]['reason'], 'with no diagnostic, the status is the reason');
    }

    /**
     * A report with no Status takes hard or soft from the server's reply in the diagnostic, and
     * one with neither is a permanent failure. A message that is not a report is no failure.
     */
    public function testWithoutAStatusTheReplyDecides(): void
    {
        // Arrange
        $block = static fn (string $address, string $diagnostic): string => "\r\nFinal-Recipient: rfc822; " . $address
            . "\r\nAction: Failed" . ($diagnostic === '' ? '' : "\r\nDiagnostic-Code: smtp; " . $diagnostic) . "\r\n";

        // Act
        $failures = Bounces::parseReport($block('a@example.com', '421 try later') . $block('b@example.com', '554 rejected') . $block('c@example.com', ''));

        // Assert
        $this->assertSame(['4.0.0', '5.0.0', '5.0.0'], array_column($failures, 'status'));
        $this->assertSame([true, false, false], array_column($failures, 'soft'));
        $this->assertSame([], Bounces::parseReport("Subject: hello\r\n\r\nNo report here.\r\n"));
        $this->assertNull(Bounces::hash('nobody'));
        $this->assertSame(hash('sha256', 'someone@example.com'), Bounces::hash('Someone <Someone@Example.com>'), 'the key of the address, whatever its form');
    }

    /**
     * A send asks the store about its recipient; a store that cannot answer refuses nothing, so a
     * password reset is not held back by a bounce table that is down.
     */
    public function testAStoreThatCannotAnswerRefusesNothing(): void
    {
        // Arrange
        $previous  = \Pramnos\Framework\Factory::getDatabase();
        $singleton = &\Pramnos\Framework\Factory::getDatabase();
        $singleton = new class extends \Pramnos\Database\Database {
            /** A schema that cannot be asked. */
            public function schema(): \Pramnos\Database\SchemaBuilder
            {
                throw new \RuntimeException('the database is away');
            }
        };
        Bounces::forget();

        // Act
        try {
            $answer = (new UnreadableBouncesEmail())->askBounced('someone@example.com');
            $worker = (new class extends \Pramnos\Console\Commands\MailFlush {
                /** The worker's question, asked from outside. */
                public function ask(string $address): ?string
                {
                    return $this->bounced($address);
                }
            })->ask('someone@example.com');
        } finally {
            $singleton = $previous;
            Bounces::forget();
        }

        // Assert
        $this->assertNull($answer);
        $this->assertNull($worker, 'and the outbox worker sends as well');
    }
}
