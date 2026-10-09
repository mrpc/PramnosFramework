<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\CapabilitiesSyncService;
use Pramnos\Auth\Controllers\PermissionsController;
use Pramnos\Auth\Controllers\RolesController;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\AuthServer\CreateClientCapabilitiesTables;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Framework\Testing\Schema;

/**
 * A grant for an application speaks that application's declared vocabulary.
 *
 * An application declares its resources, the scopes on each and its condition keys. The
 * permission form took Object Type and Action as free text, so `invoice` for `invoices`, or
 * `View invoices` for `read`, was stored and matched nothing, without a word. Now the form offers
 * the declared names, the save refuses an undeclared one, and the lists mark a grant whose
 * resource or scope the application stopped declaring — the answer to "why did this stop working".
 *
 * A grant with no application, or for one that declared nothing, stays free text: nothing that
 * saved before is refused now.
 */
#[CoversClass(CapabilitiesSyncService::class)]
#[CoversClass(PermissionsController::class)]
#[CoversClass(RolesController::class)]
class PermissionGrantVocabularyTest extends BaseTestCase
{
    /** The application that declared its vocabulary. */
    private const DECLARED = 990301;

    /** An application that declared nothing. */
    private const SILENT = 990302;

    private \Pramnos\Database\Database $db;

    private ?\Pramnos\Database\Database $previous = null;

    /** Whether setUp() built the permissions table, and so tearDown() drops it. */
    private bool $builtPermissions = false;

    /** The engine under test: the suite's own connection here, PostgreSQL in the subclass. */
    protected function connection(): \Pramnos\Database\Database
    {
        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }

