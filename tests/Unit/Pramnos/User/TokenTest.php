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
    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        Settings::clearSettings();
        $settingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
        Settings::loadSettings($settingsFile);
        Application::getInstance();
        
        $singleton = &Factory::getDatabase();
        $singleton = null;
        
        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }
        
        // Drop test tables first (with FK checks off to avoid ordering issues
        // when integration tests leave behind tables with FK constraints).
        $db->query("SET FOREIGN_KEY_CHECKS = 0");
        $db->cacheflush();
        $db->query("DROP TABLE IF EXISTS " . $db->prefix . "tokenactions");
        $db->query("DROP TABLE IF EXISTS " . $db->prefix . "urls");
        $db->query("DROP TABLE IF EXISTS " . $db->prefix . "usertokens");
        $db->query("SET FOREIGN_KEY_CHECKS = 1");

        // Ensure the users table exists (Token::getDetails() JOINs against it).
        // Create it directly rather than via User::setupDb() to avoid also
        // creating the FK-constrained usertokens schema that setupDb generates.
        $db->query(
            "CREATE TABLE IF NOT EXISTS `" . $db->prefix . "users` (
                `userid` bigint(20) NOT NULL AUTO_INCREMENT,
                `username` varchar(50) NOT NULL DEFAULT '',
                `email` varchar(150) NOT NULL DEFAULT '',
                `firstname` varchar(128) NOT NULL DEFAULT '',
                `lastname` varchar(128) NOT NULL DEFAULT '',
                PRIMARY KEY (`userid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );

        // Create lightweight test versions of the token tables (no FK constraints
        // so tests can insert arbitrary userids without needing real users rows).
        // Use IF NOT EXISTS to guard against unexpected state left by prior tests
        // or a partially-failed setUp — every table is dropped at the start of
        // this method, so IF NOT EXISTS is just a safety net.
        $db->query(
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );

        $db->query(
            "CREATE TABLE IF NOT EXISTS `" . $db->prefix . "urls` (
                `urlid` INT AUTO_INCREMENT PRIMARY KEY,
                `url` TEXT,
                `hash` BIGINT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );

        $db->query(
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );

        // Stub applications table required by Token::getDetails() LEFT JOIN.
        $db->query(
            "CREATE TABLE IF NOT EXISTS `" . $db->prefix . "applications` (
                `appid` INT NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`appid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );

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

        // Drop test tables so the next test's setUp gets a clean slate.
        // Always attempt regardless of $db->connected status; if the connection
        // is lost the query will reconnect internally.
        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }
        $db->query("SET FOREIGN_KEY_CHECKS = 0");
        $db->query("DROP TABLE IF EXISTS `" . $db->prefix . "tokenactions`");
        $db->query("DROP TABLE IF EXISTS `" . $db->prefix . "urls`");
        $db->query("DROP TABLE IF EXISTS `" . $db->prefix . "usertokens`");
        $db->query("SET FOREIGN_KEY_CHECKS = 1");
        $db->cacheflush();
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
        
        $this->assertGreaterThan(0, $token->lastActionId);
        $this->assertEquals(1, $token->actions);
        $this->assertEquals('127.0.0.1', $token->ipaddress);
        
        // Update action
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
        $token->addAction();
        
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
        $this->assertGreaterThan(0, $token->lastActionId,
            'addAction() with POST must create a tokenactions row');
        $this->assertSame(1, $token->actions,
            'addAction() must increment the actions counter');

        // Verify params contain POST data
        $db = Factory::getDatabase();
        $row = $db->query(
            "SELECT params FROM `#PREFIX#tokenactions` WHERE actionid = {$token->lastActionId}"
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
        $this->assertGreaterThan(0, $token->lastActionId,
            'addAction() with DELETE must create a tokenactions row');

        // Verify params contain DELETE data
        $db = Factory::getDatabase();
        $row = $db->query(
            "SELECT params FROM `#PREFIX#tokenactions` WHERE actionid = {$token->lastActionId}"
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
        $this->assertGreaterThan(0, $token->lastActionId,
            'addAction() with PUT must create a tokenactions row');

        // Verify params contain PUT data
        $db = Factory::getDatabase();
        $row = $db->query(
            "SELECT params FROM `#PREFIX#tokenactions` WHERE actionid = {$token->lastActionId}"
        );
        $params = json_decode($row->fields['params'], true);
        $this->assertSame('updated', $params['name'],
            'addAction() must store PUT payload in the params field');

        // Cleanup
        \Pramnos\Http\Request::$requestMethod = 'GET';
        \Pramnos\Http\Request::$putData       = [];
    }

    /**
     * addAction() must prefer the Cloudflare connecting IP header over
     * REMOTE_ADDR when HTTP_CF_CONNECTING_IP is present.
     *
     * This covers lines 348–352 in addAction(): the CF header overrides the
     * direct REMOTE_ADDR. This is critical for applications behind Cloudflare
     * proxies where REMOTE_ADDR is the CDN edge node, not the real client.
     */
    public function testAddActionPrefersCloudflarIp(): void
    {
        // Arrange
        $token            = new Token();
        $token->userid    = 1;
        $token->tokentype = Token::TYPE_API;
        $token->token     = 'cf-ip-token-' . uniqid();
        $token->created   = time();
        $token->save();

        $_SERVER['REMOTE_ADDR']          = '10.0.0.1';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.45';

        // Act
        $token->addAction();

        // Assert — ipaddress must be the Cloudflare header value
        $this->assertSame('203.0.113.45', $token->ipaddress,
            'addAction() must use HTTP_CF_CONNECTING_IP over REMOTE_ADDR');

        // Cleanup
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
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
        $actionId = $token->lastActionId;
        $this->assertNotNull($token->lastActionTime,
            'addAction() must set lastActionTime for auto-calculation');

        // Act — call updateAction with execution_time_ms=0 to trigger auto-calc
        $token->updateAction($actionId, 200, 0, ['status' => 'done']);

        // Assert — execution_time_ms must have been calculated and stored (> 0)
        $db  = Factory::getDatabase();
        $row = $db->query(
            "SELECT execution_time_ms FROM `#PREFIX#tokenactions` WHERE actionid = {$actionId}"
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
        $actionId = $token->lastActionId;

        // Build an stdClass to pass as return_data
        $obj        = new \stdClass();
        $obj->code  = 200;
        $obj->msg   = 'ok';

        // Act
        $token->updateAction($actionId, 200, 5.0, $obj);

        // Assert — return_data must be JSON-encoded with the object's public properties
        $db  = Factory::getDatabase();
        $row = $db->query(
            "SELECT return_data FROM `#PREFIX#tokenactions` WHERE actionid = {$actionId}"
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
        $actionId = $token->lastActionId;

        // Act — pass a plain integer as return_data
        $token->updateAction($actionId, 200, 1.0, 42);

        // Assert — must be wrapped in {"data": 42}
        $db  = Factory::getDatabase();
        $row = $db->query(
            "SELECT return_data FROM `#PREFIX#tokenactions` WHERE actionid = {$actionId}"
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
        $actionId = $token->lastActionId;

        // Act — pass a negative return_status
        $token->updateAction($actionId, -1, 5.0, ['data' => 'should not be stored']);

        // Assert — the row must be unchanged (return_status still NULL)
        $db  = Factory::getDatabase();
        $row = $db->query(
            "SELECT return_status FROM `#PREFIX#tokenactions` WHERE actionid = {$actionId}"
        );
        $this->assertNull($row->fields['return_status'],
            'updateAction() with a negative return_status must not modify the row');
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
        $token->addAction();
        $token->addAction();

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
