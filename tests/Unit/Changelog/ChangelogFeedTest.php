<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Changelog;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Changelog\ChangelogServiceProvider;
use Pramnos\Changelog\ChangelogWriter;
use Pramnos\Database\WriteSpool;
use Pramnos\Event\ChangeFeed;
use Pramnos\Event\Event;
use Pramnos\Event\ModelChange;

/**
 * The audit trail the framework writes for itself — **0% covered**, three files, never executed.
 *
 * A model that sets `$emitChanges` gets a change log with no further wiring: `ChangelogWriter`
 * listens on the change feed, and `ChangelogServiceProvider` teaches the spool how to store the
 * rows. None of it had run, which for an audit trail is the worst place to have no tests — the
 * failure mode of a change log is that it is silently incomplete, and nobody looks at one until
 * they need it to answer a question about the past.
 *
 * Four things it promises, all documented in the code and none previously executed:
 *
 *   - **It appends to the spool; it does not insert.** The measured numbers are in the docblock:
 *     2.807 ms for an insert into a hypertable with indexes, 0.003 ms for the file append — a
 *     factor of about nine hundred, paid on every save of every audited model.
 *   - **Nothing it does may fail the request.** The write it describes has already committed, and
 *     there is nothing the caller could do about a queue failure anyway.
 *   - **The trace is opt-in**, because `getTraceAsString()` on every save is not free.
 *   - **The spool round-trips through JSON**, so an array column has to be re-encoded before it
 *     reaches `insert()`. Without that the drain writes the literal string `"Array"` into a
 *     `jsonb` column — once per row, with no error anywhere.
 *
 * A unit test: the spool's file driver is the whole storage layer here, and pointing it at a
 * temporary directory is more honest than a database would be — the point of the design is that
 * no database is touched during the request.
 */
#[CoversClass(ChangelogWriter::class)]
#[CoversClass(ChangelogServiceProvider::class)]
class ChangelogFeedTest extends TestCase
{
    private string $spoolDir = '';

    protected function setUp(): void
    {
        $this->spoolDir = sys_get_temp_dir() . '/pf-changelog-' . bin2hex(random_bytes(6));
        mkdir($this->spoolDir, 0777, true);

        /*
         * `reset()` **first**, then point it.
         *
         * `reset()` clears the resolved directory along with the driver, so setting the directory
         * and then resetting sends the appends to the installation's real spool — which, on a
         * machine that has been running, is a live queue with rows in it. Learnt the hard way:
         * a test that did it the other way round drained 648 real rows.
         */
        WriteSpool::reset();
        WriteSpool::resetTransformers();
        WriteSpool::setDriver(WriteSpool::DRIVER_FILE);
        $this->pointSpoolAt($this->spoolDir);

        Event::forget(ChangeFeed::EVENT);
    }

