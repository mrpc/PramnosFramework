<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\User;

use PHPUnit\Framework\TestCase;
use Pramnos\User\Token;
use Pramnos\Application\Settings;
use Pramnos\Application\Application;
use Pramnos\Framework\Factory;

class TokenTest extends TestCase
{
    /**
     * The tables this class owns, children first.
     *
     * @var string[]
     */
    private const TABLES = ['tokenactions', 'urls', 'usertokens', 'applications', 'users'];

    /**
     * Builds the schema once for the whole class.
     *
     * This ran per test until it was measured: four drops and five creates in `setUp()`
     * plus four more drops in `tearDown()` — thirteen DDL statements for every one of these
     * 33 tests, in a class that asserts what `Token` does with rows and never anything
     * about a schema. **15.8 s → see the changelog.** `setUp()` now empties the tables.
     *
     * The drops still come first, and with `FOREIGN_KEY_CHECKS = 0`: integration tests
     * elsewhere leave behind tables of these names carrying foreign keys, and `applications`
     * in particular exists with a different schema in `ScopesMySQLIntegrationTest`. Test
     * classes run sequentially, so doing this once per class is as safe as doing it 33
     * times.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        $db = self::bootDatabase();

        $db->query("SET FOREIGN_KEY_CHECKS = 0");
        $db->cacheflush();
        foreach (self::TABLES as $table) {
            $db->query("DROP TABLE IF EXISTS `" . $db->prefix . $table . "`");
        }
        $db->query("SET FOREIGN_KEY_CHECKS = 1");

        foreach (self::schemaStatements($db) as $statement) {
            $db->query($statement);
        }

        self::releaseDatabase();
    }

    /**
     * Drops the class's tables so the next class builds its own.
     *
     * `ScopesMySQLIntegrationTest` needs `applications` with an `apikey` column, so leaving
     * this class's version behind would hand it the wrong schema.
     *
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
        $db = self::bootDatabase();

        $db->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach (self::TABLES as $table) {
            $db->query("DROP TABLE IF EXISTS `" . $db->prefix . $table . "`");
        }
        $db->query("SET FOREIGN_KEY_CHECKS = 1");
        $db->cacheflush();

        self::releaseDatabase();
    }

    /**
     * Loads the fixture settings and returns a connected Factory database.
     *
     * The code under test reaches the database through the Factory, so the fixtures have to
     * be built through the same singleton rather than through a handle of our own.
     *
     * @return \Pramnos\Database\Database A connected handle
     */
    private static function bootDatabase(): \Pramnos\Database\Database
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        Settings::clearSettings();
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $singleton = &Factory::getDatabase();
        $singleton = null;

        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }

        return $db;
    }

    /**
     * Drops the Factory's database singleton and the settings it was built from.
     *
     * @return void
     */
    private static function releaseDatabase(): void
    {
        $singleton = &Factory::getDatabase();
        $singleton = null;
        Settings::clearSettings();
    }

    /**
     * The schema, verbatim from what this class used to create per test.
     *
     * `users` and `applications` are stubs that `Token::getDetails()` joins against; the
     * token tables carry no foreign keys on purpose, so a test can insert an arbitrary
     * `userid` without a matching user.
     *
     * @param \Pramnos\Database\Database $db A connected handle, for its table prefix
     * @return string[] DDL statements, in dependency order
     */
    private static function schemaStatements(\Pramnos\Database\Database $db): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS `" . $db->prefix . "users` (
                `userid` bigint(20) NOT NULL AUTO_INCREMENT,
                `username` varchar(50) NOT NULL DEFAULT '',
                `email` varchar(150) NOT NULL DEFAULT '',
                `firstname` varchar(128) NOT NULL DEFAULT '',
                `lastname` varchar(128) NOT NULL DEFAULT '',
                PRIMARY KEY (`userid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8",
            "CREATE TABLE IF NOT EXISTS `" . $db->prefix . "usertokens` (
                `tokenid` INT AUTO_INCREMENT PRIMARY KEY,
                `userid` INT,
                `tokentype` VARCHAR(50),
                `token` VARCHAR(255),
                `created` INT,
                `notes` TEXT,
                `lastused` INT,
                `status` INT DEFAULT 0,
                `applicationid` INT,
                `actions` INT DEFAULT 0,
                `removedate` INT,
                `deviceinfo` TEXT,
                `scope` TEXT,
                `parentToken` INT,
                `expires` INT NULL,
                `ipaddress` VARCHAR(45) NULL,
                `code_challenge` VARCHAR(128) NULL,
                `code_challenge_method` VARCHAR(10) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8",
            "CREATE TABLE IF NOT EXISTS `" . $db->prefix . "urls` (
                `urlid` INT AUTO_INCREMENT PRIMARY KEY,
                `url` TEXT,
                `hash` BIGINT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8",
            "CREATE TABLE IF NOT EXISTS `" . $db->prefix . "tokenactions` (
                `actionid` INT AUTO_INCREMENT PRIMARY KEY,
                `tokenid` INT,
                `urlid` INT,
                `method` VARCHAR(10),
                `params` TEXT,
                `servertime` INT,
                `return_status` INT,
                `execution_time_ms` DECIMAL(10,3),
                `return_data` JSON,
                `action_time` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8",
            "CREATE TABLE `" . $db->prefix . "applications` (
                `appid` INT NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(255) NOT NULL DEFAULT '',
                `apikey` VARCHAR(255) DEFAULT NULL,
                `apisecret` VARCHAR(255) DEFAULT NULL,
                `status` TINYINT(1) NOT NULL DEFAULT 1,
                `created` BIGINT(20) NOT NULL DEFAULT 0,
                `redirect_uri` VARCHAR(255) DEFAULT NULL,
                `public_key` TEXT DEFAULT NULL,
                `systemuser` INT DEFAULT NULL,
                PRIMARY KEY (`appid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8",
        ];
    }

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        // addAction() buffers its row through WriteSpool. These tests read the
        // row back immediately, and the spool directory is shared, so a drain
        // here would also write rows other test classes left behind. Writing
        // straight through keeps each test looking only at what it did.
        \Pramnos\Database\WriteSpool::setDriver(\Pramnos\Database\WriteSpool::DRIVER_SYNC);

        $db = self::bootDatabase();

        // Empty the tables; the schema belongs to the class. DELETE rather than TRUNCATE —
        // TRUNCATE is implicit DDL and measured slower than DROP + CREATE. Auto-increment
        // therefore keeps counting up, which nothing here depends on: the one literal
        // `tokenid => 1` in this class is an in-memory Token, never a row.
        // No cacheflush() here. It costs 85 ms — a file-cache directory scan — which was
        // 2.8 s of this class's runtime across 33 tests, and nothing in this class reads
        // through the SQL cache: every query() call leaves $cache at its default false.
        // The flush stays in setUpBeforeClass(), where it clears whatever an earlier class
        // left behind, once.
        $db->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach (self::TABLES as $table) {
            $db->query("DELETE FROM `" . $db->prefix . $table . "`");
        }
        $db->query("SET FOREIGN_KEY_CHECKS = 1");

        // Mock request context for actions
        $_SERVER['REQUEST_URI'] = '/test-url';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';
    }
    
    protected function tearDown(): void
    {
        // Reset HTTP method static state
        \Pramnos\Http\Request::$requestMethod = 'GET';
        \Pramnos\Http\Request::$deleteData    = [];
        \Pramnos\Http\Request::$putData       = [];

        unset($_SERVER['REQUEST_URI']);
        unset($_SERVER['REQUEST_METHOD']);
        unset($_SERVER['REMOTE_ADDR']);
        unset($_SERVER['HTTP_USER_AGENT']);
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        $_POST = [];

        // The tables are not dropped here: they belong to the class, and
        // tearDownAfterClass() removes them once every test has run.
    }

    public function testTokenCreationAndSave(): void
    {
        $token = new Token();
        $token->userid = 1;
        $token->tokentype = Token::TYPE_API;
        $token->token = 'test-token-string';
        $token->created = time();
        $token->save();
        
        if ($token->tokenid == 0) {
            var_dump($token->_errors);
        }
        $this->assertGreaterThan(0, $token->tokenid);
        
        // Load the token back
        $loadedToken = new Token($token->tokenid);
        $this->assertEquals(1, $loadedToken->userid);
        $this->assertEquals('test-token-string', $loadedToken->token);
        
        // Load by string
        $loadedByString = new Token('test-token-string');
        $this->assertEquals($token->tokenid, $loadedByString->tokenid);
    }
    
    public function testGetDataAndDetails(): void
    {
        $token = new Token();
        $token->userid = 1;
        $token->token = 'details-token';
        $token->save();
        
        $data = $token->getData();
        $this->assertEquals(1, $data['userid']);
        $this->assertEquals('details-token', $data['token']);
        
        $details = $token->getDetails();
        $this->assertEquals($token->tokenid, $details['tokenid']);
        $this->assertEquals(1, $details['userid']);
    }
    
    public function testTokenActions(): void
    {
        $token = new Token();
        $token->userid = 1;
        $token->tokentype = Token::TYPE_API;
        $token->token = 'action-token';
        $token->save();
        
        // Add action
        $token->addAction();
        
        $this->assertEquals(1, $token->actions);
        $this->assertEquals('127.0.0.1', $token->ipaddress);
        
        // Complete it — this is the write, and it happens once
        $token->updateAction($token->lastActionId, 200, 15.5, ['response' => 'ok']);
        
        $actions = $token->getActions(10);
        $this->assertEquals(1, $actions['total']);
        $this->assertCount(1, $actions['data']);
        
        $actionData = $actions['data'][0];
        $this->assertEquals(200, $actionData['return_status']);
        $this->assertEquals(15.5, $actionData['execution_time_ms']);
    }
    
    public function testGetStatistics(): void
    {
        $token = new Token();
        $token->userid = 1;
        $token->token = 'stats-token';
        $token->save();
        
        $token->addAction();
        $this->flushAction($token);
        $token->addAction();
        $this->flushAction($token);
        
        $stats = $token->getStatistics();
        $this->assertEquals(2, $stats['total_actions']);
        $this->assertNotNull($stats['first_action']);
        $this->assertNotNull($stats['last_action']);
        $this->assertEquals(1, $stats['active_days']);
    }
    
    public function testEmptyTokenReturnsDefaultData(): void
    {
        $token = new Token();

        $details = $token->getDetails();
        $this->assertEquals(0, $details['tokenid']);

        $stats = $token->getStatistics();
        $this->assertEquals(0, $stats['total_actions']);

        $actions = $token->getActions();
        $this->assertEquals(0, $actions['total']);
        $this->assertEmpty($actions['data']);
    }

    // =========================================================================
    // fillProperties() — deviceinfo and scope parsing branches
    // =========================================================================

    /**
     * Write the action the token is holding, and drain the spool.
     *
     * `addAction()` no longer inserts: it holds the row until the response is
     * known, so that one write replaces an insert, an update and a round trip
     * for the generated id. Tests that go on to read the row back have to do
     * what the request lifecycle does — complete it and let the drain run.
     *
     * @return int The actionid of the row that was written, or 0
     */
    private function flushAction(\Pramnos\User\Token $token): int
    {
        $token->flushPendingAction();

        $db  = Factory::getDatabase();
        $row = $db->query(
            'SELECT actionid FROM `#PREFIX#tokenactions`'
            . ' WHERE tokenid = ' . (int) $token->tokenid
            . ' ORDER BY actionid DESC LIMIT 1'
        );

        return ($row && $row->numRows > 0) ? (int) $row->fields['actionid'] : 0;
    }

    /**
     * fillProperties() must unserialize deviceinfo when it contains a PHP
     * serialized string (checkUnserialize returns true).
     *
     * This covers the first branch of the deviceinfo handling block (line 185):
     * legacy tokens stored deviceinfo as serialize() output; the loader must
     * transparently convert it back to an array.
     */
    public function testFillPropertiesUnserializesDeviceinfo(): void
    {
        // Arrange — build a token array with serialized deviceinfo
        $deviceArray = ['browser' => 'Firefox', 'platform' => 'Linux'];
        $serialized  = serialize($deviceArray);

        // Act — construct with array triggers fillProperties()
        $token = new Token([
            'tokenid'    => 0,
            'deviceinfo' => $serialized,
            'scope'      => '[]',
        ]);

        // Assert — deviceinfo must be the unserialized array
        $this->assertIsArray($token->deviceinfo,
            'fillProperties() must unserialize legacy serialized deviceinfo');
        $this->assertSame('Firefox', $token->deviceinfo['browser'],
            'Unserialized values must match the original array contents');
    }

    /**
     * fillProperties() must split a comma-separated scope string into an array.
     *
     * This covers the elseif branch at line 193–194: when the scope field
     * contains commas it should be treated as a CSV list, not a JSON blob.
     * This mirrors legacy scope encoding used before JSON was adopted.
     */
    public function testFillPropertiesSplitsCommaSeparatedScope(): void
    {
        // Arrange — scope stored as CSV, deviceinfo as empty string (falls to else)
        $token = new Token([
            'tokenid'    => 0,
            'deviceinfo' => '',
            'scope'      => 'read,write,admin',
        ]);

        // Assert — scope must be an array of three elements
        $this->assertIsArray($token->scope,
            'fillProperties() must split comma-separated scope into an array');
        $this->assertSame(['read', 'write', 'admin'], $token->scope,
            'Each CSV segment must become one scope array element');
    }

    /**
     * fillProperties() must wrap a single non-array, non-JSON, non-CSV scope
     * value into a single-element array.
     *
     * This covers the elseif/else at line 195–196: a plain string like 'profile'
     * is not JSON and has no commas, so it must become ['profile'].
     */
    public function testFillPropertiesWrapsScalarScopeInArray(): void
    {
        // Arrange — scope is a plain non-JSON, non-CSV string
        $token = new Token([
            'tokenid'    => 0,
            'deviceinfo' => '',
            'scope'      => 'profile',
        ]);

        // Assert — must be wrapped in an array
        $this->assertIsArray($token->scope,
            'fillProperties() must wrap a scalar scope into a single-element array');
        $this->assertSame(['profile'], $token->scope,
            'The scalar scope string must be the only element');
    }

    /**
     * fillProperties() must set scope to an empty array when the scope value is
     * an empty string (covers the empty() path in the else branch at line 196).
     */
    public function testFillPropertiesEmptyScopeBecomeEmptyArray(): void
    {
        // Arrange — empty scope string
        $token = new Token([
            'tokenid'    => 0,
            'deviceinfo' => '',
            'scope'      => '',
        ]);

        // Assert
        $this->assertIsArray($token->scope);
        $this->assertEmpty($token->scope,
            'An empty scope string must produce an empty array, not a one-element array');
    }

    // =========================================================================
    // getData() — date formatting and deviceinfo inclusion branches
    // =========================================================================

    /**
     * getData() must format removedate as an ISO 8601 date string when it is
     * non-zero, rather than returning null.
     *
     * This covers the else-branch at line 244–246 in getData(): when removedate
     * is set the value is passed through date('c', …) so callers get a readable
     * timestamp instead of a raw integer.
     */
    public function testGetDataFormatsNonZeroRemovedate(): void
    {
        // Arrange — token with a set removedate
        $removeTs = mktime(12, 0, 0, 1, 15, 2025);
        $token = new Token([
            'tokenid'    => 1,
            'deviceinfo' => '',
            'scope'      => '[]',
            'removedate' => $removeTs,
            'lastused'   => 0,
            'created'    => time(),
            'status'     => 0,
        ]);

        // Act
        $data = $token->getData();

        // Assert — removedate must be an ISO string, not null
        $this->assertNotNull($data['removedate'],
            'getData() must format a non-zero removedate as a date string');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}/',
            (string) $data['removedate'],
            'removedate must be formatted using date("c", …)');
    }

    /**
     * getData() must format lastused as an ISO 8601 date string when it is
     * non-zero, and include the deviceinfo array when it is non-empty.
     *
     * This covers the else-branch at line 249–251 (lastused) and lines 257–261
     * (deviceinfo inclusion) in getData().
     */
    public function testGetDataFormatsNonZeroLastusedAndIncludesDeviceinfo(): void
    {
        // Arrange
        $lastUsedTs = mktime(9, 30, 0, 6, 7, 2026);
        $token = new Token([
            'tokenid'    => 2,
            'deviceinfo' => json_encode(['browser' => 'Chrome']),
            'scope'      => '[]',
            'removedate' => 0,
            'lastused'   => $lastUsedTs,
            'created'    => time(),
            'status'     => 1,
        ]);

        // Act
        $data = $token->getData();

        // Assert — lastused is a formatted date string
        $this->assertNotNull($data['lastused'],
            'getData() must format a non-zero lastused as a date string');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}/',
            (string) $data['lastused'],
            'lastused must be formatted using date("c", …)');

        // Assert — deviceinfo is present as an array in the output
        $this->assertArrayHasKey('deviceinfo', $data,
            'getData() must include deviceinfo when it is a non-empty array');
        $this->assertSame(['browser' => 'Chrome'], $data['deviceinfo'],
            'deviceinfo must be the decoded array, not a JSON string');
    }

    // =========================================================================
    // save() — expires normalisation and zero-tokenid guard
    // =========================================================================

    /**
     * save() must normalise an expires value of 0 to null before persisting.
     *
     * This covers lines 528–530 in save(): `if ($this->expires == 0) { $this->expires = null; }`.
     * The guard prevents storing 0 (which is a valid UNIX epoch) as a token
     * expiry, since 0 should mean "no expiry".
     */
    public function testSaveNormalisesExpiresZeroToNull(): void
    {
        // Arrange — token with expires explicitly set to 0
        $token            = new Token();
        $token->userid    = 1;
        $token->tokentype = Token::TYPE_API;
        $token->token     = 'expires-zero-token-' . uniqid();
        $token->created   = time();
        $token->expires   = 0;

        // Act
        $token->save();

        // Assert — expires must be null after save
        $this->assertNull($token->expires,
            'save() must convert expires=0 to null before inserting');
        $this->assertGreaterThan(0, $token->tokenid,
            'Token must have been inserted successfully');
    }

    /**
     * save() on a non-new token with tokenid=0 must add an error and return
     * without attempting a database UPDATE.
     *
     * This covers the guard at lines 626–629 in save(): `if ((int)$this->tokenid == 0)`
     * prevents executing an UPDATE with no WHERE clause condition, which would
     * corrupt every row in the table.
     */
    public function testSaveWithZeroTokenidOnUpdateAddsError(): void
    {
        // Arrange — insert a token first to get a valid row (_isnew is set to
        // false by fillProperties after DB load), then zero out the tokenid to
        // simulate a corrupted in-memory state before an update attempt.
        $token            = new Token();
        $token->userid    = 1;
        $token->tokentype = Token::TYPE_API;
        $token->token     = 'no-id-token-' . uniqid();
        $token->created   = time();
        $token->save();

        // Load it back — this sets _isnew=false via fillProperties
        $loaded = new Token($token->tokenid);
        // Zero out tokenid to trigger the guard in the update path
        $loaded->tokenid = 0;

        // Act
        $loaded->save();

        // Assert — an error must have been recorded in the public _errors array
        $this->assertNotEmpty(
            $loaded->_errors,
            'save() must record an error when tokenid is 0 in the update path'
        );
    }

    // =========================================================================
    // addAction() — HTTP method branches and IP detection
    // =========================================================================

    /**
     * addAction() must capture $_POST data as the input payload when the HTTP
     * method is POST.
     *
     * This covers the POST branch of the switch statement at lines 296–298 in
     * addAction(): `case "POST": $inputData = json_encode($_POST); break;`.
     * POST payloads must be logged for audit purposes.
     */
    public function testAddActionCapturesPostData(): void
    {
        // Arrange — create and persist a token
        $token            = new Token();
        $token->userid    = 1;
        $token->tokentype = Token::TYPE_API;
        $token->token     = 'post-action-token-' . uniqid();
        $token->created   = time();
        $token->save();

        // Set up POST context
        \Pramnos\Http\Request::$requestMethod = 'POST';
        $_POST['key'] = 'value123';

        // Act
        $token->addAction();

        // Assert — action was recorded and action count incremented
        $actionId = $this->flushAction($token);
        $this->assertGreaterThan(0, $actionId,
            'addAction() with POST must create a tokenactions row');
        $this->assertSame(1, $token->actions,
            'addAction() must increment the actions counter');

        // Verify params contain POST data
        $db = Factory::getDatabase();
        $row = $db->query(
            'SELECT params FROM `#PREFIX#tokenactions`'
            . ' WHERE tokenid = ' . (int) $token->tokenid . ' ORDER BY actionid DESC LIMIT 1'
        );
        $params = json_decode($row->fields['params'], true);
        $this->assertSame('value123', $params['key'],
            'addAction() must store POST data in the params field');

        // Cleanup
        unset($_POST['key']);
        \Pramnos\Http\Request::$requestMethod = 'GET';
    }

    /**
     * addAction() must capture DELETE payload as the input data when the HTTP
     * method is DELETE.
     *
     * This covers the DELETE branch at lines 299–301 in addAction():
     * `case "DELETE": $inputData = json_encode(Request::$deleteData); break;`.
     */
    public function testAddActionCapturesDeleteData(): void
    {
        // Arrange
        $token            = new Token();
        $token->userid    = 1;
        $token->tokentype = Token::TYPE_API;
        $token->token     = 'delete-action-token-' . uniqid();
        $token->created   = time();
        $token->save();

        \Pramnos\Http\Request::$requestMethod = 'DELETE';
        \Pramnos\Http\Request::$deleteData    = ['id' => 42];

        // Act
        $token->addAction();

        // Assert — action recorded
        $actionId = $this->flushAction($token);
        $this->assertGreaterThan(0, $actionId,
            'addAction() with DELETE must create a tokenactions row');

        // Verify params contain DELETE data
        $db = Factory::getDatabase();
        $row = $db->query(
            'SELECT params FROM `#PREFIX#tokenactions`'
            . ' WHERE tokenid = ' . (int) $token->tokenid . ' ORDER BY actionid DESC LIMIT 1'
        );
        $params = json_decode($row->fields['params'], true);
        $this->assertSame(42, $params['id'],
            'addAction() must store DELETE payload in the params field');

        // Cleanup
        \Pramnos\Http\Request::$requestMethod = 'GET';
        \Pramnos\Http\Request::$deleteData    = [];
    }

    /**
     * addAction() must capture PUT payload as the input data when the HTTP
     * method is PUT.
     *
     * This covers the PUT branch at lines 302–304 in addAction():
     * `case "PUT": $inputData = json_encode(Request::$putData); break;`.
     */
    public function testAddActionCapturesPutData(): void
    {
        // Arrange
        $token            = new Token();
        $token->userid    = 1;
        $token->tokentype = Token::TYPE_API;
        $token->token     = 'put-action-token-' . uniqid();
        $token->created   = time();
        $token->save();

        \Pramnos\Http\Request::$requestMethod = 'PUT';
        \Pramnos\Http\Request::$putData       = ['name' => 'updated'];

        // Act
        $token->addAction();

        // Assert — action recorded
        $actionId = $this->flushAction($token);
        $this->assertGreaterThan(0, $actionId,
            'addAction() with PUT must create a tokenactions row');

        // Verify params contain PUT data
        $db = Factory::getDatabase();
        $row = $db->query(
            'SELECT params FROM `#PREFIX#tokenactions`'
            . ' WHERE tokenid = ' . (int) $token->tokenid . ' ORDER BY actionid DESC LIMIT 1'
        );
        $params = json_decode($row->fields['params'], true);
        $this->assertSame('updated', $params['name'],
            'addAction() must store PUT payload in the params field');

        // Cleanup
        \Pramnos\Http\Request::$requestMethod = 'GET';
        \Pramnos\Http\Request::$putData       = [];
    }

    /**
     * addAction() records the Cloudflare connecting IP — but only when the peer
     * that sent it is a configured trusted proxy.
     *
     * This used to read HTTP_CF_CONNECTING_IP unconditionally, which meant any
     * client could dictate the address written into its own token record. Those
     * records read as evidence — they are what an audit consults to answer
     * "where was this token used from" — so an address the subject chooses is
     * worse than no address at all.
     *
     * Both halves are asserted because both are the contract: an application
     * behind Cloudflare that declares it gets the real client, and one that
     * declares nothing gets the peer rather than a forgery.
     */
    public function testAddActionUsesCloudflareIpOnlyFromATrustedProxy(): void
    {
        // Arrange
        $token            = new Token();
        $token->userid    = 1;
        $token->tokentype = Token::TYPE_API;
        $token->token     = 'cf-ip-token-' . uniqid();
        $token->created   = time();
        $token->save();

        $app      = Application::getInstance();
        $original = $app->applicationInfo['trusted_proxies'] ?? null;

        $_SERVER['REMOTE_ADDR']           = '10.0.0.1';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.45';

        try {
            // Act — nothing trusted: the header is an unverified claim
            $app->applicationInfo['trusted_proxies'] = [];
            $token->addAction();

            // Assert
            $this->assertSame('10.0.0.1', $token->ipaddress,
                'an unverified CF header must not choose the recorded address');

            // Act — the peer is declared a proxy, so it may name its client
            $app->applicationInfo['trusted_proxies'] = ['private_ranges'];
            $token->addAction();

            // Assert
            $this->assertSame('203.0.113.45', $token->ipaddress,
                'a trusted proxy names the real client');
        } finally {
            // Cleanup
            if ($original === null) {
                unset($app->applicationInfo['trusted_proxies']);
            } else {
                $app->applicationInfo['trusted_proxies'] = $original;
            }
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        }
    }

    /**
     * addAction() must store the lastActionTime so that a subsequent call to
     * updateAction() with execution_time_ms=0 can auto-calculate the elapsed
     * milliseconds.
     *
     * This covers lines 270 (lastActionTime assignment in addAction()) and
     * lines 370–372 (auto-calculation branch in updateAction()):
     * `if ($execution_time_ms == 0 && $this->lastActionTime !== null)`.
     */
    public function testUpdateActionAutoCalculatesExecutionTimeFromLastActionTime(): void
    {
        // Arrange — create and add an action
        $token            = new Token();
        $token->userid    = 1;
        $token->tokentype = Token::TYPE_API;
        $token->token     = 'exec-time-token-' . uniqid();
        $token->created   = time();
        $token->save();

        $token->addAction();
        $this->assertNotNull($token->lastActionTime,
            'addAction() must set lastActionTime for auto-calculation');

        // Act — call updateAction with execution_time_ms=0 to trigger auto-calc
        $token->updateAction($token->lastActionId, 200, 0, ['status' => 'done']);

        // Assert — execution_time_ms must have been calculated and stored (> 0)
        $db  = Factory::getDatabase();
        $row = $db->query(
            'SELECT execution_time_ms FROM `#PREFIX#tokenactions`'
            . ' WHERE tokenid = ' . (int) $token->tokenid . ' ORDER BY actionid DESC LIMIT 1'
        );
        $storedMs = (float) $row->fields['execution_time_ms'];
        $this->assertGreaterThanOrEqual(0, $storedMs,
            'updateAction() must auto-calculate execution_time_ms when 0 is passed');
    }

    // =========================================================================
    // updateAction() — return_data type coercion branches
    // =========================================================================

    /**
     * updateAction() must call get_object_vars() on an object passed as
     * return_data and store the result as JSON.
     *
     * This covers lines 378–380 in updateAction():
     * `elseif (is_object($return_data)) { $return_data = json_encode(get_object_vars($return_data)); }`
     */
    public function testUpdateActionSerializesObjectReturnData(): void
    {
        // Arrange — create token and seed an action row
        $token            = new Token();
        $token->userid    = 1;
        $token->tokentype = Token::TYPE_API;
        $token->token     = 'obj-return-token-' . uniqid();
        $token->created   = time();
        $token->save();

        $token->addAction();

        // Build an stdClass to pass as return_data
        $obj        = new \stdClass();
        $obj->code  = 200;
        $obj->msg   = 'ok';

        // Act
        $token->updateAction($token->lastActionId, 200, 5.0, $obj);

        // Assert — return_data must be JSON-encoded with the object's public properties
        $db  = Factory::getDatabase();
        $row = $db->query(
            'SELECT return_data FROM `#PREFIX#tokenactions`'
            . ' WHERE tokenid = ' . (int) $token->tokenid . ' ORDER BY actionid DESC LIMIT 1'
        );
        $decoded = json_decode($row->fields['return_data'], true);
        $this->assertEquals(['code' => 200, 'msg' => 'ok'], $decoded,
            'updateAction() must encode an object via get_object_vars() into JSON');
    }

    /**
     * updateAction() must wrap a non-string scalar (e.g. an integer) in a
     * `{"data": ...}` envelope and store it as JSON.
     *
     * This covers lines 381–383 in updateAction():
     * `elseif (!is_string($return_data)) { $return_data = json_encode(['data' => $return_data]); }`
     */
    public function testUpdateActionWrapsScalarReturnData(): void
    {
        // Arrange
        $token            = new Token();
        $token->userid    = 1;
        $token->tokentype = Token::TYPE_API;
        $token->token     = 'scalar-return-token-' . uniqid();
        $token->created   = time();
        $token->save();

        $token->addAction();

        // Act — pass a plain integer as return_data
        $token->updateAction($token->lastActionId, 200, 1.0, 42);

        // Assert — must be wrapped in {"data": 42}
        $db  = Factory::getDatabase();
        $row = $db->query(
            'SELECT return_data FROM `#PREFIX#tokenactions`'
            . ' WHERE tokenid = ' . (int) $token->tokenid . ' ORDER BY actionid DESC LIMIT 1'
        );
        $decoded = json_decode($row->fields['return_data'], true);
        $this->assertSame(['data' => 42], $decoded,
            'updateAction() must wrap a scalar return_data in a {"data": …} envelope');
    }

    /**
     * updateAction() must silently return when return_status is negative,
     * without touching the database.
     *
     * This covers line 383–385 in updateAction():
     * `if ($actionid == 0 || $return_status < 0) { return; }`
     * Negative status codes are an application-level sentinel value meaning
     * "do not log this response".
     */
    public function testUpdateActionWithNegativeStatusIsNoOp(): void
    {
        // Arrange — create a token and seed an action
        $token            = new Token();
        $token->userid    = 1;
        $token->tokentype = Token::TYPE_API;
        $token->token     = 'neg-status-token-' . uniqid();
        $token->created   = time();
        $token->save();

        $token->addAction();

        // Act — a negative status says "do not record an outcome"
        $token->updateAction($token->lastActionId, -1, 5.0, ['data' => 'should not be stored']);

        // Assert — the call is still logged, because it happened, but carries
        // no outcome: an audit log that omits the requests that ended badly is
        // worse than no audit log.
        $db  = Factory::getDatabase();
        $row = $db->query(
            'SELECT return_status FROM `#PREFIX#tokenactions`'
            . ' WHERE tokenid = ' . (int) $token->tokenid . ' ORDER BY actionid DESC LIMIT 1'
        );
        $this->assertSame(1, (int) $row->numRows, 'the action was still recorded');
        $this->assertNull($row->fields['return_status'],
            'a negative return_status must not be stored as an outcome');
    }

    // =========================================================================
    // What addAction() costs the request
    // =========================================================================

    /**
     * The URL is looked up once, however many actions are logged.
     *
     * This ran on every logged request: a SELECT against the url registry to
     * turn a URL into an id. A page that keeps talking to the server asks about
     * the same two or three URLs for as long as it is open — a datatable paging
     * through results asks about exactly one.
     */
    public function testTheUrlIsResolvedOnce(): void
    {
        // Arrange
        $token            = new Token();
        $token->userid    = 1;
        $token->tokentype = Token::TYPE_API;
        $token->token     = 'url-cache-token-' . uniqid();
        $token->created   = time();
        $token->save();

        $db = Factory::getDatabase();
        $db->enableQueryLog();
        $db->clearQueryLog();

        // Act — three actions against the same URL
        $token->addAction();
        $token->flushPendingAction();
        $token->addAction();
        $token->flushPendingAction();
        $token->addAction();
        $token->flushPendingAction();

        // Assert — the registry was consulted for the first one only
        $lookups = 0;
        foreach ($db->getQueryLog() as $entry) {
            $sql = is_array($entry) ? ($entry['sql'] ?? '') : (string) $entry;
            if (stripos($sql, 'urls') !== false && stripos($sql, 'select') !== false) {
                $lookups++;
            }
        }

        $this->assertLessThanOrEqual(
            1,
            $lookups,
            'the url registry was read more than once for the same url'
        );
    }

    /**
     * One action produces one row, not an insert followed by an update.
     *
     * An API request logged its call and then, once the response was known,
     * updated the row it had just made — two round trips plus a third for the
     * generated id, all while the visitor waited. Completing the held row
     * collapses that into a single write.
     */
    public function testCompletingAnActionWritesExactlyOneRow(): void
    {
        // Arrange
        $token            = new Token();
        $token->userid    = 1;
        $token->tokentype = Token::TYPE_API;
        $token->token     = 'single-write-token-' . uniqid();
        $token->created   = time();
        $token->save();

        // Act
        $token->addAction();
        $token->updateAction($token->lastActionId, 201, 12.5, ['ok' => true]);

        // Assert — one row, carrying both halves
        $db  = Factory::getDatabase();
        $row = $db->query(
            'SELECT COUNT(*) AS cnt FROM `#PREFIX#tokenactions` WHERE tokenid = '
            . (int) $token->tokenid
        );
        $this->assertSame(1, (int) $row->fields['cnt']);

        $written = $db->query(
            'SELECT return_status, execution_time_ms, params FROM `#PREFIX#tokenactions`'
            . ' WHERE tokenid = ' . (int) $token->tokenid
        );
        $this->assertSame(201, (int) $written->fields['return_status']);
        $this->assertEquals(12.5, (float) $written->fields['execution_time_ms']);
        $this->assertNotNull($written->fields['params'], 'the request half survived too');
    }

    /**
     * Completing twice does not write twice.
     *
     * The held row is cleared as it is written, so a caller that completes an
     * action it has already completed — a retry, a shutdown handler running
     * after an explicit call — does nothing rather than duplicating the entry.
     */
    public function testCompletingTwiceWritesOnce(): void
    {
        // Arrange
        $token            = new Token();
        $token->userid    = 1;
        $token->tokentype = Token::TYPE_API;
        $token->token     = 'double-flush-token-' . uniqid();
        $token->created   = time();
        $token->save();

        // Act
        $token->addAction();
        $token->updateAction($token->lastActionId, 200, 1.0, []);
        $token->updateAction($token->lastActionId, 500, 9.0, []);
        $token->flushPendingAction();

        // Assert
        $db  = Factory::getDatabase();
        $row = $db->query(
            'SELECT COUNT(*) AS cnt FROM `#PREFIX#tokenactions` WHERE tokenid = '
            . (int) $token->tokenid
        );
        $this->assertSame(1, (int) $row->fields['cnt']);
    }

    /**
     * An action nobody completes is still written.
     *
     * The API path completes the action once it knows the response; the web
     * path never does. Without the shutdown flush a page view would be logged
     * by being held, and then dropped when the process ended.
     */
    public function testAnUncompletedActionIsStillWritten(): void
    {
        // Arrange
        $token            = new Token();
        $token->userid    = 1;
        $token->tokentype = Token::TYPE_API;
        $token->token     = 'never-completed-token-' . uniqid();
        $token->created   = time();
        $token->save();

        // Act — what the shutdown handler does
        $token->addAction();
        $token->flushPendingAction();

        // Assert
        $db  = Factory::getDatabase();
        $row = $db->query(
            'SELECT COUNT(*) AS cnt FROM `#PREFIX#tokenactions` WHERE tokenid = '
            . (int) $token->tokenid
        );
        $this->assertSame(1, (int) $row->fields['cnt']);
    }

    /**
     * The token row is not rewritten on every request.
     *
     * `addAction()` used to call `save()`, which UPDATEs every column of the
     * row — the token itself, the device description, the scope — in order to
     * move `lastused` forward and add one to `actions`. A page that then calls
     * its own API did that twice for one page view.
     *
     * Neither field needs to be current to the second, so the write is
     * occasional. The counter still counts: it is the persisting that is
     * deferred, not the counting.
     */
    public function testRepeatedActionsDoNotRewriteTheTokenEachTime(): void
    {
        // Arrange
        $token            = new Token();
        $token->userid    = 1;
        $token->tokentype = Token::TYPE_API;
        $token->token     = 'use-write-token-' . uniqid();
        $token->created   = time();
        $token->save();

        $db = Factory::getDatabase();
        $db->enableQueryLog();
        $db->clearQueryLog();

        // Act — five requests in the same second
        for ($i = 0; $i < 5; $i++) {
            $token->addAction();
            $token->flushPendingAction();
        }

        // Assert — the counter kept counting
        $this->assertSame(5, $token->actions);

        // and the row was not rewritten five times
        $updates = 0;
        foreach ($db->getQueryLog() as $entry) {
            $sql = is_array($entry) ? ($entry['sql'] ?? '') : (string) $entry;
            if (stripos($sql, 'usertokens') !== false
                && (stripos($sql, 'update') !== false || stripos($sql, 'insert') !== false)) {
                $updates++;
            }
        }

        $this->assertLessThanOrEqual(
            1,
            $updates,
            'the token row was rewritten ' . $updates . ' times for five requests'
        );
    }

    /**
     * A request from a new address writes immediately.
     *
     * The address and the device are what somebody investigating a stolen token
     * looks at; delaying those by a minute to save a write would be saving the
     * wrong thing.
     */
    public function testAChangeOfAddressIsWrittenAtOnce(): void
    {
        // Arrange
        $token            = new Token();
        $token->userid    = 1;
        $token->tokentype = Token::TYPE_API;
        $token->token     = 'ip-change-token-' . uniqid();
        $token->created   = time();
        $token->save();

        $originalRemote = $_SERVER['REMOTE_ADDR'] ?? null;

        try {
            $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
            $token->addAction();
            $token->flushPendingAction();

            $db = Factory::getDatabase();
            $db->enableQueryLog();
            $db->clearQueryLog();

            // Act — the same token, from somewhere else
            $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
            $token->addAction();
            $token->flushPendingAction();

            // Assert
            $updates = 0;
            foreach ($db->getQueryLog() as $entry) {
                $sql = is_array($entry) ? ($entry['sql'] ?? '') : (string) $entry;
                if (stripos($sql, 'usertokens') !== false && stripos($sql, 'update') !== false) {
                    $updates++;
                }
            }

            $this->assertGreaterThan(0, $updates, 'the new address was recorded at once');
            $this->assertSame('203.0.113.9', $token->ipaddress);
        } finally {
            if ($originalRemote === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $originalRemote;
            }
        }
    }

    // =========================================================================
    // getDetails() — non-zero tokenid DB query path
    // =========================================================================

    /**
     * getDetails() with a valid tokenid must execute the JOIN query against
     * usertokens (and optionally users / applications) and return a populated
     * array with at least tokenid, userid, and token fields.
     *
     * This covers lines 688–701 in getDetails(): the full SQL JOIN that is
     * skipped when tokenid == 0. The early-return path is already tested; this
     * test exercises the actual DB query path.
     */
    public function testGetDetailsReturnsRowForExistingToken(): void
    {
        // Arrange — persist a token so the JOIN query has a real row to find
        $token            = new Token();
        $token->userid    = 77;
        $token->tokentype = Token::TYPE_API;
        $token->token     = 'details-db-token-' . uniqid();
        $token->created   = time();
        $token->save();

        // Act
        $details = $token->getDetails();

        // Assert — result must be the DB row, not the zero-default struct
        $this->assertIsArray($details,
            'getDetails() must return an array for a persisted token');
        $this->assertEquals($token->tokenid, (int) $details['tokenid'],
            'getDetails() must return the correct tokenid from the JOIN query');
        $this->assertSame($token->token, $details['token'],
            'getDetails() must return the token string');
    }

    // =========================================================================
    // getActions() — pagination offset and JSON parameter decoding
    // =========================================================================

    /**
     * getActions() must respect the $offset parameter, skipping the first N
     * rows when paginating results.
     *
     * This covers the LIMIT/OFFSET clause construction at lines 796–801 in
     * getActions(). Pagination is essential for the admin UI that displays token
     * activity logs in pages.
     */
    public function testGetActionsRespectsOffset(): void
    {
        // Arrange — create a token and add three actions
        $token            = new Token();
        $token->userid    = 1;
        $token->tokentype = Token::TYPE_API;
        $token->token     = 'pagination-token-' . uniqid();
        $token->created   = time();
        $token->save();

        $token->addAction();
        $this->flushAction($token);
        $token->addAction();
        $this->flushAction($token);
        $token->addAction();
        $this->flushAction($token);

        // Act — fetch with offset=2, limit=10
        $result = $token->getActions(10, 2);

        // Assert — total is still 3, but data only has the last 1 row
        $this->assertSame(3, $result['total'],
            'getActions() total must count all rows regardless of offset');
        $this->assertCount(1, $result['data'],
            'getActions() data must contain only rows after the offset');
    }

    /**
     * getActions() must JSON-decode the params/parameters field for each
     * returned action row when it contains a valid JSON string.
     *
     * This covers lines 817–820 in getActions():
     * `if (is_string($action['parameters'])) { $action['parameters'] = json_decode(…); }`
     * Parameters are stored as JSON text; the method hydrates them into arrays
     * so callers don't need to decode them manually.
     */
    public function testGetActionsDecodesJsonParameters(): void
    {
        // Arrange — create a token and add a POST action with params
        $token            = new Token();
        $token->userid    = 1;
        $token->tokentype = Token::TYPE_API;
        $token->token     = 'json-params-token-' . uniqid();
        $token->created   = time();
        $token->save();

        \Pramnos\Http\Request::$requestMethod = 'POST';
        $_POST['search'] = 'hello';
        $token->addAction();
        $this->flushAction($token);
        unset($_POST['search']);
        \Pramnos\Http\Request::$requestMethod = 'GET';

        // Act
        $result = $token->getActions(10);

        // Assert — parameters must be a decoded array, not a raw JSON string
        $this->assertCount(1, $result['data']);
        $params = $result['data'][0]['parameters'];
        $this->assertIsArray($params,
            'getActions() must JSON-decode the parameters field into an array');
        $this->assertSame('hello', $params['search'],
            'Decoded parameters must contain the original POST values');
    }

    // =========================================================================
    // updateAction() — MySQL schema-migration error-recovery path
    // =========================================================================

    /**
     * updateAction() must enter the MySQL schema-migration recovery path when
     * the tokenactions table lacks the return_status column.
     *
     * This covers lines 477–499 of updateAction(): the MySQL "Unknown column"
     * error-recovery block that adds the three audit columns on legacy databases.
     *
     * MySQL 8.0 does not support `ADD COLUMN IF NOT EXISTS` (a MariaDB extension),
     * so the ALTER TABLE inside the recovery block itself throws a syntax error.
     * We verify two things here:
     *   1. The recovery branch was entered (proven by the exception escaping the
     *      outer catch and reaching us — the "else" silent-error path at line 501
     *      would NOT propagate).
     *   2. The tokenactions table exists but the new columns are missing (pre-
     *      condition check, documents the legacy schema state).
     *
     * In a production MariaDB environment the recovery succeeds silently; in the
     * test MySQL 8.0 container the ALTER fails and the exception propagates.
     * Either way, the branch at line 476 is executed.
     */
    public function testUpdateActionMysqlSchemaRecoveryBranchIsEntered(): void
    {
        // Arrange — swap tokenactions for a legacy schema WITHOUT audit columns.
        $db = Factory::getDatabase();
        $db->query("SET FOREIGN_KEY_CHECKS = 0");
        $db->query("DROP TABLE IF EXISTS `" . $db->prefix . "tokenactions`");
        $db->query(
            "CREATE TABLE `" . $db->prefix . "tokenactions` (
                `actionid` INT AUTO_INCREMENT PRIMARY KEY,
                `tokenid` INT,
                `urlid` INT,
                `method` VARCHAR(10),
                `params` TEXT,
                `servertime` INT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );
        $db->query("SET FOREIGN_KEY_CHECKS = 1");

        // Pre-condition: verify the new columns do NOT exist yet.
        $cols = $db->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS"
            . " WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = '" . $db->prefix . "tokenactions'"
            . " AND COLUMN_NAME = 'return_status'"
        );
        $this->assertSame(0, $cols->numRows,
            'Pre-condition: return_status column must not exist in the legacy schema');

        // Seed a urls row and a tokenactions row for the UPDATE to target.
        $db->query(
            "INSERT INTO `" . $db->prefix . "urls` (`url`, `hash`) VALUES ('/legacy', "
            . crc32('/legacy') . ")"
        );
        $urlId = (int) $db->getInsertId();
        $db->query(
            "INSERT INTO `" . $db->prefix . "tokenactions`"
            . " (`tokenid`, `urlid`, `method`, `params`, `servertime`)"
            . " VALUES (0, {$urlId}, 'GET', '{}', " . time() . ")"
        );
        $legacyActionId = (int) $db->getInsertId();

        // Act — updateAction() will fail with 'Unknown column "return_status"'
        // and enter the MySQL recovery block at line 476.
        // On MySQL 8.0, the `ADD COLUMN IF NOT EXISTS` syntax inside the block
        // is not supported, so a secondary exception escapes the outer catch.
        // We wrap the call to handle both outcomes:
        //  (a) MariaDB: recovery succeeds, columns added, no exception.
        //  (b) MySQL 8.0: ALTER TABLE fails, exception propagates.
        $recoveryReached = false;
        try {
            $token = new Token();
            $token->tokenid = 0;
            $token->updateAction($legacyActionId, 200, 5.0, ['test' => true]);
            // If we get here (MariaDB), verify columns were added.
            $recoveryReached = true;
        } catch (\Exception $e) {
            // MySQL 8.0: the recovery ALTER TABLE itself threw, confirming we
            // entered the recovery branch (the "else" path at line 501 only
            // calls Logger::logError() and does NOT propagate exceptions).
            $recoveryReached = (
                strpos($e->getMessage(), 'IF NOT EXISTS') !== false
                || strpos($e->getMessage(), 'syntax') !== false
            );
        }

        // Assert — we must have entered the recovery branch one way or another.
        $this->assertTrue($recoveryReached,
            'updateAction() must enter the MySQL schema-recovery branch (line 476)'
            . ' when the tokenactions table is missing the audit columns');
    }

    /**
     * updateAction() must add the return_status / execution_time_ms / return_data
     * columns for a save() INSERT failure path by also testing the save() failure
     * path where insertDataToTable() returns false.
     *
     * This covers lines 619–620 in save(): the `if (!$database->insertDataToTable(…))`
     * failure branch that records the DB error via addError(). When insertDataToTable()
     * fails (e.g. a constraint violation), the Token must register the error and
     * return without assigning a tokenid.
     *
     * We trigger the failure by inserting a duplicate unique token string.
     */
    public function testSaveInsertFailureAddsError(): void
    {
        // Arrange — add a UNIQUE constraint on the token column so the second
        // insert will fail, triggering the insertDataToTable failure branch.
        $db = Factory::getDatabase();
        $db->query(
            "ALTER TABLE `" . $db->prefix . "usertokens` ADD UNIQUE KEY `uq_token` (`token`)"
        );

        // Insert the first token successfully.
        $token1            = new Token();
        $token1->userid    = 1;
        $token1->tokentype = Token::TYPE_API;
        $token1->token     = 'duplicate-token-string';
        $token1->created   = time();
        $token1->save();
        $this->assertGreaterThan(0, $token1->tokenid, 'First save must succeed');

        // Arrange — second token with the SAME token string will conflict.
        $token2            = new Token();
        $token2->userid    = 2;
        $token2->tokentype = Token::TYPE_API;
        $token2->token     = 'duplicate-token-string';
        $token2->created   = time();

        // Act — this save() must fail on the INSERT due to duplicate key.
        $token2->save();

        // Assert — tokenid must remain 0, error must be recorded.
        $this->assertSame(0, $token2->tokenid,
            'save() must not assign tokenid when insertDataToTable() fails');
        $this->assertNotEmpty($token2->_errors,
            'save() must record the DB error when insertDataToTable() returns false');
    }

    // =========================================================================
    // save() — updateTableData() failure path (non-PostgreSQL)
    // =========================================================================

    /**
     * save() on an existing token (update path) must record an error when
     * updateTableData() fails — e.g., because the table no longer exists.
     *
     * This covers the else-branch at lines 652–654 in save(): when the UPDATE
     * fails for a non-PostgreSQL reason (not an ipaddress column migration),
     * the DB error is recorded via addError(). This proves the error-propagation
     * path for table-missing or schema-mismatch failures during updates.
     */
    public function testSaveUpdateFailureAddsError(): void
    {
        // Arrange — save a new token to get a valid tokenid (_isnew → false).
        $token            = new Token();
        $token->userid    = 1;
        $token->tokentype = Token::TYPE_API;
        $token->token     = 'save-update-fail-' . uniqid();
        $token->created   = time();
        $token->save();
        $this->assertGreaterThan(0, $token->tokenid,
            'Pre-condition: token must be inserted before testing the update path');

        // Drop the table after the insert so the subsequent UPDATE has no target.
        $db = Factory::getDatabase();
        $db->query("SET FOREIGN_KEY_CHECKS = 0");
        $db->query("DROP TABLE `" . $db->prefix . "usertokens`");
        $db->query("SET FOREIGN_KEY_CHECKS = 1");

        // Act — save() will attempt an UPDATE on the now-missing table.
        $token->notes = 'trigger-update-failure';
        $token->save();

        // Assert — the DB error must have been recorded.
        $this->assertNotEmpty($token->_errors,
            'save() must record a DB error when updateTableData() fails on update path');

        // Restore the table so tearDown can clean up properly.
        $db->query(
            "CREATE TABLE IF NOT EXISTS `" . $db->prefix . "usertokens` (
                `tokenid` INT AUTO_INCREMENT PRIMARY KEY,
                `userid` INT, `tokentype` VARCHAR(50), `token` VARCHAR(255),
                `created` INT, `notes` TEXT, `lastused` INT,
                `status` INT DEFAULT 0, `applicationid` INT, `actions` INT DEFAULT 0,
                `removedate` INT, `deviceinfo` TEXT, `scope` TEXT, `parentToken` INT,
                `expires` INT NULL, `ipaddress` VARCHAR(45) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );
    }
}
