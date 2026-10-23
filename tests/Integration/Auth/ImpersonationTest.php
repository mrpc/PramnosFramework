<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Auth;
use Pramnos\Auth\Impersonation;
use Pramnos\Auth\RememberTokens;
use Pramnos\Tests\Characterization\Auth\SignsInOnEachDatabase;
use Pramnos\User\User;

/**
 * "Log in as", and back — on MySQL and PostgreSQL.
 *
 * What holds: an administrator signs in as an account of a lower rank; their own sign-in token
 * ends, so nothing that signs them in stays in the browser; the session knows it is somebody
 * else, for a banner; coming back ends the impersonated sign-in's token and signs the
 * administrator in again with a fresh one, without a password, once — the way back is spent; an
 * expired way back does not open; signing out while impersonating closes it; and who may, as whom,
 * is refused with a reason.
 */
#[CoversClass(Impersonation::class)]
#[CoversClass(Auth::class)]
#[CoversClass(\Pramnos\Application\Controllers\Impersonation::class)]
#[CoversClass(\Pramnos\Application\Controllers\UsersController::class)]
class ImpersonationTest extends TestCase
{
    use SignsInOnEachDatabase {
        tearDown as tearDownSignIn;
    }

    /** @var list<int> Accounts this test made beside the trait's */
    private array $made = [];

