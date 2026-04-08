<?php

use Pramnos\Application\Application;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests for Nonce-based CSP logic in Application class.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\Pramnos\Application\Application::class)]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class ApplicationCspTest extends TestCase
{
    /**
     * Set up basic environment defines
     */
    protected function setUp(): void
    {
        if (!defined('ROOT')) {
            define('ROOT', realpath(__DIR__ . '/../../../../'));
        }
        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }
        if (!defined('APP_PATH')) {
            define('APP_PATH', ROOT . DS . 'app');
        }
    }

    /**
     * Test that exec() generates a cryptographically secure nonce.
     */
    public function testExecGeneratesNonce()
    {
        // Mock Application to avoid actual controller execution and version checks
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['sendCspHeader', 'checkversion', 'getController', 'addbreadcrumb'])
            ->getMock();

        $app->method('checkversion')->willReturn(true);
        $app->method('sendCspHeader')->willReturn(null);
        $app->method('addbreadcrumb')->willReturn($app);
        
        // Mock controller and its output
        $controllerMock = $this->getMockBuilder(\Pramnos\Application\Controller::class)
            ->disableOriginalConstructor()
            ->getMock();
        $controllerMock->method('exec')->willReturn('');
        
        $app->method('getController')->willReturn($controllerMock);

        $app->exec('home');
        
        $this->assertNotEmpty($app->cspNonce, 'CSP Nonce should not be empty after exec()');
        $this->assertEquals(24, strlen($app->cspNonce), 'Base64 encoded 16-byte nonce should be 24 characters');
    }

    /**
     * Test getCspDomains helper for various configuration scenarios.
     */
    public function testGetCspDomains()
    {
        // Use an anonymous class to expose protected getCspDomains
        $app = new class extends Application {
            public function __construct() {}
            public function callGetCspDomains(array $csp, string $directive) {
                return $this->getCspDomains($csp, $directive);
            }
        };

        $cspConfig = [
            'script-src' => ['https://cdn.example.com', 'https://js.test.com'],
            'style-src' => ['https://css.example.com'],
            'empty-src' => []
        ];

        $this->assertEquals(
            ' https://cdn.example.com https://js.test.com', 
            $app->callGetCspDomains($cspConfig, 'script-src'),
            'Should return space-prefixed joined domains'
        );

        $this->assertEquals(
            ' https://css.example.com', 
            $app->callGetCspDomains($cspConfig, 'style-src'),
            'Should handle single domain array'
        );

        $this->assertEquals(
            '', 
            $app->callGetCspDomains($cspConfig, 'empty-src'),
            'Should return empty string for empty array'
        );

        $this->assertEquals(
            '', 
            $app->callGetCspDomains($cspConfig, 'non-existent'),
            'Should return empty string for non-existent directive'
        );
    }
}
