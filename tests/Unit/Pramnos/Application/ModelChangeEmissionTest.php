<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Model;
use Pramnos\Application\ServiceController;
use Pramnos\Event\ChangeFeed;
use Pramnos\Event\Event;
use Pramnos\Event\ModelChange;

/**
 * Unit tests for the change-feed emission Model::emitChange() performs.
 *
 * The database write is not exercised here — that is the integration suite's job. What
 * is exercised is every decision made *around* the write: whether to emit at all, what
 * the payload is reduced to, which channels are named, and what happens when something
 * in that chain throws.
 *
 * The first test is the most important one in the file, and would be the one to keep if
 * only one could be: a model that has not opted in emits nothing. Every existing model in
 * every consuming application is that model.
 */
class ModelChangeEmissionTest extends TestCase
{
    /** @var list<ModelChange> Everything the feed delivered during the current test. */
    private array $received = [];

    protected function setUp(): void
    {
        // Arrange — a clean bus and buffer, and a recorder on the feed.
        Event::forget();
        ChangeFeed::reset();

        $this->received = [];
        Event::listen(ChangeFeed::EVENT, function (ModelChange $change): void {
            $this->received[] = $change;
        });
    }

    protected function tearDown(): void
    {
        Event::forget();
        ChangeFeed::reset();
    }