    /** The impersonations table, and an administrator (99), a manager (90) and a customer (1). */
    private function people(string $type, string $host, int $port): array
    {
        $this->connect($type, $host, $port);
        $app = $this->getMockBuilder(\Pramnos\Application\Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $this->db;
        require_once ROOT . '/database/migrations/framework/auth/2026_10_16_000003_create_impersonations_table.php';
        (new \Pramnos\Framework\Migrations\Auth\CreateImpersonationsTable($app))->up();
        // Where what happened goes: the audit log, asked afresh on each backend.
        require_once ROOT . '/database/migrations/framework/authserver/2020_01_01_000024_create_authserver_audit_log_table.php';
        (new \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverAuditLogTable($app))->up();
        \Pramnos\Auth\AuditLog::resetTableCache();

        $ids = [];
        foreach (['admin' => 99, 'manager' => 90, 'customer' => 1] as $role => $type) {
            $user           = new User();
            $user->username = 'imp_' . $role . '_' . bin2hex(random_bytes(3));
            $user->email    = $user->username . '@example.com';
            $user->usertype = $type;
            $user->setPassword('x');
            $user->save();
            $ids[$role]   = (int) $user->userid;
            $this->made[] = (int) $user->userid;
        }

        return $ids;
    }

    /** Sign in as one of them, as from the sign-in form. */
    private function signIn(int $userId): string
    {
        $_SESSION = [];
        User::forgetCurrentUser();
        (new Auth())->loginById($userId, true);

        return (string) $_SESSION['auth'];
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            foreach ($this->made as $id) {
                $this->db->queryBuilder()->table('#PREFIX#impersonations')->where('actor_userid', $id)->delete();
                $this->db->queryBuilder()->table('#PREFIX#remember_tokens')->where('userid', $id)->delete();
                $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $id)->delete();
            }
        }
        $this->made = [];
        $this->tearDownSignIn();
    }

    /**
     * There and back: the administrator's token ends on the way in; the session is the customer's
     * and says who is behind it; back, the customer's token ends, the administrator has a fresh
     * one, and the way back is spent — its row gone, the audit log saying it began and how it
     * ended.
     */
    #[DataProvider('databases')]
    public function testThereAndBack(string $type, string $host, int $port): void
    {
        // Arrange
        $people = $this->people($type, $host, $port);
        $admins = $this->signIn($people['admin']);

        // Act — in as the customer
        $started  = Impersonation::start($people['customer']);
        $asThem   = (int) $_SESSION['uid'];
        $theirs   = (string) $_SESSION['auth'];
        $current  = Impersonation::current();
        $banner   = Impersonation::banner('/Impersonation');
        $open     = $this->db->queryBuilder()->table('#PREFIX#impersonations')->where('actor_userid', $people['admin'])->first()->fields;
        // …and back
        $back     = (new Auth())->endImpersonation();
        $asAdmin  = (int) $_SESSION['uid'];
        $fresh    = (string) $_SESSION['auth'];
        $again    = Impersonation::end();
        $left     = $this->db->queryBuilder()->table('#PREFIX#impersonations')->where('actor_userid', $people['admin'])->count();
        $audit    = \Pramnos\Auth\AuditLog::query(['actor' => $people['admin'], 'event' => 'user.impersonation_*']);

        // Assert
        $this->assertNull($started);
        $this->assertSame($people['customer'], $asThem);
        $this->assertNull(RememberTokens::userFor($admins), 'nothing that signs the administrator in stays in the browser');
        $this->assertSame($people['admin'], $current['actor']);
        $this->assertStringContainsString('You are signed in as imp_customer_', $banner);
        $this->assertStringContainsString('action="/Impersonation"', $banner);
        $this->assertTrue($back);
        $this->assertSame($people['admin'], $asAdmin, 'back, without a password');
        $this->assertNull(RememberTokens::userFor($theirs), 'the impersonated sign-in ended');
        $this->assertSame($people['admin'], RememberTokens::userFor($fresh));
        $this->assertFalse($again, 'the way back is spent');
        $this->assertSame($people['customer'], (int) $open['target_userid']);
        $this->assertNotSame(hash('sha256', ''), $open['return_hash'], 'only a hash of the key is kept');
        $this->assertSame(0, (int) $left, 'a way back that was used is not kept');
        $this->assertSame(['user.impersonation_ended', 'user.impersonation_started'], array_column($audit, 'event_type'), 'what happened is the audit log\'s');
        $this->assertSame((string) $people['customer'], (string) $audit[0]['target_id']);
        $this->assertSame('return', $audit[0]['metadata']['how'] ?? null);
        $this->assertNull(Impersonation::current());
        $this->assertSame('', Impersonation::banner('/Impersonation'), 'nobody else now');
    }

    /**
     * An expired way back does not open, and is closed as expired; signing out closes it too; the
     * next one to begin clears any left past its expiry. None of them is kept.
     */
    #[DataProvider('databases')]
    public function testExpiredAndSignedOut(string $type, string $host, int $port): void
    {
        // Arrange
        $people = $this->people($type, $host, $port);
        $this->signIn($people['admin']);
        Impersonation::start($people['customer']);
        $this->db->queryBuilder()->table('#PREFIX#impersonations')->where('actor_userid', $people['admin'])->update(['expires_at' => time() - 1]);

        // Act
        $expired = Impersonation::end();
        $this->signIn($people['admin']);
        Impersonation::start($people['customer']);
        (new Auth())->logout();
        $this->db->queryBuilder()->table('#PREFIX#impersonations')->insert(['actor_userid' => $people['admin'], 'target_userid' => $people['customer'],
            'return_hash' => str_repeat('0', 64), 'remember' => false, 'created_at' => time() - 7200, 'expires_at' => time() - 3600]);
        $this->signIn($people['admin']);
        Impersonation::start($people['customer']);
        $kept    = (int) $this->db->queryBuilder()->table('#PREFIX#impersonations')->where('actor_userid', $people['admin'])->count();
        $endings = array_map(static fn (array $row): ?string => $row['metadata']['how'] ?? null,
            \Pramnos\Auth\AuditLog::query(['actor' => $people['admin'], 'event' => 'user.impersonation_ended']));

        // Assert
        $this->assertFalse($expired);
        $this->assertSame(['logout', 'expired'], $endings, 'newest first: how each ended');
        $this->assertSame(1, $kept, 'only the one open now: the one left past its expiry was cleared when this began');
    }

    /**
     * The two addresses: `Users/loginas/{id}` signs in as the account and goes to the site, or
     * back to the account with why not; `POST /Impersonation` comes back to the administration,
     * or says there is nothing to come back to.
     */
    #[DataProvider('databases')]
    public function testTheAddresses(string $type, string $host, int $port): void
    {
        // Arrange
        $people   = $this->people($type, $host, $port);
        $users = new class () extends \Pramnos\Application\Controllers\UsersController {
            /** @var list<string> */
            public array $errors = [];

            public string $to = '';

            public function __construct()
            {
            }

            protected function requireMinUserType($type): bool
            {
                return false;
            }

            protected function addError($error)
            {
                $this->errors[] = (string) $error;

                return $this;
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->to = (string) $url;
            }
        };
        $return = new class () extends \Pramnos\Application\Controllers\Impersonation {
            /** @var list<string> */
            public array $said = [];

            public string $to = '';

            public function __construct()
            {
            }

            protected function addMessage($message)
            {
                $this->said[] = (string) $message;

                return $this;
            }

            protected function addError($error)
            {
                $this->said[] = (string) $error;

                return $this;
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->to = (string) $url;
            }
        };

        // Act
        $this->signIn($people['admin']);
        $users->loginas($people['admin']);
        $refused = [$users->errors, $users->to];
        $users->loginas($people['customer']);
        $asThem = (int) $_SESSION['uid'];
        $return->display();
        $back   = [(int) $_SESSION['uid'], $return->said, $return->to];
        $return->said = [];
        $return->display();

        // Assert
        $this->assertSame(['There is no such account to sign in as.'], $refused[0]);
        $this->assertStringEndsWith('users/view/' . $people['admin'], $refused[1]);
        $this->assertSame($people['customer'], $asThem);
        $this->assertSame([$people['admin'], ['You are back in your own account.']], [$back[0], $back[1]]);
        $this->assertSame(adminUrl(), $back[2]);
        $this->assertSame(['There is no account to return to.'], $return->said);
    }

    /**
     * An account that cannot sign in — deactivated — is refused, and the attempt not left open but
     * audited as refused; before `migrate` has made the table, it is refused saying so, and nothing changes.
     */
    #[DataProvider('databases')]
    public function testWhenItCannotBeDone(string $type, string $host, int $port): void
    {
        // Arrange
        $people = $this->people($type, $host, $port);
        $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $people['customer'])->update(['active' => 0]);
        $this->signIn($people['admin']);

        // Act
        $inactive = Impersonation::start($people['customer']);
        $closed   = (int) $this->db->queryBuilder()->table('#PREFIX#impersonations')->where('actor_userid', $people['admin'])->count();
        $refused  = \Pramnos\Auth\AuditLog::query(['actor' => $people['admin'], 'event' => 'user.impersonation_ended']);
        $this->db->schema()->dropTableIfExists('impersonations');
        $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $people['customer'])->update(['active' => 1]);
        $noTable  = Impersonation::start($people['customer']);
        $_SESSION['impersonation'] = ['id' => 1, 'key' => 'x', 'actor' => $people['admin']];
        $noWayBack = Impersonation::end();
        unset($_SESSION['impersonation']);
        $app = $this->getMockBuilder(\Pramnos\Application\Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $this->db;
        (new \Pramnos\Framework\Migrations\Auth\CreateImpersonationsTable($app))->up();

        // Assert
        $this->assertSame('That account cannot sign in.', $inactive);
        $this->assertSame(0, $closed, 'the attempt is not left open');
        $this->assertSame('refused', $refused[0]['metadata']['how'] ?? null);
        $this->assertSame('Signing in as somebody else is not set up here (run migrate).', $noTable);
        $this->assertFalse($noWayBack);
        $this->assertSame($people['admin'], (int) $_SESSION['uid'], 'still the administrator');
    }

    /**
     * Who may, as whom: refused with a reason for nobody signed in, below the role, an account
     * of equal or higher rank, one's own, one that is not there, and while already somebody else;
     * the role from app.php.
     */
    #[DataProvider('databases')]
    public function testWhoMayAsWhom(string $type, string $host, int $port): void
    {
        // Arrange
        $people = $this->people($type, $host, $port);
        $app    = \Pramnos\Application\Application::getInstance();
        $info   = $app->applicationInfo;

        try {
            // Act
            $_SESSION = [];
            User::forgetCurrentUser();
            $nobody = Impersonation::start($people['customer']);
            $this->signIn($people['manager']);
            $low = Impersonation::start($people['customer']);
            $this->signIn($people['admin']);
            $self   = Impersonation::start($people['admin']);
            $higher = Impersonation::start($people['manager'] + 100000);
            $app->applicationInfo['auth'] = ['impersonation' => ['min_role' => 'manager']];
            $this->signIn($people['manager']);
            $up     = Impersonation::start($people['admin']);
            $down   = Impersonation::start($people['customer']);
            $twice  = Impersonation::start($people['customer']);
        } finally {
            $app->applicationInfo = $info;
        }

        // Assert
        $this->assertSame('Sign in first.', $nobody);
        $this->assertSame('You may not sign in as somebody else.', $low, 'a manager, below super');
        $this->assertSame('There is no such account to sign in as.', $self);
        $this->assertSame('There is no such account to sign in as.', $higher, 'not there');
        $this->assertSame('You may sign in only as an account of a lower rank.', $up);
        $this->assertNull($down, 'a manager may, where app.php says so');
        $this->assertSame('Return to your own account first.', $twice);
    }
}
