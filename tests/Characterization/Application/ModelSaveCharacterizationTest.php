<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Model;
use Pramnos\Database\Database;

/**
 * What `Model::_save()` writes, on every engine the framework supports.
 *
 * `_save()` is the write path of nearly every model in every application, so a change to it is
 * pinned here first: an insert, an update after a load, a save with nothing changed, a forced
 * save, a model nobody loaded, values posted with `$autoGetValues`, the NOT NULL coercions, and
 * what `getLastSaveChanges()` reports. Each assertion reads the row back from the database —
 * the SQL a save builds is not the contract; what the row holds afterwards is.
 */
#[CoversClass(Model::class)]
class ModelSaveCharacterizationTest extends TestCase
{
    public const TABLE = 'model_save_probe';

    private ?Database $previous = null;

    private ?Database $db = null;

    /** @return array<string, array{string, string, int, string}> type, host, port, user */
    public static function engines(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306, 'root'],
            'mariadb'    => ['mysql', 'mariadb', 3306, 'root'],
            'postgresql' => ['postgresql', 'timescaledb', 5432, 'postgres'],
        ];
    }

    protected function setUp(): void
    {
        $this->previous = Database::getInstance();
        Model::$columnCache = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        if ($this->db !== null && $this->db->connected) {
            $this->db->schema()->dropTableIfExists(self::TABLE);
        }
        $singleton = &Database::getInstance();
        $singleton = $this->previous;
        Model::$columnCache = [];
        $_POST = [];
    }

    /** Connect, make it the models' connection, and create the probe table. */
    private function engine(string $type, string $host, int $port, string $user): Database
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $user;
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        $db->schema   = $type === 'mysql' ? null : 'public';

        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }

        $this->db = $db;
        $singleton = &Database::getInstance();
        $singleton = $db;

        $db->schema()->dropTableIfExists(self::TABLE);
        $db->schema()->createTable(self::TABLE, function ($table) {
            $table->increments('id');
            $table->string('name', 50)->nullable();
            $table->string('code', 10);
            $table->integer('stats')->nullable();
            $table->string('note', 50)->nullable();
            $table->double('score')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        return $db;
    }

    /** One row, as the database holds it now. */
    private function row(int $id): array
    {
        return $this->db->queryBuilder()->table(self::TABLE)->where('id', $id)->first()->fields;
    }

    /** Change a column behind the model's back, as another process would. */
    private function elsewhere(int $id, array $values): void
    {
        $this->db->queryBuilder()->table(self::TABLE)->where('id', $id)->update($values);
    }

    /** A saved row to work on. */
    private function seed(): int
    {
        $probe       = new ModelSaveProbe();
        $probe->name = 'first';
        $probe->code = '0123';
        $probe->stats = 5;
        $probe->note = 'kept';
        $probe->save();

        return (int) $probe->id;
    }

    /**
     * An insert writes what was set and gives the model its id; a NOT NULL timestamp left null
     * takes the column's default instead of an impossible empty date.
     */
    #[DataProvider('engines')]
    public function testAnInsertWritesTheRowAndTakesTheDefaults(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->engine($type, $host, $port, $user);

        // Act
        $id = $this->seed();

        // Assert
        $row = $this->row($id);
        $this->assertGreaterThan(0, $id, 'the model knows its new id');
        $this->assertSame('first', $row['name']);
        $this->assertSame('0123', $row['code']);
        $this->assertSame(5, (int) $row['stats']);
        $this->assertNotEmpty($row['created_at'], 'the default filled the omitted timestamp');
    }

    /**
     * A load, a change, a save: the changed column is in the row, and so is everything else.
     */
    #[DataProvider('engines')]
    public function testAnUpdateAfterALoadWritesTheChange(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->engine($type, $host, $port, $user);
        $id    = $this->seed();
        $probe = (new ModelSaveProbe())->load($id);

        // Act
        $probe->name = 'renamed';
        $probe->save();

        // Assert
        $row = $this->row($id);
        $this->assertSame('renamed', $row['name']);
        $this->assertSame('0123', $row['code']);
        $this->assertSame('kept', $row['note']);
        $this->assertSame(['name' => ['old' => 'first', 'new' => 'renamed']],
            $probe->getLastSaveChanges(), 'what the save reports as changed');
    }

    /**
     * A save with nothing changed writes nothing, so a change made elsewhere meanwhile stays.
     */
    #[DataProvider('engines')]
    public function testASaveWithNothingChangedWritesNothing(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->engine($type, $host, $port, $user);
        $id    = $this->seed();
        $probe = (new ModelSaveProbe())->load($id);
        $this->elsewhere($id, ['note' => 'changed elsewhere']);

        // Act
        $probe->save();

        // Assert
        $this->assertSame('changed elsewhere', $this->row($id)['note']);
    }

    /**
     * `force` writes the model's whole row — what it holds overwrites what changed elsewhere.
     */
    #[DataProvider('engines')]
    public function testAForcedSaveWritesTheWholeRow(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->engine($type, $host, $port, $user);
        $id    = $this->seed();
        $probe = (new ModelSaveProbe())->load($id);
        $this->elsewhere($id, ['note' => 'changed elsewhere']);

        // Act
        $probe->save(force: true);

        // Assert
        $this->assertSame('kept', $this->row($id)['note'], 'the model\'s value was written back');
    }

    /**
     * A model that was never loaded — its id set by hand and marked existing — has nothing to
     * compare against, so it writes every column it holds.
     */
    #[DataProvider('engines')]
    public function testAModelThatWasNeverLoadedWritesEveryColumn(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->engine($type, $host, $port, $user);
        $id    = $this->seed();
        $probe = new ModelSaveProbe();
        $probe->id    = $id;
        $probe->name  = 'by hand';
        $probe->code  = 'X1';
        $probe->stats = null;
        $probe->note  = null;
        $probe->markExisting();

        // Act
        $probe->save();

        // Assert
        $row = $this->row($id);
        $this->assertSame('by hand', $row['name']);
        $this->assertSame('X1', $row['code']);
        $this->assertNull($row['stats'], 'null was written, not skipped');
        $this->assertNull($row['note']);
    }

    /**
     * `$autoGetValues` writes the posted value of a column, beside the change that made the
     * save go ahead.
     */
    #[DataProvider('engines')]
    public function testPostedValuesAreWritten(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->engine($type, $host, $port, $user);
        $id    = $this->seed();
        $probe = (new ModelSaveProbe())->load($id);
        $probe->name = 'changed in code';
        $_POST = ['note' => 'from the form'];

        // Act
        $probe->save(autoGetValues: true);

        // Assert
        $row = $this->row($id);
        $this->assertSame('changed in code', $row['name']);
        $this->assertSame('from the form', $row['note'], 'the posted value reached the row');
    }

    /**
     * A string column changed to the same number written differently — `0123` to `123` — is
     * written when the save goes ahead for another change.
     *
     * `getChanges()` compares numbers as numbers, so on its own this change does not make a
     * save go ahead; beside another one, the row must still end up with it.
     */
    #[DataProvider('engines')]
    public function testANumericLookingStringChangeIsWritten(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->engine($type, $host, $port, $user);
        $id    = $this->seed();
        $probe = (new ModelSaveProbe())->load($id);

        // Act
        $probe->code = '123';
        $probe->name = 'and a real change';
        $probe->save();

        // Assert
        $this->assertSame('123', $this->row($id)['code']);
    }

    /**
     * A NOT NULL string the model holds as null is written as an empty string.
     */
    #[DataProvider('engines')]
    public function testANullInANotNullStringIsWrittenEmpty(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->engine($type, $host, $port, $user);
        $id    = $this->seed();
        $probe = (new ModelSaveProbe())->load($id);

        // Act
        $probe->code = null;
        $probe->save();

        // Assert
        $this->assertSame('', $this->row($id)['code']);
    }

    /**
     * A second save after a first one compares against what the first wrote: changing a value
     * back to the loaded one is a change.
     */
    #[DataProvider('engines')]
    public function testASecondSaveComparesAgainstTheFirst(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->engine($type, $host, $port, $user);
        $id    = $this->seed();
        $probe = (new ModelSaveProbe())->load($id);
        $probe->name = 'second';
        $probe->save();

        // Act
        $probe->name = 'first';
        $probe->save();

        // Assert
        $this->assertSame('first', $this->row($id)['name']);
    }

    // ── Only what changed is written ─────────────────────────────────────────

    /**
     * Two copies of one row, each changing a different column: both changes survive.
     *
     * The lost update this exists for — a daemon saving statistics while an administrator
     * renames the same record. When `_save()` wrote every column, whichever saved second
     * wrote back the other's columns as it had loaded them, and the first change vanished
     * without an error.
     */
    #[DataProvider('engines')]
    public function testTwoWritersOfDifferentColumnsBothSurvive(string $type, string $host, int $port, string $user): void
    {
        // Arrange — both load the row before either saves
        $this->engine($type, $host, $port, $user);
        $id     = $this->seed();
        $daemon = (new ModelSaveProbe())->load($id);
        $admin  = (new ModelSaveProbe())->load($id);

        // Act
        $daemon->stats = 99;
        $daemon->save();
        $admin->name = 'renamed by the administrator';
        $admin->save();

        // Assert
        $row = $this->row($id);
        $this->assertSame(99, (int) $row['stats'], 'the daemon\'s write was not undone');
        $this->assertSame('renamed by the administrator', $row['name']);
    }

    /**
     * A column the model did not change keeps whatever the database has now, not what the model
     * loaded — and `getLastSaveChanges()` and the next save still see the model's own state.
     */
    #[DataProvider('engines')]
    public function testAColumnTheModelDidNotChangeIsNotWritten(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->engine($type, $host, $port, $user);
        $id    = $this->seed();
        $probe = (new ModelSaveProbe())->load($id);
        $this->elsewhere($id, ['note' => 'changed elsewhere', 'code' => 'ELSE']);

        // Act
        $probe->name = 'renamed';
        $probe->save();

        // Assert
        $row = $this->row($id);
        $this->assertSame('renamed', $row['name']);
        $this->assertSame('changed elsewhere', $row['note']);
        $this->assertSame('ELSE', $row['code']);
        $this->assertSame(['name' => ['old' => 'first', 'new' => 'renamed']], $probe->getLastSaveChanges());

        // A second save with nothing changed still writes nothing.
        $this->elsewhere($id, ['note' => 'changed again']);
        $probe->save();
        $this->assertSame('changed again', $this->row($id)['note']);
    }

    /**
     * A posted value is written even when only the form changed it, beside a change in code —
     * the posted values are read inside the save, after it decided what had changed.
     */
    #[DataProvider('engines')]
    public function testAPostedValueIsWrittenWhenOnlyTheFormChangedIt(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->engine($type, $host, $port, $user);
        $id    = $this->seed();
        $probe = (new ModelSaveProbe())->load($id);
        $probe->stats = 6;
        $_POST = ['note' => 'from the form', 'code' => '0123'];
        $this->elsewhere($id, ['name' => 'changed elsewhere']);

        // Act
        $probe->save(autoGetValues: true);

        // Assert
        $row = $this->row($id);
        $this->assertSame('from the form', $row['note'], 'the form\'s change is written');
        $this->assertSame(6, (int) $row['stats']);
        $this->assertSame('changed elsewhere', $row['name'], 'a column nobody touched is not');
    }

    /**
     * A column that changed only in what the database would store — `5` loaded, `'5'` set — is
     * not a change, and a column that changed only in spelling — `0123` to `123` — is.
     */
    #[DataProvider('engines')]
    public function testWhatCountsAsAChangeForTheWrite(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->engine($type, $host, $port, $user);
        $id    = $this->seed();
        $probe = (new ModelSaveProbe())->load($id);
        $this->elsewhere($id, ['stats' => 42]);

        // Act
        $probe->stats = (string) $probe->stats; // the same number, as a form would post it
        $probe->code  = '123';
        $probe->name  = 'with a real change';
        $probe->save();

        // Assert
        $row = $this->row($id);
        $this->assertSame(42, (int) $row['stats'], 'the same value as text is not a change');
        $this->assertSame('123', $row['code'], 'a different spelling of a number is');
    }

    /**
     * Emptying a nullable column is a change, and so is filling it again.
     */
    #[DataProvider('engines')]
    public function testANullIsWrittenAndReplaced(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->engine($type, $host, $port, $user);
        $id    = $this->seed();
        $probe = (new ModelSaveProbe())->load($id);

        // Act & Assert — to null
        $probe->stats = null;
        $probe->save();
        $this->assertNull($this->row($id)['stats']);

        // …and back from it
        $probe->stats = 7;
        $probe->save();
        $this->assertSame(7, (int) $this->row($id)['stats']);
    }

    /**
     * A change only to a property that is not a column — a joined field a model keeps beside
     * its row — writes nothing and does not fail. There is no column to put in a SET, and an
     * UPDATE without one is a syntax error.
     */
    #[DataProvider('engines')]
    public function testAChangeOnlyToANonColumnWritesNothing(string $type, string $host, int $port, string $user): void
    {
        // Arrange — loaded, with a joined field recorded as loaded too
        $this->engine($type, $host, $port, $user);
        $id    = $this->seed();
        $probe = (new ModelSaveProbe())->load($id);
        $probe->rememberLoaded('joined', 'from another table');
        $this->elsewhere($id, ['note' => 'changed elsewhere']);

        // Act
        $probe->joined = 'changed';
        $probe->save();

        // Assert
        $this->assertSame('changed elsewhere', $this->row($id)['note'], 'no column was written');
    }

    /**
     * A column the model did not load — a list that selected only some columns — is written
     * when the model holds a value for it, and left alone when it does not: writing its empty
     * value is how a column nobody read would be blanked.
     */
    #[DataProvider('engines')]
    public function testAColumnThatWasNotLoadedIsWrittenOnlyWithAValue(string $type, string $host, int $port, string $user): void
    {
        // Arrange — loaded as if `note` and `stats` had not been selected
        $this->engine($type, $host, $port, $user);
        $id    = $this->seed();
        $probe = (new ModelSaveProbe())->load($id);
        $probe->forgetLoaded('note');
        $probe->forgetLoaded('stats');
        $probe->stats = null;

        // Act
        $probe->note = 'set without loading';
        $probe->name = 'renamed';
        $probe->save();

        // Assert
        $row = $this->row($id);
        $this->assertSame('set without loading', $row['note'], 'a value the model holds is written');
        $this->assertSame(5, (int) $row['stats'], 'an empty one it never read is not');
    }

    /**
     * A model that sets `$_saveWholeRow` keeps the old behaviour: every save writes every column,
     * so its untouched columns go back to what it loaded.
     */
    #[DataProvider('engines')]
    public function testAModelCanAskForTheWholeRow(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->engine($type, $host, $port, $user);
        $id    = $this->seed();
        $probe = (new ModelSaveProbe())->load($id);
        $probe->wholeRow();
        $this->elsewhere($id, ['note' => 'changed elsewhere']);

        // Act
        $probe->name = 'renamed';
        $probe->save();

        // Assert
        $this->assertSame('kept', $this->row($id)['note'], 'the loaded value was written back');
    }

    // ── Conflicts in the same column, for a model that asks ──────────────────

    /**
     * Without the check, the second writer of the same column wins and nobody is told — the
     * default, and the case writing only changed columns cannot settle.
     */
    #[DataProvider('engines')]
    public function testWithoutTheCheckTheLastWriterOfAColumnWins(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->engine($type, $host, $port, $user);
        $id     = $this->seed();
        $first  = (new ModelSaveProbe())->load($id);
        $second = (new ModelSaveProbe())->load($id);

        // Act
        $first->name = 'from the first';
        $first->save();
        $second->name = 'from the second';
        $second->save();

        // Assert
        $this->assertSame('from the second', $this->row($id)['name']);
    }

    /**
     * With the check, the second writer of the same column is refused: nothing it changed is
     * written — not the contested column, not the others — and the exception names the column.
     */
    #[DataProvider('engines')]
    public function testWithTheCheckTheSecondWriterOfAColumnIsRefused(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->engine($type, $host, $port, $user);
        $id     = $this->seed();
        $first  = (new ModelSaveProbe())->load($id)->checkConflicts();
        $second = (new ModelSaveProbe())->load($id)->checkConflicts();
        $first->name = 'from the first';
        $first->save();

        // Act
        $second->name = 'from the second';
        $second->note = 'also from the second';
        try {
            $second->save();
            $this->fail('the conflict was not reported');
        } catch (\Pramnos\Application\WriteConflictException $e) {
            // Assert
            $this->assertSame(['name'], $e->columns);
            $this->assertSame(ModelSaveProbe::class, $e->model);
            $this->assertSame($id, (int) $e->primaryKey);
            $this->assertStringNotContainsString('name', $e->getMessage(), 'the message names no column');
        }
        $row = $this->row($id);
        $this->assertSame('from the first', $row['name']);
        $this->assertSame('kept', $row['note'], 'nothing of the refused save was written');
    }

    /**
     * With the check, a save goes through when nobody touched its columns: another writer's
     * change to a different column is no conflict, and a column loaded as null compares as null.
     */
    #[DataProvider('engines')]
    public function testWithTheCheckAnUncontestedSaveGoesThrough(string $type, string $host, int $port, string $user): void
    {
        // Arrange — `score` loaded null; another writer changes `note`
        $this->engine($type, $host, $port, $user);
        $id    = $this->seed();
        $probe = (new ModelSaveProbe())->load($id)->checkConflicts();
        $this->elsewhere($id, ['note' => 'changed elsewhere']);

        // Act
        $probe->name  = 'renamed';
        $probe->stats = 6;
        $probe->save();
        $probe->stats = null;
        $probe->save();

        // Assert
        $row = $this->row($id);
        $this->assertSame('renamed', $row['name']);
        $this->assertNull($row['stats'], 'a second save checks against what the first wrote');
        $this->assertSame('changed elsewhere', $row['note']);
    }

    /**
     * With the check, a row deleted since the load is a conflict with no columns to name.
     */
    #[DataProvider('engines')]
    public function testWithTheCheckADeletedRowIsAConflict(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->engine($type, $host, $port, $user);
        $id    = $this->seed();
        $probe = (new ModelSaveProbe())->load($id)->checkConflicts();
        $this->db->queryBuilder()->table(self::TABLE)->where('id', $id)->delete();

        // Act & Assert
        $probe->name = 'renamed';
        try {
            $probe->save();
            $this->fail('the conflict was not reported');
        } catch (\Pramnos\Application\WriteConflictException $e) {
            $this->assertSame([], $e->columns);
        }
    }

    /**
     * A float column is written without the check: its loaded value went through a PHP float,
     * and comparing that back is how a save would be refused for a rounding difference.
     */
    #[DataProvider('engines')]
    public function testWithTheCheckAFloatColumnIsNotCompared(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->engine($type, $host, $port, $user);
        $id    = $this->seed();
        $probe = (new ModelSaveProbe())->load($id)->checkConflicts();
        $this->elsewhere($id, ['score' => 1.5]);

        // Act
        $probe->score = 2.25;
        $probe->save();

        // Assert
        $this->assertSame(2.25, (float) $this->row($id)['score']);
    }

    /**
     * On MySQL, an `ON UPDATE CURRENT_TIMESTAMP` column now moves when a model saves. It never
     * did: every save wrote the column explicitly with the value it had loaded, which MySQL
     * takes as the application choosing it.
     */
    public function testAnOnUpdateTimestampMovesOnMysql(): void
    {
        // Arrange
        $this->engine('mysql', 'db', 3306, 'root');
        $this->db->query('ALTER TABLE `' . self::TABLE . '` ADD `touched` TIMESTAMP NOT NULL '
            . "DEFAULT '2001-01-01 00:00:00' ON UPDATE CURRENT_TIMESTAMP");
        Model::$columnCache = [];
        $id    = $this->seed();
        $this->elsewhere($id, ['touched' => '2001-01-01 00:00:00']);
        $probe = (new ModelSaveProbe())->load($id);

        // Act
        $probe->name = 'renamed';
        $probe->save();

        // Assert
        $this->assertNotSame('2001-01-01 00:00:00', $this->row($id)['touched']);
    }
}

