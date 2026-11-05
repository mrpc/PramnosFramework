<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;
use Pramnos\Llm\Chat\StoredSkills;

/**
 * A database that fails under the stored skills: each write says it did not happen — false, or
 * why — and logs, instead of throwing into the page or the answer; a read answers none.
 */
final class StoredSkillsFailureTest extends TestCase
{
    /**
     * A database whose reads find one skill of the person's own by its id — and nothing by name —
     * and whose writes fail; or, with `$readsFail`, whose reads fail too.
     */
    private static function failing(TestCase $test, bool $readsFail = false, string $status = 'pending'): Database
    {
        $byId  = false;
        $query = $test->getMockBuilder(QueryBuilder::class)->disableOriginalConstructor()->getMock();
        foreach (['table', 'orderBy', 'whereIn'] as $chain) {
            $query->method($chain)->willReturnSelf();
        }
        $query->method('where')->willReturnCallback(static function (string $column) use (&$byId, $query): QueryBuilder {
            $byId = $byId || $column === 'skill_id';

            return $query;
        });
        $row = ['skill_id' => 5, 'userid' => 7, 'level' => 'user', 'role' => null, 'name' => 'notes', 'description' => 'Notes',
            'tools' => '[]', 'body' => 'x', 'status' => $status, 'created_at' => 0, 'updated_at' => 0];
        $readsFail
            ? $query->method('getAllOrFail')->willThrowException(new \RuntimeException('down'))
            : $query->method('getAllOrFail')->willReturnCallback(static function () use (&$byId, $row): array {
                $found = $byId ? [$row] : [];
                $byId  = false;

                return $found;
            });
        foreach (['insert', 'update', 'delete'] as $write) {
            $query->method($write)->willThrowException(new \RuntimeException('read only'));
        }
        $db = $test->getMockBuilder(Database::class)->disableOriginalConstructor()->getMock();
        $db->method('queryBuilder')->willReturn($query);

        return $db;
    }

    /** A person of an id and usertype. */
    private static function person(int $id, int $type): object
    {
        return (object) ['userid' => $id, 'usertype' => $type];
    }

    /**
     * Every write against a failing database answers that it did not happen, never an exception:
     * save and offer say why, turn, delete, approve and turn down say false.
     */
    public function testEveryWriteSaysItDidNotHappen(): void
    {
        // Arrange
        $store = new StoredSkills(self::failing($this));
        $owner = self::person(7, 10);
        $admin = self::person(8, 90);

        // Act + Assert
        $this->assertSame('The skill could not be saved.', $store->save($owner, ['name' => 'new', 'description' => 'd', 'body' => 'b']));
        $this->assertFalse($store->delete($owner, 5));
        $this->assertSame('The skill could not be offered.', $store->share($owner, 5, '90'));
        $this->assertFalse($store->approve($admin, 5), 'the offer could not be approved');
        $this->assertFalse($store->reject($admin, 5));
        $this->assertFalse((new StoredSkills(self::failing($this, false, 'on')))->turn($owner, 5, false), 'turning it could not be written');
    }

    /** A turn of a pending one is refused; with the database down, nothing is found or listed. */
    public function testReadsThatFailFindNothing(): void
    {
        // Arrange
        $down = new StoredSkills(self::failing($this, true));

        // Act + Assert
        $this->assertNull($down->find(5));
        $this->assertSame([], $down->mine(7));
        $this->assertSame([], $down->roles());
        $this->assertFalse($down->turn(self::person(7, 10), 5, true));
    }

    /** An unknown level is refused, and a status nobody may set falls back to on. */
    public function testAnUnknownLevelIsRefused(): void
    {
        // Arrange
        $store = new StoredSkills(self::failing($this, true));

        // Act + Assert
        $this->assertSame('Unknown level: group', $store->save(self::person(7, 10), ['level' => 'group', 'name' => 'x', 'description' => 'd', 'body' => 'b']));
        $this->assertFalse(StoredSkills::canManageRoles(null));
        $this->assertFalse(StoredSkills::canManageRoles(self::person(1, 99)), 'the anonymous id is nobody');
    }
}
