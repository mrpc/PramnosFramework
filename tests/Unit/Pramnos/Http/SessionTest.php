<?php

use Pramnos\Http\Session;
use Pramnos\Http\Request;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\CoversClass(\Pramnos\Http\Session::class)]
class SessionTest extends \PHPUnit\Framework\TestCase
{
    protected $_object;

    protected function setUp(): void
    {
        if (!isset($_SERVER['HTTP_USER_AGENT'])) {
            $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Test Browser)';
        }
        if (!isset($_SERVER['REMOTE_ADDR'])) {
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        }
        $this->_object = Session::getInstance();
    }

    /**
     * Helper method to calculate the expected CSRF fingerprint based on the test environment.
     * @param string $token The session token
     * @param bool $useIp Whether to include the IP address in the fingerprint
     * @return string
     */
    private function getExpectedFingerprint($token, $useIp = false)
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'none';
        $ip = $useIp ? ($_SERVER['REMOTE_ADDR'] ?? 'none') : '';
        return md5($ua . $ip . $token);
    }

    /**
     * Test that getInstance returns a Session object.
     */
    public function testGetInstance()
    {
        $this->assertInstanceOf(Session::class, Session::getInstance());
    }

    /**
     * Test that start() initializes the session and returns a session ID.
     */
    public function testStart()
    {
        $id = $this->_object->start();
        $this->assertIsString($id);
        $this->assertNotEmpty($id);
    }

    /**
     * Test the URL snapshot functionality (save and retrieve).
     */
    public function testGetAndSnapshot()
    {
        $this->_object->snapshot('http://test.com');
        $this->assertEquals('http://test.com', $this->_object->getSnapshot());
        $this->assertFalse($this->_object->getSnapshot());
    }

    /**
     * Test that the CSRF token remains stable across multiple calls to start().
     */
    public function testTokenIsStable()
    {
        $id1 = $this->_object->start();
        $token1 = $this->_object->getToken();
        
        $this->_object->start();
        $this->assertEquals($token1, $this->_object->getToken());
    }

    /**
     * Test the generation of the hidden input field for CSRF.
     */
    public function testGetTokenField()
    {
        $this->_object->start();
        $token = $this->_object->getToken();
        $field = $this->_object->getTokenField();
        $expectedValue = $this->getExpectedFingerprint($token);
        
        $this->assertStringContainsString('<input type="hidden"', $field);
        $this->assertStringContainsString('name="' . $token . '"', $field);
        $this->assertStringContainsString('value="' . $expectedValue . '"', $field);
    }

    /**
     * Test basic CSRF token validation using the default User-Agent fingerprint.
     */
    public function testCheckToken()
    {
        $this->_object->start();
        $token = $this->_object->getToken();
        $expectedValue = $this->getExpectedFingerprint($token);
        
        $_REQUEST[$token] = $expectedValue;
        $this->assertTrue($this->_object->checkToken());
        
        $_REQUEST[$token] = 'wrong-fingerprint';
        $this->assertFalse($this->_object->checkToken());
        
        unset($_REQUEST[$token]);
        $this->assertFalse($this->_object->checkToken());
    }

    /**
     * Test CSRF token validation with IP pinning enabled.
     */
    public function testCheckTokenWithIpPinning()
    {
        $this->_object->start();
        $token = $this->_object->getToken();
        $expectedValueWithIp = $this->getExpectedFingerprint($token, true);
        
        $_REQUEST[$token] = $expectedValueWithIp;
        $this->assertTrue($this->_object->checkToken('request', '', true));
        
        // Fails if IP in request doesn't match current environment fingerprint
        $_REQUEST[$token] = '1';
        $this->assertFalse($this->_object->checkToken('request', '', true));
        
        // Fails if environment IP changes
        $_REQUEST[$token] = $expectedValueWithIp;
        $oldIp = $_SERVER['REMOTE_ADDR'];
        $_SERVER['REMOTE_ADDR'] = '192.168.1.1';
        $this->assertFalse($this->_object->checkToken('request', '', true));
        $_SERVER['REMOTE_ADDR'] = $oldIp;
    }

    /**
     * Test that CSRF validation fails if the User-Agent changes during the session.
     */
    public function testCheckTokenFailureOnUserAgentChange()
    {
        $this->_object->start();
        $token = $this->_object->getToken();
        $expectedValue = $this->getExpectedFingerprint($token);
        
        $_REQUEST[$token] = $expectedValue;
        
        // Change UA
        $oldUa = $_SERVER['HTTP_USER_AGENT'];
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Evil Browser)';
        
        $this->assertFalse($this->_object->checkToken());
        
        $_SERVER['HTTP_USER_AGENT'] = $oldUa;
    }

    /**
     * Test manual regeneration of the CSRF token.
     */
    public function testRegenerateToken()
    {
        $this->_object->start();
        $token1 = $this->_object->getToken();
        
        $this->_object->regenerateToken();
        $token2 = $this->_object->getToken();
        
        $this->assertNotEquals($token1, $token2);
        $this->assertEquals($token2, $_SESSION['token']);
    }

    /**
     * Test that calling reset() also regenerates the CSRF token.
     */
    public function testResetRegeneratesToken()
    {
        $this->_object->start();
        $token1 = $this->_object->getToken();
        
        $this->_object->reset();
        $token2 = $this->_object->getToken();
        
        $this->assertNotEquals($token1, $token2);
    }

    /**
     * Test CSRF validation when using a field name prefix.
     */
    public function testCheckTokenWithPrefix()
    {
        $this->_object->start();
        $token = $this->_object->getToken();
        $expectedValue = $this->getExpectedFingerprint($token);
        $prefix = 'sf_';
        
        $_REQUEST[$prefix . $token] = $expectedValue;
        $this->assertTrue($this->_object->checkToken('request', $prefix));
        
        unset($_REQUEST[$prefix . $token]);
        $this->assertFalse($this->_object->checkToken('request', $prefix));
    }

    /**
     * Test CSRF validation across different request methods (GET, POST).
     */
    public function testCheckTokenWithDifferentMethods()
    {
        $this->_object->start();
        $token = $this->_object->getToken();
        $expectedValue = $this->getExpectedFingerprint($token);
        
        // Test POST
        $_POST[$token] = $expectedValue;
        $this->assertTrue($this->_object->checkToken('post'));
        unset($_POST[$token]);
        
        // Test GET
        $_GET[$token] = $expectedValue;
        $this->assertTrue($this->_object->checkToken('get'));
        unset($_GET[$token]);
    }

    /**
     * Test that the session and token are automatically initialized if not already started.
     */
    public function testEnsureStarted()
    {
        $token = $this->_object->getToken();
        $this->assertNotEmpty($token);
        $this->assertEquals(PHP_SESSION_ACTIVE, session_status());
    }

    /**
     * Test manual deletion of the URL snapshot.
     */
    public function testDeleteSnapshot()
    {
        $this->_object->snapshot('http://test.com');
        $this->assertEquals('http://test.com', $_SESSION['_snapshot']);
        
        $this->_object->deleteSnapshot();
        $this->assertFalse(isset($_SESSION['_snapshot']));
    }

    /**
     * Test the user login status check.
     */
    public function testIsLogged()
    {
        $this->_object->start();
        
        // Default: Not logged in
        $this->assertFalse($this->_object->isLogged());
        
        // Logged in via session
        $_SESSION['logged'] = true;
        $_SESSION['uid'] = 5;
        $this->assertTrue($this->_object->isLogged());
        $this->assertTrue(Session::staticIsLogged());
        
        // Not logged in if uid <= 1
        $_SESSION['uid'] = 1;
        $this->assertFalse($this->_object->isLogged());
        
        unset($_SESSION['logged'], $_SESSION['uid']);
    }

    /**
     * Test the login status check with UNITTESTING override.
     */
    public function testIsLoggedWithUnitTestOverride()
    {
        if (!defined('UNITTESTING')) {
            define('UNITTESTING', true);
        }
        
        global $unittesting_logged;
        $unittesting_logged = true;
        
        $this->assertTrue(Session::staticIsLogged());
        
        $unittesting_logged = false;
        $this->assertFalse(Session::staticIsLogged());
    }

    /**
     * Test cookie management methods (proxying to Request).
     */
    public function testCookiesetAndGet()
    {
        // cookieset returns boolean (success of setcookie or false)
        $result = $this->_object->cookieset('test', 'value');
        $this->assertIsBool($result);
        
        // cookieget should return null since we didn't actually set a physical cookie in CLI
        $this->assertNull($this->_object->cookieget('test'));
    }

    /**
     * Test generic session variable access.
     */
    public function testGetGenericVar()
    {
        $this->_object->start();
        $_SESSION['test_key'] = 'test_value';
        
        $this->assertEquals('test_value', $this->_object->get('test_key'));
        $this->assertNull($this->_object->get('non_existent'));
    }

    /**
     * Test the static version of the snapshot deletion.
     */
    public function testStaticDeleteSnapshot()
    {
        $this->_object->start();
        $_SESSION['_snapshot'] = 'url';
        
        Session::staticDeleteSnapshot();
        $this->assertFalse(isset($_SESSION['_snapshot']));
    }
}
