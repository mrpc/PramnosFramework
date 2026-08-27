<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\OrganizationsController;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;
use Pramnos\Application\Settings;

class TestableOrganizationsController extends OrganizationsController
{
    protected function requireMinUserType(int $minType): bool
    {
        return false; // bypass for tests
    }

    protected function terminate(): void
    {
        // Do nothing in tests to avoid exit;
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
            public $organization = null;
            public function __construct($name) { 
                $this->name = $name;
            }
            public function display(string $layout = 'default', bool $return = false, bool $outputBuffer = true): mixed
            {
                $out = "";
                if ($layout === 'default' || $layout === 'list') {
                    $out = "dt-organizations";
                } elseif ($layout === 'edit') {
                    if (is_array($this->organization) && isset($this->organization['name'])) {
                        $out = $this->organization['name'];
                    } else {
                        $out = "Edit View";
                    }
                } elseif ($layout === 'members') {
                    $out = "testuser";
                }
                
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

class OrganizationsControllerIntegrationTest extends TestCase
{
    private TestableOrganizationsController $controller;
    private $dbMock;
    private $queryBuilderMock;
    private $originalDb;

    protected function setUp(): void
    {
        \Pramnos\Http\Session::getInstance();

        // Save original database reference
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $this->originalDb = clone $dbRef;

        // Mock Settings
        $settings = $this->createMock(\Pramnos\Application\Settings::class);
        $settings->method('getSetting')->willReturnMap([
            ['authserver_organization_table', false, 'authserver_user_organizations'],
            ['authserver_organization_column', false, 'organization_id']
        ]);

        // Mock QueryBuilder
        $this->queryBuilderMock = $this->createMock(QueryBuilder::class);
        $this->queryBuilderMock->method('table')->willReturnSelf();
        $this->queryBuilderMock->method('select')->willReturnSelf();
        $this->queryBuilderMock->method('from')->willReturnSelf();
        $this->queryBuilderMock->method('distinct')->willReturnSelf();
        $this->queryBuilderMock->method('joinRaw')->willReturnSelf();
        $this->queryBuilderMock->method('whereRaw')->willReturnSelf();
        $this->queryBuilderMock->method('upsert')->willReturn(true);
        $this->queryBuilderMock->method('where')->willReturnSelf();
        $this->queryBuilderMock->method('orderBy')->willReturnSelf();
        $this->queryBuilderMock->method('limit')->willReturnSelf();
        $this->queryBuilderMock->method('offset')->willReturnSelf();
        $this->queryBuilderMock->method('join')->willReturnSelf();

        // Mock Database
        $this->dbMock = $this->createMock(Database::class);
        $this->dbMock->method('queryBuilder')->willReturn($this->queryBuilderMock);

        // Inject Database via reference
        $dbRef = $this->dbMock;

        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->controller = new TestableOrganizationsController(null);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];

        // Restore original database
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $dbRef = $this->originalDb;
    }

    public function testDisplay()
    {
        ob_start();
        $this->controller->display();
        $echoed = ob_get_clean();

        $this->assertIsString($echoed);
        $this->assertStringContainsString('dt-organizations', $echoed);
    }

    public function testDataReturnsJson()
    {
        $mockResult = new class {
            public $numRows = 1;
            public $fields = ['organization_id' => 1, 'name' => 'Test Org', 'description' => '', 'org_type' => 'corp', 'is_active' => 1];
            private $fetched = false;
            public function fetch() {
                if (!$this->fetched) {
                    $this->fetched = true;
                    return true;
                }
                return false;
            }
        };
        
        $this->queryBuilderMock->method('get')->willReturn($mockResult);
        $this->queryBuilderMock->method('count')->willReturn(1);

        $response = $this->controller->data();
        $this->assertInstanceOf(\Pramnos\Http\Response::class, $response);
        $data = json_decode($response->getBody(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('aaData', $data);
        $this->assertCount(1, $data['aaData']);
        $this->assertStringContainsString('Test Org', $data['aaData'][0][1]);
    }

    public function testEditNew()
    {
        $_GET['_option'] = '0';
        ob_start();
        $this->controller->edit();
        $echoed = ob_get_clean();

        $this->assertIsString($echoed);
    }

    public function testEditExisting()
    {
        $_GET['_option'] = '99';
        
        $mockResult = new \stdClass();
        $mockResult->numRows = 1;
        $mockResult->fields = ['organization_id' => 99, 'name' => 'Edit Me', 'description' => '', 'org_type' => '', 'is_active' => 1];
        
        $this->queryBuilderMock->method('first')->willReturn($mockResult);

        ob_start();
        $this->controller->edit();
        $echoed = ob_get_clean();

        $this->assertIsString($echoed);
        $this->assertStringContainsString('Edit Me', $echoed);
    }

    public function testEditNotFound()
    {
        $_GET['_option'] = '999';
        
        $this->queryBuilderMock->method('first')->willReturn(false);

        ob_start();
        $this->controller->edit();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        // The message, not a query parameter: `?error=…` was in the URL and nothing read it.
        $this->assertContains(
            'That record no longer exists.',
            $_SESSION['_errors'] ?? []
        );
    }

    public function testSaveCreate()
    {
        $_POST['name'] = 'New Corp';
        $_POST['description'] = 'Desc';
        $_POST['org_type'] = 'NGO';
        $_POST['is_active'] = '1';
        $_POST['_csrf_token'] = \Pramnos\Http\Session::getInstance()->getCsrfToken();

        $this->queryBuilderMock->expects($this->once())->method('insert')->willReturn(true);

        ob_start();
        $this->controller->save();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
    }

    public function testSaveUpdate()
    {
        $_POST['organization_id'] = '1';
        $_POST['name'] = 'Updated Corp';
        $_POST['_csrf_token'] = \Pramnos\Http\Session::getInstance()->getCsrfToken();

        $this->queryBuilderMock->expects($this->once())->method('update')->willReturn(true);

        ob_start();
        $this->controller->save();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
    }

    public function testSaveMissingName()
    {
        $_POST['name'] = '';
        $_POST['_csrf_token'] = \Pramnos\Http\Session::getInstance()->getCsrfToken();

        ob_start();
        $this->controller->save();
        $echoed = ob_get_clean();

        // The message, not a query parameter: `?error=…` was in the URL and nothing read it.
        $this->assertContains(
            'A name is required.',
            $_SESSION['_errors'] ?? []
        );
    }

    public function testDelete()
    {
        $_GET['_option'] = '10';

        $this->queryBuilderMock->expects($this->once())->method('update')->willReturn(true);

        ob_start();
        $this->controller->delete();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
    }

    public function testMembers()
    {
        $_GET['_option'] = '5';
        
        $orgResult = new \stdClass();
        $orgResult->numRows = 1;
        $orgResult->fields = ['organization_id' => 5, 'name' => 'Org 5'];
        
        $userResult = clone $orgResult;
        $userResult->fields = ['userid' => 100, 'username' => 'testuser', 'granted_at' => '2023-01-01', 'granted_by' => 0];
        
        $this->queryBuilderMock->method('first')->willReturn($orgResult);
        $this->queryBuilderMock->method('getAll')->willReturn([$userResult]);

        ob_start();
        $this->controller->members();
        $echoed = ob_get_clean();

        $this->assertIsString($echoed);
        $this->assertStringContainsString('testuser', $echoed);
    }

    public function testMembersNotFound()
    {
        $_GET['_option'] = '999';
        $this->queryBuilderMock->method('first')->willReturn(false);

        ob_start();
        $this->controller->members();
        $echoed = ob_get_clean();

        // The message, not a query parameter: `?error=…` was in the URL and nothing read it.
        $this->assertContains(
            'That record no longer exists.',
            $_SESSION['_errors'] ?? []
        );
    }

    public function testAddMember()
    {
        $_POST['org_id'] = '5';
        $_POST['userid'] = '100';

        $this->queryBuilderMock->expects($this->once())->method('upsert')->willReturn(true);

        ob_start();
        $this->controller->addmember();
        $echoed = ob_get_clean();

        // The message, not a query parameter: `?message=…` was in the URL and nothing read it.
        $this->assertContains(
            'Added.',
            $_SESSION['_messages'] ?? []
        );
    }

    public function testRemoveMember()
    {
        $row = new \stdClass();
        $row->numRows = 1;
        $row->fields = ['userid' => 100, 'organization_id' => 5, 'is_active' => 1];
        $this->queryBuilderMock->method('first')->willReturn($row);
        $this->queryBuilderMock->expects($this->once())->method('update')->willReturn(true);

        ob_start();
        $this->controller->removemember(5, 100);
        $echoed = ob_get_clean();

        // The message, not a query parameter: `?message=…` was in the URL and nothing read it.
        $this->assertContains(
            'Removed.',
            $_SESSION['_messages'] ?? []
        );
    }

    /**
     * Adding a member works when the organization is only in the URL.
     *
     * Which is the only way the screen sends it: the form posts to
     * `organizations/addmember/{id}` and carries just `userid`. The action used to
     * read the id from its own route argument, and the classic dispatcher passes
     * the arguments **array** — so `(int)` of it was never the id, `$_POST`
     * carried no `org_id`, and adding a member to an organization was simply
     * impossible. The screen answered "No valid entries were selected" and
     * redirected to `organizations/0/members`.
     */
    public function testAddMemberTakesTheOrganizationFromTheUrl()
    {
        // Arrange — a URL option, no org_id in the body
        $_GET['_option'] = '5';
        $_POST['userid'] = '100';

        // Assert — the membership is written
        $this->queryBuilderMock->expects($this->once())->method('upsert')->willReturn(true);

        // Act
        ob_start();
        $this->controller->addmember();
        ob_get_clean();

        $this->assertContains('Added.', $_SESSION['_messages'] ?? []);
    }

    /**
     * Removing a member works when the ids arrive as the link supplies them.
     *
     * `removemember/{orgId}?userid={userId}` — the organization as the URL
     * option, the user as a query parameter. Two path segments cannot carry both:
     * the framework's URL parser turns `action/a/b` into `$_GET['a'] = 'b'`
     * rather than into two options, so the second id never arrived at all.
     *
     * And the user id must NOT be read from the URL option, which is the
     * organization segment — resolving both the same way made them equal, the
     * update matched nothing, and the screen still said "Removed."
     */
    public function testRemoveMemberTakesTheUserFromTheQueryString()
    {
        // Arrange
        $_GET['_option'] = '5';
        $_GET['userid'] = '100';
        $row = new \stdClass();
        $row->numRows = 1;
        $row->fields = ['userid' => 100, 'organization_id' => 5, 'is_active' => 1];
        $this->queryBuilderMock->method('first')->willReturn($row);

        // Assert
        $this->queryBuilderMock->expects($this->once())->method('update')->willReturn(true);

        // Act — no arguments, as the dispatcher calls it
        ob_start();
        $this->controller->removemember();
        ob_get_clean();

        $this->assertContains('Removed.', $_SESSION['_messages'] ?? []);
    }

    /**
     * Removing somebody who is not a member says so, and changes nothing.
     *
     * A second click, a back button, a link bookmarked before somebody else
     * removed them — all of those matched no row and still reported "Removed.",
     * which is the report an operator would act on.
     */
    public function testRemovingSomebodyWhoIsNotAMemberSaysSo()
    {
        // Arrange — no membership row
        $_GET['_option'] = '5';
        $_GET['userid'] = '100';
        $this->queryBuilderMock->method('first')->willReturn(false);

        // Assert — nothing is written
        $this->queryBuilderMock->expects($this->never())->method('update');

        // Act
        ob_start();
        $this->controller->removemember();
        ob_get_clean();

        $this->assertContains(
            'That person is not a member of this organization.',
            $_SESSION['_errors'] ?? []
        );
    }
}
