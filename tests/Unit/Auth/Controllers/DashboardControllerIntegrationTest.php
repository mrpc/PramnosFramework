<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Dashboard;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;
use Pramnos\User\User;

class TestableDashboardController extends Dashboard
{
    protected function terminate(): void
    {
        // Do nothing to avoid exit;
    }

    public function redirect($url = null, $quit = true, $code = '302')
    {
        echo "REDIRECTED_TO:" . $url;
    }
    
    public function renderLayout(string $activeTab, string $content): void
    {
        echo $content;
    }

    public function &getView($name = '', $type = '', $args = [])
    {
        $view = new #[\AllowDynamicProperties] class($name) {
            public function __construct($name) { 
                $this->name = $name;
            }
            public function display(string $layout = 'default', bool $return = false, bool $outputBuffer = true): mixed
            {
                $out = "View Display: " . $layout;
                if ($return) {
                    return $out;
                }
                echo $out;
                return true;
            }
            public function assign(string $key, mixed $val): void
            {
                $this->$key = $val;
            }
        };
        return $view;
    }
}

class DashboardControllerIntegrationTest extends TestCase
{
    private TestableDashboardController $controller;
    private $dbMock;
    private $queryBuilderMock;
    private $originalDb;
    private $originalUser;

    protected function setUp(): void
    {
        \Pramnos\Http\Session::getInstance();

        // Save original database reference
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $this->originalDb = clone $dbRef;

        // Mock User
        $userMock = $this->createMock(User::class);
        $userMock->userid = 100;
        $userMock->method('save')->willReturn(true);
        $appRef = \Pramnos\Application\Application::getInstance();
        if ($appRef) {
            $this->originalUser = $appRef->currentUser;
            $appRef->currentUser = $userMock;
        }
        
        // Setup CSRF token
        $session = \Pramnos\Http\Session::getInstance();
        $session->regenerateToken();

        // Simulate login
        $_SESSION['logged'] = true;
        $_SESSION['uid'] = 100;
        $property = new \ReflectionProperty(\Pramnos\User\User::class, '_usercache');
        $property->setValue(null, [100 => $userMock]);

        // Mock QueryBuilder
        $this->queryBuilderMock = $this->createMock(QueryBuilder::class);
        $this->queryBuilderMock->method('table')->willReturnSelf();
        $this->queryBuilderMock->method('select')->willReturnSelf();
        $this->queryBuilderMock->method('where')->willReturnSelf();
        $this->queryBuilderMock->method('orWhere')->willReturnSelf();
        $this->queryBuilderMock->method('join')->willReturnSelf();
        $this->queryBuilderMock->method('orderBy')->willReturnSelf();
        $this->queryBuilderMock->method('limit')->willReturnSelf();
        $this->queryBuilderMock->method('distinct')->willReturnSelf();
        $this->queryBuilderMock->method('groupBy')->willReturnSelf();

        // Mock Database
        $this->dbMock = $this->createMock(Database::class);
        $this->dbMock->method('queryBuilder')->willReturn($this->queryBuilderMock);
        $this->dbMock->method('updateTableData')->willReturn(true);

        // Inject Database via reference
        $dbRef = $this->dbMock;

        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = '';

        $this->controller = new TestableDashboardController(null);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];

        // Restore original database
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $dbRef = $this->originalDb;
        
        $property = new \ReflectionProperty(\Pramnos\User\User::class, '_usercache');
        $property->setValue(null, []);
        
