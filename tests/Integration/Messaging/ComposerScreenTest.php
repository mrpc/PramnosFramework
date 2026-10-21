<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Messaging;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\Composer;
use Pramnos\Auth\Gate;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Html\MessageComposer;
use Pramnos\User\User;

/**
 * The message composer's server — saved messages and the signature — against MySQL and
 * PostgreSQL.
 *
 * What holds: a saved message is the account's own, or shared; the list for a scope is the
 * account's and the shared ones, not another account's; the same name in a scope is replaced;
 * sharing needs `messages.share` where the application defines it; an account forgets its own,
 * and not another's; the body is cleaned; a name is required; the signature is a setting of the
 * account's, cleaned, and saved only with the session's token.
 */
#[CoversClass(Composer::class)]
final class ComposerScreenTest extends TestCase
{
    private ?Database $db = null;

    private ?Database $previous = null;

    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306],
            'postgresql' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    /** The framework's connection back, the tables gone, signed out. */
    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->schema()->dropTableIfExists('savedmessages');
            try {
                $this->db->queryBuilder()->table('#PREFIX#usersettings')->where('userid', 2)
                    ->where('setting', MessageComposer::SIGNATURE_SETTING)->delete();
            } catch (\Throwable) {
                // no table on this lane
            }
        }
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        Gate::reset();
        $_SESSION = [];
        $_GET     = [];
        $_POST    = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        if (Application::getInstance()) {
            Application::getInstance()->currentUser = null;
        }
    }

    /** A connection made the framework's, with a fresh savedmessages and the settings table. */
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
        $db->schema()->dropTableIfExists('savedmessages');
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $db;
        require_once ROOT . '/database/migrations/framework/messaging/2026_10_21_000003_create_savedmessages_table.php';
        (new \Pramnos\Framework\Migrations\Messaging\CreateSavedmessagesTable($app))->up();
        (new \Pramnos\Framework\Migrations\Core\CreateUsersettingsTable($app))->up();
    }

    /** Signed in as account `$id`, an administrator. */
    private function signIn(int $id = 2): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = ['logged' => true, 'login' => true, 'userid' => $id, 'uid' => $id, 'usertype' => 98];
        $user = new User(0);
        $user->userid   = $id;
        $user->usertype = 98;
        $user->username = 'admin' . $id;
        Application::getInstance()->currentUser = $user;
    }

    /** A controller whose redirect ends the action and whose views keep what they got. */
    private function controller(): Composer
    {
        return new class (Application::getInstance()) extends Composer {
            /** The last view it filled. */
            public ?object $lastView = null;

            /** The request ends at a redirect. */
            public function redirect($url = null, $quit = true, $code = '302')
            {
                throw new \RuntimeException('redirect_quit');
            }

            /** A plain object to publish on; display() answers the template's name. */
            public function &getView($name = '', $type = '', $args = [])
            {
                $view = new class extends \stdClass {
                    /** The template it was asked for. */
                    public function display($template = ''): string
                    {
                        return 'view:' . $template;
                    }
                };
                $this->lastView = $view;

                return $view;
            }
        };
    }

    /**
     * An action's JSON answer.
     *
     * @return array<string, mixed>
     */
    private static function json(mixed $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }

    /** POST `$data` to `$action` of a fresh controller, as account `$who`. */
    private function post(string $action, array $data, int $who = 2): array
    {
        $this->signIn($who);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $data;

        return self::json($this->controller()->{$action}());
    }

    /**
     * Saved for oneself, and shared: the list for a scope is the account's and the shared ones,
     * never another account's, nor another scope's; the same name is replaced, not added; the
     * body is cleaned of anything a message may not carry.
     */
    #[DataProvider('databases')]
    public function testSavedMessagesAreTheAccountsAndTheShared(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $this->post('save', ['name' => 'Welcome', 'subject' => 'Hi {firstname}', 'body' => '<p>Hello</p><script>x()</script>', 'scope' => 'user-message']);
        $this->post('save', ['name' => 'Welcome', 'subject' => 'Hi again {firstname}', 'body' => '<p>Hello</p>', 'scope' => 'user-message']);
        $shared = $this->post('save', ['name' => 'Rules', 'subject' => 'Rules', 'body' => '<p>Be kind</p>', 'scope' => 'user-message', 'shared' => '1']);
        $this->post('save', ['name' => 'Theirs', 'subject' => 'x', 'body' => '', 'scope' => 'user-message'], 3);
        $this->post('save', ['name' => 'Elsewhere', 'subject' => 'x', 'body' => '', 'scope' => 'mass-message']);

        // Act
        $this->signIn();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['scope' => 'user-message'];
        \Pramnos\Http\Request::resetInstance();
        $list = self::json($this->controller()->saved());
        $byName = array_column($list['messages'], null, 'name');

        // Assert
        $this->assertSame(['Rules', 'Welcome'], array_keys($byName), 'another account\'s or another scope\'s message was listed, or one is missing');
        $this->assertSame('Hi again {firstname}', $byName['Welcome']['subject'], 'the same name was added instead of replaced');
        $this->assertStringNotContainsString('<script', $byName['Welcome']['body']);
        $this->assertTrue($byName['Rules']['shared']);
        $this->assertTrue($shared['shared']);
        $this->assertTrue($list['canShare'], 'no messages.share defined: the administration floor is enough');
    }

    /**
     * Where the application defines `messages.share`, sharing — and forgetting a shared message —
     * needs it. An account forgets its own and not another's. A name is required.
     */
    #[DataProvider('databases')]
    public function testSharingAndForgettingFollowTheRule(string $type, string $host, int $port): void
    {
        // Arrange — the rule says no
        $this->connect($type, $host, $port);
        $own    = $this->post('save', ['name' => 'Mine', 'subject' => 's', 'body' => '', 'scope' => 'x'])['saved'];
        $shared = $this->post('save', ['name' => 'Ours', 'subject' => 's', 'body' => '', 'scope' => 'x', 'shared' => '1'])['saved'];
        Gate::define(Composer::SHARE_ABILITY, static fn (): bool => false);

        // Act
        $refusedShare  = $this->post('save', ['name' => 'Ours 2', 'subject' => 's', 'body' => '', 'scope' => 'x', 'shared' => '1']);
        $refusedForget = $this->post('forget', ['id' => (string) $shared]);
        $notMine       = $this->post('forget', ['id' => (string) $own], 3);
        $forgotten     = $this->post('forget', ['id' => (string) $own]);
        $gone          = $this->post('forget', ['id' => (string) $own]);
        $nameless      = $this->post('save', ['name' => '  ', 'scope' => 'x']);

        // Assert
        $this->assertArrayHasKey('error', $refusedShare, 'shared without the right');
        $this->assertArrayHasKey('error', $refusedForget, 'a shared message forgotten without the right');
        $this->assertArrayHasKey('error', $notMine, 'another account\'s message forgotten');
        $this->assertSame($own, $forgotten['forgotten'] ?? null);
        $this->assertArrayHasKey('error', $gone);
        $this->assertArrayHasKey('error', $nameless);
    }

    /**
     * The signature is the account's setting, cleaned, saved only with the session's token; the
     * page shows the saved one; the toolbar carries it for the form.
     */
    #[DataProvider('databases')]
    public function testTheSignatureIsTheAccountsOwn(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $this->signIn();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['signature' => '<p>— Ann</p><script>x()</script>'];

        // Act — without the token, then with it
        try {
            $this->controller()->signature();
        } catch (\RuntimeException) {
            // the redirect back to the page
        }
        $before = MessageComposer::signature();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = \Pramnos\Http\Session::getInstance()->getCsrfToken();
        try {
            $this->controller()->signature();
        } catch (\RuntimeException) {
            // the redirect back to the page
        }
        $saved = MessageComposer::signature();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $page = $this->controller();
        $shown = $page->signature();

        // Assert
        $this->assertSame('', $before, 'saved without the session\'s token');
        $this->assertSame('<p>— Ann</p>', $saved);
        $this->assertSame('view:signature', $shown);
        $this->assertSame('<p>— Ann</p>', $page->lastView->signature);
        $this->assertStringContainsString('data-signature="&lt;p&gt;— Ann&lt;/p&gt;"', MessageComposer::toolbar(['ai' => false]));
    }
}
