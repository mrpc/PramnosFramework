<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Model;
use Pramnos\Changelog\ChangelogWriter;
use Pramnos\Database\WriteSpool;

/** A model with just enough of one to emit an event. */
class EventProbeModel extends Model
{
    protected $modelname = 'eventprobe';

    protected $_primaryKey = 'probeid';

    public $probeid = 0;

    public function __construct()
    {
    }

    public function callLogEvent(
        string $event,
        array $details = [],
        int $logtype = 0,
        ?string $description = null
    ): void {
        $this->logEvent($event, $details, $logtype, $description);
    }

    public function callWithoutChangeEmission(callable $callback): mixed
    {
        return $this->withoutChangeEmission($callback);
    }

    public function setChangeEntity(string $entity): void
    {
        $this->changeEntity = $entity;
    }

    public function suppressed(): bool
    {
        return (bool) (new \ReflectionProperty(Model::class, '_suppressChangeEmit'))
            ->getValue($this);
    }
}

/**
 * The semantic half of the audit trail — what a model says happened, as opposed to what changed.
 *
 * Two feeds exist and the distinction is the point. The automatic one is a row per save, derived
 * from the columns that moved; this one is a model saying «approved», «revoked», «merged» — the
 * words that describe what a person did, which no diff of columns can recover. An operator asking
 * "why is this account like that" reads the second.
 *
 * Which makes the failure modes specific, and all three are pinned below:
 *
 * - **recording must not be able to break the thing it records.** The event goes through
 *   `WriteSpool`, which exists so that an audit write is never in the caller's transaction — and if
 *   spooling fails anyway, the exception is swallowed and logged. A save that failed *because* the
 *   audit trail was full is the worst possible reading of "audit".
 * - **empty details are `null`, not `[]`.** The column holds JSON, and `[]` re-encodes as an empty
 *   *array* — so a reader distinguishing "no details" from "details that happen to be empty" gets
 *   the wrong answer, and the panel shows an empty object where it should show nothing.
 * - **the entity can be named.** A model's class name is not always what the feed should say, and a
 *   timeline filtered by entity is only as good as the name the writer used.
 *
 * Plus `withoutChangeEmission()`, which exists for the one operation whose physical shape is not its
 * meaning: a soft delete is an `UPDATE` that means DELETED. The automatic feed would say «updated`,
 * so the caller silences it and emits the truthful event itself — and the flag has to be restored
 * even when the callback throws, or one failed soft delete silences the feed for the rest of the
 * request.
 *
 * No database: the spool's file driver is the seam, which is what it is for.
 */
#[CoversClass(Model::class)]
class ModelChangeEventsTest extends TestCase
{
    private string $spoolDir = '';

    protected function setUp(): void
    {
        $this->spoolDir = sys_get_temp_dir() . '/pramnos_events_' . bin2hex(random_bytes(4));
        mkdir($this->spoolDir, 0777, true);

        /*
         * `reset()` first, then the directory.
         *
         * `reset()` clears `$directory` along with everything else, so setting it first is setting
         * it and then throwing it away — after which the spool writes to the real `var/spool` and
         * this test reads an empty directory and asserts nothing.
         */
        WriteSpool::reset();
        WriteSpool::setDriver(WriteSpool::DRIVER_FILE);
        $this->pointSpoolAt($this->spoolDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->spoolDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->spoolDir);

        WriteSpool::reset();
    }

    private function pointSpoolAt(?string $directory): void
    {
        (new \ReflectionProperty(WriteSpool::class, 'directory'))->setValue(null, $directory);
    }

    /** Every row the spool holds, decoded. @return list<array<string, mixed>> */
    private function spooled(): array
    {
        $rows = [];

        foreach (glob($this->spoolDir . '/*.spool') ?: [] as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }
        }

