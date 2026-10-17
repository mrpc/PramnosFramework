<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\OauthKeys;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command that gives an authorization server its signing key.
 *
 * The second run is the dangerous one: a new pair invalidates every token signed with the old,
 * so the refusal to overwrite is asserted first, and `--force` is the only way past it.
 */
#[CoversClass(OauthKeys::class)]
final class OauthKeysTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/oauth-keys-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** Runs the command against the temporary root; with `$failing`, OpenSSL makes no key. */
    private function keys(array $options, bool $failing = false, bool $asRoot = false): CommandTester
    {
        $root    = $this->root;
        $command = new class ($root, $failing, $asRoot) extends OauthKeys {
            /** The temporary root, in place of ROOT. */
            public function __construct(private string $at, private bool $failing, private bool $asRoot)
            {
                parent::__construct();
            }

            /** Root or not, as the test says. */
            protected function runningAsRoot(): bool
            {
                return $this->asRoot;
            }

            protected function root(): string
            {
                return $this->at;
            }

            protected function generate(): ?array
            {
                return $this->failing ? null : parent::generate();
            }
        };
        $application = new Application();
        $application->add($command);
        $tester = new CommandTester($application->find('oauth:keys'));
        $tester->execute($options);

        return $tester;
    }

    /**
     * A fresh installation gets an RSA pair that signs and verifies, the private key readable
     * only by its owner, and the warning to back it up.
     */
    public function testAFreshInstallationGetsAWorkingPair(): void
    {
        // Act
        $tester  = $this->keys([]);
        $private = $this->root . '/app/keys/private.key';
        $public  = $this->root . '/app/keys/public.key';

        // Assert
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertTrue(openssl_sign('payload', $signature, (string) file_get_contents($private), OPENSSL_ALGO_SHA256));
        $this->assertSame(1, openssl_verify('payload', $signature, (string) file_get_contents($public), OPENSSL_ALGO_SHA256), 'the two halves belong together');
        $this->assertSame('0600', substr(sprintf('%o', fileperms($private)), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms(dirname($private))), -4));
        $this->assertStringContainsString('Back this pair up', $tester->getDisplay());
    }

    /**
     * A second run refuses and leaves the pair as it was; `--force` replaces it.
     */
    public function testAnExistingPairIsReplacedOnlyWithForce(): void
    {
        // Arrange
        $this->keys([]);
        $first = (string) file_get_contents($this->root . '/app/keys/public.key');

        // Act
        $refused = $this->keys([]);
        $kept    = (string) file_get_contents($this->root . '/app/keys/public.key');
        $forced  = $this->keys(['--force' => true]);
        $new     = (string) file_get_contents($this->root . '/app/keys/public.key');

        // Assert
        $this->assertSame(1, $refused->getStatusCode());
        $this->assertStringContainsString('invalidates every token', $refused->getDisplay());
        $this->assertSame($first, $kept, 'refused: nothing written');
        $this->assertSame(0, $forced->getStatusCode());
        $this->assertNotSame($first, $new);
    }

    /** One half alone still counts as a pair to keep: replacing it is still a decision. */
    public function testHalfAPairIsNotSilentlyReplaced(): void
    {
        // Arrange
        mkdir($this->root . '/app/keys', 0700, true);
        file_put_contents($this->root . '/app/keys/private.key', 'old');

        // Act
        $tester = $this->keys([]);

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertSame('old', file_get_contents($this->root . '/app/keys/private.key'));
    }

    /** A directory that cannot be created, or files that cannot be written, are said, not hidden. */
    public function testWhatCannotBeWrittenIsSaid(): void
    {
        // Arrange — `app` is a file, so `app/keys` cannot be made
        file_put_contents($this->root . '/app', 'not a directory');

        // Act
        $tester = $this->keys([]);

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Could not create app/keys', $tester->getDisplay());
    }

    /**
     * A key file that cannot be written (a directory stands where it goes) and an OpenSSL that
     * makes no key each fail the command with the reason; and by default the root is ROOT.
     */
    public function testAWriteOrAKeyThatFailsIsSaid(): void
    {
        // Arrange
        mkdir($this->root . '/app/keys/private.key', 0700, true);

        // Act
        $unwritable = $this->keys([]);
        exec('rm -rf ' . escapeshellarg($this->root . '/app'));
        $nokey = $this->keys([], true);
        $root  = (new \ReflectionMethod(OauthKeys::class, 'root'))->invoke(new OauthKeys());
        $asRoot = (new \ReflectionMethod(OauthKeys::class, 'runningAsRoot'))->invoke(new OauthKeys());

        // Assert
        $this->assertSame(1, $unwritable->getStatusCode());
        $this->assertStringContainsString('Could not write the key pair', $unwritable->getDisplay());
        $this->assertSame(1, $nokey->getStatusCode());
        $this->assertStringContainsString('RSA key generation failed', $nokey->getDisplay());
        $this->assertFileDoesNotExist($this->root . '/app/keys/private.key', 'nothing half-written');
        $this->assertSame((string) ROOT, $root);
        $this->assertSame(function_exists('posix_geteuid') && posix_geteuid() === 0, $asRoot, 'what the process really is');
    }

    /**
     * The encryption key is made with the pair and never replaced — a new one would end every
     * refresh token — so the server no longer makes it in the middle of a request.
     */
    public function testTheEncryptionKeyIsMadeOnceAndKept(): void
    {
        // Act
        $this->keys([]);
        $first = (string) file_get_contents($this->root . '/app/keys/encryption.key');
        $this->keys(['--force' => true]);
        $kept = (string) file_get_contents($this->root . '/app/keys/encryption.key');

        // Assert
        $this->assertSame(32, strlen((string) base64_decode($first, true)));
        $this->assertSame($first, $kept, 'a new signing pair, the same encryption key');
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->root . '/app/keys/encryption.key')), -4));
    }

    /**
     * Run as root, the keys go to the web server's user with `--owner`; without it the command
     * says the web server cannot read them; a user that is not there fails the command.
     */
    public function testOwnershipIsGivenOrWarnedAbout(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->markTestSkipped('Giving files away needs root.');
        }

        // Act
        $owned   = $this->keys(['--owner' => 'nobody']);
        $warned  = $this->keys(['--force' => true], false, true);
        $nowhere = $this->keys(['--force' => true, '--owner' => 'no-such-user-here']);

        // Assert
        $this->assertSame(0, $owned->getStatusCode());
        $this->assertStringContainsString('Owned by nobody', $owned->getDisplay());
        $this->assertStringContainsString('Written as root', $warned->getDisplay());
        $this->assertSame(1, $nowhere->getStatusCode());
        $this->assertStringContainsString('Could not give app/keys to no-such-user-here', $nowhere->getDisplay());
        $this->assertSame('nobody', posix_getpwuid((int) fileowner($this->root . '/app/keys/encryption.key'))['name'], 'kept from the first run, still nobody\'s');
    }
}
