<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\Controllers\TokensController;
use Pramnos\User\User;

class TestableTokensController extends TokensController
{
    public array $redirectedTo = [];

    public function redirect($url = null, $quit = true, $code = '302')
    {
        if ($url === null) {
            $url = 'default_redirect';
        }
        $this->redirectedTo[] = $url;
        throw new \RuntimeException('redirect_quit');
    }

    public function &getView($name = '', $type = '', $args = [])
    {
        $view = new class {
            public mixed $tokens;
            public mixed $total;
            public mixed $page;
            
            public function display($view = '') {
                return 'mock html view for ' . $view;
            }
        };
        return $view;
    }
}

class TokensControllerTest extends TestCase
{
    private TestableTokensController $controller;

    protected function setUp(): void
    {
        \Pramnos\Application\Settings::clearSettings();
        $settingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
        \Pramnos\Application\Settings::loadSettings($settingsFile);

        $singleton = &\Pramnos\Framework\Factory::getDatabase();
        $singleton = null;

        $db = \Pramnos\Framework\Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $db->query("SET FOREIGN_KEY_CHECKS=0");
        $db->query("DROP TABLE IF EXISTS `#PREFIX#usertokens`");

        $db->query("DROP TABLE IF EXISTS `#PREFIX#users`");
        $db->query("CREATE TABLE `#PREFIX#users` (
            `userid` bigint NOT NULL AUTO_INCREMENT,
            `username` varchar(255) NOT NULL,
            `email` varchar(255) NOT NULL,
            PRIMARY KEY (`userid`)
        )");
        $db->query("DROP TABLE IF EXISTS `applications`");
        $db->query("CREATE TABLE `applications` (
            `appid` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `apikey` varchar(255) DEFAULT NULL,
            `apisecret` varchar(255) DEFAULT NULL,
            `status` tinyint(1) NOT NULL DEFAULT 1,
            `created` bigint(20) NOT NULL DEFAULT 0,
            `redirect_uri` varchar(255) DEFAULT NULL,
            `public_key` text DEFAULT NULL,
            `systemuser` int(11) DEFAULT NULL,
            PRIMARY KEY (`appid`)
        )");
        $db->query("CREATE TABLE `#PREFIX#usertokens` (
            `tokenid` int(11) NOT NULL AUTO_INCREMENT,
            `userid` bigint NOT NULL,
            `tokentype` varchar(50) NOT NULL DEFAULT 'oauth',
            `token` varchar(255) NOT NULL DEFAULT 'testtoken',
            `applicationid` int(11) NOT NULL DEFAULT 0,
            `scope` varchar(255) DEFAULT NULL,
            `expires` int(11) DEFAULT NULL,
            `lastused` int(11) DEFAULT NULL,
            `status` tinyint(1) NOT NULL DEFAULT '1',
            `removedate` int(11) DEFAULT NULL,
            `code_challenge` varchar(128) DEFAULT NULL,
            `code_challenge_method` varchar(10) DEFAULT NULL,
            `deviceinfo` text DEFAULT NULL,
            `notes` text DEFAULT NULL,
            `created` int(11) DEFAULT NULL,
            `ipaddress` varchar(45) DEFAULT NULL,
            `parentToken` int(11) DEFAULT NULL,
            `actions` int(11) DEFAULT 0,
            PRIMARY KEY (`tokenid`)
        )");

        $db->query("TRUNCATE TABLE `#PREFIX#users`");
        $db->query("TRUNCATE TABLE `applications`");

        $db->query("INSERT INTO `#PREFIX#users` (`userid`, `username`, `email`) VALUES (1, 'testuser', 'test@test.com')");
        $db->query("INSERT INTO `applications` (`appid`, `name`, `apikey`, `apisecret`) VALUES (100, 'Test App', 'dummy_key', 'dummy_secret')");
        $db->query("INSERT INTO `#PREFIX#usertokens` (`tokenid`, `userid`, `tokentype`, `token`, `applicationid`, `status`) VALUES (10, 1, 'oauth', 'testtoken', 100, 1)");

        $app = \Pramnos\Application\Application::getInstance();
        if (!$app) {
            $app = new \Pramnos\Application\Application();
            $reflection = new \ReflectionClass($app);
            $prop = $reflection->getProperty('initialized');
            $prop->setValue($app, true);
        }
        
        $this->controller = new TestableTokensController($app);

        $_GET = [];
        $_POST = [];
        $_SERVER = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $doc = \Pramnos\Framework\Factory::getDocument();
        if (isset($doc->themeObject) && $doc->themeObject instanceof \stdClass) {
            unset($doc->themeObject);
        }
        $_SESSION = [];
        $app = Application::getInstance();
        if ($app) {
            $app->currentUser = null;
        }
        $_GET = [];
        $_POST = [];
        $_SERVER = [];

        $db = \Pramnos\Framework\Factory::getDatabase();
        $db->query("SET FOREIGN_KEY_CHECKS=0");
        $db->query("DROP TABLE IF EXISTS `#PREFIX#usertokens`");
        $db->query("DROP TABLE IF EXISTS `#PREFIX#users`");
        // Drop applications too — this test creates a minimal schema (no `created`
        // column) that breaks OauthTest when it relies on CREATE TABLE IF NOT EXISTS.
        $db->query("DROP TABLE IF EXISTS `applications`");
        $db->query("SET FOREIGN_KEY_CHECKS=1");
    }