        return $db;
    }

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        // The controllers read Factory's connection, so this class's engine is put there.
        $this->previous = Factory::getDatabase();
        $this->db       = $this->connection();
        $singleton      = &Factory::getDatabase();
        $singleton      = $this->db;

        // Dropped again afterwards when built here: another class keeps its own copy of the table
        // when there is none, and finding this one instead changes what it can insert.
        $this->builtPermissions = !$this->db->schema()->hasTable('authserver.permissions');
        Schema::table('authserver.permissions', $this->db);
        $this->capabilitiesMigration()->down();
        $this->capabilitiesMigration()->up();
        $this->db->queryBuilder()->table('authserver.permissions')->where('subject_id', 4401)->delete();

        // Declared, then redeclared without `archive` and without `invoices.delete`: both soft-deleted.
        $service = new CapabilitiesSyncService($this->db);
        $service->sync(self::DECLARED, [
            'resources'  => [
                'invoices' => ['scopes' => ['read' => 'View', 'write' => 'Edit', 'delete' => 'Remove']],
                'archive'  => ['scopes' => ['read' => 'View']],
            ],
            'conditions' => ['location_id' => ['value_type' => 'int[]'], 'legacy' => ['value_type' => 'string']],
        ]);
        $service->sync(self::DECLARED, [
            'resources'  => ['invoices' => ['scopes' => ['read' => 'View', 'write' => 'Edit']]],
            'conditions' => ['location_id' => ['value_type' => 'int[]']],
        ]);

        $_POST = [];
        $_GET  = [];
        \Pramnos\Http\Request::resetInstance();
    }

    protected function tearDown(): void
    {
        $this->db->queryBuilder()->table('authserver.permissions')->where('subject_id', 4401)->delete();
        $this->capabilitiesMigration()->down();
        if ($this->builtPermissions) {
            $this->db->schema()->dropTableIfExists('authserver.permissions');
        }
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        $_POST = [];
        $_GET  = [];
        \Pramnos\Http\Request::resetInstance();
    }

    /** The capabilities tables' own migration. */
    private function capabilitiesMigration(): CreateClientCapabilitiesTables
    {
        $app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $app->database = $this->db;

        return new CreateClientCapabilitiesTables($app);
    }

    /** A grant to user 4401. Returns its id. */
    private function grant(?int $appId, string $objectType, string $action): int
    {
        $this->db->queryBuilder()->table('authserver.permissions')->insert([
            'subject_type' => 'user', 'subject_id' => 4401, 'object_type' => $objectType,
            'action' => $action, 'grant_type' => 'allow', 'priority' => 100, 'app_id' => $appId,
        ]);

        return (int) $this->db->queryBuilder()->table('authserver.permissions')
            ->where('subject_id', 4401)->where('object_type', $objectType)->where('action', $action)
            ->value('permissionid');
    }

    /**
     * A controller with the gate, the redirect, the flash and the view replaced; every query runs.
     *
     * @param class-string $class
     */
    private function controller(string $class): object
    {
        $recorder = new class {
            public ?object $view = null;
            public array $redirects = [];
            public array $errors = [];
        };
        return $class === PermissionsController::class
            ? new class ($recorder) extends PermissionsController {
                use RecordsScreen;
            }
            : new class ($recorder) extends RolesController {
                use RecordsScreen;
            };
    }

    /** The posted form of a new user grant. */
    private function post(string $appId, string $objectType, string $action): void
    {
        $_POST = [
            'subject_type' => 'user', 'subject_id' => '4401', 'object_type' => $objectType,
            'action' => $action, 'grant_type' => 'allow', 'priority' => '100', 'app_id' => $appId,
            'conditions' => '', 'expires_at' => '',
        ];
    }

    /** How many grants user 4401 holds. */
    private function grants(): int
    {
        return $this->db->queryBuilder()->table('authserver.permissions')->where('subject_id', 4401)->count();
    }

    /**
     * The catalog holds only applications that declared resources, inactive names included and flagged.
     *
     * Keeping the inactive ones is what lets a stale grant be told apart from a typo.
     */
    public function testTheCatalogKeepsWhatWasDeclaredAndFlagsWhatWasWithdrawn(): void
    {
        // Act
        $catalog = (new CapabilitiesSyncService($this->db))->catalog();

        // Assert
        $this->assertSame([self::DECLARED], array_keys($catalog));
        $resources = $catalog[self::DECLARED]['resources'];
        $this->assertTrue($resources['invoices']['active']);
        $this->assertFalse($resources['archive']['active']);
        $this->assertSame(['delete' => false, 'read' => true, 'write' => true], $resources['invoices']['scopes']);
        $this->assertSame(
            ['legacy' => ['value_type' => 'string', 'active' => false], 'location_id' => ['value_type' => 'int[]', 'active' => true]],
            $catalog[self::DECLARED]['conditions']
        );
    }

    /**
     * Without the capabilities tables there is nothing declared, and no screen breaks.
     */
    public function testWithoutTheTablesTheCatalogIsEmpty(): void
    {
        // Arrange
        $this->capabilitiesMigration()->down();

        // Act + Assert
        $this->assertSame([], (new CapabilitiesSyncService($this->db))->catalog());
    }

    /**
     * The form's vocabulary is the active part only: what may be granted now.
     */
    public function testTheVocabularyIsWhatIsDeclaredNow(): void
    {
        // Act
        $vocabulary = CapabilitiesSyncService::vocabulary((new CapabilitiesSyncService($this->db))->catalog());

        // Assert
        $this->assertSame(
            [self::DECLARED => ['resources' => ['invoices' => ['read', 'write']], 'conditions' => ['location_id' => 'int[]']]],
            $vocabulary
        );
    }

    /**
     * Each way a grant can miss its application's vocabulary has its own reason; a matching one has none.
     */
    public function testEachMismatchIsNamed(): void
    {
        // Arrange
        $catalog = (new CapabilitiesSyncService($this->db))->catalog();
        $check   = static fn (int $app, string $object, string $action): ?string
            => CapabilitiesSyncService::problemWith($catalog, $app, $object, $action);

        // Assert — free text where nothing is declared
        $this->assertNull($check(0, 'anything', 'goes'), 'a grant for every application');
        $this->assertNull($check(self::SILENT, 'anything', 'goes'), 'an application that declared nothing');
        // Matches
        $this->assertNull($check(self::DECLARED, 'invoices', 'read'));
        $this->assertNull($check(self::DECLARED, 'invoices', '*'), '* is every action');
        // Misses
        $this->assertStringContainsString('declares no resource "invoice"', (string) $check(self::DECLARED, 'invoice', 'read'));
        $this->assertStringContainsString('no longer declares the resource "archive"', (string) $check(self::DECLARED, 'archive', 'read'));
        $this->assertStringContainsString('"View invoices" is not an action', (string) $check(self::DECLARED, 'invoices', 'View invoices'));
        $this->assertStringContainsString('no longer declares the action "delete"', (string) $check(self::DECLARED, 'invoices', 'delete'));
    }

    /**
     * A typo against a declared application is refused with the reason, and nothing is stored.
     */
    public function testASaveOutsideTheDeclaredVocabularyIsRefused(): void
    {
        // Arrange
        $controller = $this->controller(PermissionsController::class);
        $this->post((string) self::DECLARED, 'invoice', 'read');

        // Act
        $controller->save();

        // Assert
        $this->assertSame(0, $this->grants(), 'the typo was stored');
        $this->assertStringContainsString('declares no resource "invoice"', $controller->recorder->errors[0] ?? '');
        $this->assertStringContainsString('permissions/edit/0', $controller->recorder->redirects[0] ?? '');
    }

    /**
     * A declared name saves; so does free text with no application, or for one that declared nothing.
     */
    public function testWhatMatchesOrIsUndeclaredStillSaves(): void
    {
        // Arrange
        $controller = $this->controller(PermissionsController::class);

        // Act
        foreach ([[(string) self::DECLARED, 'invoices', 'write'], ['', 'reports', 'view'], [(string) self::SILENT, 'reports', 'export']] as [$app, $object, $action]) {
            $this->post($app, $object, $action);
            $controller->save();
        }

        // Assert
        $this->assertSame([], $controller->recorder->errors);
        $this->assertSame(3, $this->grants());
    }

    /**
     * The edit form gets the vocabulary its script offers.
     */
    public function testTheFormIsGivenTheVocabulary(): void
    {
        // Arrange
        $controller = $this->controller(PermissionsController::class);
        $_GET['_option'] = '0';
        \Pramnos\Http\Request::resetInstance();

        // Act
        $controller->edit();

        // Assert
        $this->assertSame(['read', 'write'], $controller->recorder->view->vocabulary[self::DECLARED]['resources']['invoices']);
    }

    /**
     * The permissions list marks the grants that can no longer match, and only those.
     */
    public function testTheListMarksGrantsThatStoppedMatching(): void
    {
        // Arrange
        $stale  = $this->grant(self::DECLARED, 'archive', 'read');
        $scope  = $this->grant(self::DECLARED, 'invoices', 'delete');
        $fine   = $this->grant(self::DECLARED, 'invoices', 'read');
        $global = $this->grant(null, 'reports', 'view');
        $controller = $this->controller(PermissionsController::class);
        $_GET['subject_id'] = '4401';
        \Pramnos\Http\Request::resetInstance();

        // Act
        $controller->display();

        // Assert
        $problems = $controller->recorder->view->problems;
        $this->assertSame([$stale, $scope], array_keys($problems));
        $this->assertArrayNotHasKey($fine, $problems);
        $this->assertArrayNotHasKey($global, $problems);
    }

    /**
     * A role's page marks its stale grants the same way.
     */
    public function testTheRolePageMarksItsStaleGrants(): void
    {
        // Arrange — a role grant to a withdrawn resource; the role row itself is not needed to list it
        $this->db->queryBuilder()->table('authserver.permissions')->insert([
            'subject_type' => 'role', 'subject_id' => 4401, 'object_type' => 'archive',
            'action' => 'read', 'grant_type' => 'allow', 'priority' => 100, 'app_id' => self::DECLARED,
        ]);
        $controller = $this->controller(RolesController::class);
        $method     = new \ReflectionMethod(RolesController::class, 'permissionsOfRole');

        // Act
        $rows     = $method->invoke($controller, 4401);
        $problems = CapabilitiesSyncService::problemsIn((new CapabilitiesSyncService($this->db))->catalog(), $rows);

        // Assert
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('no longer declares the resource "archive"', (string) reset($problems));
    }
}

