<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\MailDnsCheck;
use Pramnos\Email\DnsAuthentication;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The deploy check for the half of mail delivery that is not in the message.
 *
 * What it prints is the whole product — an operator reads it and then edits a DNS zone — and
 * the exit code is what lets it sit in a pipeline. Both are asserted against a fixed zone,
 * because a test that resolved a real domain would report whatever somebody changed this morning.
 */
#[CoversClass(MailDnsCheck::class)]
class MailDnsCheckTest extends TestCase
{
    /**
     * A domain that meets the bar exits 0 and says so.
     */
    public function testAReadyDomainSucceeds(): void
    {
        // Act
        $tester = $this->check([
            'example.com'        => ['v=spf1 -all'],
            '_dmarc.example.com' => ['v=DMARC1; p=reject'],
        ]);

        // Assert
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('meets the bulk-sender bar', $tester->getDisplay());
    }

    /**
     * A domain that does not exits non-zero, so a pipeline can fail on it.
     *
     * This is the point of the exit code: DNS is correct on the day it is set up and wrong two
     * domain transfers later, and nobody re-runs a check by hand.
     */
    public function testAnIncompleteDomainFails(): void
    {
        // Act
        $tester = $this->check(['example.com' => ['v=spf1 -all']]);

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Not yet', $tester->getDisplay());
    }

    /**
     * The record it found is printed, because that is the line somebody is about to edit.
     */
    public function testItPrintsTheRecordsItFound(): void
    {
        // Act
        $display = $this->check([
            'example.com'        => ['v=spf1 include:spf.example -all'],
            '_dmarc.example.com' => ['v=DMARC1; p=none; rua=mailto:a@example.com'],
        ])->getDisplay();

        // Assert
        $this->assertStringContainsString('v=spf1 include:spf.example -all', $display);
        $this->assertStringContainsString('rua=mailto:a@example.com', $display);
    }

    /**
     * Both SPF records are printed when there are two.
     *
     * The finding is "you have two of these" — naming only one would send somebody to fix the
     * wrong record, or to conclude the check is confused.
     */
    public function testBothSpfRecordsArePrintedWhenThereAreTwo(): void
    {
        // Act
        $display = $this->check(['example.com' => [
            'v=spf1 include:a.example -all',
            'v=spf1 include:b.example -all',
        ]])->getDisplay();

        // Assert
        $this->assertStringContainsString('include:a.example', $display);
        $this->assertStringContainsString('include:b.example', $display);
        $this->assertStringContainsString('PermError', $display);
    }

    /**
     * Every check prints its fix, because a finding without one is a complaint.
     */
    public function testEveryFindingCarriesItsFix(): void
    {
        // Act
        $display = $this->check([])->getDisplay();

        // Assert
        $this->assertStringContainsString('v=spf1', $display, 'the SPF fix');
        $this->assertStringContainsString('_dmarc.example.com', $display, 'the DMARC fix');
        $this->assertStringContainsString('default._bimi.example.com', $display, 'the BIMI fix');
        $this->assertStringContainsString('DKIM-Signature', $display, 'how to find the selector');
    }

    /**
     * A selector given on the command line reaches the check.
     */
    public function testASelectorReachesTheCheck(): void
    {
        // Act
        $display = $this->check(
            ['example.com' => ['v=spf1 -all']],
            ['--selector' => 'mail']
        )->getDisplay();

        // Assert
        $this->assertStringContainsString('mail._domainkey.example.com', $display);
    }

    /**
     * With no domain anywhere, it says so instead of checking the empty string.
     */
    public function testNoDomainIsReportedRatherThanChecked(): void
    {
        // Arrange
        \Pramnos\Application\Settings::clearSettings();

        // Act
        $tester = $this->check([], [], '');

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('No domain given', $tester->getDisplay());
    }

    /**
     * The domain is taken from the administrator's address when none is given.
     */
    public function testTheDomainComesFromTheAdministratorAddress(): void
    {
        // Arrange
        \Pramnos\Application\Settings::clearSettings();
        \Pramnos\Application\Settings::setSetting('admin_mail', 'ops@example.com', false);

        try {
            // Act
            $domain = $this->command([])->probeSendingDomain();

            // Assert
            $this->assertSame('example.com', $domain);
        } finally {
            \Pramnos\Application\Settings::clearSettings();
        }
    }

    /**
     * Failing that, from the site URL, with `www.` dropped.
     *
     * `www.example.com` is not the sending domain — mail is not sent as `www` — and checking it
     * would report every record as missing on a perfectly configured installation.
     */
    public function testTheDomainFallsBackToTheSiteUrlWithoutWww(): void
    {
        // Arrange
        \Pramnos\Application\Settings::clearSettings();
        \Pramnos\Application\Settings::setSetting('site_url', 'https://www.example.com/', false);

        try {
            // Assert
            $this->assertSame('example.com', $this->command([])->probeSendingDomain());
        } finally {
            \Pramnos\Application\Settings::clearSettings();
        }
    }

    /**
     * The real inspector is what the command reaches for when nothing overrides it.
     */
    public function testItUsesTheRealInspector(): void
    {
        // Assert
        $this->assertInstanceOf(
            DnsAuthentication::class,
            (new class extends MailDnsCheck {
                public function probeInspector(): object { return $this->inspector(); }
            })->probeInspector()
        );
    }

    /**
     * @param array<string, list<string>>  $zone
     * @param array<string, mixed>         $input
     */
    private function check(array $zone, array $input = [], string $domain = 'example.com'): CommandTester
    {
        $command = $this->command($zone);

        $application = new Application();
        $application->add($command);

        $tester = new CommandTester($command);
        $tester->execute(
            $domain === '' ? $input : ['domain' => $domain] + $input,
            ['interactive' => false]
        );

        return $tester;
    }

    /** @param array<string, list<string>> $zone */
    private function command(array $zone): object
    {
        return new class ($zone) extends MailDnsCheck {
            public function __construct(private array $zone)
            {
                parent::__construct();
            }

            protected function inspector(): DnsAuthentication
            {
                $zone = $this->zone;

                return new DnsAuthentication(static function (string $host, int $type) use ($zone) {
                    return array_map(
                        static fn (string $record): array => ['type' => 'TXT', 'txt' => $record],
                        $zone[$host] ?? []
                    );
                });
            }

            public function probeSendingDomain(): string
            {
                return $this->sendingDomain();
            }
        };
    }
}
