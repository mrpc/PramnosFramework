<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Console\Commands\MailBounces;
use Pramnos\Email\BounceMailbox;
use Pramnos\Email\Bounces;
use Pramnos\Framework\Factory;
use Pramnos\Http\Client;
use Pramnos\Http\ClientResponse;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The bounce mailbox read over IMAP, without the `imap` extension: signed in with a password or
 * with OAuth2 (XOAUTH2, as Gmail wants), its unseen messages read without marking them, each
 * delivery report recorded and marked seen, anything else left unread for a person. Against a
 * scripted IMAP server in a process of its own.
 */
#[CoversClass(BounceMailbox::class)]
#[CoversClass(\Pramnos\Email\ImapClient::class)]
#[CoversClass(MailBounces::class)]
final class BounceMailboxTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    private string $dir = '';

    private ?array $savedInstances = null;

    /** @var array<string, string> Settings this test changed, and what they were */
    private array $settings = [];

    protected function setUp(): void
    {
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Messaging\CreateBouncesTable::class], $db);
        Bounces::forget();
        $this->dir = sys_get_temp_dir() . '/pf-imap-' . bin2hex(random_bytes(3));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
        foreach (['gone@bounces.test', 'full@bounces.test'] as $address) {
            Bounces::clear($address);
        }
        foreach ($this->settings as $name => $value) {
            Settings::setSetting($name, $value, false);
        }
        if ($this->savedInstances !== null) {
            (new \ReflectionProperty(Application::class, 'appInstances'))->setValue(null, $this->savedInstances);
        }
        Client::resetFakes();
    }

    /** Start the scripted server with these messages; the port it listens on. */
    private function serve(array $messages, array $more = []): int
    {
        file_put_contents($this->dir . '/messages.json', json_encode(['user' => 'bounces@example.com', 'password' => 'p"ss\\word', 'token' => 'tok-123', 'messages' => $messages] + $more));
        $this->server = proc_open(
            [PHP_BINARY, ROOT . '/tests/fixtures/imap/server.php', $this->dir . '/messages.json', $this->dir . '/log'],
            [1 => ['pipe', 'w'], 2 => ['file', $this->dir . '/server.err', 'a']],
            $pipes
        );

        return (int) trim((string) fgets($pipes[1]));
    }

    /** What the server was told. */
    private function log(): string
    {
        return (string) @file_get_contents($this->dir . '/log');
    }

    /** A delivery report for one failed recipient. */
    private static function report(string $address, string $status): string
    {
        return "From: MAILER-DAEMON@mx.example\r\nContent-Type: multipart/report; report-type=delivery-status; boundary=b\r\n\r\n"
            . "--b\r\nContent-Type: message/delivery-status\r\n\r\nReporting-MTA: dns; mx.example\r\n\r\n"
            . "Final-Recipient: rfc822; " . $address . "\r\nAction: failed\r\nStatus: " . $status . "\r\nDiagnostic-Code: smtp; 550 " . $status . "\r\n\r\n--b--\r\n";
    }

    /** `mail:bounces --imap` against this mailbox. */
    private function bounces(?BounceMailbox $mailbox, array $options = []): CommandTester
    {
        $tester = new CommandTester(new class ($mailbox) extends MailBounces {
            /** @param BounceMailbox|null $box The mailbox the test gives */
            public function __construct(private ?BounceMailbox $box)
            {
                parent::__construct();
            }

            protected function mailbox(): ?BounceMailbox
            {
                return $this->box;
            }
        });
        $tester->execute(['--imap' => true] + $options);

        return $tester;
    }

    /**
     * Signed in with a password — quoted, whatever it holds — the unseen messages are read
     * oldest first without marking them: each report is recorded and marked seen, and a message
     * that is not one is left unread.
     */
    public function testReportsAreRecordedAndMarkedSeen(): void
    {
        // Arrange
        $port = $this->serve([
            '7' => self::report('gone@bounces.test', '5.1.1'),
            '8' => "Subject: Re: your invoice\r\n\r\nThanks!\r\n",
            '9' => self::report('full@bounces.test', '5.2.2'),
        ]);
        $mailbox = new BounceMailbox(['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none', 'username' => 'bounces@example.com', 'password' => 'p"ss\\word']);

        // Act
        $tester = $this->bounces($mailbox);

        // Assert
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('2 report(s) in 3 unseen message(s) of bounces@example.com@127.0.0.1/INBOX', $tester->getDisplay());
        $this->assertNotNull(Bounces::refusal('gone@bounces.test'), 'a hard bounce, recorded');
        $this->assertSame(1, (int) Bounces::status('full@bounces.test')['softbounces'], 'a full mailbox, soft');
        $this->assertStringContainsString('LOGIN "bounces@example.com" "p\\"ss\\\\word"', $this->log());
        $this->assertSame(1, preg_match_all('/UID STORE 7 /', $this->log()), 'the first report marked seen');
        $this->assertStringNotContainsString('UID STORE 8 ', $this->log(), 'the invoice reply left for a person');
        $this->assertLessThan(strpos($this->log(), 'FETCH 9 '), strpos($this->log(), 'FETCH 7 '), 'oldest first');
        $this->assertStringContainsString('LOGOUT', $this->log());
    }

    /**
     * With a refresh token, the access token is fetched and the mailbox signed into with XOAUTH2;
     * a limit reads only that many.
     */
    public function testOAuthSignsInWithXoauth2(): void
    {
        // Arrange
        $port = $this->serve(['1' => self::report('gone@bounces.test', '5.1.1'), '2' => self::report('full@bounces.test', '5.2.2')]);
        Client::fake(['https://oauth2.googleapis.com/token' => new ClientResponse(200, '{"access_token":"tok-123"}')]);
        $mailbox = new BounceMailbox(['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none', 'username' => 'bounces@example.com',
            'oauth' => ['client_id' => 'id', 'client_secret' => 'secret', 'refresh_token' => 'refresh']]);

        // Act
        $tester = $this->bounces($mailbox, ['--limit' => 1]);

        // Assert
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('AUTHENTICATE XOAUTH2 ', $this->log());
        $this->assertStringNotContainsString('LOGIN', $this->log());
        $this->assertStringContainsString('1 report(s)', $tester->getDisplay());
        $this->assertNull(Bounces::status('full@bounces.test'), 'the second message waits for the next run');
    }

    /**
     * Refused credentials, a refused token or a refused refresh token fail the run and say which;
     * the password is not in what is said.
     */
    public function testARefusedSignInIsSaid(): void
    {
        // Arrange
        $port = $this->serve([]);
        $wrong = new BounceMailbox(['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none', 'username' => 'bounces@example.com', 'password' => 'not-it']);

        // Act
        $refused = $this->bounces($wrong);
        proc_close($this->server);
        $port = $this->serve([]);
        Client::fake(['https://oauth2.googleapis.com/token' => new ClientResponse(200, '{"access_token":"stale"}')]);
        $stale = $this->bounces(new BounceMailbox(['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none', 'username' => 'bounces@example.com',
            'oauth' => ['refresh_token' => 'refresh']]));
        proc_close($this->server);
        $port = $this->serve([]);
        Client::fake(['https://login.example/token' => new ClientResponse(400, '{"error":"invalid_grant"}')]);
        $grant = $this->bounces(new BounceMailbox(['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none',
            'oauth' => ['refresh_token' => 'revoked', 'token_url' => 'https://login.example/token']]));

        // Assert
        $this->assertSame(1, $refused->getStatusCode());
        $this->assertStringContainsString('refused LOGIN', $refused->getDisplay());
        $this->assertStringNotContainsString('not-it', $refused->getDisplay(), 'the password is not repeated');
        $this->assertStringContainsString('refused AUTHENTICATE', $stale->getDisplay());
        $this->assertStringContainsString('refresh token was refused (400)', $grant->getDisplay());
    }

    /**
     * A server that cannot be reached, or does not greet, fails the run; and without a mailbox
     * the command says how to name one.
     */
    public function testAMailboxThatIsNotThereIsSaid(): void
    {
        // Arrange
        $port = $this->serve([], ['greeting' => '* BYE']);

        // Act
        $silent      = $this->bounces(new BounceMailbox(['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none']));
        $unreachable = $this->bounces(new BounceMailbox(['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none']));
        // The command's own mailbox: none, where neither app.php nor the settings name one.
        $this->settings['imap_host'] = (string) Settings::getSetting('imap_host');
        Settings::setSetting('imap_host', '', false);
        $none = new CommandTester(new MailBounces());
        $none->execute(['--imap' => true]);

        // Assert
        $this->assertStringContainsString('did not greet as IMAP', $silent->getDisplay());
        $this->assertStringContainsString('Could not reach the mail server at 127.0.0.1:1', $unreachable->getDisplay());
        $this->assertSame(1, $none->getStatusCode());
        $this->assertStringContainsString('No bounce mailbox is configured', $none->getDisplay());
    }

    /**
     * The mailbox is the application's `email.bounces.mailbox`, or else the settings an
     * application already keeps — `imap_host` in the imap extension's `{host:port/flags}folder`
     * form, `imap_user`, `imap_pass`, the OAuth2 ones — and none without either.
     */
    public function testTheMailboxIsReadFromTheConfigurationOrTheSettings(): void
    {
        // Arrange
        foreach (['imap_host', 'imap_user', 'imap_pass', 'imap_oauth_client_id', 'imap_oauth_client_secret', 'imap_oauth_refresh_token'] as $name) {
            $this->settings[$name] = (string) Settings::getSetting($name);
        }
        $configure = function (array $info): void {
            $stub = new class extends Application {
                /** Only its settings are read. */
                public function __construct()
                {
                }
            };
            $stub->applicationInfo = $info;
            $ref = new \ReflectionProperty(Application::class, 'appInstances');
            $this->savedInstances ??= $ref->getValue() ?? [];
            $ref->setValue(null, ['default' => $stub] + $this->savedInstances);
        };

        // Act
        $configure(['email' => ['bounces' => ['mailbox' => ['host' => 'imap.example.com', 'username' => 'b@example.com', 'folder' => 'Bounces']]]]);
        $fromConfig = BounceMailbox::configured()?->describe();
        $configure([]);
        Settings::setSetting('imap_host', '', false);
        $none = BounceMailbox::configured();
        Settings::setSetting('imap_host', '{imap.gmail.com:993/imap/ssl}INBOX', false);
        Settings::setSetting('imap_user', 'bounces@example.com', false);
        Settings::setSetting('imap_oauth_refresh_token', 'refresh', false);
        $gmail = BounceMailbox::configured();
        Settings::setSetting('imap_host', '{mail.example.com/imap}Returned', false);
        Settings::setSetting('imap_oauth_refresh_token', '', false);
        $plain = BounceMailbox::configured();
        Settings::setSetting('imap_host', 'mail.example.com', false);
        $bare = BounceMailbox::configured();
        $config = static fn (?BounceMailbox $box): array => (array) (new \ReflectionProperty(BounceMailbox::class, 'config'))->getValue($box);

        // Assert
        $this->assertSame('b@example.com@imap.example.com/Bounces', $fromConfig);
        $this->assertNull($none);
        $this->assertSame(['imap.gmail.com', 993, 'ssl', 'INBOX', 'refresh'], [
            $config($gmail)['host'], $config($gmail)['port'], $config($gmail)['encryption'], $config($gmail)['folder'], $config($gmail)['oauth']['refresh_token'],
        ]);
        $this->assertSame(['mail.example.com', 143, 'tls', 'Returned'], [$config($plain)['host'], $config($plain)['port'], $config($plain)['encryption'], $config($plain)['folder']]);
        $this->assertArrayNotHasKey('oauth', $config($plain));
        $this->assertSame(['mail.example.com', 993, 'ssl'], [$config($bare)['host'], $config($bare)['port'], $config($bare)['encryption']]);
    }
}