/**
 * The parts of a screen a test replaces: the gate, the redirect, the flash, the view.
 */
trait RecordsScreen
{
    /** @param object $recorder Collects what the action decided */
    public function __construct(public object $recorder)
    {
        $this->application    = Application::getInstance();
        $this->controllerName = 'Test';
    }

    /** Always admitted. */
    protected function requireMinUserType(int $minType): bool
    {
        return false;
    }

    /** Recorded, not sent. */
    public function redirect($url = null, $quit = true, $code = '302')
    {
        $this->recorder->redirects[] = (string) $url;
    }

    /** Recorded, not flashed. */
    protected function addError($error)
    {
        $this->recorder->errors[] = (string) $error;

        return $this;
    }

    /** Not flashed. */
    protected function addMessage($message)
    {
        return $this;
    }

    /** No webhook to queue in a test. */
    protected function emitPermissionsChanged(string $subjectType, int $subjectId, array $context): void
    {
    }

    /** A view that records what was assigned to it. */
    public function &getView($name = '', $type = '', $args = [])
    {
        $this->recorder->view = new class {
            public array $assigned = [];

            public function __set($key, $value)
            {
                $this->assigned[$key] = $value;
            }

            public function __get($key)
            {
                return $this->assigned[$key] ?? null;
            }

            public function display($layout = '')
            {
                return 'rendered';
            }
        };

        return $this->recorder->view;
    }
}