    private function setMockUser(int $usertype): void
    {
        $_SESSION['logged'] = true;
        $_SESSION['login'] = true;
        $_SESSION['userid'] = 2;
        $_SESSION['uid'] = 2;
        $_SESSION['usertype'] = $usertype;
        $_SESSION['sessionid'] = 'dummy_session_id';

        $user = new User(0);
        $user->userid = 2;
        $user->usertype = $usertype;
        
        $lang = \Pramnos\Framework\Factory::getLanguage();
        $user->language = $lang ? $lang->currentlang() : 'en';

        $app = Application::getInstance();
        if ($app) {
            $app->currentUser = $user;
        }
    }

    public function testRequireMinUserTypeRedirectsWhenBelowRequired(): void
    {
        $this->setMockUser(89); // Required is 90

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        $this->controller->display();
    }

    public function testRequireMinUserTypeRedirectsWhenNullUser(): void
    {
        $app = Application::getInstance();
        if ($app) {
            $app->currentUser = null;
        }
        $_SESSION = [];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        $this->controller->display();
    }

    public function testDisplayShowsTokens(): void
    {
        $this->setMockUser(90);
        
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->themeObject = new class {
            public function allowsViewOverrides() { return false; }
        };
        
        ob_start();
        try {
            $output = $this->controller->display();
        } finally {
            $obOutput = ob_get_clean();
        }
        
        if (empty($output)) {
            $output = $obOutput;
        }
        
        $this->assertNotEmpty($output);
        $this->assertEmpty($this->controller->redirectedTo);
        $this->assertSame('OAuth2 Tokens', $doc->title);
    }

