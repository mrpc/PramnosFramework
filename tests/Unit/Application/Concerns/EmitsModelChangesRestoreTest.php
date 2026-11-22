<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Concerns\EmitsModelChanges;

/**
 * FW-577: the parts of {@see EmitsModelChanges::restoreFromChangelog()} and its
 * {@see EmitsModelChanges::_restoreSave()} fallback that do not need a database — the two early
 * refusals that never reach a query, and the default save-method lookup a third class (one that
 * uses the trait directly rather than through {@see \Pramnos\Application\Model} or
 * {@see \Pramnos\User\User}) falls back to. The database-backed paths — a real restore, a stale
 * logid refused, `$ifLogid`, a row for another record — are
 * {@see \Pramnos\Tests\Integration\Changelog\RestoreFromChangelogTest}'s job.
 */
#[CoversClass(EmitsModelChanges::class)]
class EmitsModelChangesRestoreTest extends TestCase
{
    /** A record with no key does not even reach a query — there is nothing to match against. */
    public function testWithNoPrimaryKeyValueItRefusesWithoutQuerying(): void
    {
        // Arrange — a record this trait would call "new": no key yet
        $record = $this->record(key: null);

        // Act
        $restored = $record->restoreFromChangelog(1);

        // Assert
        $this->assertFalse($restored, 'nothing to restore onto without a key to match a changelog row against');
    }

    /**
     * A host class using the trait directly, with neither `save()` nor `_save()`, must say so
     * rather than silently doing nothing — the one case {@see Model}/{@see \Pramnos\User\User}
     * never hit, because both always have one of the two.
     */
    public function testWithNeitherSaveMethodItSaysSo(): void
    {
        // Arrange
        $record = $this->record(key: 1, withSave: false, withUnderscoreSave: false);

        // Act & Assert
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/must override _restoreSave\(\)/');
        $record->restoreUsingTheDefault();
    }

    /** A host with a public `save()` uses it — the shape {@see \Pramnos\User\User} has. */
    public function testWithAPublicSaveMethodTheDefaultUsesIt(): void
    {
        // Arrange
        $record = $this->record(key: 1, withSave: true, withUnderscoreSave: false);

        // Act
        $record->restoreUsingTheDefault();

        // Assert
        $this->assertSame(['save'], $record->called, 'save() exists and must be the one the default calls');
    }

    /** A host with only `_save()` uses that — the shape {@see \Pramnos\Application\Model} has. */
    public function testWithOnlyUnderscoreSaveTheDefaultUsesIt(): void
    {
        // Arrange
        $record = $this->record(key: 1, withSave: false, withUnderscoreSave: true);

        // Act
        $record->restoreUsingTheDefault();

        // Assert
        $this->assertSame(['_save'], $record->called);
    }

    /** `save()` is preferred over `_save()` when a host happens to have both. */
    public function testWithBothItPrefersThePublicOne(): void
    {
        // Arrange
        $record = $this->record(key: 1, withSave: true, withUnderscoreSave: true);

        // Act
        $record->restoreUsingTheDefault();

        // Assert
        $this->assertSame(['save'], $record->called);
    }

    /**
     * One instance of the trait's three host shapes, built per test so `withSave`/
     * `withUnderscoreSave` can vary what the class under test actually has — PHP has no way to
     * remove a method from an anonymous class once declared.
     */
    private function record(?int $key, bool $withSave = true, bool $withUnderscoreSave = false): object
    {
        if ($withSave && $withUnderscoreSave) {
            return new class ($key) {
                use EmitsModelChanges;
                /** @var list<string> */
                public array $called = [];
                public function __construct(private readonly ?int $key)
                {
                }
                protected function changeDefaultEntity(): string
                {
                    return 'probe';
                }
                protected function changeTable(): string
                {
                    return 'probes';
                }
                protected function changeKey(): ?int
                {
                    return $this->key;
                }
                public function save(): void
                {
                    $this->called[] = 'save';
                }
                public function _save(): void
                {
                    $this->called[] = '_save';
                }
                public function restoreUsingTheDefault(): void
                {
                    $this->_restoreSave();
                }
            };
        }
        if ($withSave) {
            return new class ($key) {
                use EmitsModelChanges;
                /** @var list<string> */
                public array $called = [];
                public function __construct(private readonly ?int $key)
                {
                }
                protected function changeDefaultEntity(): string
                {
                    return 'probe';
                }
                protected function changeTable(): string
                {
                    return 'probes';
                }
                protected function changeKey(): ?int
                {
                    return $this->key;
                }
                public function save(): void
                {
                    $this->called[] = 'save';
                }
                public function restoreUsingTheDefault(): void
                {
                    $this->_restoreSave();
                }
            };
        }
        if ($withUnderscoreSave) {
            return new class ($key) {
                use EmitsModelChanges;
                /** @var list<string> */
                public array $called = [];
                public function __construct(private readonly ?int $key)
                {
                }
                protected function changeDefaultEntity(): string
                {
                    return 'probe';
                }
                protected function changeTable(): string
                {
                    return 'probes';
                }
                protected function changeKey(): ?int
                {
                    return $this->key;
                }
                public function _save(): void
                {
                    $this->called[] = '_save';
                }
                public function restoreUsingTheDefault(): void
                {
                    $this->_restoreSave();
                }
            };
        }

        return new class ($key) {
            use EmitsModelChanges;
            /** @var list<string> */
            public array $called = [];
            public function __construct(private readonly ?int $key)
            {
            }
            protected function changeDefaultEntity(): string
            {
                return 'probe';
            }
            protected function changeTable(): string
            {
                return 'probes';
            }
            protected function changeKey(): ?int
            {
                return $this->key;
            }
            public function restoreUsingTheDefault(): void
            {
                $this->_restoreSave();
            }
        };
    }
}
