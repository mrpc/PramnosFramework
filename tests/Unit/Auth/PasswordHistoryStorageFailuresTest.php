<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\PasswordHistory;

/**
 * `PasswordHistory` against a database that cannot answer — every `catch (\Throwable)` the
 * {@see \Pramnos\Tests\Integration\Auth\PasswordHistoryTest} integration suite has no reason
 * to reach, because its real database always can.
 *
 * `$database` carries no type hint, which is itself the seam: these stubs are plain objects
 * with only the methods `PasswordHistory` actually calls, not a `Database` at all. "Not fatal"
 * is the whole point of every catch block here — a reuse check, or the bookkeeping around it,
 * failing must never surface as the password change itself failing.
 */
#[CoversClass(PasswordHistory::class)]
class PasswordHistoryStorageFailuresTest extends TestCase
{
    private ?array $savedInstances = null;

    protected function tearDown(): void
    {
        if ($this->savedInstances !== null) {
            (new \ReflectionProperty(Application::class, 'appInstances'))
                ->setValue(null, $this->savedInstances);
            $this->savedInstances = null;
        }
        parent::tearDown();
    }

    /** Declare how many previous passwords to remember, without an application or a database. */
    private function withHistory(int $keep): void
    {
        $stub = new class extends Application {
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = ['auth' => ['security' => ['password_history' => $keep]]];

        $reflection           = new \ReflectionProperty(Application::class, 'appInstances');
        $this->savedInstances = $reflection->getValue() ?? [];
        $instances            = $this->savedInstances;
        $instances['default'] = $stub;
        $reflection->setValue(null, $instances);
    }

    /**
     * `remember()`'s write fails: logged, not thrown — the password has already changed by
     * the time this runs, so a failure here must not look like the change itself failed.
     */
    public function testRememberLogsRatherThanThrowsWhenTheWriteFails(): void
    {
        // Arrange
        $this->withHistory(3);
        $history = new PasswordHistory(new class {
            public function queryBuilder(): never
            {
                throw new \RuntimeException('no authserver schema here');
            }
        });

        // Act — the point of the test is that this does not throw
        $history->remember(42, 'some-previous-hash');

        // Assert — reaching here at all is the assertion; nothing is thrown
        $this->addToAssertionCount(1);
    }

    /** `forget()`'s delete fails the same way: logged, not thrown. */
    public function testForgetLogsRatherThanThrowsWhenTheDeleteFails(): void
    {
        // Arrange
        $history = new PasswordHistory(new class {
            public function queryBuilder(): never
            {
                throw new \RuntimeException('no authserver schema here');
            }
        });

        // Act
        $history->forget(42);

        // Assert
        $this->addToAssertionCount(1);
    }

    /**
     * A `get()` that answers `null` rather than a `Result` — a possible answer the query
     * builder's own return type allows for — is read as "no rows", not as a hash to compare.
     */
    public function testANullResultFromTheQueryIsReadAsNoHistory(): void
    {
        // Arrange
        $this->withHistory(3);
        $history = new PasswordHistory(new NullAnsweringDatabaseStub());

        // Act / Assert
        $this->assertFalse($history->wasUsedBefore(42, 'whatever'));
    }

    /**
     * `prune()` found a cutoff, but the delete itself fails: logged, not thrown — the same
     * "not fatal" contract as `remember()`'s own write.
     */
    public function testPruneLogsRatherThanThrowsWhenTheDeleteFails(): void
    {
        // Arrange
        $this->withHistory(2);
        $history = new PasswordHistory(new CutoffFoundButDeleteFailsDatabaseStub());

        // Act — the point of the test is that this does not throw
        $history->remember(42, 'some-previous-hash');

        // Assert
        $this->addToAssertionCount(1);
    }

    /**
     * `prune()`'s own query for the cutoff row answers `null`: there is nothing to delete by,
     * so it does nothing rather than deleting without a bound.
     *
     * Reached only once there is enough history to prune in the first place — otherwise
     * `prune()` returns before ever asking for a cutoff, which is
     * {@see testANullResultFromTheQueryIsReadAsNoHistory}'s case instead.
     */
    public function testPruneDoesNothingWhenTheCutoffQueryAnswersNull(): void
    {
        // Arrange
        $this->withHistory(2);
        $history = new PasswordHistory(new MoreThanKeptButNoCutoffDatabaseStub());

        // Act — the point of the test is that this does not throw
        $history->remember(42, 'some-previous-hash');

        // Assert
        $this->addToAssertionCount(1);
    }
}

/**
 * A fluent stub answering `null` from every terminal call (`get()`, `first()`) — the shape
 * `recentHashes()` and `prune()` read as "nothing to work with", distinct from an exception.
 */
class NullAnsweringDatabaseStub
{
    public function queryBuilder(): self
    {
        return $this;
    }

