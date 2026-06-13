<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\EmailsController;
use Pramnos\User\User;

class TestableEmailsController extends EmailsController
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
            public mixed $mails;
            public mixed $mail;
            public mixed $total;
            public mixed $page;
            
            public function display($view = '') {
                return 'mock html view for ' . $view;
            }
        };
        return $view;
    }
}

class EmailsControllerTest extends TestCase
{
    private TestableEmailsController $controller;

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

        // Create mails table
        $db->query("CREATE TABLE IF NOT EXISTS `#PREFIX#mails` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `status` tinyint(1) NOT NULL DEFAULT '0',
            `tomail` varchar(255) NOT NULL,
            `toname` varchar(255) NOT NULL,
            `subject` varchar(255) NOT NULL,
            `date` datetime NOT NULL,
            `module` varchar(255) NOT NULL,
            PRIMARY KEY (`id`)
        )");

        // Insert mock data
        $db->query("TRUNCATE TABLE `#PREFIX#mails`");
        $db->query("INSERT INTO `#PREFIX#mails` (`id`, `status`, `tomail`, `toname`, `subject`, `date`, `module`) VALUES (1, 1, 'test@test.com', 'Test', 'Subject 1', '2023-01-01 10:00:00', 'system')");
        $db->query("INSERT INTO `#PREFIX#mails` (`id`, `status`, `tomail`, `toname`, `subject`, `date`, `module`) VALUES (2, 0, 'fail@test.com', 'Fail', 'Subject 2', '2023-01-02 10:00:00', 'system')");

        $app = \Pramnos\Application\Application::getInstance();
        if (!$app) {
            $app = new \Pramnos\Application\Application();
            $reflection = new \ReflectionClass($app);
            $prop = $reflection->getProperty('initialized');
            $prop->setValue($app, true);
        }
        
        $this->controller = new TestableEmailsController($app);

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
        $this->setMockUser(79); // Required is 80

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

    public function testDisplayShowsEmails(): void
    {
        $this->setMockUser(80);
        
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
        $this->assertSame('Email History', $doc->title);
    }

    public function testShowDisplaysEmailPreview(): void
    {
        $this->setMockUser(80);
        
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->themeObject = new class {
            public function allowsViewOverrides() { return false; }
        };
        
        ob_start();
        try {
            $output = $this->controller->show(1);
        } finally {
            $obOutput = ob_get_clean();
        }
        
        if (empty($output)) {
            $output = $obOutput;
        }
        
        $this->assertNotEmpty($output);
        $this->assertEmpty($this->controller->redirectedTo);
        $this->assertStringContainsString('Email Preview — Subject 1', $doc->title);
    }

    public function testShowRedirectsWhenInvalidId(): void
    {
        $this->setMockUser(80);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->show(0);
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('error=invalid_id', $this->controller->redirectedTo[0]);
        }
    }

    public function testShowRedirectsWhenNotFound(): void
    {
        $this->setMockUser(80);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->show(999);
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('error=not_found', $this->controller->redirectedTo[0]);
        }
    }

    public function testResendUpdatesStatus(): void
    {
        $this->setMockUser(80);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->resend(2); // failed email
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('message=requeued', $this->controller->redirectedTo[0]);

            $db = \Pramnos\Framework\Factory::getDatabase();
            $result = $db->queryBuilder()->table('mails')->where('id', 2)->first();
            $this->assertEquals(2, $result->fields['status']);
        }
    }

    public function testResendRedirectsWhenInvalidId(): void
    {
        $this->setMockUser(80);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redirect_quit');

        try {
            $this->controller->resend(0);
        } finally {
            $this->assertCount(1, $this->controller->redirectedTo);
            $this->assertStringContainsString('error=invalid_id', $this->controller->redirectedTo[0]);
        }
    }

    /**
     * display() with a status GET parameter must add a WHERE clause to the query.
     *
     * The filterStatus branch (lines 59-62 in EmailsController) adds a WHERE
     * condition only when $_GET['status'] is present.  Without this test the
     * branch stays uncovered and a future regression (e.g. filter silently
     * ignored) would go undetected.  We set status=1 (sent) and verify the page
     * renders without error — the actual SQL filtering is an integration concern
     * covered by the query-builder tests.
     */
    public function testDisplayWithStatusFilterRendersPage(): void
    {
        // Arrange — admin user, status filter set in GET
        $this->setMockUser(80);
        $_GET['status'] = '1'; // filter for sent emails

        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->themeObject = new class {
            public function allowsViewOverrides() { return false; }
        };

        // Act — display() reads $_GET['status'] and adds WHERE status=1
        ob_start();
        try {
            $output = $this->controller->display();
        } finally {
            $obOutput = ob_get_clean();
        }

        if (empty($output)) {
            $output = $obOutput;
        }

        // Assert — page rendered without redirect; filter branch was executed
        $this->assertNotEmpty($output,
            'display() must render the emails view even when a status filter is applied');
        $this->assertEmpty($this->controller->redirectedTo,
            'display() must not redirect when a valid status filter is set');
        $this->assertSame('Email History', $doc->title,
            'display() must set the correct page title when filtered');
    }
}
