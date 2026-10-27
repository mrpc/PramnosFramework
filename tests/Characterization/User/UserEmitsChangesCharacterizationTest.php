<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Event\ChangeFeed;
use Pramnos\Event\Event;
use Pramnos\Event\ModelChange;
use Pramnos\Framework\Factory;
use Pramnos\User\User;

/**
 * Characterization tests for FW-295-adjacent work: `User` now shares
 * `Pramnos\Application\Concerns\EmitsModelChanges` and `HasEvents` with `Model`, instead of
 * never participating in the model change feed at all (the bug this closes).
 *
 * `User::_save()` cannot call `Model::_save()` — it predates `Model` and is not a subclass of
 * it — so the wiring is explicit inside `User::_save()` itself, mirroring the shape
 * `OrmModel::_save()` already has. These tests lock that wiring in: a create announces CREATED
 * with the full row, an update announces UPDATED with only the fields that actually changed
 * (not every column, which would make the change feed useless for an audit trail), nothing
 * fires unless `$emitChanges` is turned on, and a `creating`/`updating` listener returning
 * `false` cancels the save.
 */
#[CoversClass(User::class)]
class UserEmitsChangesCharacterizationTest extends TestCase
{
    private \Pramnos\Database\Database $db;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        $settingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
        Settings::loadSettings($settingsFile);
        Application::getInstance();

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        User::setupDb();
        Event::forget(ChangeFeed::EVENT);
        $this->flushAllListeners();
    }

    protected function tearDown(): void
    {
        Event::forget(ChangeFeed::EVENT);
        $this->flushAllListeners();
    }

    /**
     * `HasEvents` keys listeners by exact class, and `configurableUser()`'s anonymous
     * subclass is one stable class for the whole run (PHP caches an anonymous class
     * definition by its file+line) — so its listeners persist across test methods exactly
     * like `User`'s own unless both are flushed.
     */
    private function flushAllListeners(): void
    {
        User::flushEventListeners();
        $this->configurableUser()::flushEventListeners();
    }

    /**
     * With `$emitChanges` off (the default), a save announces nothing — the new behaviour must
     * not surprise every existing application that never opted in.
     */
    public function testSaveEmitsNothingByDefault(): void
    {
        // Arrange
        $fired = false;
        Event::listen(ChangeFeed::EVENT, function () use (&$fired) {
            $fired = true;
        });
        $user = $this->newUser();

        // Act
        $user->save();

        // Assert
        $this->assertFalse($fired, 'a User with $emitChanges left at its default must emit nothing');
    }

    /**
     * Creating a new user, with $emitChanges on, announces CREATED with the full row.
     */
    public function testCreatingAUserEmitsCreatedWithFullData(): void
    {
        // Arrange
        $captured = null;
        Event::listen(ChangeFeed::EVENT, function (ModelChange $change) use (&$captured) {
            $captured = $change;
        });
        $user = $this->newUser();
        $user->configure(['emitChanges' => true]);

        // Act
        $user->save();

        // Assert
        $this->assertNotNull($captured, 'save() of a new user must emit a change');
        $this->assertSame(ModelChange::CREATED, $captured->op);
        $this->assertSame('user', $captured->entity);
        $this->assertSame((int) $user->userid, (int) $captured->key);
        $this->assertSame($user->username, $captured->data['username'] ?? null);
    }

    /**
     * Updating one field announces UPDATED with only that field in $changes — not every
     * column, which would make the feed useless for an audit trail (same contract
     * Model::getChanges()/emitChange() already has).
     */
    public function testUpdatingAUserEmitsOnlyTheChangedField(): void
    {
        // Arrange
        $user = $this->newUser();
        $user->configure(['emitChanges' => true]);
        $user->save();

        $loaded = $this->loadUser((int) $user->userid);
        $loaded->configure(['emitChanges' => true]);
        $captured = null;
        Event::listen(ChangeFeed::EVENT, function (ModelChange $change) use (&$captured) {
            $captured = $change;
        });
        $loaded->firstname = 'Changed';

        // Act
        $loaded->save();

        // Assert
        $this->assertNotNull($captured, 'save() of an updated user must emit a change');
        $this->assertSame(ModelChange::UPDATED, $captured->op);
        $this->assertTrue($captured->has('firstname'), 'the changed field must be reported');
        $this->assertSame('Changed', $captured->changes['firstname']['new'] ?? null);
        $this->assertFalse(
            $captured->has('username'),
            'a field that did not change must not be reported as changed'
        );
    }

    /**
     * A no-op save (nothing actually changed) emits nothing — same as Model's own
     * getChanges()-gated early return.
     */
    public function testSavingWithNoActualChangeEmitsNothing(): void
    {
        // Arrange
        $user = $this->newUser();
        $user->configure(['emitChanges' => true]);
        $user->save();

        $loaded = $this->loadUser((int) $user->userid);
        $loaded->configure(['emitChanges' => true]);
        $fired = false;
        Event::listen(ChangeFeed::EVENT, function () use (&$fired) {
            $fired = true;
        });

        // Act — nothing on $loaded was changed before saving again
        $loaded->save();

        // Assert
        $this->assertFalse($fired, 'a save with no actual field change must not emit');
    }

    /**
     * A `creating`/`updating` listener returning false cancels the save — the same
     * BC-preserving contract OrmModel::_save() already has for Model-based classes.
     */
    public function testUpdatingListenerReturningFalseCancelsTheSave(): void
    {
        // Arrange
        $user = $this->newUser();
        $user->save();

        $loaded = new User((int) $user->userid);
        User::on('updating', fn () => false);
        $loaded->firstname = 'Should Not Persist';

        // Act
        $loaded->save();

        // Assert
        $reread = new User((int) $user->userid);
        $this->assertNotSame('Should Not Persist', $reread->firstname);
    }

    /**
     * `created`/`updated` (the "after" events) fire once the row is actually written.
     */
    public function testCreatedEventFiresAfterTheRowIsWritten(): void
    {
        // Arrange
        $seenUserid = null;
        $user = $this->newUser();
        $user::on('created', function (User $u) use (&$seenUserid) {
            $seenUserid = $u->userid;
        });

        // Act
        $user->save();

        // Assert
        $this->assertGreaterThan(0, (int) $seenUserid, 'created must fire after the insert, once userid is known');
        $this->assertSame((int) $user->userid, (int) $seenUserid);
    }

    /**
     * `emitChanges` is `protected` (shared with `Model`, where only a subclass sets it) —
     * a plain `$user->emitChanges = true` from outside the class is itself exactly the bug
     * this whole change fixes: an inaccessible property write that `User::__set()` catches
     * and silently drops into `$otherinfo` instead. `configure()` sets it from inside the
     * hierarchy instead, the same pattern `ModelChangeEmissionTest` uses for `Model`.
     */
    private function configurableUser(): User
    {
        return new class extends User {
            /** @param array<string, mixed> $config */
            public function configure(array $config): void
            {
                foreach ($config as $key => $value) {
                    $this->$key = $value;
                }
            }
        };
    }

    private function newUser(): User
    {
        $username = 'emitchg_char_' . bin2hex(random_bytes(4));
        $user = $this->configurableUser();
        $user->username = $username;
        $user->email    = $username . '@example.com';
        $user->setPassword('secret123');

        return $user;
    }

    private function loadUser(int $userid): User
    {
        $user = $this->configurableUser();
        $user->load($userid);

        return $user;
    }
}
