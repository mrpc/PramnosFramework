<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Email\DkimKey;
use Pramnos\Email\Email;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * A transport that keeps what it is given, so a test can read the message as it left.
 */
final class CapturingTransport extends AbstractTransport
{
    /** @var list<string> Each message, as the bytes that would have gone on the wire */
    public array $sent = [];

    protected function doSend(SentMessage $message): void
    {
        $this->sent[] = $message->toString();
    }

    public function __toString(): string
    {
        return 'capture://';
    }
}

/**
 * Mail sent from a customer's own domain: its DKIM signature and its own server.
 *
 * Both are opt-in, per message. Without them nothing changes: the installation's SMTP
 * settings carry the mail and the relay signs it, as it always has. With them, the message
 * carries a signature for the customer's domain, or leaves through the customer's server —
 * and a password or a key never reaches a log or the outbox.
 */
#[CoversClass(Email::class)]
#[CoversClass(DkimKey::class)]
class EmailOwnDomainTest extends TestCase
{
    private static ?DkimKey $key = null;

    /** One key for the class: generating RSA keys is the slow part. */
    private static function key(): DkimKey
    {
        return self::$key ??= DkimKey::generate('Customer.Example', 'pramnos1');
    }

    /** A message ready to send, from the customer's address. */
    private function message(): Email
    {
        return (new Email())->setBody('<p>Thank you for your release.</p>')->setTo('editor@news.example')
            ->setFrom('press@customer.example')->setSubject('Your press release');
    }

    /**
     * The key pair comes with the record to publish, under the selector's host.
     */
    public function testAKeyComesWithTheRecordToPublish(): void
    {
        // Act
        $key = self::key();

        // Assert
        $this->assertSame('pramnos1._domainkey.customer.example', $key->recordName(), 'the domain is not normalised');
        $this->assertStringStartsWith('v=DKIM1; k=rsa; p=', $key->recordValue());
        $this->assertNotFalse(openssl_pkey_get_private($key->privateKeyPem), 'the private key does not load');
        // The published half is the private key's own public key.
        $details = openssl_pkey_get_details(openssl_pkey_get_private($key->privateKeyPem));
        $this->assertStringContainsString(
            $key->publicKey,
            (string) preg_replace('/-----[^-]+-----|\s+/', '', (string) $details['key'])
        );
    }

    /**
     * A key needs a domain, a selector and enough bits.
     */
    public function testAKeyRefusesWhatCannotBeAKey(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        DkimKey::generate('customer.example', 'pramnos1', 512);
    }

    /**
     * A message signed for the customer's domain leaves with that signature.
     *
     * `d=` must be the From domain for DMARC to align, and the signature is the last header
     * added, so nothing after it breaks it.
     */
    public function testASignedMessageCarriesTheDomainsSignature(): void
    {
        // Arrange
        $transport = new CapturingTransport();
        $email     = $this->message()->withTransport($transport)
            ->signWith('customer.example', 'pramnos1', self::key()->privateKeyPem);

        // Act
        $sent = $email->send();

        // Assert
        $this->assertTrue($sent, $email->getLastError());
        $this->assertCount(1, $transport->sent);
        $this->assertMatchesRegularExpression('/^DKIM-Signature:.*d=customer\.example/ms', $transport->sent[0]);
        $this->assertMatchesRegularExpression('/^DKIM-Signature:.*s=pramnos1/ms', $transport->sent[0]);
    }

    /**
     * Without signWith(), no signature is added: the relay signs, as it always has.
     */
    public function testAnUnsignedMessageIsLeftToTheRelay(): void
    {
        // Arrange
        $transport = new CapturingTransport();

        // Act
        $this->message()->withTransport($transport)->send();

        // Assert
        $this->assertCount(1, $transport->sent);
        $this->assertStringNotContainsString('DKIM-Signature:', $transport->sent[0]);
    }

    /**
     * signWith() refuses an empty domain, selector or key at the call, not at the send.
     */
    public function testSigningNeedsADomainASelectorAndAKey(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        $this->message()->signWith('customer.example', ' ', 'key');
    }

    /**
     * A key that does not load fails the send, and the key is not in the error.
     */
    public function testAnUnusableKeyFailsTheSendWithoutShowingTheKey(): void
    {
        // Arrange
        $transport = new CapturingTransport();
        $email     = $this->message()->withTransport($transport)
            ->signWith('customer.example', 'pramnos1', "-----BEGIN PRIVATE KEY-----\nTm90QUtleUF0QWxs\n-----END PRIVATE KEY-----");

        // Act
        $sent = $email->send();

        // Assert
        $this->assertFalse($sent);
        $this->assertSame([], $transport->sent, 'an unsigned message left in place of a signed one');
        $this->assertStringContainsString('DKIM', $email->getLastError());
        $this->assertStringNotContainsString('Tm90QUtleUF0QWxs', $email->getLastError());
    }

    /**
     * queue() sends a signed message at once: the outbox would have to store the key.
     */
    public function testQueueingASignedMessageSendsItNow(): void
    {
        // Arrange
        $transport = new CapturingTransport();
        $email     = $this->message()->withTransport($transport)
            ->signWith('customer.example', 'pramnos1', self::key()->privateKeyPem);

        // Act
        $queued = $email->queue();

        // Assert
        $this->assertTrue($queued, $email->getLastError());
        $this->assertCount(1, $transport->sent, 'the signed message was not sent at once');
    }

    /**
     * A message with its own server goes there, and a failure names that server.
     *
     * The installation's settings point elsewhere, so an error naming 127.0.0.1:1 proves
     * which server was used; the password set for it appears nowhere in the error or the log.
     */
    public function testAMessageWithItsOwnServerGoesThereAndAFailureNamesIt(): void
    {
        // Arrange
        Settings::setSetting('smtp_host', 'installation-relay.invalid', false);
        $stream = fopen('php://memory', 'r+');
        \Pramnos\Logs\Logger::setOutputMode(\Pramnos\Logs\Logger::OUTPUT_STREAM);
        \Pramnos\Logs\Logger::setStreamTarget($stream);
        $email = $this->message()->setDebug(true)
            ->via(['host' => '127.0.0.1', 'port' => 1, 'user' => 'press', 'password' => 'Vx7CustomerSecret', 'tls' => false]);

        try {
            // Act
            $sent = $email->send();
            rewind($stream);
            $log = (string) stream_get_contents($stream);
        } finally {
            \Pramnos\Logs\Logger::setStreamTarget(null);
            \Pramnos\Logs\Logger::setOutputMode(\Pramnos\Logs\Logger::OUTPUT_FILE);
            Settings::deleteSetting('smtp_host');
        }

        // Assert
        $this->assertFalse($sent);
        $this->assertStringStartsWith('SMTP 127.0.0.1:1: ', $email->getLastError());
        $this->assertStringNotContainsString('installation-relay', $email->getLastError());
        $this->assertStringContainsString('a server given for this message', $log);
        $this->assertStringNotContainsString('Vx7Customer', $log . $email->getLastError(), 'the password was written down');
    }

    /**
     * A server needs a host.
     */
    public function testAServerNeedsAHost(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        $this->message()->via(['port' => 587]);
    }
}
