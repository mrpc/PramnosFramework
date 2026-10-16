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
     * one, and the way back is spent.
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
        // …and back
        $back     = (new Auth())->endImpersonation();
        $asAdmin  = (int) $_SESSION['uid'];
        $fresh    = (string) $_SESSION['auth'];
        $again    = Impersonation::end();
        $row      = $this->db->queryBuilder()->table('#PREFIX#impersonations')->where('actor_userid', $people['admin'])->first()->fields;

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
        $this->assertSame([$people['customer'], 'return'], [(int) $row['target_userid'], $row['ended_by']]);
        $this->assertNotSame(hash('sha256', ''), $row['return_hash'], 'only a hash of the key is kept');
        $this->assertNull(Impersonation::current());
        $this->assertSame('', Impersonation::banner('/Impersonation'), 'nobody else now');
    }

    /** An expired way back does not open, and is closed as expired; signing out closes it too. */
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
        $first   = $this->db->queryBuilder()->table('#PREFIX#impersonations')->where('actor_userid', $people['admin'])->first()->fields['ended_by'];
        $this->signIn($people['admin']);
        Impersonation::start($people['customer']);
        (new Auth())->logout();
        $endings = [];
        $result  = $this->db->queryBuilder()->table('#PREFIX#impersonations')->where('actor_userid', $people['admin'])->orderBy('id')->get();
        while (($row = $result->fetch()) !== null) {
            $endings[] = $row['ended_by'];
        }

        // Assert
        $this->assertFalse($expired);
        $this->assertSame('expired', $first);
        $this->assertSame(['expired', 'logout'], $endings);
        $this->assertNull(Impersonation::current());
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
     * An account that cannot sign in — deactivated — is refused, and the attempt closed, not left
     * open; before `migrate` has made the table, it is refused saying so, and nothing changes.
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
        $closed   = $this->db->queryBuilder()->table('#PREFIX#impersonations')->where('actor_userid', $people['admin'])->first()->fields['ended_by'];
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
        $this->assertSame('refused', $closed);
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
