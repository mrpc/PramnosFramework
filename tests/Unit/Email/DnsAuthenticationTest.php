<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Email\DnsAuthentication;

/**
 * What a sending domain's DNS says, read the way a receiving server reads it.
 *
 * The whole point of this class is that it must not be optimistic. A check that reports a
 * domain as fine when it is not is worse than no check: the operator stops looking, and the
 * symptom — mail quietly filed as spam — surfaces months later as "I never got the password
 * reset".
 */
#[CoversClass(DnsAuthentication::class)]
class DnsAuthenticationTest extends TestCase
{
    /**
     * A record ending in `-all` is recognised as ending in `-all`.
     *
     * This is here because it did not. `~` is a valid SPF qualifier and was also the pattern's
     * delimiter, so the expression ended inside its own character class and every record was
     * reported as missing its `all` mechanism — a confidently wrong answer about the one line
     * somebody would then go and edit. Every qualifier is asserted, not just the one that broke.
     */
    public function testEveryAllQualifierIsRecognised(): void
    {
        foreach (['-all', '~all', '?all', '+all'] as $mechanism) {
            // Act
            $check = $this->inspect(['example.com' => ['v=spf1 include:a.example ' . $mechanism]])
                ['checks']['spf'];

            // Assert
            $this->assertTrue($check['ok'], $mechanism);
            $this->assertSame('Present.', $check['says'], $mechanism);
        }
    }

    /**
     * A record with no `all` at the end says nothing about servers it did not list.
     */
    public function testARecordWithNoAllIsIncomplete(): void
    {
        // Act
        $check = $this->inspect(['example.com' => ['v=spf1 include:a.example']])['checks']['spf'];

        // Assert
        $this->assertTrue($check['ok'], 'it is still a record');
        $this->assertStringContainsString('does not end in an `all`', $check['says']);
        $this->assertNotNull($check['fix']);
    }

    /**
     * Two SPF records authenticate **less** than one.
     *
     * RFC 7208: a domain with more than one is in error, and a receiver treating that as a
     * PermError gets no result at all. It is a common state — each record added by a different
     * person for a different service — and it looks like more coverage rather than none.
     */
    public function testTwoSpfRecordsAreAnError(): void
    {
        // Act
        $check = $this->inspect(['example.com' => [
            'v=spf1 include:a.example -all',
            'v=spf1 include:b.example -all',
        ]])['checks']['spf'];

        // Assert
        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('PermError', $check['says']);
    }

    /**
     * Other TXT records on the domain are not SPF records.
     */
    public function testUnrelatedTxtRecordsAreIgnored(): void
    {
        // Act
        $report = $this->inspect(['example.com' => [
            'google-site-verification=abc',
            'MS=ms12345',
        ]]);

        // Assert
        $this->assertFalse($report['checks']['spf']['ok']);
        $this->assertFalse($report['ready']);
    }

    /**
     * `p=none` is a DMARC record that enforces nothing, and is reported as such.
     *
     * The state that most looks like success: the record exists, every tool says "DMARC
     * found", and somebody forging the domain is still delivered. It is also the thing that
     * silently stops BIMI from ever working.
     */
    public function testAMonitoringOnlyDmarcIsNotEnforcing(): void
    {
        // Act
        $check = $this->inspect(['_dmarc.example.com' => ['v=DMARC1; p=none; rua=mailto:a@example.com']])
            ['checks']['dmarc'];

        // Assert
        $this->assertTrue($check['ok'], 'the record is there');
        $this->assertFalse($check['enforcing']);
        $this->assertSame('none', $check['policy']);
        $this->assertStringContainsString('enforces nothing', $check['says']);
    }

    /**
     * `p=quarantine` and `p=reject` are enforcing.
     */
    public function testAnEnforcingDmarcIsRecognised(): void
    {
        foreach (['quarantine', 'reject'] as $policy) {
            // Act
            $check = $this->inspect(['_dmarc.example.com' => ['v=DMARC1; p=' . $policy]])
                ['checks']['dmarc'];

            // Assert
            $this->assertTrue($check['enforcing'], $policy);
            $this->assertSame($policy, $check['policy']);
        }
    }