    public function table(string $table): self
    {
        return $this;
    }

    public function select(array $columns): self
    {
        return $this;
    }

    public function where(...$args): self
    {
        return $this;
    }

    public function orderBy(...$args): self
    {
        return $this;
    }

    public function limit(int $limit): self
    {
        return $this;
    }

    public function offset(int $offset): self
    {
        return $this;
    }

    public function insert(array $values): bool
    {
        return true;
    }

    public function get(): ?object
    {
        return null;
    }

    public function first(): ?object
    {
        return null;
    }
}

/**
 * A `Result`-shaped stub carrying a fixed number of rows, read the way `recentHashes()` reads
 * one: by calling `fetch()` until it answers `null`.
 */
class FakeHashRows
{
    private int $remaining;

    public function __construct(private readonly int $count)
    {
        $this->remaining = $count;
    }

    /** @return array{password_hash: string}|null */
    public function fetch(): ?array
    {
        if ($this->remaining <= 0) {
            return null;
        }
        $this->remaining--;

        return ['password_hash' => 'hash-' . $this->remaining];
    }
}

/** A cutoff row, as `first()` answers one: one row, with the column `prune()` reads. */
class FakeCutoffRow
{
    public int $numRows = 1;

    /** @var array{created_at: int} */
    public array $fields;

    public function __construct()
    {
        $this->fields = ['created_at' => time() - 1000];
    }
}

/**
 * A cutoff is found, but the delete that should follow it fails.
 */
class CutoffFoundButDeleteFailsDatabaseStub
{
    public function queryBuilder(): self
    {
        return $this;
    }

    public function table(string $table): self
    {
        return $this;
    }

    public function select(array $columns): self
    {
        return $this;
    }

    public function where(...$args): self
    {
        return $this;
    }

    public function orderBy(...$args): self
    {
        return $this;
    }

    public function limit(int $limit): self
    {
        return $this;
    }

    public function offset(int $offset): self
    {
        return $this;
    }

    public function insert(array $values): bool
    {
        return true;
    }

    public function get(): FakeHashRows
    {
        return new FakeHashRows(60);
    }

    public function first(): FakeCutoffRow
    {
        return new FakeCutoffRow();
    }

    public function delete(): never
    {
        throw new \RuntimeException('no authserver schema here');
    }
}

/**
 * More history than the configured limit — so `prune()` proceeds to ask for a cutoff row —
 * but that second query answers `null`, as a `LIMIT 1 OFFSET …` past the end of the table does.
 */
class MoreThanKeptButNoCutoffDatabaseStub
{
    public function queryBuilder(): self
    {
        return $this;
    }

    public function table(string $table): self
    {
        return $this;
    }

    public function select(array $columns): self
    {
        return $this;
    }

    public function where(...$args): self
    {
        return $this;
    }

    public function orderBy(...$args): self
    {
        return $this;
    }

    public function limit(int $limit): self
    {
        return $this;
    }

    public function offset(int $offset): self
    {
        return $this;
    }

    public function insert(array $values): bool
    {
        return true;
    }

    public function get(): FakeHashRows
    {
        // Comfortably more than any $keep this test class uses.
        return new FakeHashRows(60);
    }

    public function first(): ?object
    {
        return null;
    }
}
