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
        $this->_object = Session::getInstance();
    }

    public function testGetInstance()
    {
        $this->assertInstanceOf(Session::class, Session::getInstance());
    }

    public function testStart()
    {
        // session_start() will try to send headers, but in PHPUnit it might be okay
        // if we run in a separate process and use @.
        $id = $this->_object->start();
        $this->assertIsString($id);
        $this->assertNotEmpty($id);
    }

    public function testGetAndSnapshot()
    {
        $this->_object->snapshot('http://test.com');
        $this->assertEquals('http://test.com', $this->_object->getSnapshot());
        $this->assertFalse($this->_object->getSnapshot());
    }

    public function testTokenIsStable()
    {
        $id1 = $this->_object->start();
        $token1 = $this->_object->getToken();
        
        // Restarting session (simulating next request) should return same token
        $this->_object->start();
        $this->assertEquals($token1, $this->_object->getToken());
    }

    public function testGetTokenField()
    {
        $this->_object->start();
        $token = $this->_object->getToken();
        $field = $this->_object->getTokenField();
        
        $this->assertStringContainsString('<input type="hidden"', $field);
        $this->assertStringContainsString('name="' . $token . '"', $field);
        $this->assertStringContainsString('value="1"', $field);
    }

    public function testCheckToken()
    {
        $this->_object->start();
        $token = $this->_object->getToken();
        
        $_REQUEST[$token] = '1';
        $this->assertTrue($this->_object->checkToken());
        
        $_REQUEST[$token] = '0';
        $this->assertFalse($this->_object->checkToken());
        
        unset($_REQUEST[$token]);
        $this->assertFalse($this->_object->checkToken());
    }

    public function testRegenerateToken()
    {
        $this->_object->start();
        $token1 = $this->_object->getToken();
        
        $this->_object->regenerateToken();
        $token2 = $this->_object->getToken();
        
        $this->assertNotEquals($token1, $token2);
        $this->assertEquals($token2, $_SESSION['token']);
    }

    public function testResetRegeneratesToken()
    {
        $this->_object->start();
        $token1 = $this->_object->getToken();
        
        $this->_object->reset();
        $token2 = $this->_object->getToken();
        
        $this->assertNotEquals($token1, $token2);
    }
}