    /**
     * A model with the feed configured however a test needs it.
     *
     * `emit()` exposes the protected hook, because the gating lives there and reaching it
     * through a real `_save()` would drag a database into a test about an if-statement.
     *
     * @param array<string, mixed> $config
     */
    private function model(array $config = []): Model
    {
        $model = new class (ServiceController::shared()) extends Model {
            /** @var string */
            protected $_dbtable = 'probe';
            /** @var string */
            protected $_primaryKey = 'id';
            /** @var int|null */
            public $id = 42;
            /** @var string */
            public $status = 'active';
            /** @var string */
            public $secret = 'do not publish';
            /** @var string */
            public $viewcache = 'a big serialized blob';

            /** @param array<string, mixed> $config */
            public function configure(array $config): void
            {
                foreach ($config as $key => $value) {
                    $this->$key = $value;
                }
            }

            /**
             * @param  array<string, array{old: mixed, new: mixed}> $changes
             */
            public function emit(string $op, array $changes = [], mixed $key = null): void
            {
                $this->emitChange($op, $changes, $key);
            }
        };

        $model->configure($config + ['modelname' => 'device']);

        return $model;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The default: silence
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A model that has not opted in emits nothing.
     *
     * **The backwards-compatibility guarantee.** Every model in every application that
     * upgrades is this model, and the feature is only additive if this holds on every
     * path. Asserted for all three operations rather than one, because the guard is a
     * single early return and a refactor could easily move it below one of them.
     */
    public function testAModelThatHasNotOptedInEmitsNothing(): void
    {
        // Arrange
        $model = $this->model();

        // Act
        $model->emit(ModelChange::CREATED);
        $model->emit(ModelChange::UPDATED, ['status' => ['old' => 'a', 'new' => 'b']]);
        $model->emit(ModelChange::DELETED);

        // Assert
        $this->assertSame([], $this->received);
    }

    /**
     * Opting in emits, and the value object describes the model.
     */
    public function testOptingInEmits(): void
    {
        // Arrange
        $model = $this->model(['emitChanges' => true, 'changeEntity' => 'wcm-device']);

        // Act
        $model->emit(ModelChange::UPDATED, ['status' => ['old' => 'a', 'new' => 'b']]);

        // Assert
        $this->assertCount(1, $this->received);
        $change = $this->received[0];
        $this->assertSame('wcm-device', $change->entity);
        $this->assertSame(42, $change->key);
        $this->assertSame(ModelChange::UPDATED, $change->op);
        $this->assertSame(['status' => ['old' => 'a', 'new' => 'b']], $change->changes);
    }

    /**
     * Without changeEntity, the entity is the model name.
     *
     * So a model can opt in with one property and still produce a sensible channel and
     * changelog entity — the second property is for when the class name is not the name
     * the business uses.
     */
    public function testEntityFallsBackToTheModelName(): void
    {
        // Arrange
        $model = $this->model(['emitChanges' => true]);

        // Act
        $model->emit(ModelChange::CREATED);

        // Assert
        $this->assertSame('device', $this->received[0]->entity);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Filtering
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Ignored fields are absent from both the diff and the record.
     *
     * Both, not just the diff: a listener reading `data` would otherwise still receive
     * the blob the model asked to keep out — which is the case that matters, since the
     * record is the larger of the two.
     */
    public function testIgnoredFieldsLeaveNeitherTheDiffNorTheRecord(): void
    {
        // Arrange
        $model = $this->model([
            'emitChanges'        => true,
            'changeIgnoreFields' => ['viewcache'],
        ]);

        // Act
        $model->emit(ModelChange::UPDATED, [
            'status'    => ['old' => 'a', 'new' => 'b'],
            'viewcache' => ['old' => 'x', 'new' => 'y'],
        ]);

        // Assert
        $change = $this->received[0];
        $this->assertArrayNotHasKey('viewcache', $change->changes);
        $this->assertArrayNotHasKey('viewcache', $change->data);
        $this->assertArrayHasKey('status', $change->changes);
    }

    /**
     * An update touching no significant field is not announced.
     *
     * The cheap defence against a busy table filling a log with noise, without having to
     * enumerate every field that *is* noise.
     */
    public function testAnUpdateWithNoSignificantFieldIsSilent(): void
    {
        // Arrange
        $model = $this->model([
            'emitChanges'             => true,
            'changeSignificantFields' => ['status'],
        ]);

        // Act
        $model->emit(ModelChange::UPDATED, ['lastseen' => ['old' => 1, 'new' => 2]]);

        // Assert
        $this->assertSame([], $this->received);
    }

    /**
     * An update touching a significant field is announced.
     */
    public function testAnUpdateTouchingASignificantFieldIsAnnounced(): void
    {
        // Arrange
        $model = $this->model([
            'emitChanges'             => true,
            'changeSignificantFields' => ['status'],
        ]);

        // Act
        $model->emit(ModelChange::UPDATED, [
            'lastseen' => ['old' => 1, 'new' => 2],
            'status'   => ['old' => 'a', 'new' => 'b'],
        ]);

        // Assert
        $this->assertCount(1, $this->received);
    }

    /**
     * The significance gate applies to updates only.
     *
     * A create has no previous state, so "nothing important changed" cannot be true of
     * it; a delete is always worth knowing about. Gating either would make a model with
     * a significant-fields list silently miss the two operations a subscriber most needs.
     */
    public function testTheSignificanceGateDoesNotApplyToCreatesOrDeletes(): void
    {
        // Arrange
        $model = $this->model([
            'emitChanges'             => true,
            'changeSignificantFields' => ['status'],
        ]);

        // Act — neither carries a significant field, and neither may be suppressed
        $model->emit(ModelChange::CREATED, []);
        $model->emit(ModelChange::DELETED, []);

        // Assert
        $this->assertCount(2, $this->received);
        $this->assertSame(ModelChange::CREATED, $this->received[0]->op);
        $this->assertSame(ModelChange::DELETED, $this->received[1]->op);
    }

    /**
     * An ignored field cannot satisfy the significance gate.
     *
     * The ignore list is applied first, so a model that ignores `viewcache` and calls it
     * significant gets silence rather than an announcement of a change it said it did not
     * care about. Contradictory configuration, resolved in the quieter direction.
     */
    public function testAnIgnoredFieldCannotSatisfyTheSignificanceGate(): void
    {
        // Arrange
        $model = $this->model([
            'emitChanges'             => true,
            'changeIgnoreFields'      => ['viewcache'],
            'changeSignificantFields' => ['viewcache'],
        ]);

        // Act
        $model->emit(ModelChange::UPDATED, ['viewcache' => ['old' => 'x', 'new' => 'y']]);

        // Assert
        $this->assertSame([], $this->received);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Broadcast allow-list
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * By default no allow-list is carried, which means identifiers only on the wire.
     *
     * The record itself is still on the value object — in-process there is no boundary to
     * cross — but `broadcastFields` being null is what tells a broadcaster to publish
     * nothing but entity, key and operation.
     */
    public function testNoAllowListIsCarriedByDefault(): void
    {
        // Arrange
        $model = $this->model(['emitChanges' => true]);

        // Act
        $model->emit(ModelChange::UPDATED, ['status' => ['old' => 'a', 'new' => 'b']]);

        // Assert
        $change = $this->received[0];
        $this->assertNull($change->broadcastFields);
        $this->assertArrayHasKey('secret', $change->data);
    }

    /**
     * A declared allow-list is carried to the listener.
     */
    public function testADeclaredAllowListIsCarried(): void
    {
        // Arrange
        $model = $this->model([
            'emitChanges'     => true,
            'broadcastFields' => ['id', 'status'],
        ]);

        // Act
        $model->emit(ModelChange::UPDATED, ['status' => ['old' => 'a', 'new' => 'b']]);

        // Assert
        $this->assertSame(['id', 'status'], $this->received[0]->broadcastFields);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Channels
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The default channels are the table firehose and the row.
     */
    public function testDefaultChannels(): void
    {
        // Arrange
        $model = $this->model(['emitChanges' => true, 'changeEntity' => 'wcm-device']);

        // Act
        $model->emit(ModelChange::UPDATED, ['status' => ['old' => 'a', 'new' => 'b']]);

        // Assert
        $this->assertSame(
            ['private-wcm-device', 'private-wcm-device.42'],
            $this->received[0]->channels
        );
    }

    /**
     * With no primary key value, only the firehose is named.
     *
     * A per-row channel called `private-device.` would be a channel nobody can
     * meaningfully authorize and nobody will ever subscribe to — a publish into nothing,
     * silently.
     */
    public function testWithoutAKeyOnlyTheFirehoseIsNamed(): void
    {
        // Arrange
        $model = $this->model(['emitChanges' => true, 'id' => null]);

        // Act
        $model->emit(ModelChange::DELETED);

        // Assert
        $this->assertSame(['private-device'], $this->received[0]->channels);
    }

    /**
     * An explicit key overrides the model's own — the delete case.
     *
     * `_delete($primaryKey)` may be called with a key the loaded model does not hold, and
     * the feed has to report the row that was actually deleted rather than whichever one
     * the object happened to be carrying.
     */
    public function testAnExplicitKeyOverridesTheModels(): void
    {
        // Arrange
        $model = $this->model(['emitChanges' => true]);

        // Act
        $model->emit(ModelChange::DELETED, [], 99);

        // Assert
        $this->assertSame(99, $this->received[0]->key);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Failure is never the save's problem
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A listener that throws does not propagate out of the emission.
     *
     * A feed is a side effect of the save, never its purpose. A broadcaster that cannot
     * reach Redis must not turn a successful write into an exception the user sees — the
     * row is already committed, and there is nothing useful for the caller to do about it.
     */
    public function testAThrowingListenerDoesNotEscape(): void
    {
        // Arrange
        Event::forget();
        Event::listen(ChangeFeed::EVENT, function (): void {
            throw new \RuntimeException('the relay is down');
        });
        $model = $this->model(['emitChanges' => true]);

        // Act
        $model->emit(ModelChange::UPDATED, ['status' => ['old' => 'a', 'new' => 'b']]);

        // Assert — reaching this line is the assertion
        $this->addToAssertionCount(1);
    }

    /**
     * Emission carries the source and a null user when nobody is signed in.
     *
     * The suite runs under CLI, which is exactly the context a worker writes from — and
     * the context where asking who is signed in must not throw.
     */
    public function testSourceAndUserAreResolvedWithoutASession(): void
    {
        // Arrange
        $model = $this->model(['emitChanges' => true]);

        // Act
        $model->emit(ModelChange::CREATED);

        // Assert
        $this->assertSame(ModelChange::SOURCE_CLI, $this->received[0]->source);
        $this->assertNull($this->received[0]->userid);
    }

    /**
     * The value object names the class and the table it came from.
     *
     * A listener handling several models needs both: the class to know what it is looking
     * at, the table because a changelog reader queries by it.
     */
    public function testTheChangeNamesItsModelAndTable(): void
    {
        // Arrange
        $model = $this->model(['emitChanges' => true]);

        // Act
        $model->emit(ModelChange::CREATED);

        // Assert
        $this->assertSame($model::class, $this->received[0]->model);
        $this->assertStringContainsString('probe', $this->received[0]->table);
    }
}
