<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\SchemaDiff;

/**
 * The comparison itself, over described shapes: every kind of difference has its key and its
 * severity, and what only the installation has is information rather than a difference.
 */
#[CoversClass(SchemaDiff::class)]
final class SchemaDiffCompareTest extends TestCase
{
    /** A described table. */
    private static function table(array $columns = [], array $indexes = [], array $foreign = []): array
    {
        return ['columns' => $columns, 'indexes' => $indexes, 'foreign' => $foreign];
    }

    /** A described column. */
    private static function column(string $type, bool $nullable = false, ?string $default = null, ?string $charset = 'utf8mb4'): array
    {
        return ['type' => $type, 'nullable' => $nullable, 'default' => $default, 'charset' => $charset];
    }

    /**
     * Columns, indexes and keys each differ in every way they can, and a missing table is one
     * difference rather than one per column; the allow-list turns a match into `allowed`.
     */
    public function testEveryKindOfDifferenceHasItsKey(): void
    {
        // Arrange
        $reference = [
            'users'  => self::table(
                ['id' => self::column('int unsigned'), 'name' => self::column('varchar(100)'), 'email' => self::column('varchar(255)', true, null)],
                ['id' => ['name' => 'PRIMARY', 'unique' => true], 'email' => ['name' => 'idx_users_email', 'unique' => true], 'name' => ['name' => 'idx_users_name', 'unique' => false]],
                ['org_id>orgs.id' => ['rule' => 'CASCADE', 'name' => 'fk_users_org'], 'boss>users.id' => ['rule' => 'SET NULL', 'name' => 'fk_boss']]
            ),
            'orgs'   => self::table(['id' => self::column('int')]),
        ];
        $live = [
            'users' => self::table(
                ['id' => self::column('int unsigned'), 'name' => self::column('varchar(50)', true, "''", 'latin1'), 'email' => self::column('varchar(255)', true), 'mine' => self::column('int')],
                ['id' => ['name' => 'PRIMARY', 'unique' => true], 'email' => ['name' => 'email', 'unique' => false], 'mine' => ['name' => 'idx_mine', 'unique' => false]],
                ['org_id>orgs.id' => ['rule' => 'SET NULL', 'name' => 'users_ibfk_1'], 'mine>orgs.id' => ['rule' => 'CASCADE', 'name' => 'x']]
            ),
        ];

        // Act
        $found = SchemaDiff::compare($reference, $live, ['users.column.name.charset']);
        $by    = [];
        foreach ($found as $d) {
            $by[$d['key']] = $d['severity'];
        }

        // Assert
        $this->assertSame([
            'users.column.name.type'               => SchemaDiff::DIFFERENCE,
            'users.column.name.nullable'           => SchemaDiff::DIFFERENCE,
            'users.column.name.default'            => SchemaDiff::DIFFERENCE,
            'users.column.name.charset'            => SchemaDiff::ALLOWED,
            'users.column.mine.extra'              => SchemaDiff::INFO,
            'users.index.email.unique'             => SchemaDiff::DIFFERENCE,
            'users.index.name.missing'             => SchemaDiff::DIFFERENCE,
            'users.index.mine.extra'               => SchemaDiff::INFO,
            'users.foreign.org_id>orgs.id.rule'    => SchemaDiff::DIFFERENCE,
            'users.foreign.boss>users.id.missing'  => SchemaDiff::DIFFERENCE,
            'users.foreign.mine>orgs.id.extra'     => SchemaDiff::INFO,
            'orgs.table.orgs.missing'              => SchemaDiff::DIFFERENCE,
        ], $by);
    }

    /** A charset the reference does not state (PostgreSQL, a number) is not a difference. */
    public function testACharsetOnlyOneSideStatesIsNotADifference(): void
    {
        // Arrange
        $reference = ['t' => self::table(['n' => self::column('int', false, null, null)])];
        $live      = ['t' => self::table(['n' => self::column('int', false, null, 'latin1')])];

        // Act + Assert
        $this->assertSame([], SchemaDiff::compare($reference, $live));
    }

    /** Integer display widths are left out of a type; everything else in it stays. */
    public function testDisplayWidthsAreNotPartOfAType(): void
    {
        // Act + Assert
        $this->assertSame('int unsigned', SchemaDiff::normaliseType('INT(10) UNSIGNED'));
        $this->assertSame('bigint', SchemaDiff::normaliseType('bigint(20)'));
        $this->assertSame('varchar(255)', SchemaDiff::normaliseType('varchar(255)'));
        $this->assertSame('decimal(12,4)', SchemaDiff::normaliseType('decimal(12,4)'));
    }

    /** Apply runs only missing columns and indexes that have a definition, nothing else. */
    public function testApplyRunsOnlyWhatOnlyAdds(): void
    {
        // Arrange
        $db = $this->createMock(\Pramnos\Database\Database::class);
        $db->expects($this->exactly(2))->method('query')->willReturnCallback(static function (string $sql) {
            if (str_contains($sql, 'refuse')) {
                throw new \RuntimeException('no');
            }

            return true;
        });
        $reference = ['t' => ['columns' => ['a' => ['definition' => 'ADD a'], 'b' => ['definition' => 'refuse b'], 'c' => []], 'indexes' => [], 'foreign' => []]];
        $d = static fn (string $kind, string $name, string $aspect, string $severity = SchemaDiff::DIFFERENCE): array => ['key' => "t.$kind.$name.$aspect", 'table' => 't', 'kind' => $kind, 'name' => $name, 'aspect' => $aspect, 'severity' => $severity];

        // Act
        $done = SchemaDiff::apply($db, $reference, [
            $d('column', 'a', 'missing'), $d('column', 'b', 'missing'), $d('column', 'c', 'missing'),
            $d('column', 'a', 'type'), $d('foreign', 'x', 'missing'), $d('column', 'z', 'missing', SchemaDiff::ALLOWED),
        ]);

        // Assert
        $this->assertSame([['t.column.a.missing', null], ['t.column.b.missing', 'no']], array_map(static fn (array $s): array => [$s['key'], $s['error']], $done));
    }
}
