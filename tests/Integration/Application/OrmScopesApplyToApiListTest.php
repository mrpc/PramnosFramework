<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\Model;
use Pramnos\Application\OrmModel;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;

/**
 * A global scope must hold on `_getApiList()`, not only on `_getList()`.
 *
 * `OrmModel::_getList()` has always applied the soft-delete filter and the
 * registered global scopes. `_getApiList()` did not: it delegates to `ApiListQuery`,
 * which never asked the source for conditions of its own. The two therefore
 * disagreed about what a model's rows *are*.
 *
 * That gap sits exactly where it does the most damage. `HasScopes` documents global
 * scopes with a tenant example — `addGlobalScope('tenant', fn($f) => "$f AND
 * tenant_id = " . Auth::tenantId())` — and `_getApiList()` is what the REST
 * endpoints, the generated CRUD controllers and the datatables all call. An
 * application that scoped its models the documented way got the scope on its admin
 * screens and one tenant's rows in another tenant's API responses. Soft-deleted
 * records came back through the same hole.
 *
 * So the assertions here are deliberately about a *scoped* model rather than about
 * the plumbing: the question a reader has is "does my tenant scope actually hold",
 * and the answer has to be visible in the rows that come back.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class OrmScopesApplyToApiListTest extends TestCase
{
    private Database $db;
    private Controller $controller;

    private const TABLE = 'pramnos_scope_apilist_probe';

    /** Whatever was the global Database singleton before this test replaced it. */
    private ?Database $previousSingleton = null;

    /** The connection and schema shared by every test in the class. */
    private static ?Database $sharedDb = null;

    public static function setUpBeforeClass(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . \DS . 'fixtures' . \DS . 'app');
        }

        Settings::loadSettings(
            ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php'
        );
        Application::getInstance();

        $settings = Settings::getSetting('database');
        if (!$settings) {
            return;
        }

        $db           = new Database();
        $db->type     = 'mysql';
        $db->server   = $settings->hostname;
        $db->user     = $settings->user;
        $db->password = $settings->password;
        $db->database = $settings->database;
        $db->port     = $settings->port ?? 3306;

        try {
            $db->connect(true);
        } catch (\Throwable) {
            return;
        }
        if (!$db->connected) {
            return;
        }

        $db->query('DROP TABLE IF EXISTS ' . self::TABLE);
        $db->query(
            'CREATE TABLE ' . self::TABLE . ' ('
            . 'id INT AUTO_INCREMENT PRIMARY KEY, '
            . 'label VARCHAR(100) NOT NULL, '
            . 'tenant_id INT NOT NULL DEFAULT 0, '
            . 'deleted_at DATETIME NULL'
            . ') ENGINE=InnoDB'
        );

        self::$sharedDb     = $db;
        Model::$columnCache = [];
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$sharedDb !== null) {
            self::$sharedDb->query('DROP TABLE IF EXISTS ' . self::TABLE);
            self::$sharedDb = null;
        }
        Model::$columnCache = [];
    }

    protected function setUp(): void
    {
        if (self::$sharedDb === null) {
            $this->markTestSkipped('MySQL container not reachable');
        }

        $this->db = self::$sharedDb;

        // The list paths use the singleton, not the controller's connection.
        $this->previousSingleton = Factory::getDatabase();
        $singleton               = &Factory::getDatabase();
        $singleton               = $this->db;

        $this->controller = $this->makeController();

        $this->db->query('DELETE FROM ' . self::TABLE);
        ScopedApiListItem::removeGlobalScope('tenant');
    }

    protected function tearDown(): void
    {
        // A global scope is registered per class and would otherwise leak into the
        // next test in this file, and into anything else that touches this model.
        ScopedApiListItem::removeGlobalScope('tenant');

        $singleton = &Factory::getDatabase();
        $singleton = $this->previousSingleton;
    }

    private function makeController(): Controller
    {
        /** @var Controller&\PHPUnit\Framework\MockObject\MockObject $ctrl */
        $ctrl = $this->getMockBuilder(Controller::class)
            ->disableOriginalConstructor()
            ->getMock();

        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database     = $this->db;
        $ctrl->application = $app;

        return $ctrl;
    }

    private function item(): ScopedApiListItem
    {
        return new ScopedApiListItem($this->controller);
    }

    /** Insert one row directly — the list is what is under test, not the save path. */
    private function seed(string $label, int $tenantId, ?string $deletedAt = null): void
    {
        $deleted = $deletedAt === null ? 'NULL' : "'" . $deletedAt . "'";
        $this->db->query(
            'INSERT INTO ' . self::TABLE . ' (label, tenant_id, deleted_at) '
            . "VALUES ('" . $this->db->prepareInput($label) . "', {$tenantId}, {$deleted})"
        );
    }

    /**
     * The labels an API list returned, sorted, so an assertion reads as a set.
     *
     * @param array<string, mixed> $response The `_getApiList()` envelope.
     * @return string[]
     */
    private function labelsFrom(array $response): array
    {
        $rows   = $response['data'] ?? [];
        $labels = [];
        foreach ($rows as $row) {
            $labels[] = (string) ($row['label'] ?? '');
        }
        sort($labels);

        return $labels;
    }

    // ── The regression ────────────────────────────────────────────────────────

    /**
     * THE regression: a tenant global scope must hold on the API list.
     *
     * Before the fix this returned every row in the table — the other tenant's
     * included — while `_getList()` on the same model, with the same scope
     * registered, returned only the two that belong.
     */
    public function testAGlobalScopeFiltersTheApiList(): void
    {
        // Arrange
        $this->seed('ours-one', 1);
        $this->seed('ours-two', 1);
        $this->seed('theirs', 2);

        ScopedApiListItem::addGlobalScope(
            'tenant',
            fn(string $filter): string => ($filter === '' ? '' : "({$filter}) AND ")
                . 'tenant_id = 1'
        );

        // Act
        $response = $this->item()->_getApiList(['id', 'label', 'tenant_id']);

        // Assert
        $this->assertSame(['ours-one', 'ours-two'], $this->labelsFrom($response));
    }

    /**
     * The scope must also govern the reported total, not only the page.
     *
     * A count that includes the other tenant's rows tells the caller there are more
     * pages, and paging into them returns nothing — a leak of *how many* records the
     * other tenant has, and a broken pager, from the same missing condition.
     */
    public function testAGlobalScopeFiltersThePaginatedTotal(): void
    {
        // Arrange
        $this->seed('ours-one', 1);
        $this->seed('theirs-one', 2);
        $this->seed('theirs-two', 2);

        ScopedApiListItem::addGlobalScope(
            'tenant',
            fn(string $filter): string => ($filter === '' ? '' : "({$filter}) AND ")
                . 'tenant_id = 1'
        );

        // Act — page 1 of a paginated request, which is the path that counts.
        $response = $this->item()->_getApiList(
            ['id', 'label'], '', '', '', '', '', null, null, 1, 10
        );

        // Assert
        $this->assertSame(['ours-one'], $this->labelsFrom($response));
        $this->assertSame(1, (int) ($response['pagination']['totalitems'] ?? -1));
    }

    /**
     * A caller's own filter and the scope both apply — the scope is not a default
     * that a filter replaces.
     *
     * Getting this wrong is worse than having no scope: an endpoint that accepts any
     * filter would let a caller drop the tenant condition by supplying one.
     */
    public function testACallerFilterIsCombinedWithTheScopeRatherThanReplacingIt(): void
    {
        // Arrange
        $this->seed('keep', 1);
        $this->seed('drop', 1);
        $this->seed('keep', 2);

        ScopedApiListItem::addGlobalScope(
            'tenant',
            fn(string $filter): string => ($filter === '' ? '' : "({$filter}) AND ")
                . 'tenant_id = 1'
        );

        // Act
        $response = $this->item()->_getApiList(
            ['id', 'label', 'tenant_id'], '', '', "label = 'keep'"
        );

        // Assert — one row: the label matches and the tenant matches.
        $this->assertSame(['keep'], $this->labelsFrom($response));
    }

    /**
     * Soft-deleted rows do not appear in the API list.
     *
     * The same gap, with the framework's own scope rather than an application's: a
     * record the application considers deleted was still served by every REST
     * endpoint.
     */
    public function testSoftDeletedRowsAreExcludedFromTheApiList(): void
    {
        // Arrange
        $this->seed('alive', 1);
        $this->seed('gone', 1, '2026-01-01 00:00:00');

        // Act
        $response = $this->item()->_getApiList(['id', 'label']);

        // Assert
        $this->assertSame(['alive'], $this->labelsFrom($response));
    }

    /**
     * With nothing registered, the list is unchanged.
     *
     * The BC half: a model with no scopes and no soft delete must behave exactly as
     * it did before the hook existed, or this fix would be a silent behaviour change
     * for every application that never asked for one.
     */
    public function testAModelWithNoScopesListsEverything(): void
    {
        // Arrange
        $this->seed('one', 1);
        $this->seed('two', 2);

        // Act — UnscopedApiListItem has softDelete off and no global scopes.
        $response = (new UnscopedApiListItem($this->controller))
            ->_getApiList(['id', 'label']);

        // Assert
        $this->assertSame(['one', 'two'], $this->labelsFrom($response));
    }

    /**
     * `_getList()` and `_getApiList()` agree.
     *
     * The property the whole change is about, asserted directly: the two list paths
     * of one model, with one scope registered, must describe the same set of rows.
     * Anything that makes them diverge again fails here regardless of which path it
     * touched.
     */
    public function testTheTwoListPathsAgree(): void
    {
        // Arrange
        $this->seed('ours', 1);
        $this->seed('theirs', 2);
        $this->seed('deleted', 1, '2026-01-01 00:00:00');

        $scope = fn(string $filter): string => ($filter === '' ? '' : "({$filter}) AND ")
            . 'tenant_id = 1';

        // Act — a fresh model per call: the scope helpers are one-shot per query.
        ScopedApiListItem::addGlobalScope('tenant', $scope);
        $fromApi = $this->labelsFrom($this->item()->_getApiList(['id', 'label']));

        ScopedApiListItem::addGlobalScope('tenant', $scope);
        $rows      = $this->item()->_getList();
        $fromList  = [];
        foreach ($rows as $row) {
            $fromList[] = (string) $row->label;
        }
        sort($fromList);

        // Assert
        $this->assertSame(['ours'], $fromApi);
        $this->assertSame($fromList, $fromApi);
    }

    /**
     * The datatables grand total is scoped too.
     *
     * `recordsTotal` is the count *before* the search box, and it is the one path
     * that reaches `_datatablesRecordsTotal()` — only when a search is active, since
     * without one it would equal the filtered count and the extra query is skipped.
     *
     * Unscoped, it is the number of rows in the whole table. A datatable then draws
     * a pager for every tenant's records, shows a footer counting them, and pages
     * into emptiness — the row count of another organisation, leaked through a
     * widget nobody thinks of as an endpoint.
     */
    public function testTheDatatablesGrandTotalIsScoped(): void
    {
        // Arrange
        $this->seed('ours-one', 1);
        $this->seed('ours-two', 1);
        $this->seed('theirs-one', 2);
        $this->seed('theirs-two', 2);
        $this->seed('theirs-three', 2);

        ScopedApiListItem::addGlobalScope(
            'tenant',
            fn(string $filter): string => ($filter === '' ? '' : "({$filter}) AND ")
                . 'tenant_id = 1'
        );

        // Act — a search narrows it further, which is what makes recordsTotal and
        // recordsFiltered differ and sends the code down the extra-count path.
        $response = $this->item()->_getApiList(
            ['id', 'label'], 'ours-one', '', '', '', '', null, null, 1, 10,
            false, false, false, false, false, 'datatables'
        );

        // Assert — two rows belong to this tenant, one of them matches the search.
        $this->assertSame(1, (int) ($response['recordsFiltered'] ?? -1));
        $this->assertSame(
            2,
            (int) ($response['recordsTotal'] ?? -1),
            'recordsTotal counted rows outside the scope.'
        );
    }
}

/**
 * A soft-deleting ORM model over the probe table, used to register a tenant scope.
 */
class ScopedApiListItem extends OrmModel
{
    protected $_dbtable    = 'pramnos_scope_apilist_probe';
    protected $_primaryKey = 'id';
    protected bool $softDelete = true;

    /** @var int|null */
    public $id = null;
    /** @var string */
    public $label = '';
    /** @var int */
    public $tenant_id = 0;
    /** @var string|null */
    public $deleted_at = null;
}

/**
 * The same table with nothing registered on it — the control for the BC assertion.
 *
 * A separate class rather than the same one with its scope removed, because global
 * scopes are keyed by class name and soft-delete is a property: sharing the class
 * would mean the control depended on the order tests ran in.
 */
class UnscopedApiListItem extends OrmModel
{
    protected $_dbtable    = 'pramnos_scope_apilist_probe';
    protected $_primaryKey = 'id';
    protected bool $softDelete = false;

    /** @var int|null */
    public $id = null;
    /** @var string */
    public $label = '';
    /** @var int */
    public $tenant_id = 0;
    /** @var string|null */
    public $deleted_at = null;
}