/**
 * A model over the probe table, with its save options reachable.
 */
class ModelSaveProbe extends Model
{
    /** @var int|null */
    public $id;

    /** @var string|null */
    public $name;

    /** @var string|null */
    public $code;

    /** @var int|null */
    public $stats;

    /** @var string|null */
    public $note;

    /** @var float|null */
    public $score;

    /** @var string|null */
    public $created_at;

    /** @var string|null Only the MySQL ON UPDATE test adds this column. */
    public $touched;

    /** @var string|null Not a column: a joined field, as some models keep. */
    public $joined;

    protected $_primaryKey = 'id';

    protected $_dbtable = ModelSaveCharacterizationTest::TABLE;

    /** No controller is needed to save. */
    public function __construct()
    {
        parent::__construct(new \Pramnos\Application\Controller());
    }

    /** Write the row, with `_save()`'s own options. */
    public function save(bool $autoGetValues = false, bool $force = false): static
    {
        return $this->_save(null, null, $autoGetValues, false, $force);
    }

    /** Read a row. */
    public function load(int $id): static
    {
        return $this->_load($id, null, null, false, false);
    }

    /** Record a property as loaded, as a model that merges a join into its row does. */
    public function rememberLoaded(string $property, mixed $value): void
    {
        $this->$property = $value;
        $this->_initialData[$property] = $value;
    }

    /** Ask for every column on every save, as a model declaring `$_saveWholeRow` does. */
    public function wholeRow(): void
    {
        $this->_saveWholeRow = true;
    }

    /** Check written columns against the loaded values, as `$_checkWriteConflicts` does. */
    public function checkConflicts(): static
    {
        $this->_checkWriteConflicts = true;

        return $this;
    }

    /** As if the load had not selected this column. */
    public function forgetLoaded(string $column): void
    {
        unset($this->_initialData[$column]);
    }

    /** An existing row the model was never loaded from. */
    public function markExisting(): void
    {
        $this->_isnew = false;
    }
}
