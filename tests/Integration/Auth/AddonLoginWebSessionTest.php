<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use Pramnos\Addon\Addon;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Auth;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\Token;
use Pramnos\User\User;

/**
 * A sign-in through a user addon gets a web-session token, as the built-in path does.
 *
 * The token was created only by the built-in lifecycle, so an application signing in through
 * `Addon\User\User` had none: no request was attributed in `usertokens`, *sign out everywhere*
 * had nothing to act on, and the page head never printed `<meta name="csrf">` — every write
 * `pf-utils.js` sends was refused. These tests drive `Auth::auth()` against the real database.
 */
class AddonLoginWebSessionTest extends BaseTestCase
{
    /** @var \Pramnos\Database\Database */
    private $db;

    /** @var array<string, mixed> The addon registry as it was before the test. */
    private array $addonSnapshot = [];

    /** @var int The account seeded for the test. */
    private int $uid = 0;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $dbRef = null;
        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        User::setupDb();

        $u = new User();
        $u->username = 'addonlogin_' . bin2hex(random_bytes(4));
        $u->email    = $u->username . '@example.com';
        $u->setPassword('Secr3t!pass');
        $u->save();
        $this->uid = (int) $u->userid;

        $prop = new \ReflectionProperty(Addon::class, '_addons');
        $this->addonSnapshot = $prop->getValue();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Addon::class, '_addons'))->setValue(null, $this->addonSnapshot);
        if ($this->uid > 0) {
            $this->db->queryBuilder()->table('usertokens')->where('userid', $this->uid)->delete();
            $this->db->queryBuilder()->table('users')->where('userid', $this->uid)->delete();
        }
        $_SESSION = [];
    }

    /**
     * Register an auth addon that accepts the seeded account, and a user addon whose onLogin()
     * writes the session the way `Addon\User\User` does.
     */
    private function registerAddons(int $uid): void
    {
        $username = 'addon-user';
        $auth = new class ($uid, $username) {
            public function __construct(private int $uid, private string $username)
            {
            }

            /** Accept whoever asks, as the seeded account. */
            public function onAuth(string $u, string $p, bool $r, bool $e, bool $v): array
            {
                return ['status' => true, 'uid' => $this->uid, 'username' => $this->username,
                        'email' => 'addon@example.com', 'auth' => 'x', 'remember' => false];
            }
        };
        $user = new class {
            /** What Addon\User\User::onLogin() writes to the session, and nothing else. */
            public function onLogin(array $info): void
            {
                $_SESSION['logged'] = true;
                $_SESSION['uid']    = $info['uid'];
            }
        };
        (new \ReflectionProperty(Addon::class, '_addons'))->setValue(null, [
            'auth' => ['primary' => $auth],
            'user' => ['listener' => $user],
        ]);
    }

    /**
     * Signing in through a user addon leaves a web-session token in the session and its row.
     */
    public function testAnAddonLoginGetsAWebSessionToken(): void
    {
        // Arrange
        $this->registerAddons($this->uid);

        // Act
        $ok = (new Auth())->auth('anyone', 'anything', false, true, false);

        // Assert — the session holds the token the CSRF tag and the request log read
        $this->assertTrue($ok);
        $this->assertInstanceOf(Token::class, $_SESSION['usertoken'] ?? null);
        $this->assertSame(Token::TYPE_WEB_SESSION, $_SESSION['usertoken']->tokentype);
        // …and it is a real row, which is what sign-out-everywhere acts on
        $this->assertSame(1, $this->db->queryBuilder()->table('usertokens')
            ->where('userid', $this->uid)->where('tokentype', Token::TYPE_WEB_SESSION)->count());
    }

    /**
     * A refused sign-in, or one for the anonymous account, creates no token.
     */
    public function testNoTokenWithoutARealSignIn(): void
    {
        // Arrange — the addon answers for uid 1, the anonymous account
        $this->registerAddons(1);

        // Act
        (new Auth())->auth('anyone', 'anything', false, true, false);

        // Assert
        $this->assertArrayNotHasKey('usertoken', $_SESSION);
    }
}
