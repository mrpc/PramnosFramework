<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\OrganizationAdmin;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * Somebody who manages one organisation, and nothing beyond it — against real tables.
 *
 * msdauthserver asked for an organisation administrator: members, invitations into it, its own
 * roles, never more than the manager holds, outside `/admin`. The owner chose a permission
 * (`organization` / `manage` / its id) rather than a flag, assigning existing roles only, and
 * adding by address — a member at once for an account, an invitation otherwise.
 */
#[CoversClass(OrganizationAdmin::class)]
#[CoversClass(\Pramnos\Application\Controllers\Organization::class)]
class OrganizationAdminTest extends BaseTestCase
{
    private const ACTOR    = 8801;
    private const MEMBER   = 8802;
    private const OUTSIDER = 8803;
    private const ORG      = 51;
    private const OTHER    = 52;

    protected $db;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();
        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if ($this->db->type === 'postgresql') {
            $this->markTestSkipped('Runs on MySQL; the query builder abstracts the dialect.');
        }

        // Rebuilt rather than trusted: other suites create the membership table with DDL of their
        // own and leave it behind, and a migration is a no-op when the table exists.
        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        $this->db->schema()->dropTableIfExists('authserver.user_organizations');
        $this->db->query('SET FOREIGN_KEY_CHECKS=1');
        $this->runMigrations([
            \Pramnos\Framework\Migrations\Auth\CreateUsersTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateOrganizationsTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverRolesTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverUserRolesTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverUserOrganizationsTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverPermissionsTable::class,
            \Pramnos\Framework\Migrations\AuthServer\AddAudienceAndConditionsToPermissions::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverInvitationsTable::class,
        ], $this->db);
        $this->clean();

        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach ([self::ACTOR => 'actor', self::MEMBER => 'member', self::OUTSIDER => 'outsider'] as $id => $name) {
            $this->db->queryBuilder()->table('#PREFIX#users')->insert([
                'userid' => $id, 'username' => 'oa-' . $name, 'email' => 'oa-' . $name . '@example.test',
                'active' => 1, 'usertype' => 0, 'sex' => 0, 'birthdate' => 0, 'modified' => time(), 'regdate' => time(),
            ]);
        }
        foreach ([self::ORG => 'Acme', self::OTHER => 'Other'] as $id => $name) {
            $this->db->queryBuilder()->table('organizations')->insert(['organization_id' => $id, 'name' => $name, 'is_active' => 1]);
        }
        $this->db->queryBuilder()->table('authserver.user_organizations')->insert(['userid' => self::MEMBER, 'organization_id' => self::ORG, 'is_active' => 1]);
        $this->db->query('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function tearDown(): void
    {
        $this->clean();
        $_POST = [];
        $_GET  = [];
        unset($_SERVER['HTTP_ACCEPT']);

        // As the other suites here leave it: the fixture's settings and no parked connection.
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        // Constructing the real controller builds the application, which points Settings at this
        // connection for writes; the next suite expects it not to be.
        (new \ReflectionProperty(Settings::class, 'database'))->setValue(null, null);
        parent::tearDown();
    }

    private function clean(): void
    {

        $users = [self::ACTOR, self::MEMBER, self::OUTSIDER];
        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        $this->db->queryBuilder()->table('authserver.permissions')->whereIn('subject_id', array_merge($users, [9901, 9902, 9903]))->delete();
        $this->db->queryBuilder()->table('authserver.user_roles')->whereIn('userid', $users)->delete();
        $this->db->queryBuilder()->table('authserver.user_organizations')->whereIn('userid', $users)->delete();
        $this->db->queryBuilder()->table('authserver.roles')->whereIn('roleid', [9901, 9902, 9903])->delete();
        $this->db->queryBuilder()->table('authserver.invitations')->where('email', 'oa-new@example.test')->delete();
        $this->db->queryBuilder()->table('organizations')->whereIn('organization_id', [self::ORG, self::OTHER])->delete();
        $this->db->queryBuilder()->table('#PREFIX#users')->whereIn('userid', $users)->delete();
        $this->db->query('SET FOREIGN_KEY_CHECKS=1');
    }

    private function user(int $id, int $usertype = 0): object
    {
        return (object) ['userid' => $id, 'usertype' => $usertype];
    }

    private function allow(string $subjectType, int $subjectId, string $objectType, string $objectId, string $action, string $grant = 'allow'): void
    {
        $this->db->queryBuilder()->table('authserver.permissions')->insert([
            'subject_type' => $subjectType, 'subject_id' => $subjectId, 'object_type' => $objectType,
            'object_id' => $objectId, 'action' => $action, 'grant_type' => $grant, 'priority' => 100, 'is_active' => 1,
        ]);
    }

    private function role(int $id, int $orgId): void
    {
        $this->db->queryBuilder()->table('authserver.roles')->insert(['roleid' => $id, 'role_name' => 'role-' . $id, 'organization_id' => $orgId, 'is_active' => 1]);
    }

    /**
     * A manager is whoever is allowed `manage` on the organisation — directly or through one of its
     * roles — and a deny takes it away. Another organisation is not managed.
     */
    public function testWhoManages(): void
    {
        // Arrange — the actor is allowed on ORG, and nobody is on OTHER
        $this->allow('user', self::ACTOR, 'organization', (string) self::ORG, 'manage');

        // Act & Assert
        $this->assertTrue(OrganizationAdmin::manages($this->user(self::ACTOR), self::ORG));
        $this->assertFalse(OrganizationAdmin::manages($this->user(self::ACTOR), self::OTHER), 'one organisation, not every one');
        $this->assertFalse(OrganizationAdmin::manages($this->user(self::OUTSIDER), self::ORG));
        $this->assertTrue(OrganizationAdmin::manages($this->user(self::OUTSIDER, 98), self::OTHER), 'a superuser manages all');
        $this->assertSame([self::ORG => 'Acme'], OrganizationAdmin::managedBy($this->user(self::ACTOR)));

        // Arrange — through a role of the organisation, held by a member
        $this->role(9901, self::ORG);
        $this->allow('role', 9901, 'organization', (string) self::ORG, 'manage');
        $this->db->queryBuilder()->table('authserver.user_roles')->insert(['userid' => self::MEMBER, 'roleid' => 9901, 'is_active' => 1]);

        // Assert
        $this->assertTrue(OrganizationAdmin::manages($this->user(self::MEMBER), self::ORG), 'through the organisation\'s role');

        // Arrange — a deny on the member directly, at a higher priority
        $this->db->queryBuilder()->table('authserver.permissions')->insert([
            'subject_type' => 'user', 'subject_id' => self::MEMBER, 'object_type' => 'organization',
            'object_id' => (string) self::ORG, 'action' => 'manage', 'grant_type' => 'deny', 'priority' => 200, 'is_active' => 1,
        ]);

        // Assert
        $this->assertFalse(OrganizationAdmin::manages($this->user(self::MEMBER), self::ORG), 'a deny decides');
    }

    /**
     * Adding by address: an account becomes a member at once, an unknown address gets an
     * invitation into the organisation; removing ends the membership.
     */
    public function testAddingAndRemoving(): void
    {
        // Arrange
        $admin = new OrganizationAdmin(self::ACTOR);

        // Act
        $added   = $admin->add(self::ORG, 'OA-OUTSIDER@example.test');
        $invited = $admin->add(self::ORG, 'oa-new@example.test');

        // Assert
        $this->assertSame('added', $added);
        $this->assertSame('invited', $invited);
        $this->assertContains(self::OUTSIDER, array_column($admin->members(self::ORG), 'userid'));
        $invitation = $admin->invitations(self::ORG)[0];
        $this->assertSame('oa-new@example.test', $invitation['email']);
        $this->assertSame(self::ORG, (int) $invitation['organization_id']);

        // Act & Assert — removing, withdrawing, and the refusals
        $this->assertTrue($admin->remove(self::ORG, self::OUTSIDER));
        $this->assertFalse($admin->remove(self::ORG, self::OUTSIDER), 'not a member any more');
        $this->assertTrue($admin->withdraw(self::ORG, (int) $invitation['invitation_id']));
        $this->assertFalse($admin->withdraw(self::OTHER, (int) $invitation['invitation_id']), 'another organisation\'s invitation');
        $this->expectException(\InvalidArgumentException::class);
        $admin->add(self::ORG, 'not an address');
    }

    /**
     * Only the organisation's own roles can be given, and only one that allows nothing the manager
     * is not allowed themselves.
     */
    public function testNeverMoreThanTheyHold(): void
    {
        // Arrange — the actor may read reports; one role allows that, one allows deleting them
        $this->allow('user', self::ACTOR, 'reports', '*', 'read');
        $this->role(9902, self::ORG);
        $this->allow('role', 9902, 'reports', '*', 'read');
        $this->role(9903, self::ORG);
        $this->allow('role', 9903, 'reports', '*', 'delete');
        $admin = new OrganizationAdmin(self::ACTOR);

        // Act
        $roles = array_column($admin->roles(self::ORG), 'grantable', 'roleid');
        $admin->giveRole(self::ORG, 9902, self::MEMBER);
        $heldAfterGive = array_column($admin->members(self::ORG)[0]['roles'], 'roleid');
        $admin->takeRole(self::ORG, 9902, self::MEMBER);

        // Assert
        $this->assertSame([9902 => true, 9903 => false], $roles);
        $this->assertSame([9902], $heldAfterGive);
        $this->assertSame([], $admin->members(self::ORG)[0]['roles'], 'taken away');
        $this->assertFalse($admin->mayGive(9902, self::OTHER), 'not another organisation\'s');
        $this->assertTrue((new OrganizationAdmin(self::OUTSIDER))->mayGive(9903, self::ORG) === false);

        $this->expectException(\InvalidArgumentException::class);
        $admin->giveRole(self::ORG, 9903, self::MEMBER);
    }

    /**
     * The screen answers JSON for a script, refuses somebody who does not manage the organisation,
     * and a write goes through the service.
     */
    public function testTheScreenAnswersJsonAndRefusesAnOutsider(): void
    {
        // Arrange
        $this->allow('user', self::ACTOR, 'organization', (string) self::ORG, 'manage');
        $screen = fn (int $userId) => new class ($userId) extends \Pramnos\Application\Controllers\Organization {
            public function __construct(private int $as)
            {
            }

            protected function currentUser(): ?object
            {
                return (object) ['userid' => $this->as, 'usertype' => 0];
            }
        };
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_GET['_option'] = (string) self::ORG;

        // Act
        $list    = $screen(self::ACTOR)->display();
        $view    = $screen(self::ACTOR)->view();
        $refused = $screen(self::OUTSIDER)->view();
        $_POST   = ['email' => 'oa-outsider@example.test'];
        $added   = $screen(self::ACTOR)->add();
        $_POST   = ['email' => 'nonsense'];
        $bad     = $screen(self::ACTOR)->add();

        // Assert
        $this->assertSame([['organization_id' => self::ORG, 'name' => 'Acme']], json_decode($list->getBody(), true)['organizations']);
        $this->assertSame([self::MEMBER], array_column(json_decode($view->getBody(), true)['members'], 'userid'));
        $this->assertSame(403, $refused->getStatusCode());
        $this->assertTrue(json_decode($added->getBody(), true)['ok']);
        $this->assertSame(422, $bad->getStatusCode());
    }

    /** The screen as a person uses it: HTML, messages and redirects captured. */
    private function htmlScreen(int $userId): object
    {
        return new class ($userId) extends \Pramnos\Application\Controllers\Organization {
            public array $redirects = [];
            public array $messages = [];
            public array $errors = [];
            public ?object $shown = null;

            public function __construct(private int $as)
            {
            }

            protected function currentUser(): ?object
            {
                return $this->as > 0 ? (object) ['userid' => $this->as, 'usertype' => 0] : null;
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirects[] = (string) $url;
            }

            protected function addMessage($message)
            {
                $this->messages[] = (string) $message;

                return $this;
            }

            protected function addError($error)
            {
                $this->errors[] = (string) $error;

                return $this;
            }

            public function &getView($name = '', $type = '', $args = [])
            {
                $owner = $this;
                $view = new class ($owner) extends \stdClass {
                    public function __construct(private $owner)
                    {
                    }

                    public function display($layout = '')
                    {
                        $this->owner->shown = (object) (get_object_vars($this) + ['layout' => $layout]);

                        return 'shown';
                    }
                };

                return $view;
            }
        };
    }

    /**
     * Every action as a person uses it: the pages, each write with its message and the way back,
     * and the refusals — a stranger, nobody signed in, and an action the service turns down.
     */
    public function testEveryActionAsAPersonUsesIt(): void
    {
        // Arrange
        $this->allow('user', self::ACTOR, 'organization', (string) self::ORG, 'manage');
        $this->allow('user', self::ACTOR, 'reports', '*', 'read');
        $this->role(9902, self::ORG);
        $this->allow('role', 9902, 'reports', '*', 'read');
        $_GET['_option'] = (string) self::ORG;
        $screen = $this->htmlScreen(self::ACTOR);

        // Act — the pages
        $screen->display();
        $list = $screen->shown;
        $screen->view();
        $page = $screen->shown;

        // Act — the writes
        $_POST = ['email' => 'oa-outsider@example.test', 'roleid' => '9902'];
        $screen->add();
        $_POST = ['email' => 'oa-new@example.test'];
        $screen->add();
        $_POST = ['userid' => (string) self::MEMBER, 'roleid' => '9902'];
        $screen->giverole();
        $screen->takerole();
        $_POST = ['userid' => (string) self::OUTSIDER];
        $screen->remove();
        $screen->remove();
        $invitation = (new OrganizationAdmin(self::ACTOR))->invitations(self::ORG)[0]['invitation_id'];
        $_POST = ['invitation' => (string) $invitation];
        $screen->withdraw();
        $screen->withdraw();

        // Assert
        $this->assertSame([self::ORG => 'Acme'], $list->organizations);
        $this->assertSame('view', $page->layout);
        $this->assertSame('Acme', $page->name);
        $this->assertSame([
            'Added to the organisation.', 'No account has that address; an invitation was sent.',
            'Role given.', 'Role taken away.', 'Removed from the organisation.', 'Invitation withdrawn.',
        ], $screen->messages);
        $this->assertSame(['That person is not a member.', 'That invitation is not waiting.'], $screen->errors);
        $this->assertStringEndsWith('organization/view/' . self::ORG, end($screen->redirects));

        // Act & Assert — a stranger, and nobody
        $stranger = $this->htmlScreen(self::OUTSIDER);
        $stranger->view();
        $this->assertSame(['You do not manage that organisation.'], $stranger->errors);
        $this->assertStringEndsWith('organization', $stranger->redirects[0]);
        $nobody = $this->htmlScreen(0);
        $nobody->display();
        $nobody->view();
        $this->assertSame(['Sign in first.', 'Sign in first.'], $nobody->errors);
    }

    /**
     * The service's edges: nobody manages without an id, members and roles of an empty or
     * missing organisation, and a superuser may give any of the organisation's roles.
     */
    public function testTheEdges(): void
    {
        // Arrange
        $this->role(9903, self::ORG);
        $this->allow('role', 9903, 'reports', '*', 'delete');
        $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', self::ACTOR)->update(['usertype' => 98]);
        $admin = new OrganizationAdmin(self::ACTOR);

        // Act & Assert
        $this->assertFalse(OrganizationAdmin::manages(null, self::ORG));
        $this->assertFalse(OrganizationAdmin::manages($this->user(self::ACTOR), 0));
        $this->assertSame([], $admin->members(self::OTHER), 'nobody in it');
        $this->assertSame([], $admin->roles(self::OTHER), 'no roles of its own');
        $this->assertTrue($admin->mayGive(9903, self::ORG), 'a superuser gives what they like of the organisation\'s');
        $this->assertFalse($admin->mayGive(424242, self::ORG), 'no such role');
        $this->expectException(\InvalidArgumentException::class);
        $admin->giveRole(self::ORG, 9903, self::OUTSIDER);   // not a member: Role::assignTo refuses
    }

    /**
     * The real controller: its writes are declared, nobody signed in is nobody, and a script's
     * `X-Requested-With` is enough to be answered JSON.
     */
    public function testTheRealControllersSeams(): void
    {
        // Arrange
        $controller = new \Pramnos\Application\Controllers\Organization(null);
        $call = fn (string $method) => (new \ReflectionMethod($controller, $method))->invoke($controller);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        try {
            // Act & Assert
            $this->assertSame(['add', 'remove', 'giverole', 'takerole', 'withdraw'],
                (new \ReflectionProperty($controller, 'writeActions'))->getValue($controller));
            $this->assertNull($call('currentUser'), 'nobody signed in in this test');
            $this->assertInstanceOf(OrganizationAdmin::class, $call('admin'));
            $this->assertTrue($call('wantsJson'));
        } finally {
            unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        }
    }

    /**
     * The screens render in every theme, with a form per action and the token in each.
     */
    public function testTheScreensRenderInEveryTheme(): void
    {
        foreach (['bootstrap', 'tailwind', 'plain-css'] as $theme) {
            // Arrange
            $data = new \stdClass();
            $data->organization_id = self::ORG;
            $data->name = 'Acme';
            $data->members = [['userid' => 7, 'username' => 'u', 'email' => 'u@example.test', 'name' => 'U', 'roles' => [['roleid' => 9902, 'role_name' => 'Reader', 'counts' => true]]]];
            $data->roles = [['roleid' => 9902, 'role_name' => 'Reader', 'grantable' => true], ['roleid' => 9903, 'role_name' => 'Deleter', 'grantable' => false]];
            $data->invitations = [['invitation_id' => 3, 'email' => 'x@example.test', 'state' => 'waiting']];
            $data->organizations = [self::ORG => 'Acme'];
            $render = \Closure::bind(function (string $file): void {
                include $file;
            }, $data, null);
            $root = ROOT . '/scaffolding/themes/' . $theme . '/views/organization/';

            // Act
            ob_start();
            $render($root . 'view.html.php');
            $render($root . 'organization.html.php');
            $html = (string) ob_get_clean();

            // Assert
            foreach (['add', 'remove', 'giverole', 'takerole', 'withdraw'] as $action) {
                $this->assertStringContainsString('organization/' . $action . '/' . self::ORG, $html, $theme . ': ' . $action);
            }
            $this->assertStringNotContainsString('value="9903"', $html, $theme . ': a role the manager cannot give is not offered');
            $this->assertStringContainsString('organization/view/' . self::ORG, $html, $theme);
        }
    }
}
