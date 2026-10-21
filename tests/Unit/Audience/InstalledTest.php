<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Audience\Installed;
use Pramnos\Database\Database;

/**
 * Where the database cannot be asked, the audience tables count as not installed — and the
 * answer is remembered, so a broken connection is not asked again on every event.
 */
#[CoversClass(Installed::class)]
class InstalledTest extends TestCase
{
    /** Forget what was remembered. */
    protected function tearDown(): void
    {
        Installed::$cache = null;
        Installed::reset();
    }

    /** A database that cannot answer means not installed, asked once. */
    public function testADatabaseThatCannotAnswerMeansNotInstalled(): void
    {
        // Arrange
        Installed::reset();
        $singleton = &Database::getInstance();
        $previous  = $singleton;
        $asked     = 0;
        $singleton = new class ($asked) extends Database {
            /** @param int $asked Counts the questions */
            public function __construct(private int &$asked)
            {
            }

            /** A schema that cannot be read. */
            public function schema()
            {
                $this->asked++;
                throw new \RuntimeException('no connection');
            }
        };

        // Act
        try {
            $answers = [Installed::segments(), Installed::segments(), Installed::personEvents()];
        } finally {
            $singleton = $previous;
        }

        // Assert
        $this->assertSame([false, false, false], $answers);
        $this->assertSame(2, $asked, 'segments once, person events once');
    }

    /**
     * A cache that cannot be reached — a Redis that is down — is not a reason to say anything is
     * missing: the database is asked directly, and forgetting does not fail either.
     */
    public function testACacheThatIsDownIsGoneAround(): void
    {
        // Arrange
        Installed::$cache = static function (): \Pramnos\Cache\Cache {
            throw new \RuntimeException('redis down');
        };
        $singleton = &Database::getInstance();
        $previous  = $singleton;
        $singleton = new class extends Database {
            /** Nothing to connect to. */
            public function __construct()
            {
            }

            /** A schema where every table is there. */
            public function schema()
            {
                return new class {
                    /** Every table exists. */
                    public function hasTable(string $table): bool
                    {
                        return true;
                    }
                };
            }
        };

        // Act
        try {
            Installed::reset();
            $answer = Installed::has('anything');
        } finally {
            $singleton = $previous;
        }

        // Assert
        $this->assertTrue($answer);
    }
}