    /**
     * An unchecked DKIM is not a failed DKIM.
     *
     * The selector belongs to whatever signs the mail — often a relay — so an application
     * frequently does not know it. Reporting that as broken would be a false alarm on a
     * perfectly good installation, and false alarms are how a check stops being read.
     */
    public function testDkimWithoutASelectorIsUnknownRatherThanFailed(): void
    {
        // Act
        $report = $this->inspect([
            'example.com'         => ['v=spf1 -all'],
            '_dmarc.example.com'  => ['v=DMARC1; p=reject'],
        ]);

        // Assert
        $this->assertNull($report['checks']['dkim']['ok']);
        $this->assertTrue($report['ready'], 'an unknown DKIM must not fail the whole domain');
    }

    /**
     * With a selector, a missing key is a real failure.
     */
    public function testDkimWithASelectorIsCheckedForReal(): void
    {
        // Act
        $report = $this->inspect([
            'example.com'         => ['v=spf1 -all'],
            '_dmarc.example.com'  => ['v=DMARC1; p=reject'],
        ], 'mail');

        // Assert
        $this->assertFalse($report['checks']['dkim']['ok']);
        $this->assertSame('mail._domainkey.example.com', $report['checks']['dkim']['host']);
        $this->assertFalse($report['ready']);
    }

    /**
     * A DKIM key split across TXT chunks is one key, not a malformed one.
     *
     * A TXT record over 255 characters is stored as several strings, and a 2048-bit key always
     * is. Reading only the first chunk reports a working key as broken.
     */
    public function testAChunkedDkimKeyIsJoined(): void
    {
        // Arrange
        $key = str_repeat('A', 300);

        // Act
        $check = $this->inspect(
            ['mail._domainkey.example.com' => [['v=DKIM1; k=rsa; p=', $key]]],
            'mail'
        )['checks']['dkim'];

        // Assert
        $this->assertTrue($check['ok']);
        $this->assertStringContainsString($key, $check['record']);
    }

    /**
     * BIMI without a Verified Mark Certificate is reported as the partial thing it is.
     */
    public function testBimiWithoutACertificateIsPartial(): void
    {
        // Act
        $check = $this->inspect(['default._bimi.example.com' => ['v=BIMI1; l=https://example.com/logo.svg']])
            ['checks']['bimi'];

        // Assert
        $this->assertTrue($check['ok']);
        $this->assertSame('https://example.com/logo.svg', $check['logo']);
        $this->assertStringContainsString('Gmail and Apple', $check['says']);
    }

    /**
     * A BIMI record naming no logo has nothing to show.
     */
    public function testABimiRecordWithNoLogoIsUseless(): void
    {
        // Act
        $check = $this->inspect(['default._bimi.example.com' => ['v=BIMI1;']])['checks']['bimi'];

        // Assert
        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('no logo', $check['says']);
    }

    /**
     * A complete BIMI record says so without a caveat.
     */
    public function testACompleteBimiRecordIsComplete(): void
    {
        // Act
        $check = $this->inspect(['default._bimi.example.com' => [
            'v=BIMI1; l=https://example.com/logo.svg; a=https://example.com/vmc.pem',
        ]])['checks']['bimi'];

        // Assert
        $this->assertSame('https://example.com/vmc.pem', $check['vmc']);
        $this->assertNull($check['fix']);
    }

    /**
     * BIMI is not part of "ready", because a logo is not deliverability.
     *
     * A VMC is bought and requires a registered trademark. An installation without one is not
     * misconfigured, and failing the check for it would train people to ignore the result.
     */
    public function testBimiDoesNotDecideWhetherADomainIsReady(): void
    {
        // Act
        $report = $this->inspect([
            'example.com'        => ['v=spf1 -all'],
            '_dmarc.example.com' => ['v=DMARC1; p=quarantine'],
        ]);

        // Assert
        $this->assertFalse($report['checks']['bimi']['ok']);
        $this->assertTrue($report['ready']);
    }