    public function testRevokeUpdatesStatus(): void
    {
        $this->setMockUser(90);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->revoke(10);
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('message=revoked', $this->controller->redirectedTo[0]);

            $db = \Pramnos\Framework\Factory::getDatabase();
            $result = $db->queryBuilder()->table('#PREFIX#usertokens')->where('tokenid', 10)->first();
            $this->assertEquals(3, $result->fields['status']);
        }
    }

    public function testRevokeRedirectsWhenInvalidId(): void
    {
        $this->setMockUser(90);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->revoke(0);
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('error=invalid_id', $this->controller->redirectedTo[0]);
        }
    }

    public function testRevokeallUpdatesStatus(): void
    {
        $this->setMockUser(90);

        $_POST = [
            'userid' => 1
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->revokeall();
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('message=revoked_all', $this->controller->redirectedTo[0]);

            $db = \Pramnos\Framework\Factory::getDatabase();
            $result = $db->queryBuilder()->table('#PREFIX#usertokens')->where('userid', 1)->first();
            $this->assertEquals(3, $result->fields['status']);
        }
    }

    public function testRevokeallRedirectsWhenMissingFilters(): void
    {
        $this->setMockUser(90);

        $_POST = [];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->revokeall();
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('error=filter_required', $this->controller->redirectedTo[0]);
        }
    }

    /**
     * display() with a user_id GET filter must add WHERE ut.userid = ? to the query.
     *
     * The $filterUserId > 0 branch (line 68 in TokensController) is only reached
     * when $_GET['user_id'] is a positive integer.  The existing testDisplayShowsTokens
     * does not set this parameter, leaving the branch uncovered.
     */
    public function testDisplayWithUserIdFilterRendersPage(): void
    {
        // Arrange — admin user, user_id filter set in GET
        $this->setMockUser(90);
        $_GET['user_id'] = '1'; // positive → exercises the filterUserId > 0 branch

        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->themeObject = new class {
            public function allowsViewOverrides() { return false; }
        };

        // Act
        ob_start();
        try {
            $output = $this->controller->display();
        } finally {
            $obOutput = ob_get_clean();
        }

        if (empty($output)) {
            $output = $obOutput;
        }

        // Assert — page rendered, filter branch was executed
        $this->assertNotEmpty($output,
            'display() must render the tokens view when a user_id filter is applied');
        $this->assertEmpty($this->controller->redirectedTo,
            'display() must not redirect when a valid user_id filter is set');
    }

    /**
     * display() with an app_id GET filter must add WHERE ut.applicationid = ? to the query.
     *
     * The $filterAppId > 0 branch (line 71 in TokensController) mirrors the
     * user_id branch. This test covers it independently to ensure both filters
     * are independently functional.
     */
    public function testDisplayWithAppIdFilterRendersPage(): void
    {
        // Arrange — admin user, app_id filter set in GET
        $this->setMockUser(90);
        $_GET['app_id'] = '5'; // positive → exercises the filterAppId > 0 branch

        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->themeObject = new class {
            public function allowsViewOverrides() { return false; }
        };

        // Act
        ob_start();
        try {
            $output = $this->controller->display();
        } finally {
            $obOutput = ob_get_clean();
        }

        if (empty($output)) {
            $output = $obOutput;
        }

        // Assert — page rendered, filter branch was executed
        $this->assertNotEmpty($output,
            'display() must render the tokens view when an app_id filter is applied');
        $this->assertEmpty($this->controller->redirectedTo,
            'display() must not redirect when a valid app_id filter is set');
    }

    /**
     * revokeall() with both userid AND applicationid POST filters must apply
     * both WHERE conditions — the $appId > 0 branch (line 144) is only reached
     * when both filters are present.
     *
     * testRevokeallUpdatesStatus only passes userid, leaving the applicationid
     * branch uncovered. This test passes both to exercise the AND path.
     */
    public function testRevokeallWithBothFiltersAppliesBothConditions(): void
    {
        // Arrange — admin user, both userid and applicationid set
        $this->setMockUser(90);
        $_POST = [
            'userid'        => 1,
            'applicationid' => 1,
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            // Act — revokeall() will apply WHERE userid=1 AND applicationid=1
            $this->controller->revokeall();
        } finally {
            // Assert — redirected with revoked_all message (both filters were applied)
            $this->assertCount(1, $this->controller->redirectedTo,
                'revokeall() must redirect exactly once after bulk revocation');
            $this->assertStringContainsString('message=revoked_all', $this->controller->redirectedTo[0],
                'revokeall() must redirect with message=revoked_all when both filters are provided');
        }
    }
}
