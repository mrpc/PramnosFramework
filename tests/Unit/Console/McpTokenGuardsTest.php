<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\McpToken;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The four things `mcp:token` refuses to do, and the reason each refusal exists.
 *
 * The integration test drives the successful path against a real database. These
 * are the paths a working installation cannot reach — no database, no signing
 * key — plus the two argument guards. A refusal nothing exercises is a refusal
 * nobody has read back, and one of these is the most important line in the file:
 * it is what stops a convenience command minting a credential under a key every
 * installation in the same state would share.
 */
#[CoversClass(McpToken::class)]
class McpTokenGuardsTest extends TestCase
{
    /**
     * Run the command, optionally with one of its two seams forced.
     *
     * @param array<string, string> $options
     */
    /** @var array<string, mixed> What the command asked the user object to write */
    private array $written = [];

    private function mint(array $options, ?\Throwable $dbError = null, ?string $key = null): CommandTester
    {
        $this->written = [];
        $recorder      = &$this->written;

        // A User that records the write instead of performing it. The row itself is
        // the integration test's business; here the question is what the command
        // *decided* — which scopes, which expiry — and reading that off a captured
        // call is stronger than parsing it back out of the printed report.
        $user = new class ($recorder) extends \Pramnos\User\User {
            public function __construct(private array &$recorder)
            {
                parent::__construct();
                $this->userid = 4242;
                $this->email  = 'someone@example.com';
            }

            public function addScopedToken($tokentype, $token, array $scope, $notes = '', $expires = null)
            {
                $this->recorder = compact('tokentype', 'token', 'scope', 'notes', 'expires');

                return $this;
            }
        };

        $command = new class ($dbError, $key, $user) extends McpToken {
            public function __construct(
                private ?\Throwable $dbError,
                private ?string $key,
                private \Pramnos\User\User $user
            ) {
                parent::__construct();
            }

            protected function findUser(string $reference): ?\Pramnos\User\User
            {
                if ($this->dbError !== null) {
                    throw $this->dbError;
                }

                return $reference === 'nobody' ? null : $this->user;
            }

            protected function signingKey(): string
            {
                return $this->key ?? 'a-key-long-enough-for-hs256-signing-and-then-some';
            }
        };

        $application = new ConsoleApplication();
        $application->add($command);

        $tester = new CommandTester($application->find('mcp:token'));
        $tester->execute($options);

        return $tester;
    }

    /**
     * Without `--user` it mints nothing and says why.
     *
     * A token acts as somebody, and every call it makes is recorded as theirs. A
     * default here — the first user, or none — would produce a credential no
     * revocation screen lists against anybody.
     */
    public function testWithoutAUserItRefuses(): void
    {
        // Act
        $tester = $this->mint(array());

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('--user is required', $tester->getDisplay());
    }

    /**
     * An empty `--scopes` refuses rather than defaulting to something.
     *
     * `--scopes=` reads as "I have decided what this token may do", and the answer
     * to that is not "everything the default happens to be".
     */
    public function testAnEmptyScopeListRefuses(): void
    {
        // Act
        $tester = $this->mint(array('--user' => '1', '--scopes' => ''));

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('--scopes is empty', $tester->getDisplay());
    }

    /**
     * A database that is not there is reported as what it is.
     *
     * Minting a token means writing a row, so there is nowhere else this can happen
     * — and the driver's own message for it is «No such file or directory» from a
     * socket path, which is not a sentence anybody can act on. The added line says
     * where to run the command instead.
     */
    public function testAnUnreachableDatabaseSaysWhereToRunIt(): void
    {
        // Act
        $tester = $this->mint(
            array('--user' => '1'),
            new \RuntimeException('No such file or directory')
        );

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Could not reach the database', $display);
        // the driver's own words are kept, because they are sometimes the real cause
        $this->assertStringContainsString('No such file or directory', $display);
        $this->assertStringContainsString('on the installation you want to reach', $display);
    }

    /**
     * With no usable signing key it refuses to mint anything at all.
     *
     * The most important refusal in the command. Without `authenticationKey` and
     * without `sURL`, the derivation reduces to a hash of a version string that
     * defaults to `edge` — so every installation in that state signs with the same
     * publicly computable constant, and a token from any of them verifies against
     * all of them. Signing anyway would be worse than failing.
     */
    public function testWithNoSigningKeyItMintsNothing(): void
    {
        // Act
        $tester = $this->mint(array('--user' => '1'), null, '');

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('no usable signing key', $display);
        // and it says what the consequence of not refusing would have been
        $this->assertStringContainsString('every other site in the same state', $display);
        // nothing that looks like a JWT was printed
        $this->assertDoesNotMatchRegularExpression(
            '/[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/',
            $display
        );
    }

    /**
     * A scope the framework does not define is named back, not dropped.
     *
     * Dropping it would mint a token that half works, and the half that does not
     * appears as a tool missing from a list with nothing anywhere to say why. The
     * message lists what is available, because the person typing it has just got a
     * name wrong and the fix is one line up the terminal.
     */
    public function testAnUnknownScopeIsNamedWithTheAlternatives(): void
    {
        // Act
        $tester = $this->mint(array('--user' => '1', '--scopes' => 'diagnostics,everything'));

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('everything', $display);
        $this->assertStringContainsString('mcp:diagnostics', $display);
        $this->assertStringContainsString('Short forms', $display);
    }

    /**
     * The short names and the full names are the same thing.
     *
     * `db` and `mcp:db_read` have to produce identical tokens, or the documented
     * shorthand is a second vocabulary that can drift from the first.
     */
    public function testShortAndFullScopeNamesAgree(): void
    {
        // Act
        $this->mint(array('--user' => '1', '--scopes' => 'db'));
        $viaShort = $this->written['scope'];
        $this->mint(array('--user' => '1', '--scopes' => 'mcp:db_read'));
        $viaFull = $this->written['scope'];

        // Assert — the same scopes reached the write, not merely the same printed line
        $this->assertSame(array('mcp', 'mcp:db_read'), $viaShort);
        $this->assertSame($viaShort, $viaFull);
    }

    /**
     * `--days=0` means a token that never expires, and the report says so in words.
     *
     * A date is checkable at a glance and «never» is not, so it has to be spelt out
     * rather than shown as an empty column or a 1970 timestamp.
     */
    public function testZeroDaysReportsNeverRatherThanADate(): void
    {
        // Act
        $display = $this->mint(array('--user' => '1', '--days' => '0'))->getDisplay();

        // Assert
        $this->assertStringContainsString('never', $display);
        $this->assertStringNotContainsString('1970', $display);
        // and it is a real absence rather than a zero the row would read as 1970
        $this->assertNull($this->written['expires']);
        $this->assertSame('access_token', $this->written['tokentype']);
    }

    /**
     * With no `--url` and no `sURL`, the printed config carries a placeholder rather
     * than a broken URL.
     *
     * `/mcp` on its own would be pasted and fail with a message about a relative
     * address; a visible `https://your-site` is a blank somebody fills in.
     */
    public function testWithoutABaseUrlThePlaceholderIsVisible(): void
    {
        if (defined('sURL') && sURL !== '') {
            $this->markTestSkipped('This process has an sURL, so the fallback cannot be reached.');
        }

        // Act
        $display = $this->mint(array('--user' => '1'))->getDisplay();

        // Assert
        $this->assertStringContainsString('https://your-site/mcp', $display);
    }
}
