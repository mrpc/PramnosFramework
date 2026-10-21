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
}
