<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Database\MigrationRunner;
use Pramnos\Framework\Factory;

/**
 * The migrations ledger, on an installation that has a table prefix.
 *
 * Every raw statement in `MigrationRunner` used the history table's name
 * verbatim while `hasColumn()` went through `SchemaBuilder::resolveTable()`,
 * which applies the prefix. On an installation with one those are two different
 * tables, and both failures happened at once:
 *
 *  - `CREATE TABLE IF NOT EXISTS` made a **second, bare** table beside the real
 *    one — so `migrate:status`, a read-only command, created a table;
 *  - `hasColumn()` inspected the *prefixed* one, found a column missing, and the
 *    `ALTER` added it to the *bare* one, which already had it. The command died
 *    with `Duplicate column name 'scope'` and every later call died identically,
 *    because an asymmetry does not resolve itself on a retry.
 *
 * Reported by nannuka as FW-060, with the two tables and their timestamps.
 */
#[CoversClass(MigrationRunner::class)]
class MigrationHistoryPrefixTest extends TestCase
{
    private \Pramnos\Database\Database $db;
    private string $prefix = '';

    private const BARE = 'probe_schemaversion';

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
    }

    protected function setUp(): void
    {
        Settings::loadSettings($this->settingsFixture());

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();

        if (!$this->db->connected) {
            $this->db->connect(true);
        }

        // A prefix of our own for the duration, so the assertions are about the
        // resolution and not about however this installation happens to be configured.
        $this->prefix     = $this->db->prefix;
        $this->db->prefix = 'fw060_';

        $this->drop();
    }

    protected function tearDown(): void
    {
        $this->drop();
        $this->db->prefix = $this->prefix;

        parent::tearDown();
    }

    /** The identifier quote this backend uses. */
    private function q(string $name): string
    {
        return $this->db->type === 'postgresql' ? '"' . $name . '"' : '`' . $name . '`';
    }

    private function drop(): void
    {
        foreach (array(self::BARE, 'fw060_' . self::BARE) as $table) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->q($table));
        }
    }

    private function exists(string $table): bool
    {
        if ($this->db->type === 'postgresql') {
            $result = $this->db->query(
                $this->db->prepareQuery('SELECT to_regclass(%s) AS oid', $table)
            );

            return $result && $result->numRows > 0 && !empty($result->fields['oid']);
        }

        $result = $this->db->query(
            $this->db->prepareQuery(
                'SELECT 1 FROM information_schema.tables '
                . 'WHERE table_schema = DATABASE() AND table_name = %s',
                $table
            )
        );

        return $result && $result->numRows > 0;
    }

    /**
     * One table, under the prefixed name, and no bare one beside it.
     *
     * The bare table is the visible half of the bug: a read-only command created it,
     * and it then sat in the database being nobody's ledger.
     */
    public function testTheLedgerIsCreatedUnderThePrefixedNameOnly(): void
    {
        // Act
        (new MigrationRunner($this->db, self::BARE))->ensureHistoryTable();

        // Assert
        $this->assertTrue($this->exists('fw060_' . self::BARE), 'the prefixed ledger is missing');
        $this->assertFalse($this->exists(self::BARE), 'a second, bare ledger was created beside it');
    }

    /**
     * Calling it twice is not worse than calling it once.
     *
     * The reported symptom: `hasColumn()` looked at one table and `ALTER` wrote to the
     * other, so the second call died with `Duplicate column name 'scope'` — and so did
     * every call after it.
     */
    public function testASecondCallDoesNotDie(): void
    {
        // Arrange
        $runner = new MigrationRunner($this->db, self::BARE);
        $runner->ensureHistoryTable();

        // Act + Assert — the failure was an exception, so reaching the end is the check
        $runner->ensureHistoryTable();
        (new MigrationRunner($this->db, self::BARE))->ensureHistoryTable();

        $this->assertTrue($this->exists('fw060_' . self::BARE));
        $this->assertFalse($this->exists(self::BARE));
    }

    /**
     * A ledger already written under the bare name is adopted, not abandoned.
     *
     * The dangerous half of the fix. An installation that ran the old code has its
     * history in the bare table; a runner that simply started reading the prefixed name
     * would find nothing, conclude that no migration had ever run, and replay every one
     * of them.
     */
    public function testAnExistingBareLedgerIsTakenOverRatherThanReplayed(): void
    {
        // Arrange — the state the old code left behind
        (new MigrationRunner($this->db, self::BARE))->ensureHistoryTable();
        $this->db->query(
            'ALTER TABLE ' . $this->q('fw060_' . self::BARE)
            . ' RENAME TO ' . $this->q(self::BARE)
        );
        $this->db->query(
            $this->db->prepareQuery(
                'INSERT INTO ' . $this->q(self::BARE)
                . ' (' . $this->q('key') . ', ' . $this->q('batch') . ', '
                . $this->q('result') . ') VALUES (%s, 1, 1)',
                'core/2020_01_01_000001_something'
            )
        );

        // Act
        $runner = new MigrationRunner($this->db, self::BARE);
        $runner->ensureHistoryTable();

        // Assert — one table, prefixed, still carrying the row
        $this->assertTrue($this->exists('fw060_' . self::BARE));
        $this->assertFalse($this->exists(self::BARE), 'the bare ledger was left behind as a second copy');

        $ran = $runner->getHistory();
        $this->assertCount(1, $ran);
        $this->assertSame('core/2020_01_01_000001_something', $ran[0]['key']);
    }

    /**
     * A prefixed ledger that already exists is left alone, whatever else is lying about.
     *
     * The case that must not become a rename: an installation whose real history is
     * under the prefixed name and which also has the empty bare table the bug created.
     * Taking the bare one over would replace a real ledger with an empty one.
     */
    public function testARealLedgerIsNeverReplacedByAStrayBareOne(): void
    {
        // Arrange — the real ledger, with a row
        $runner = new MigrationRunner($this->db, self::BARE);
        $runner->ensureHistoryTable();
        $this->db->query(
            $this->db->prepareQuery(
                'INSERT INTO ' . $this->q('fw060_' . self::BARE)
                . ' (' . $this->q('key') . ', ' . $this->q('batch') . ', '
                . $this->q('result') . ') VALUES (%s, 1, 1)',
                'core/2020_01_01_000002_real'
            )
        );

        // and the empty stray the old code would have made
        $this->db->query(
            'CREATE TABLE ' . $this->q(self::BARE)
            . ' (' . $this->q('key') . ' VARCHAR(255) PRIMARY KEY)'
        );

        // Act
        (new MigrationRunner($this->db, self::BARE))->ensureHistoryTable();

        // Assert — the row survived
        $ran = $runner->getHistory();
        $this->assertCount(1, $ran);
        $this->assertSame('core/2020_01_01_000002_real', $ran[0]['key']);
    }

    /**
     * With no prefix configured, nothing about any of this changes.
     *
     * Which is most installations, and the guarantee they are owed: the resolved name
     * and the given name are the same string, so there is no rename to attempt and no
     * behaviour to notice.
     */
    public function testWithoutAPrefixTheBehaviourIsUnchanged(): void
    {
        // Arrange
        $this->db->prefix = '';

        // Act
        (new MigrationRunner($this->db, self::BARE))->ensureHistoryTable();

        // Assert
        $this->assertTrue($this->exists(self::BARE));
        $this->assertFalse($this->exists('fw060_' . self::BARE));
    }
}