    /**
     * A `p=none` domain clears the bulk-sender bar, and is still told it enforces nothing.
     *
     * Gmail and Yahoo ask for *a* DMARC record; `p=none` is one. A check that failed everybody
     * at `p=none` would be failing most of the internet, and a check that cries wolf is a check
     * nobody reads — so the verdict passes and the finding stays visible beside it.
     */
    public function testAMonitoringOnlyDomainClearsTheBarButIsStillTold(): void
    {
        // Act
        $report = $this->inspect([
            'example.com'        => ['v=spf1 -all'],
            '_dmarc.example.com' => ['v=DMARC1; p=none'],
        ]);

        // Assert
        $this->assertTrue($report['ready'], 'a DMARC record is what the bar asks for');
        $this->assertFalse($report['checks']['dmarc']['enforcing']);
        $this->assertStringContainsString('still delivered', $report['checks']['dmarc']['says']);
    }

    /**
     * A domain with no DMARC at all does not clear it.
     */
    public function testADomainWithNoDmarcIsNotReady(): void
    {
        // Act
        $report = $this->inspect(['example.com' => ['v=spf1 -all']]);

        // Assert
        $this->assertFalse($report['ready']);
        $this->assertFalse($report['checks']['dmarc']['ok']);
    }

    /**
     * A TXT record at `_dmarc` that is not a DMARC record is not one.
     *
     * Domains collect verification strings on every host they own. Reading the first TXT at
     * `_dmarc` as the policy would report an enforcing DMARC on a domain that has none.
     */
    public function testAStrayTxtAtDmarcIsNotADmarcRecord(): void
    {
        // Act
        $check = $this->inspect(['_dmarc.example.com' => [
            'some-verification=abc',
            'v=DMARC1; p=reject',
        ]])['checks']['dmarc'];

        // Assert
        $this->assertTrue($check['enforcing'], 'the real record is found past the stray one');
        $this->assertSame('reject', $check['policy']);
    }

    /**
     * The same at `default._bimi`.
     */
    public function testAStrayTxtAtBimiIsNotABimiRecord(): void
    {
        // Act
        $check = $this->inspect(['default._bimi.example.com' => [
            'unrelated=thing',
        ]])['checks']['bimi'];

        // Assert
        $this->assertFalse($check['ok']);
        $this->assertNull($check['record']);
    }

    /**
     * A domain is normalised before it is looked up.
     *
     * `Example.COM.` and `example.com` are the same domain, and an operator pasting the first
     * from a settings screen should not get "no records found".
     */
    public function testTheDomainIsNormalised(): void
    {
        // Act
        $report = $this->inspect(['example.com' => ['v=spf1 -all']], '', ' Example.COM. ');

        // Assert
        $this->assertSame('example.com', $report['domain']);
        $this->assertTrue($report['checks']['spf']['ok']);
    }

    /**
     * No domain is no report, rather than a lookup of the empty string.
     */
    public function testAnEmptyDomainIsNotLookedUp(): void
    {
        // Act
        $report = $this->inspect([], '', '   ');

        // Assert
        $this->assertSame('', $report['domain']);
        $this->assertSame([], $report['checks']);
        $this->assertFalse($report['ready']);
    }

    /**
     * A resolver that fails is a domain with no records, not a crash.
     *
     * `dns_get_record()` returns false on a lookup failure — a broken resolver, a network with
     * no DNS — and a check that fatals there is one that cannot run in the environment most
     * likely to need it.
     */
    public function testAFailingResolverIsNotAnException(): void
    {
        // Arrange
        $inspector = new DnsAuthentication(static fn (string $host, int $type) => false);

        // Act
        $report = $inspector->inspect('example.com');

        // Assert
        $this->assertFalse($report['ready']);
        $this->assertFalse($report['checks']['spf']['ok']);
    }

    /**
     * Run an inspection against a fixed set of records.
     *
     * @param array<string, list<string|list<string>>> $zone host => TXT strings, or chunks
     * @return array<string, mixed>
     */
    private function inspect(array $zone, string $selector = '', string $domain = 'example.com'): array
    {
        $resolver = static function (string $host, int $type) use ($zone) {
            $records = $zone[$host] ?? [];
            $out     = [];

            foreach ($records as $record) {
                $out[] = is_array($record)
                    ? ['type' => 'TXT', 'entries' => $record]
                    : ['type' => 'TXT', 'txt' => $record];
            }

            return $out;
        };

        return (new DnsAuthentication($resolver))->inspect($domain, $selector);
    }
}
