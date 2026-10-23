<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Auth;

use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\User\User;

/**
 * A connection to MySQL or PostgreSQL made the framework's own, with the users' tables and
 * `remember_tokens`, and one person in them — for the sign-in tests.
 */
trait SignsInOnEachDatabase
{
    private ?Database $db = null;

    private ?Database $previous = null;

    private int $userId = 0;

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
        if ($this->db !== null && $this->userId > 1) {
            foreach (['authserver.remember_tokens', 'usertokens', 'users'] as $table) {
                try {
                    $this->db->queryBuilder()->table($table)->where('userid', $this->userId)->delete();
                } catch (\Throwable) {
                    // A table this run never made.
                }
            }
        }
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        $_SESSION = [];
        $_COOKIE  = [];
        User::forgetCurrentUser();
    }

    /** A connection, made the one the framework uses, with the users' tables; a person in them. */
    private function connect(string $type, string $host, int $port): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
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

        User::setupDb();
        $migrations = glob(ROOT . '/database/migrations/framework/auth/*remember_tokens*.php') ?: [];
        foreach ($migrations as $file) {
            require_once $file;
            preg_match('/^class (\w+)/m', (string) file_get_contents($file), $m);
            $class = 'Pramnos\\Framework\\Migrations\\Auth\\' . $m[1];
            $app   = $this->getMockBuilder(\Pramnos\Application\Application::class)->disableOriginalConstructor()->getMock();
            $app->database = $db;
            (new $class($app))->up();
        }

        $user           = new User();
        $user->username = 'remember_' . bin2hex(random_bytes(4));
        $user->email    = $user->username . '@example.com';
        $user->setPassword('first-secret');
        $user->save();
        $this->userId = (int) $user->userid;
        $_SESSION     = [];
        $_COOKIE      = [];
    }

    /** The remember-me cookies as the browser sends them back. */
    private function cookies(string $username, string $auth): void
    {
        $prefix = substr(md5('pcms'), 0, 10);
        $_COOKIE[$prefix] = [str_rot13('username') => $username, str_rot13('auth') => $auth];
    }

    /** The person's username. */
    private function username(): string
    {
        return (string) (new User($this->userId))->username;
    }
}
