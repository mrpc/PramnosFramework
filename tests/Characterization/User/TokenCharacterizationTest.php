<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\User\Token;
use Pramnos\User\User;

/**
 * Characterization tests for legacy Token behavior.
 *
 * These tests lock save/load/getData contracts and mysql-specific
 * updateAction early-return behavior before token subsystem refactors.
 */
#[CoversClass(Token::class)]
class TokenCharacterizationTest extends TestCase
{
    private \Pramnos\Database\Database $db;

    /** @var int[] */
    private array $createdTokenIds = [];

    protected function setUp(): void
    {
        // Arrange
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        $settingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
        Settings::loadSettings($settingsFile);
        Application::getInstance();

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        User::setupDb();
        $this->ensureTokenTableExists();

        // Token::load() caches results for 3600 s under the 'usertokens' group.
        // Flush that cache so that tokenid-based lookups always hit the real database
        // rather than returning a stale row from a previous test run.
        $this->db->cacheflush('usertokens');

        // Remove any orphaned rows left by an earlier run whose tearDown did not
        // complete (e.g. process killed mid-test). All rows created by this test
        // carry notes = 'characterization', so the DELETE is safe and targeted.
        $this->db->query("DELETE FROM `#PREFIX#usertokens` WHERE `notes` = 'characterization'");
    }

    protected function tearDown(): void
    {
        // Arrange
        foreach ($this->createdTokenIds as $tokenId) {
            $sql = $this->db->prepareQuery(
                'DELETE FROM `#PREFIX#usertokens` WHERE `tokenid` = %d',
                $tokenId
            );
            $this->db->query($sql);
        }

        // Flush cached query results so the next test run starts with a clean cache.
        $this->db->cacheflush('usertokens');
    }

    /**
     * Ensures token save/load works both by tokenid and by token string.
     */
    public function testTokenSaveAndLoadByIdAndTokenString(): void
    {
        // Arrange
        $tokenValue = 'char_token_' . bin2hex(random_bytes(6));
        $token = new Token();
        $token->userid = 1;
        $token->tokentype = 'auth';
        $token->token = $tokenValue;
        $token->created = time();
        $token->status = 1;
        $token->notes = 'characterization';
        $token->scope = 'read';
        $token->deviceinfo = ['device' => 'test'];

        // Act
        $token->save();
        $tokenId = (int) $token->tokenid;
        if ($tokenId > 0) {
            $this->createdTokenIds[] = $tokenId;
        }

        // Assert
        $this->assertGreaterThanOrEqual(0, $tokenId);

        // Act
        $loadedById = new Token($tokenId);
        $loadedByToken = new Token($tokenValue);

        // Assert
        $this->assertGreaterThan(0, (int) $loadedByToken->tokenid);
        $this->assertSame((int) $loadedByToken->tokenid, (int) $loadedById->tokenid);
        $this->assertSame($tokenValue, (string) $loadedById->token);
        $this->assertSame('auth', (string) $loadedByToken->tokentype);

        if (!in_array((int) $loadedByToken->tokenid, $this->createdTokenIds, true)) {
            $this->createdTokenIds[] = (int) $loadedByToken->tokenid;
        }
    }

    /**
     * Ensures getData preserves status-label/date mapping conventions.
     */
    public function testGetDataStatusAndDateMappingContract(): void
    {
        // Arrange
        $token = new Token([
            'tokenid' => 777,
            'userid' => 1,
            'token' => 'abc',
            'tokentype' => 'auth',
            'created' => 1714680000,
            'lastused' => 0,
            'removedate' => 0,
            'status' => 2,
            'deviceinfo' => '{"os":"ios"}',
            'notes' => 'n',
        ]);

        // Act
        $data = $token->getData();

        // Assert
        $this->assertSame('Deleted', $data['status']);
        $this->assertNull($data['lastused']);
        $this->assertNull($data['removedate']);
        // This proves created remains exported in ISO 8601 format.
        $this->assertSame(date('c', 1714680000), $data['created']);
    }

    /**
     * Ensures updateAction is a no-op on mysql path and does not throw.
     */
    public function testUpdateActionOnMysqlReturnsWithoutFailure(): void
    {
        // Arrange
        $token = new Token();
        $token->tokenid = 123;

        // Act
        $result = $token->updateAction(1, 200, 12.4, ['ok' => true]);

        // Assert
        $this->assertNull($result);
    }

    private function ensureTokenTableExists(): void
    {
        // Arrange
        $this->db->query('CREATE TABLE IF NOT EXISTS `#PREFIX#usertokens` (
            `tokenid` INT AUTO_INCREMENT PRIMARY KEY,
            `userid` INT NULL,
            `tokentype` VARCHAR(50) NOT NULL,
            `token` VARCHAR(255) NOT NULL,
            `created` INT NOT NULL DEFAULT 0,
            `notes` TEXT NULL,
            `lastused` INT NOT NULL DEFAULT 0,
            `status` INT NOT NULL DEFAULT 0,
            `parentToken` INT NULL,
            `applicationid` INT NULL,
            `actions` INT NOT NULL DEFAULT 0,
            `removedate` INT NOT NULL DEFAULT 0,
            `deviceinfo` TEXT NULL,
            `scope` TEXT NULL,
            `ipaddress` VARCHAR(64) NULL,
            `expires` INT NULL
        )');
    }
}
