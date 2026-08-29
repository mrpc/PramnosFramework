<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Messaging;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Messaging\Controllers\MassMessagesController;

/**
 * The two pickers on the compose screen, against real tables.
 *
 * They decide what an operator can choose from, and an empty one is not rendered at all — so a
 * reader that quietly answers nothing is a filter that has silently disappeared from the screen
 * rather than a filter that is broken. Which is the harder failure to notice.
 */
#[CoversClass(MassMessagesController::class)]
class MassMessagePickersTest extends BaseTestCase
{
    private $db;

    private int $groupId = 0;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $this->db = \Pramnos\Framework\Factory::getDatabase();

        if (!$this->db->connected) {
            $this->db->connect();
        }

        \Pramnos\User\User::setupDb();
    }

    protected function tearDown(): void
    {
        if ($this->groupId > 0) {
            try {
                $this->db->queryBuilder()->table('#PREFIX#usergroups')
                    ->where('groupid', $this->groupId)->delete();
            } catch (\Throwable) {
                // Nothing to undo.
            }
        }

        parent::tearDown();
    }

    /**
     * The groups picker lists what the installation has, keyed by id.
     *
     * Keyed, because the form posts ids and the label is only what a person reads. A list keyed
     * by position would post the wrong group the moment somebody renames one.
     */
    public function testTheGroupsPickerListsTheInstallationsGroups(): void
    {
        // Arrange
        $name = 'Volunteers ' . bin2hex(random_bytes(3));
        $this->db->queryBuilder()->table('#PREFIX#usergroups')
            ->insert(['name' => $name, 'description' => '']);
        $this->groupId = (int) $this->db->getInsertId();

        // Act
        $groups = $this->probe()->probeGroups();

        // Assert
        $this->assertArrayHasKey($this->groupId, $groups);
        $this->assertSame($name, $groups[$this->groupId]);
    }

    /**
     * An installation with no organizations answers with none, rather than raising.
     *
     * The membership table belongs to the authserver feature, so an installation without it has
     * no such table at all — and the compose screen has to render either way. A screen that
     * refused because one optional picker has nothing to offer is a screen nobody can use.
     */
    public function testTheOrganizationsPickerAnswersRatherThanRaising(): void
    {
        // Act
        $organizations = $this->probe()->probeOrganizations();

        // Assert
        $this->assertIsArray($organizations);
    }

    private function probe(): object
    {
        return new class extends MassMessagesController {
            public function __construct()
            {
                // Deliberately not parent::__construct(): it registers actions against an
                // application this test does not have.
            }

            /** @return array<int, string> */
            public function probeGroups(): array
            {
                return $this->userGroups();
            }

            /** @return array<int, string> */
            public function probeOrganizations(): array
            {
                return $this->organizations();
            }
        };
    }
}
