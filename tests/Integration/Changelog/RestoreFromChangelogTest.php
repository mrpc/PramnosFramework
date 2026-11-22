<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Changelog;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Concerns\EmitsModelChanges;
use Pramnos\Application\Model;
use Pramnos\Application\ServiceController;
use Pramnos\Changelog\ChangelogWriter;
use Pramnos\Database\Database;
use Pramnos\Database\WriteSpool;
use Pramnos\Event\ChangeFeed;
use Pramnos\Event\Event;
use Pramnos\Framework\Factory;

/**
 * FW-577: {@see EmitsModelChanges::restoreFromChangelog()} reverses one recorded update back
 * onto an already-loaded model, through the model's own save — a real end-to-end run, not a
 * mock of the feed: a row is created and updated twice through a real {@see Model::_save()},
 * the real {@see ChangeFeed}, the real {@see ChangelogWriter}, each producing a real
 * `pramnos.changelog` row, and then restored from one of those rows.
 *
 * What has to hold: restoring onto the record's own newest state succeeds, through `_save()`
 * (so the restore gets its own fresh `updated` row, not a write the log never saw); restoring
 * against a row that is no longer the newest is refused — the **optimistic guard** FW-577 asked
 * for — unless the caller already knows that later state and names it as `$ifLogid`; and a row
 * for a different record, or one that is not an `updated` op, restores nothing.
 */
#[CoversClass(EmitsModelChanges::class)]
class RestoreFromChangelogTest extends TestCase
{
    private Database $db;

    /**
     * Dotted on purpose: a bare `restore_probe` hits {@see Model::columnIntrospectionSql()}'s
     * `$this->_dbschema ?: $database->schema` fallback, and this test's own `Database`
     * (constructed by hand, not through `Settings`/app.php) never sets `->schema` — so on
     * PostgreSQL the introspection query runs `WHERE table_schema = ''` and finds nothing. A
     * dotted name skips that fallback entirely on both backends: PostgreSQL keeps `public.` as
     * the real schema, MySQL flattens it into one table name — both resolved the same way by
     * {@see \Pramnos\Database\SchemaBuilder::resolveTable()} and
     * {@see Model::getFullTableName()} alike, so creating and reading can never disagree.
     */
    private const TABLE = 'public.restore_probe';

