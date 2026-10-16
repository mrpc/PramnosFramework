<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Dashboard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\UsersController;
use Pramnos\Dashboard\Widgets\SessionsOnline;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;

/** The card with a column of an application's own. */
final class SessionsWithHistory extends SessionsOnline
{
    /** The pages it saw. */
    protected function rowExtras(array $session): string
    {
        return '<td data-history>' . htmlspecialchars((string) $session['uname'], ENT_QUOTES) . '</td>';
    }
}

/** The users screen, with its redirect and flash recorded and its floor open. */
final class EndingUsers extends UsersController
{
    /** @var list<string> */
    public array $messages = [];

    /** @var list<string> */
    public array $errors = [];

    public string $redirectedTo = '';

    /** Not the application's constructor: no application here. */
    public function __construct()
    {
    }

    /** The floor is open in this test. */
    protected function requireMinUserType($type): bool
    {
        return false;
    }

    protected function addMessage($message)
    {
        $this->messages[] = (string) $message;

        return $this;
    }

    protected function addError($error)
    {
        $this->errors[] = (string) $error;

        return $this;
    }

    public function redirect($url = null, $quit = true, $code = '302')
    {
        $this->redirectedTo = (string) $url;
    }
}

/**
 * Who is online, and signing one of them out — on MySQL and PostgreSQL.
 *
 * What holds: the card lists the signed-in sessions of the last fifteen minutes, newest first —
 * not visitors, not one signed out, not one gone quiet — with how many are signed in and how many
 * are visitors; this administrator's own session has no button; an application adds a column of
 * its own; and ending a session flags it to sign out on its next request, never this one's own,
 * and says so when there is no such session.
 */
#[CoversClass(SessionsOnline::class)]
#[CoversClass(UsersController::class)]
class SessionsOnlineTest extends TestCase
{
    private ?Database $db = null;

    private ?Database $previous = null;

    private string $mark = '';

    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306],
            'postgresql' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->queryBuilder()->table('#PREFIX#sessions')->where('uname', 'like', $this->mark . '%')->delete();
        }
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        $_POST = [];
    }

    /** A connection made the framework's, and five sessions: two signed in, a visitor, one signed out, one quiet. */
    private function connect(string $type, string $host, int $port): void
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $db;
        $this->db       = $db;
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Core\CreateSessionsTable::class], $db);
        $db->queryBuilder()->table('#PREFIX#sessions')->where('time', '>=', time() - 3600)->delete();
        $this->mark = 'so' . bin2hex(random_bytes(3));
        $rows = [
            ['maria', 0, 0, 60, md5((string) session_id())],
            ['nikos', 0, 0, 10, 'other-sid'],
            ['guest', 1, 0, 30, 'g'],
            ['left', 0, 1, 20, 'l'],
            ['quiet', 0, 0, 3600, 'q'],
        ];
        foreach ($rows as $i => [$name, $guest, $logout, $ago, $sid]) {
            $db->queryBuilder()->table('#PREFIX#sessions')->insert(['visitorid' => $this->mark . $i, 'uname' => $this->mark . $name, 'time' => time() - $ago,
                'host_addr' => '10.0.0.' . $i, 'guest' => $guest, 'agent' => 'Firefox', 'userid' => 7 + $i, 'url' => '/page/' . $name,
                'logout' => $logout, 'sid' => $sid, 'history' => '']);
        }
    }

    /**
     * Signed-in sessions of the last fifteen minutes, newest first, with the count; no button on
     * this session; an application's own column.
     */
    #[DataProvider('databases')]
    public function testTheCard(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);

        // Act
        $html  = (new SessionsOnline())->render();
        $extra = (new SessionsWithHistory())->render();

        // Assert
        $this->assertStringContainsString('2 signed in · 1 visitors', $html);
        $this->assertLessThan(strpos($html, $this->mark . 'maria'), strpos($html, $this->mark . 'nikos'), 'newest first');
        foreach (['guest', 'left', 'quiet'] as $absent) {
            $this->assertStringNotContainsString($this->mark . $absent, $html, $absent);
        }
        $this->assertSame(1, substr_count($html, 'name="sid"'), 'no button on this session');
        $this->assertStringContainsString('This session', $html);
        $this->assertStringContainsString('users/view/8', $html, 'linked to the account');
        $this->assertStringContainsString('data-history', $extra);
        $this->assertSame(['sessions.online', false, SessionsOnline::FULL], [(new SessionsOnline())->id(), (new SessionsOnline())->byDefault(), (new SessionsOnline())->size()]);
        $this->assertFalse((new SessionsOnline())->allowed(null));
        $this->assertNotSame('', (new SessionsOnline())->title() . (new SessionsOnline())->description() . (new SessionsOnline())->link());
    }

    /** Ending a session flags it, never this one's own, and says when there is nothing to end. */
    #[DataProvider('databases')]
    public function testEndingASession(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $flag = fn (int $i): int => (int) $this->db->queryBuilder()->table('#PREFIX#sessions')->where('visitorid', $this->mark . $i)->first()->fields['logout'];

        // Act
        $_POST = ['sid' => 'other-sid', 'return' => '/admin/Dashboard'];
        $ended = new EndingUsers();
        $ended->endsession();
        $_POST = ['sid' => md5((string) session_id())];
        $own = new EndingUsers();
        $own->endsession();
        $_POST = ['sid' => '', 'return' => 'https://evil.test/'];
        $none = new EndingUsers();
        $none->endsession();

        // Assert
        $this->assertSame([1, 0], [$flag(1), $flag(0)], 'nikos flagged; this session not');
        $this->assertSame(['The session will be signed out on its next request.'], $ended->messages);
        $this->assertSame('/admin/Dashboard', $ended->redirectedTo);
        $this->assertSame(['There is no such session to end.'], $own->errors);
        $this->assertSame(['There is no such session to end.'], $none->errors);
        $this->assertStringEndsWith('Dashboard', $none->redirectedTo, 'not to another site');
        $this->assertStringNotContainsString('evil', $none->redirectedTo);
    }
}
