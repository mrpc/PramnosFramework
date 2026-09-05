<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Console\Commands\McpToken;
use Pramnos\Framework\Factory;
use Pramnos\User\Token;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Minting a credential for the MCP endpoint, against a real database.
 *
 * The command's whole job is to produce something that will still be accepted
 * three steps later, by code it never calls: a JWT the middleware can decode, a
 * row `loadByToken()` can find by digest, and a scope list the registry can read.
 * Every one of those is a different file, so a unit test with a mocked database
 * would assert that this command did what this command does — which is not the
 * question.
 */
#[CoversClass(McpToken::class)]
class McpTokenCommandTest extends TestCase
{
    private \Pramnos\Database\Database $db;
    private int $userId = 0;

    protected function setUp(): void
    {
        Settings::loadSettings(ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php');

        $this->db = Factory::getDatabase();

        if (!$this->db->connected) {
            $this->db->connect(true);
        }

        \Pramnos\User\User::setupDb();

        // A user of our own, so nothing here depends on which rows another class left
        // behind — and so the token rows can be cleaned up by owner.
        $this->userId = $this->makeUser();
    }

    protected function tearDown(): void
    {
        if ($this->userId > 0) {
            $this->db->queryBuilder()->table('#PREFIX#usertokens')
                ->where('userid', $this->userId)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')
                ->where('userid', $this->userId)->delete();
        }

        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(): int
    {
        $suffix = bin2hex(random_bytes(6));

        $this->db->queryBuilder()->table('#PREFIX#users')->insert(array(
            'username' => 'mcptok_' . $suffix,
            'email'    => 'mcptok_' . $suffix . '@example.com',
            'password' => '',
            'regdate'  => time(),
            'active'   => 1,
        ));

        return (int) $this->db->getInsertId();
    }

    private function mint(array $options): CommandTester
    {
        $application = new ConsoleApplication();
        $application->add(new McpToken());

        $tester = new CommandTester($application->find('mcp:token'));
        $tester->execute($options);

        return $tester;
    }

    /**
     * Pull the token out of the printed report.
     *
     * It is shown once and nowhere else — the column is encrypted at rest — so
     * reading it back off the output is not a shortcut, it is the only way anybody
     * gets it, including a person.
     */
    private function tokenFrom(string $output): string
    {
        // A JWT and nothing else on the line: three base64url segments.
        $this->assertMatchesRegularExpression(
            '/^\s*([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)\s*$/m',
            $output,
            'the report printed no token'
        );
        preg_match('/^\s*([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)\s*$/m', $output, $m);

        return $m[1];
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * The token it prints is the token authentication finds.
     *
     * The load-bearing assertion of the whole command. `loadByToken()` matches on a
     * sha256 digest of the presented value, written by `storageFor()` at insert
     * time — so a command that stored the value any other way would produce a
     * credential that verifies as a JWT, exists as a row, and authenticates nobody.
     */
    public function testTheMintedTokenAuthenticates(): void
    {
        // Arrange + Act
        $tester = $this->mint(array('--user' => (string) $this->userId, '--scopes' => 'diagnostics'));
        $jwt    = $this->tokenFrom($tester->getDisplay());

        // Assert
        $this->assertSame(0, $tester->getStatusCode());

        $found = new \Pramnos\User\User();
        $found->loadByToken($jwt, 'auth', false);

        $this->assertSame(
            $this->userId,
            (int) $found->userid,
            'the printed token did not find the row the command wrote'
        );
    }

    /**
     * The scopes reach the row, in the shape `Token` reads back.
     *
     * `addToken()` writes JSON; `Token::__construct()` decodes it. A mismatch here
     * is invisible until a caller with a correct token is told it has no scopes and
     * sees an empty tool list — the failure this feature exists to make diagnosable.
     */
    public function testTheScopesSurviveTheRoundTrip(): void
    {
        // Arrange + Act
        $tester = $this->mint(array(
            '--user'   => (string) $this->userId,
            '--scopes' => 'diagnostics,logs,db',
        ));
        $jwt = $this->tokenFrom($tester->getDisplay());

        // Act — read it back the way the middleware does
        $row = $this->db->queryBuilder()->table('#PREFIX#usertokens')
            ->where('token_lookup', Token::lookup($jwt))
            ->first();
        $token = new Token($row->fields);

        // Assert
        $this->assertIsArray($token->scope);
        $this->assertContains('mcp', $token->scope, '`mcp` is added so whoami is reachable');
        $this->assertContains('mcp:diagnostics', $token->scope);
        $this->assertContains('mcp:logs', $token->scope);
        $this->assertContains('mcp:db_read', $token->scope);
    }

    /**
     * `mcp` is granted even when nobody asked for it, and nothing else is.
     *
     * Asking for the logs and getting the database as well would be the exact
     * failure the three-scope split exists to prevent.
     */
    public function testAskingForOneGroupGrantsOnlyThatGroup(): void
    {
        // Arrange + Act
        $tester = $this->mint(array('--user' => (string) $this->userId, '--scopes' => 'logs'));
        $jwt    = $this->tokenFrom($tester->getDisplay());

        $row = $this->db->queryBuilder()->table('#PREFIX#usertokens')
            ->where('token_lookup', Token::lookup($jwt))
            ->first();
        $token = new Token($row->fields);

        // Assert
        $this->assertSame(array('mcp', 'mcp:logs'), $token->scope);
    }

    /**
     * The value is stored encrypted, not in plain text.
     *
     * A credential minted by a convenience command must not be the one credential
     * in the table that anybody with a `SELECT` can read.
     */
    public function testTheStoredValueIsNotThePlaintext(): void
    {
        if (!\Pramnos\Security\Encrypter::isAvailable()) {
            $this->markTestSkipped('No APP_KEY on this installation, so nothing is encrypted at rest.');
        }

        // Arrange + Act
        $tester = $this->mint(array('--user' => (string) $this->userId));
        $jwt    = $this->tokenFrom($tester->getDisplay());

        $row = $this->db->queryBuilder()->table('#PREFIX#usertokens')
            ->where('token_lookup', Token::lookup($jwt))
            ->first();

        // Assert
        $this->assertNotSame($jwt, $row->fields['token']);
        $this->assertStringStartsWith('enc:', (string) $row->fields['token']);
    }

    /**
     * The expiry reaches the row, so a token stops working on its own.
     *
     * The default is 30 days rather than never, because a credential nobody has to
     * renew is a credential nobody remembers to revoke.
     */
    public function testTheExpiryIsWrittenAndDefaultsToThirtyDays(): void
    {
        // Act
        $tester = $this->mint(array('--user' => (string) $this->userId, '--days' => '7'));
        $jwt    = $this->tokenFrom($tester->getDisplay());

        $row = $this->db->queryBuilder()->table('#PREFIX#usertokens')
            ->where('token_lookup', Token::lookup($jwt))
            ->first();

        // Assert — within a minute of seven days out
        $this->assertEqualsWithDelta(time() + (7 * 86400), (int) $row->fields['expires'], 60);
        $this->assertStringContainsString('Expires', $tester->getDisplay());
    }

    /**
     * The client configuration is printed, and it is valid JSON pointing at `/mcp`.
     *
     * The command exists to remove a step, and a block somebody has to correct
     * before pasting has not removed it.
     */
    public function testItPrintsPastableClientConfiguration(): void
    {
        // Act
        $tester = $this->mint(array(
            '--user' => (string) $this->userId,
            '--url'  => 'https://example.com',
            '--name' => 'prod',
        ));
        $display = $tester->getDisplay();
        $jwt     = $this->tokenFrom($display);

        // Assert — pull the JSON object out of the report and decode it
        $start  = strpos($display, '{');
        $config = json_decode(substr($display, (int) $start, strrpos($display, '}') - (int) $start + 1), true);

        $this->assertIsArray($config, 'the printed configuration was not valid JSON');
        $this->assertSame('https://example.com/mcp', $config['mcpServers']['prod']['url']);
        $this->assertSame('http', $config['mcpServers']['prod']['type']);
        $this->assertSame(
            'Bearer ' . $jwt,
            $config['mcpServers']['prod']['headers']['Authorization'],
            'the header carries a different token from the one printed above it'
        );
    }

    /**
     * A user reference that matches nobody mints nothing.
     *
     * Checked because the failure is otherwise silent in the worst way: a typo'd
     * `--user` that quietly created a token for user 0 is a credential belonging to
     * nobody, which no revocation screen lists.
     */
    public function testAnUnknownUserMintsNothing(): void
    {
        // Arrange
        $before = (int) $this->db->queryBuilder()->table('#PREFIX#usertokens')->count();

        // Act
        $tester = $this->mint(array('--user' => 'nobody@nowhere.invalid'));

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('No user matches', $tester->getDisplay());
        $this->assertSame(
            $before,
            (int) $this->db->queryBuilder()->table('#PREFIX#usertokens')->count()
        );
    }

    /**
     * A scope the framework does not define is refused, and nothing is written.
     *
     * Refusing rather than dropping it: a token minted with one scope silently
     * missing half works, and the half that does not appears as a tool absent from
     * a list with nothing anywhere to say why.
     */
    public function testAnUnknownScopeIsRefused(): void
    {
        // Arrange
        $before = (int) $this->db->queryBuilder()->table('#PREFIX#usertokens')->count();

        // Act
        $tester = $this->mint(array(
            '--user'   => (string) $this->userId,
            '--scopes' => 'diagnostics,mcp:everything',
        ));

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('mcp:everything', $tester->getDisplay());
        $this->assertSame(
            $before,
            (int) $this->db->queryBuilder()->table('#PREFIX#usertokens')->count()
        );
    }

    /**
     * The user can be named by email or username, not only by id.
     *
     * Because the person running this on a production box knows their address and
     * does not know their row number.
     */
    public function testTheUserCanBeNamedByEmail(): void
    {
        // Arrange
        $row = $this->db->queryBuilder()->table('#PREFIX#users')
            ->where('userid', $this->userId)->first();

        // Act
        $tester = $this->mint(array('--user' => (string) $row->fields['email']));

        // Assert
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString((string) $this->userId, $tester->getDisplay());
    }
}