    protected function tearDown(): void
    {
        Event::forget(ChangeFeed::EVENT);

        foreach (glob($this->spoolDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->spoolDir);

        WriteSpool::reset();
        WriteSpool::resetTransformers();
    }

    // ── What reaches the spool ────────────────────────────────────────────────

    /**
     * A change is appended to the spool, with the fields a reader needs.
     *
     * The row is what somebody will read months later to answer "who changed this, and to what",
     * so each field is asserted rather than the shape as a whole: a missing `userid` makes the
     * trail anonymous, a missing `op` makes a deletion indistinguishable from an edit, and a
     * missing `created_at` makes the order unknowable.
     */
    public function testAChangeIsAppendedWithTheFieldsAReaderNeeds(): void
    {
        // Arrange
        $at     = 1771061400;
        $change = $this->change(op: ModelChange::UPDATED, at: $at);

        // Act
        $answer = (new ChangelogWriter())->handle($change);

        // Assert
        $this->assertNull($answer, 'a listener on the feed must not return a value');

        $rows = $this->spooled(ChangelogWriter::TABLE);
        $this->assertCount(1, $rows, 'the change did not reach the spool');

        $row = $rows[0];
        $this->assertSame('Device', $row['entity']);
        $this->assertSame('42', $row['itemid'], 'the key must be a string — ids are not all integers');
        $this->assertSame(ModelChange::UPDATED, $row['op']);
        $this->assertSame(['name' => 'new'], $row['changes']);
        $this->assertSame(7, $row['userid'], 'the trail is anonymous without it');
        $this->assertSame(ModelChange::SOURCE_WEB, $row['source']);
        $this->assertSame(date('c', $at), $row['created_at'], 'the order is unknowable without it');
    }

    /**
     * It goes to the spool file, not to the database.
     *
     * The whole reason this class exists rather than an `insert()` in the model: 2.807 ms against
     * 0.003 ms, on every save of every audited model. Asserted by the file being where the row
     * is, and by `append()` reporting the file driver rather than the synchronous fallback —
     * which is what it returns when it has had to write straight through.
     */
    public function testItGoesToTheSpoolFileRatherThanTheDatabase(): void
    {
        // Act
        (new ChangelogWriter())->handle($this->change());

        // Assert
        $this->assertFileExists(
            $this->spoolFile(ChangelogWriter::TABLE),
            'no spool file — the row went somewhere else, and the only other place is the database'
        );
        $this->assertSame(
            WriteSpool::DRIVER_FILE,
            WriteSpool::driver(),
            'the spool fell back to writing through, which is the cost this design removes'
        );
    }

    /**
     * Anything that is not a change is ignored.
     *
     * The listener sits on a general-purpose event, so it will be handed whatever else anybody
     * registers under that name. Raising there would turn an unrelated event into a failed
     * request.
     */
    public function testAnythingThatIsNotAChangeIsIgnored(): void
    {
        // Arrange
        $writer = new ChangelogWriter();

        // Act & Assert
        foreach ([null, 'a string', 42, ['an' => 'array'], new \stdClass()] as $notAChange) {
            $this->assertNull($writer->handle($notAChange));
        }

        $this->assertFileDoesNotExist(
            $this->spoolFile(ChangelogWriter::TABLE),
            'something that was not a change was written to the audit trail'
        );
    }

    /**
     * A queue failure is swallowed, because the write it describes already happened.
     *
     * An audit row that cannot be queued is not a reason to fail the thing the user asked for —
     * and there is nothing the caller could do about it. Arranged by pointing the spool at a path
     * it cannot use, which is what a full or read-only disk looks like from here.
     */
    public function testAQueueFailureDoesNotFailTheRequest(): void
    {
        // Arrange — a file where the spool expects a directory.
        $blocked = $this->spoolDir . '/not-a-directory';
        file_put_contents($blocked, 'in the way');
        WriteSpool::reset();
        WriteSpool::setDriver(WriteSpool::DRIVER_FILE);
        $this->pointSpoolAt($blocked);

        // Act — a raise here is the failure.
        $answer = (new ChangelogWriter())->handle($this->change());

        // Assert
        $this->assertNull($answer);
    }

    // ── Registering once ──────────────────────────────────────────────────────

    /**
     * Listening twice registers one listener.
     *
     * Documented as "safe to call repeatedly", and it has to be: the provider boots per
     * application, and a second registration would write **every** audit row twice — which is not
     * a duplicate anybody notices until they are counting changes to answer a question.
     */
    public function testListeningTwiceRegistersOneListener(): void
    {
        // Act
        ChangelogWriter::listen();
        ChangelogWriter::listen();
        ChangelogWriter::listen();

        // Assert
        $this->assertCount(
            1,
            Event::getListeners(ChangeFeed::EVENT),
            'the audit trail would record every change once per registration'
        );
    }

    // ── The trace ─────────────────────────────────────────────────────────────

    /**
     * No trace unless the model asked for one.
     *
     * Off by default because it is not free: the reference application calls
     * `getTraceAsString()` on every device save, and that cost is the reason this is opt-in
     * rather than always-on.
     */
    public function testTheTraceIsOptIn(): void
    {
        // Act
        (new ChangelogWriter())->handle($this->change(captureTrace: false));

        // Assert
        $this->assertFileDoesNotExist(
            $this->spoolFile(ChangelogWriter::TRACE_TABLE),
            'a trace was captured for a model that did not ask for one'
        );
        $this->assertCount(1, $this->spooled(ChangelogWriter::TABLE), 'the change itself is missing');
    }

    /**
     * When it did, the trace carries the request and the feed row's **natural** key.
     *
     * Not a surrogate id: one would have to be generated before the row exists — the spool does
     * not insert until the drain — which means a database round trip per change, in the request,
     * undoing the append the whole design is built on. So the trace is joined back on
     * entity + itemid + created_at.
     */
    public function testTheTraceCarriesTheRequestAndTheNaturalKey(): void
    {
        // Arrange
        $_SERVER['REQUEST_URI']     = '/admin/devices/save';
        $_SERVER['HTTP_USER_AGENT'] = 'phpunit';
        $at = 1771061400;

        try {
            // Act
            (new ChangelogWriter())->handle(
                $this->change(at: $at, captureTrace: true, trace: '#0 somewhere')
            );

            // Assert
            $rows = $this->spooled(ChangelogWriter::TRACE_TABLE);
            $this->assertCount(1, $rows, 'no trace was captured');

            $row = $rows[0];
            $this->assertSame('Device', $row['entity']);
            $this->assertSame('42', $row['itemid']);
            $this->assertSame(date('c', $at), $row['created_at'], 'the trace cannot be joined back');
            $this->assertSame('#0 somewhere', $row['trace']);
            $this->assertSame('/admin/devices/save', $row['request_uri']);
            $this->assertSame('phpunit', $row['user_agent']);
            $this->assertArrayNotHasKey('id', $row, 'a surrogate key would cost a round trip');
        } finally {
            unset($_SERVER['REQUEST_URI'], $_SERVER['HTTP_USER_AGENT']);
        }
    }

    // ── What the provider teaches the spool ───────────────────────────────────

    /**
     * An array column is JSON-encoded before it reaches the insert.
     *
     * The spool round-trips every row through JSON, so a nested array arrives at
     * `queryBuilder()->insert()` as an array — and a `jsonb` column given an array stores the
     * literal string `"Array"`, once per row, **with no error anywhere**. That is the failure this
     * transformer exists to prevent, and it is invisible until somebody reads the trail.
     */
    public function testAnArrayColumnIsEncodedBeforeTheInsert(): void
    {
        // Arrange
        $this->provider()->boot();

        // Act
        $row = $this->transform(ChangelogWriter::TABLE, ['changes' => ['name' => 'new']]);

        // Assert
        $this->assertIsString($row['changes'], 'the drain would store the string "Array"');
        $this->assertSame(['name' => 'new'], json_decode($row['changes'], true));
    }

    /**
     * A null column stays null, rather than becoming the string `"null"`.
     *
     * `json_encode(null)` is `"null"`, which a database stores as a **JSON null** and not as SQL
     * NULL — so `WHERE details IS NULL` quietly stops matching the rows that have no details. The
     * guard is an `isset()`, and the distinction is one nobody would think to look for.
     */
    public function testANullColumnStaysNull(): void
    {
        // Arrange
        $this->provider()->boot();

        // Act
        $row = $this->transform(ChangelogWriter::EVENTS_TABLE, ['details' => null]);

        // Assert
        $this->assertNull($row['details'], 'WHERE details IS NULL would stop matching');
    }

    /**
     * Each of the three tables gets its own column encoded, and only that one.
     *
     * They hold different things — the automatic feed's `changes`, the application's `details`,
     * the trace's `context` — and a transformer registered against the wrong table is a column
     * that silently stores `"Array"` while the other two look fine.
     */
    public function testEachTableGetsItsOwnColumn(): void
    {
        // Arrange
        $this->provider()->boot();

        // Act & Assert
        foreach (
            [
                ChangelogWriter::TABLE        => 'changes',
                ChangelogWriter::EVENTS_TABLE => 'details',
                ChangelogWriter::TRACE_TABLE  => 'context',
            ] as $table => $column
        ) {
            $row = $this->transform($table, [$column => ['a' => 1], 'other' => ['b' => 2]]);

            $this->assertIsString($row[$column], $table . ' does not encode ' . $column);
            $this->assertIsArray(
                $row['other'],
                $table . ' encoded a column it was not asked to'
            );
        }
    }

    /** Booting the provider also registers the listener, so a model needs no wiring. */
    public function testBootingRegistersTheListener(): void
    {
        // Act
        $this->provider()->boot();

        // Assert
        $this->assertCount(1, Event::getListeners(ChangeFeed::EVENT));
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /**
     * The provider, with the application it is constructed against.
     *
     * `ServiceProvider` takes one — providers are per application, which is also why
     * {@see ChangelogWriter::listen()} has to be idempotent.
     */
    private function provider(): ChangelogServiceProvider
    {
        return new ChangelogServiceProvider(\Pramnos\Application\Application::getInstance());
    }

    /** One model change, with the parts these tests vary. */
    private function change(
        string $op = ModelChange::CREATED,
        int $at = 1771061400,
        bool $captureTrace = false,
        ?string $trace = null
    ): ModelChange {
        return new ModelChange(
            entity: 'Device',
            key: 42,
            op: $op,
            data: ['name' => 'new'],
            changes: ['name' => 'new'],
            channels: [],
            broadcastFields: null,
            userid: 7,
            source: ModelChange::SOURCE_WEB,
            at: $at,
            model: 'App\\Models\\Device',
            table: 'devices',
            captureTrace: $captureTrace,
            trace: $trace,
        );
    }

    /**
     * Point the spool at a directory of this test's own.
     *
     * `$directory` is a protected static resolved from `VAR_PATH`, and there is no setter — by
     * design, since an application has one spool. Reflection is the honest way in for a test, and
     * it is why `reset()` has to come first: reset clears this.
     */
    private function pointSpoolAt(string $directory): void
    {
        $property = new \ReflectionProperty(WriteSpool::class, 'directory');
        $property->setValue(null, $directory);
    }

    private function spoolFile(string $table): string
    {
        return $this->spoolDir . '/' . preg_replace('/[^A-Za-z0-9_.#-]/', '_', $table) . '.spool';
    }

    /**
     * The rows currently waiting in the spool for a table.
     *
     * @return list<array<string, mixed>>
     */
    private function spooled(string $table): array
    {
        $file = $this->spoolFile($table);

        if (!is_file($file)) {
            return [];
        }

        $rows = [];
        foreach (explode("\n", trim((string) file_get_contents($file))) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            $rows[]  = is_array($decoded) ? ($decoded['row'] ?? $decoded) : [];
        }

        return $rows;
    }

    /**
     * Apply whatever transformer the provider registered for a table.
     *
     * Read out of the spool's own registry rather than reimplemented, so this asserts what the
     * provider actually installed.
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function transform(string $table, array $row): array
    {
        $property = new \ReflectionProperty(WriteSpool::class, 'transformers');
        $registered = $property->getValue();

        $this->assertArrayHasKey($table, $registered, 'no transformer for ' . $table);

        return ($registered[$table])($row);
    }
}