    private const ENTITY = 'restore-probe';

    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306],
            'postgresql' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->connected) {
            $this->db->schema()->dropTableIfExists(self::TABLE);
            $this->db->queryBuilder()->table(ChangelogWriter::TABLE)->where('entity', self::ENTITY)->delete();
            $this->db->close();
        }
        Event::forget(ChangeFeed::EVENT);
        WriteSpool::reset();
        parent::tearDown();
    }

    private function connect(string $type, string $host, int $port): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        $this->db           = new Database();
        $this->db->type     = $type;
        $this->db->server   = $host;
        $this->db->port     = $port;
        $this->db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $this->db->password = 'secret';
        $this->db->database = TEST_DATABASE;

        try {
            if (!$this->db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }

        $singleton = &Factory::getDatabase();
        $singleton = $this->db;

        \Pramnos\Framework\Testing\Schema::ensure([
            \Pramnos\Framework\Migrations\Changelog\CreateChangelogTables::class,
            \Pramnos\Framework\Migrations\Changelog\AddScopeToChangelogEvents::class,
        ], $this->db);

        $this->db->schema()->dropTableIfExists(self::TABLE);
        $this->db->schema()->createTable(self::TABLE, static function ($table): void {
            $table->increments('id');
            $table->string('name', 200)->nullable();
        });

        // A real listener, writing straight through — the sync driver, not the spool file —
        // so the row this test reads back is the one `_save()` actually produced.
        Event::forget(ChangeFeed::EVENT);
        WriteSpool::reset();
        WriteSpool::setDriver(WriteSpool::DRIVER_SYNC);
        ChangelogWriter::registerEncoders();
        ChangelogWriter::listen();
    }

    /** A real `Model`, backed by {@see self::TABLE}, that announces its own changes. */
    private function probe(): Model
    {
        return new class (ServiceController::shared()) extends Model {
            protected $_dbtable    = 'public.restore_probe';
            protected $_primaryKey = 'id';
            protected $emitChanges = true;
            protected $changeEntity = 'restore-probe';
            public ?int $id = null;
            public ?string $name = null;

            public function save(): static
            {
                $this->_save();

                return $this;
            }

            public function load($id): static
            {
                $this->_load($id);

                return $this;
            }
        };
    }

    /** The newest `logid` for {@see self::ENTITY}/`$itemid`, or null if it has none. */
    private function newestLogid(int $itemid): ?int
    {
        $row = $this->db->queryBuilder()->table(ChangelogWriter::TABLE)
            ->where('entity', self::ENTITY)->where('itemid', (string) $itemid)
            ->orderBy('logid', 'desc')->first();

        return $row && (int) $row->numRows > 0 ? (int) $row->fields['logid'] : null;
    }

    #[DataProvider('databases')]
    public function testRestoreGoesThroughSaveAndGuardsAgainstAStaleLogid(string $type, string $host, int $port): void
    {
        // Arrange — a row created, then updated twice through real _save() calls, so the trail
        // has one 'created' row and two real 'updated' rows with their own old/new diffs.
        $this->connect($type, $host, $port);
        $probe = $this->probe();
        $probe->name = 'First';
        $probe->save();
        $itemid = (int) $probe->id;

        $probe->name = 'Second';
        $probe->save();
        $firstUpdateLogid = $this->newestLogid($itemid);

        $probe->name = 'Third (a mistake)';
        $probe->save();
        $secondUpdateLogid = $this->newestLogid($itemid);
        $this->assertNotSame($firstUpdateLogid, $secondUpdateLogid, 'two separate saves must produce two separate rows');

        // Act & Assert — restoring from the row that is no longer the newest is refused …
        $this->assertFalse(
            $probe->restoreFromChangelog((int) $firstUpdateLogid),
            'a row that is no longer the newest for this record must be refused, not silently applied over a later edit'
        );
        $reread = $this->probe();
        $reread->load($itemid);
        $this->assertSame('Third (a mistake)', $reread->name, 'the refused restore must not have changed anything');

        // … but restoring from the newest row (the mistaken edit) succeeds, through _save() —
        // so the restore itself produces its own fresh 'updated' row.
        $target = $this->probe();
        $target->load($itemid);
        $restored = $target->restoreFromChangelog((int) $secondUpdateLogid);
        $this->assertTrue($restored);
        $this->assertSame('Second', $target->name, 'restored to what the mistaken edit overwrote');
        $reread->load($itemid);
        $this->assertSame('Second', $reread->name, '_save() actually wrote the restored value');
        $afterRestoreLogid = $this->newestLogid($itemid);
        $this->assertNotSame($secondUpdateLogid, $afterRestoreLogid, 'the restore must be its own audited event, not a silent write underneath the log');

        // The restore just performed is now itself the newest row, so the SAME logid that was
        // just refused above is refused again …
        $again = $this->probe();
        $again->load($itemid);
        $this->assertFalse($again->restoreFromChangelog((int) $firstUpdateLogid));

        // … unless the caller names the state it already knows about as $ifLogid: going further
        // back than the newest row, deliberately, restores the value from before the FIRST update.
        $deeper = $this->probe();
        $deeper->load($itemid);
        $this->assertTrue($deeper->restoreFromChangelog((int) $firstUpdateLogid, (int) $afterRestoreLogid));
        $this->assertSame('First', $deeper->name, '$ifLogid let the caller go past a known later state on purpose');
    }

    #[DataProvider('databases')]
    public function testARowForAnotherRecordOrNotAnUpdateRestoresNothing(string $type, string $host, int $port): void
    {
        // Arrange — one record's own update row, and a second, unrelated record
        $this->connect($type, $host, $port);
        $mine = $this->probe();
        $mine->name = 'Mine';
        $mine->save();
        $mine->name = 'Mine, changed';
        $mine->save();
        $mineLogid = (int) $this->newestLogid((int) $mine->id);

        $other = $this->probe();
        $other->name = 'Theirs';
        $other->save();
        $createdLogid = (int) $this->newestLogid((int) $other->id);
        $other->name = 'Theirs, changed';
        $other->save();
        $otherUpdateLogid = (int) $this->newestLogid((int) $other->id);

        // Act & Assert — another record's row does not restore onto mine
        $this->assertFalse($mine->restoreFromChangelog($otherUpdateLogid), 'a row belonging to a different record must not apply');
        $this->assertSame('Mine, changed', $mine->name);

        // A 'created' row (an insert, no 'old' to go back to the way an 'updated' row has) is
        // refused too — restoreFromChangelog() only reverses an update.
        $this->assertFalse($other->restoreFromChangelog($createdLogid), 'a created row has no old value to restore');

        // A logid that does not exist at all
        $this->assertFalse($mine->restoreFromChangelog(999999999));
    }

    /**
     * An 'updated' row with nothing in `changes` — a malformed or hand-inserted row, not one
     * `_save()` would ever write itself — restores nothing rather than clearing every field.
     */
    #[DataProvider('databases')]
    public function testAnUpdatedRowWithEmptyChangesRestoresNothing(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $probe = $this->probe();
        $probe->name = 'Untouched';
        $probe->save();
        $this->db->queryBuilder()->table(ChangelogWriter::TABLE)->insert([
            'entity'     => self::ENTITY,
            'itemid'     => (string) $probe->id,
            'op'         => \Pramnos\Event\ModelChange::UPDATED,
            'changes'    => null,
            'userid'     => null,
            'source'     => 'cli',
            'created_at' => date('c'),
        ]);
        $emptyLogid = $this->newestLogid((int) $probe->id);

        // Act
        $restored = $probe->restoreFromChangelog((int) $emptyLogid);

        // Assert
        $this->assertFalse($restored, 'a row that names no field to restore must change nothing');
        $this->assertSame('Untouched', $probe->name);
    }
}