        return $rows;
    }

    /** The payload of the one spooled row, whatever envelope the spool wraps it in. */
    private function onlyRow(): array
    {
        $rows = $this->spooled();
        $this->assertCount(1, $rows, 'expected exactly one spooled event');

        $row = $rows[0];

        // The spool stores an envelope; the event's own fields are either at the top level or
        // under a payload key. Read whichever it is rather than assuming.
        foreach (['row', 'payload', 'data'] as $key) {
            if (isset($row[$key]) && is_array($row[$key])) {
                return $row[$key];
            }
        }

        return $row;
    }

    // ── What an event carries ─────────────────────────────────────────────────

    /**
     * An event is spooled with everything a timeline entry needs.
     *
     * The set is not arbitrary: without `entity` and `itemid` the row cannot be attached to a
     * record, without `event` it says nothing, and without `userid` and `created_at` it cannot
     * answer "who and when" — which is the only question an audit trail exists for.
     */
    public function testAnEventIsSpooledWithEverythingATimelineNeeds(): void
    {
        // Arrange
        $model = new EventProbeModel();
        $model->probeid = 42;

        // Act
        $model->callLogEvent('approved', ['reason' => 'looked fine'], 5, 'Approved by hand');
        $row = $this->onlyRow();

        // Assert
        $this->assertSame('eventprobe', $row['entity'] ?? null);
        $this->assertSame('42', $row['itemid'] ?? null, 'the item id is not a string, so it cannot key');
        $this->assertSame('approved', $row['event'] ?? null);
        $this->assertSame(5, $row['logtype'] ?? null);
        $this->assertSame('Approved by hand', $row['description'] ?? null);
        $this->assertArrayHasKey('userid', $row);
        $this->assertArrayHasKey('source', $row);
        $this->assertNotSame('', (string) ($row['created_at'] ?? ''), 'the event has no timestamp');
    }

    /**
     * The event goes to the changelog's own events table, not the automatic feed.
     *
     * They are separate tables because they answer separate questions, and a semantic event written
     * into the per-save feed would be buried among one row per column change — which is exactly what
     * every listing filters out.
     */
    public function testTheEventGoesToTheEventsTable(): void
    {
        // Arrange
        $model = new EventProbeModel();
        $model->probeid = 7;

        // Act
        $model->callLogEvent('revoked');

        // Assert
        $files = array_map('basename', glob($this->spoolDir . '/*.spool') ?: []);
        $this->assertCount(1, $files);
        $this->assertStringContainsString(
            str_replace('.', '', ChangelogWriter::EVENTS_TABLE),
            str_replace('.', '', $files[0]),
            'the event was spooled for a different table'
        );
    }

    /**
     * No details is `null`, not an empty array.
     *
     * The column holds JSON, and `[]` re-encodes as `[]` — so a reader asking "were there details"
     * gets a yes for an event that had none, and the panel renders an empty object where it should
     * render nothing at all.
     */
    public function testNoDetailsIsNullRatherThanAnEmptyArray(): void
    {
        // Arrange
        $model = new EventProbeModel();
        $model->probeid = 1;

        // Act
        $model->callLogEvent('touched');
        $row = $this->onlyRow();

        // Assert
        $this->assertArrayHasKey('details', $row);
        $this->assertNull($row['details'], 'an event with no details claims to have some');
    }

    /** Details that were given survive as a structure, not a string. */
    public function testDetailsThatWereGivenSurviveAsAStructure(): void
    {
        // Arrange
        $model = new EventProbeModel();
        $model->probeid = 1;

        // Act
        $model->callLogEvent('merged', ['from' => 3, 'into' => 4, 'fields' => ['a', 'b']]);
        $row = $this->onlyRow();

        // Assert
        $this->assertIsArray($row['details'] ?? null, 'the details were flattened to a string');
        $this->assertSame(3, $row['details']['from'] ?? null);
        $this->assertSame(['a', 'b'], $row['details']['fields'] ?? null);
    }

    /**
     * A model can name the entity the feed files it under.
     *
     * The class name is not always the right label — several classes can be facets of one record,
     * and a timeline filtered by entity is only as useful as the name the writer chose. Falling back
     * to `modelname` keeps every model that does not care working unchanged.
     */
    public function testTheEntityCanBeNamedAndOtherwiseFallsBack(): void
    {
        // Arrange
        $named = new EventProbeModel();
        $named->probeid = 1;
        $named->setChangeEntity('device');

        $unnamed = new EventProbeModel();
        $unnamed->probeid = 2;

        // Act
        $named->callLogEvent('renamed');
        $unnamed->callLogEvent('renamed');
        $rows = $this->spooled();

        // Assert
        $entities = [];
        foreach ($rows as $row) {
            $payload = isset($row['row']) && is_array($row['row']) ? $row['row'] : $row;
            $entities[] = $payload['entity'] ?? null;
        }

        $this->assertContains('device', $entities, 'the declared entity was ignored');
        $this->assertContains('eventprobe', $entities, 'the fallback to the model name was lost');
    }

    /**
     * A record with no id yet still produces an event.
     *
     * A creation event is emitted around the insert, so the id may not exist when the event is
     * built. An empty string is the honest answer; refusing to record would lose the one event that
     * says the record came into existence.
     */
    public function testARecordWithNoIdYetStillProducesAnEvent(): void
    {
        // Arrange
        $model = new EventProbeModel();

        // Act
        $model->callLogEvent('created');
        $row = $this->onlyRow();

        // Assert
        $this->assertSame('created', $row['event'] ?? null);
        $this->assertSame('0', (string) ($row['itemid'] ?? ''), 'a new record produced no event');
    }

    // ── Recording must not break what it records ──────────────────────────────

    /**
     * A spool that cannot be written does not raise.
     *
     * The property the whole design turns on: the audit write is out of the caller's way, and if it
     * fails anyway the failure is logged rather than thrown. A save that failed *because* the audit
     * trail was unwritable is the worst possible reading of "audit" — the operation the trail exists
     * to record is the one it prevented.
     *
     * The spool is pointed at a path under a regular file, which can never be a directory.
     */
    public function testASpoolThatCannotBeWrittenDoesNotRaise(): void
    {
        // Arrange
        $file = tempnam(sys_get_temp_dir(), 'pramnos-notadir');
        $this->assertIsString($file);
        $this->pointSpoolAt($file . DIRECTORY_SEPARATOR . 'spool');

        $model = new EventProbeModel();
        $model->probeid = 1;

        try {
            // Act & Assert — the assertion is that nothing escapes
            $model->callLogEvent('approved', ['reason' => 'x']);
            $this->assertTrue(true, 'recording an event took down the operation it was recording');
        } finally {
            @unlink($file);
            $this->pointSpoolAt($this->spoolDir);
        }
    }

    // ── Silencing the automatic feed ──────────────────────────────────────────

    /**
     * The automatic feed is silenced inside the callback and speaking again after.
     *
     * For the operation whose physical shape is not its meaning: a soft delete is an `UPDATE`, and
     * the automatic feed would file it as «updated». The caller silences that and emits the truthful
     * event itself — so the flag must be off again afterwards, or the rest of the request's saves
     * vanish from the feed.
     */
    public function testTheAutomaticFeedIsSilencedOnlyInside(): void
    {
        // Arrange
        $model = new EventProbeModel();
        $this->assertFalse($model->suppressed(), 'the feed starts silenced');

        // Act
        $inside = null;
        $returned = $model->callWithoutChangeEmission(function () use ($model, &$inside) {
            $inside = $model->suppressed();

            return 'the callback value';
        });

        // Assert
        $this->assertTrue($inside, 'the feed was not silenced inside the callback');
        $this->assertFalse($model->suppressed(), 'the feed stayed silent for the rest of the request');
        $this->assertSame('the callback value', $returned, 'the callback\'s value was swallowed');
    }

    /**
     * A callback that throws still restores the flag.
     *
     * The `finally` earns its place here: one failed soft delete would otherwise silence the feed
     * for every save after it in the same request, and the missing entries would be attributed to
     * whatever came next.
     */
    public function testAThrowingCallbackStillRestoresTheFlag(): void
    {
        // Arrange
        $model = new EventProbeModel();

        // Act
        try {
            $model->callWithoutChangeEmission(static function (): void {
                throw new \RuntimeException('the soft delete failed');
            });
            $this->fail('the exception was swallowed, so the caller cannot report the failure');
        } catch (\RuntimeException) {
            // Expected: the caller has to see it.
        }

        // Assert
        $this->assertFalse(
            $model->suppressed(),
            'a failed operation silenced the feed for everything after it'
        );
    }

    /**
     * Nesting restores the outer state rather than assuming it was off.
     *
     * It saves the previous value rather than setting false, which matters as soon as one suppressed
     * operation calls another — the inner one finishing must not un-silence the outer.
     */
    public function testNestingRestoresTheOuterStateNotFalse(): void
    {
        // Arrange
        $model = new EventProbeModel();
        $seen = [];

        // Act
        $model->callWithoutChangeEmission(function () use ($model, &$seen) {
            $model->callWithoutChangeEmission(static function (): void {
                // The inner operation, whatever it is.
            });

            $seen['after inner'] = $model->suppressed();
        });

        // Assert
        $this->assertTrue(
            $seen['after inner'] ?? false,
            'the inner call un-silenced the outer one, so its saves reached the feed'
        );
        $this->assertFalse($model->suppressed());
    }
}
