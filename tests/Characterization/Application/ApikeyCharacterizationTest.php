<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Api\Apikey;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;

/**
 * Characterization tests for Api\Apikey model-like behavior.
 *
 * Focus: constructor loading/fill, save insert/update, getList, getData formatting.
 */
#[CoversClass(Apikey::class)]
class ApikeyCharacterizationTest extends TestCase
{
    private \Pramnos\Database\Database $db;
    private string $namePrefix;

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

        $this->namePrefix = 'char_apikey_' . bin2hex(random_bytes(3));
        $this->ensureApplicationsTableExists();
        $this->cleanupTestRows();
    }

    protected function tearDown(): void
    {
        // Arrange/Act cleanup
        $this->cleanupTestRows();
    }

    /**
     * Ensures the applications table exists with required columns.
     */
    private function ensureApplicationsTableExists(): void
    {
        // Act
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS `applications` ('
            . '`appid` INT AUTO_INCREMENT PRIMARY KEY,'
            . '`name` VARCHAR(191) NOT NULL,'
            . '`apikey` VARCHAR(191) NOT NULL,'
            . '`apisecret` VARCHAR(191) NOT NULL,'
            . '`status` INT NOT NULL DEFAULT 0,'
            . '`added` INT NOT NULL DEFAULT 0,'
            . '`description` TEXT NULL,'
            . '`organization` VARCHAR(191) NULL,'
            . '`organizationurl` VARCHAR(255) NULL,'
            . '`url` VARCHAR(255) NULL,'
            . '`apptype` INT NOT NULL DEFAULT 0,'
            . '`accesstype` INT NOT NULL DEFAULT 0,'
            . '`apiversion` VARCHAR(50) NULL,'
            . '`scope` TEXT NULL,'
            . '`public` INT NOT NULL DEFAULT 0,'
            . '`callback` VARCHAR(255) NULL,'
            . '`owner` INT NULL'
            . ')'
        );
    }

    /**
     * Removes only rows created by this test class.
     */
    private function cleanupTestRows(): void
    {
        // Act
        $sql = $this->db->prepareQuery(
            'DELETE FROM `applications` WHERE `name` LIKE %s',
            $this->namePrefix . '%'
        );
        $this->db->query($sql);
    }

    /**
     * Constructor with array fills properties and marks object as existing.
     */
    public function testConstructWithArrayFillsProperties(): void
    {
        // Arrange
        $input = [
            'appid' => 42,
            'name' => $this->namePrefix . '_a',
            'apikey' => 'k1',
            'status' => 1,
        ];

        // Act
        $app = new Apikey($input);

        // Assert
        $this->assertSame(42, (int) $app->appid);
        $this->assertSame($input['name'], $app->name);
        $this->assertSame('k1', $app->apikey);
        $this->assertSame(1, (int) $app->status);
    }

    /**
     * save() on a new object inserts a row and assigns appid + generated apikey.
     */
    public function testSaveInsertsNewRowAndAssignsAppidAndApiKey(): void
    {
        // Arrange
        $app = new Apikey();
        $app->name = $this->namePrefix . '_insert';
        $app->apisecret = 'sec';
        $app->status = 1;
        $app->description = 'desc';
        $app->organization = 'org';
        $app->organizationurl = 'https://org.test';
        $app->url = 'https://app.test';
        $app->apptype = 1;
        $app->accesstype = 0;
        $app->apiversion = 'v1';
        $app->scope = 'read';
        $app->public = 1;
        $app->callback = 'https://app.test/callback';
        $app->owner = 0; // current implementation normalizes 0 -> null

        // Act
        $app->save();

        // Assert
        $this->assertGreaterThan(0, (int) $app->appid);
        $this->assertNotSame('', $app->apikey);
        $this->assertSame(32, strlen($app->apikey));
    }

    /**
     * load(appid) loads persisted values from database.
     */
    public function testLoadByAppidHydratesObject(): void
    {
        // Arrange
        $app = new Apikey();
        $app->name = $this->namePrefix . '_loadid';
        $app->apisecret = 'sec2';
        $app->status = 1;
        $app->save();

        // Act
        $loaded = new Apikey((int) $app->appid);

        // Assert
        $this->assertSame((int) $app->appid, (int) $loaded->appid);
        $this->assertSame($app->name, $loaded->name);
        $this->assertSame($app->apikey, $loaded->apikey);
    }

    /**
     * load(apikey string) resolves and hydrates the same row.
     */
    public function testLoadByApiKeyHydratesObject(): void
    {
        // Arrange
        $app = new Apikey();
        $app->name = $this->namePrefix . '_loadkey';
        $app->apisecret = 'sec3';
        $app->status = 1;
        $app->save();

        // Act
        $loaded = new Apikey($app->apikey);

        // Assert
        $this->assertSame((int) $app->appid, (int) $loaded->appid);
        $this->assertSame($app->name, $loaded->name);
    }

    /**
     * save() on existing row updates stored values.
     */
    public function testSaveUpdatesExistingRow(): void
    {
        // Arrange
        $app = new Apikey();
        $app->name = $this->namePrefix . '_update';
        $app->apisecret = 'before';
        $app->status = 0;
        $app->save();

        $app->description = 'after-update';
        $app->status = 1;

        // Act
        $app->save();
        $loaded = new Apikey((int) $app->appid);

        // Assert
        $this->assertSame(1, (int) $loaded->status);
        $this->assertSame('after-update', $loaded->description);
    }

    /**
     * getData() returns formatted status label and ISO8601 added timestamp.
     */
    public function testGetDataFormatsStatusAndAddedFields(): void
    {
        // Arrange
        $app = new Apikey([
            'appid' => 7,
            'name' => $this->namePrefix . '_data',
            'apikey' => 'keyx',
            'apisecret' => 'secretx',
            'status' => 2,
            'added' => 1700000000,
            'owner' => 0,
        ]);

        // Act
        $data = $app->getData();

        // Assert
        $this->assertSame('Deleted', $data['status']);
        $this->assertSame(date('c', 1700000000), $data['added']);
        $this->assertSame($this->namePrefix . '_data', $data['name']);
    }
}