        // Restore User
        $appRef = \Pramnos\Application\Application::getInstance();
        if ($appRef) {
            $appRef->currentUser = $this->originalUser;
        }
    }

    public function testDisplay()
    {
        ob_start();
        $this->controller->display();
        $echoed = ob_get_clean();

        $this->assertIsString($echoed);
        $this->assertStringContainsString('View Display: default', $echoed);
    }

    private function bypassCsrf(): void
    {
        $session = \Pramnos\Http\Session::getInstance();
        $ref = new \ReflectionObject($session);
        $prop = $ref->getProperty('_token');
        $tokenName = $prop->getValue($session);
        $_POST[$tokenName] = $session->getFingerprint();
    }

    public function testProfileGet()
    {
        ob_start();
        $this->controller->profile();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('View Display: profile', $echoed);
    }

    public function testProfilePost()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['firstname'] = 'John';
        $_POST['lastname'] = 'Doe';
        $_POST['email'] = 'john@example.com';
        $this->bypassCsrf();

        ob_start();
        $this->controller->profile();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertStringContainsString('message=profile_saved', $echoed);
    }
    
    public function testProfilePostInvalidEmail()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['email'] = 'invalid_email';
        $this->bypassCsrf();

        ob_start();
        $this->controller->profile();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertStringContainsString('error=invalid_email', $echoed);
    }

    public function testApplications()
    {
        ob_start();
        $this->controller->applications();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('View Display: authorized_applications', $echoed);
    }

    public function testRevokeApplicationPost()
    {
        $_POST['client_id'] = 'abc12345';
        
        $mockResult = new \stdClass();
        $mockResult->numRows = 1;
        $mockResult->fields = ['appid' => 5, 'name' => 'App Name'];
        $this->queryBuilderMock->method('first')->willReturn($mockResult);
        
        $this->queryBuilderMock->expects($this->once())->method('update')->willReturn(true);
        $this->queryBuilderMock->expects($this->once())->method('delete')->willReturn(true);

        ob_start();
        $this->controller->revokeapplication();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertStringContainsString('applications', $echoed);
    }

    public function testRevokeApplicationAjax()
    {
        $_POST['client_id'] = 'abc12345';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        
        $mockResult = new \stdClass();
        $mockResult->numRows = 1;
        $mockResult->fields = ['appid' => 5, 'name' => 'App Name'];
        $this->queryBuilderMock->method('first')->willReturn($mockResult);

        ob_start();
        $this->controller->revokeapplication();
        $echoed = ob_get_clean();

        $data = json_decode($echoed, true);
        $this->assertIsArray($data);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('Access revoked', $data['message']);
    }

    public function testExportData()
    {
        $mockResult = new \stdClass();
        $mockResult->numRows = 1;
        $mockResult->fields = ['userid' => 100, 'username' => 'tester', 'password' => 'secret'];
        $this->queryBuilderMock->method('first')->willReturn($mockResult);

        ob_start();
        $this->controller->exportdata();
        $echoed = ob_get_clean();

        $data = json_decode($echoed, true);
        $this->assertIsArray($data);
        $this->assertEquals(100, $data['userid']);
        $this->assertArrayNotHasKey('password', $data['data']); // verify sensitive data is removed
    }

    public function testDeleteAccountGet()
    {
        ob_start();
        $this->controller->deleteaccount();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('View Display: delete_account', $echoed);
    }

    public function testPrivacyGet()
    {
        ob_start();
        $this->controller->privacy();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('View Display: privacy_settings', $echoed);
    }

    public function testPrivacyPost()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['analytics'] = '1';
        $this->bypassCsrf();

        $this->queryBuilderMock->expects($this->once())->method('upsert')->willReturn(true);

        ob_start();
        $this->controller->privacy();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
    }

    public function testSecurity()
    {
        ob_start();
        $this->controller->security();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('View Display: security', $echoed);
    }

    public function testChangePasswordGet()
    {
        ob_start();
        $this->controller->changepassword();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('View Display: change_password', $echoed);
    }

    public function testChangePasswordPostPolicyError()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['current_password'] = 'old_secret';
        $_POST['new_password'] = 'short';
        $_POST['confirm_password'] = 'short';
        $this->bypassCsrf();

        $mockResult = new \stdClass();
        $mockResult->numRows = 1;
        $mockResult->fields = ['password' => password_hash('old_secret', PASSWORD_BCRYPT)];
        $this->queryBuilderMock->method('first')->willReturn($mockResult);

        ob_start();
        $this->controller->changepassword();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertStringContainsString('error=password_too_short', $echoed);
    }

    public function testChangePasswordPostSuccess()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['current_password'] = 'old_secret';
        $_POST['new_password'] = 'Strong1!Pass';
        $_POST['confirm_password'] = 'Strong1!Pass';
        $this->bypassCsrf();

        $mockResult = new \stdClass();
        $mockResult->numRows = 1;
        $mockResult->fields = ['password' => password_hash('old_secret', PASSWORD_BCRYPT)];
        $this->queryBuilderMock->method('first')->willReturn($mockResult);

        $this->queryBuilderMock->expects($this->once())->method('update')->willReturn(true);

        ob_start();
        $this->controller->changepassword();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertStringContainsString('message=password_changed', $echoed);
    }

    // ── deleteaccount() POST branches ─────────────────────────────────────────

    /**
     * Stage a stored password row so verifyUserPassword() can compare against
     * a known plaintext (legacy SHA-256 format, matching the framework default
     * for pre-v1.2 rows).
     */
    private function stubStoredPassword(string $plaintext): void
    {
        $row          = new \stdClass();
        $row->numRows = 1;
        $row->fields  = ['password' => hash('sha256', $plaintext)];
        $this->queryBuilderMock->method('first')->willReturn($row);
    }

    /**
     * POST with a wrong password must redirect back with
     * error=invalid_password and must NOT delete anything.
     *
     * This is the primary safety guard of account deletion: possession of a
     * logged-in session alone must not be enough to destroy the account.
     */
    public function testDeleteAccountPostWrongPasswordRedirectsWithError(): void
    {
        // Arrange — stored password differs from the submitted one
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['password']         = 'wrong-password';
        $_POST['confirmation']     = 'DELETE';
        $this->stubStoredPassword('the-real-password');

        // delete() must never run on a failed password check
        $this->queryBuilderMock->expects($this->never())->method('delete');

        // Act
        ob_start();
        $this->controller->deleteaccount();
        $echoed = ob_get_clean();

        // Assert
        $this->assertStringContainsString('error=invalid_password', $echoed);
    }

    /**
     * POST with the correct password but without typing the literal
     * confirmation word 'DELETE' must redirect with
     * error=confirmation_required — the second deletion safeguard.
     */
    public function testDeleteAccountPostWrongConfirmationRedirectsWithError(): void
    {
        // Arrange — correct password, wrong confirmation token
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['password']         = 'the-real-password';
        $_POST['confirmation']     = 'delete'; // must be exactly 'DELETE'
        $this->stubStoredPassword('the-real-password');

        $this->queryBuilderMock->expects($this->never())->method('delete');

        // Act
        ob_start();
        $this->controller->deleteaccount();
        $echoed = ob_get_clean();

        // Assert
        $this->assertStringContainsString('error=confirmation_required', $echoed);
    }

    /**
     * Fully confirmed POST must erase the user's data (one delete per GDPR
     * table + the users row = 7 deletes), log the user out, and redirect to
     * the site root with message=account_deleted.
     */
    public function testDeleteAccountPostSuccessErasesDataAndRedirects(): void
    {
        // Arrange — correct password and exact confirmation word
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['password']         = 'the-real-password';
        $_POST['confirmation']     = 'DELETE';
        $this->stubStoredPassword('the-real-password');

        // 6 GDPR-related tables + users = 7 delete() calls expected
        $this->queryBuilderMock->expects($this->exactly(7))
            ->method('delete')
            ->willReturn(1);

        // Act
        ob_start();
        $this->controller->deleteaccount();
        $echoed = ob_get_clean();

        // Assert — redirected to the site root with the success message
        $this->assertStringContainsString('message=account_deleted', $echoed);
        // logout() must have flipped the session flag
        $this->assertFalse($_SESSION['logged']);
    }

    /**
     * If the data-erasure step throws, the exception must be caught, logged,
     * and answered with a redirect to error=deletion_failed — never a white
     * page or a half-deleted account with an unhandled exception.
     */
    public function testDeleteAccountPostDeletionFailureRedirectsWithError(): void
    {
        // Arrange — delete() blows up mid-erasure
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['password']         = 'the-real-password';
        $_POST['confirmation']     = 'DELETE';
        $this->stubStoredPassword('the-real-password');

        $this->queryBuilderMock->method('delete')
            ->willThrowException(new \Exception('FK constraint boom'));

        // Act
        ob_start();
        $this->controller->deleteaccount();
        $echoed = ob_get_clean();

        // Assert — graceful failure path
        $this->assertStringContainsString('error=deletion_failed', $echoed);
    }
}
