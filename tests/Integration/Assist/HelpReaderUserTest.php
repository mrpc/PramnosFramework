<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Assist;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Assist\HelpReader;
use Pramnos\Framework\Factory;
use Pramnos\User\User;

/**
 * A reader built from an assistant's scope loads its user only when a gate asks — from the
 * database — and a user that does not exist is none, not an empty object a gate could mistake
 * for somebody.
 *
 * Requires the Docker MySQL container.
 */
#[CoversClass(HelpReader::class)]
class HelpReaderUserTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();
        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect(false);
        }
        if (!$db->connected) {
            $this->markTestSkipped('MySQL is not reachable.');
        }
        User::setupDb();
    }

    /** The scope's user, loaded once on first use; one that does not exist is null. */
    public function testTheUserIsLoadedWhenAsked(): void
    {
        // Arrange
        $name = 'helpreader_' . bin2hex(random_bytes(4));
        $user = new User();
        $user->username = $name;
        $user->email    = $name . '@example.com';
        $user->setPassword('pass');
        $user->save();

        try {
            $reader = new HelpReader((int) $user->userid, 7);

            // Act
            $loaded = $reader->user();

            // Assert
            $this->assertSame($name, $loaded->username);
            $this->assertSame($loaded, $reader->user(), 'loaded once');
            $this->assertNull((new HelpReader(2147483000))->user(), 'no such user');
        } finally {
            $user->deleteuser();
        }
    }
}
