<?php

declare(strict_types=1);

namespace Tests\Unit\Logs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Logs\Logger;

/**
 * Human-readable sizes in the log viewer.
 *
 * Seven statements, never executed, and two of them are guards against arithmetic that would
 * otherwise be wrong rather than merely ugly:
 *
 * - **`log(0)` is `-INF`.** Without the `$bytes ? … : 0` in front of it, an empty log file would
 *   produce `floor(-INF / log(1024))`, and `$units[-INF]` is not a unit.
 * - **`min($pow, count($units) - 1)`** stops a size past the last unit from indexing past the end
 *   of the array. A petabyte of logs is unlikely and an undefined-index warning in a log viewer is
 *   the kind of thing that gets discovered while somebody is trying to read a log.
 *
 * The rest is the ladder itself, which is worth pinning because the boundaries are where a reader
 * loses trust: a file the shell calls 1.0K should not be shown as 1024 B.
 */
#[CoversClass(Logger::class)]
class FormatBytesTest extends TestCase
{
    private function format(int $bytes, int $precision = 2): string
    {
        return (new \ReflectionMethod(Logger::class, 'formatBytes'))
            ->invoke(null, $bytes, $precision);
    }

    /**
     * The unit ladder, including both ends of it.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function sizes(): array
    {
        return [
            'empty file'          => [0,             '0 B'],
            'one byte'            => [1,             '1 B'],
            'just under a KB'     => [1023,          '1023 B'],
            'exactly a KB'        => [1024,          '1 KB'],
            'a KB and a half'     => [1536,          '1.5 KB'],
            'exactly a MB'        => [1048576,       '1 MB'],
            'exactly a GB'        => [1073741824,    '1 GB'],
            'exactly a TB'        => [1099511627776, '1 TB'],
        ];
    }

    #[DataProvider('sizes')]
    public function testTheUnitLadder(int $bytes, string $expected): void
    {
        // Act + Assert
        $this->assertSame($expected, $this->format($bytes));
    }

    /**
     * An empty file is `0 B`, not a warning.
     *
     * Called out separately from the ladder because it is the `log(0)` guard, and because an empty
     * log file is the ordinary state of a fresh installation — the first thing anybody sees.
     */
    public function testAnEmptyFileIsZeroBytes(): void
    {
        // Act + Assert
        $this->assertSame('0 B', $this->format(0));
    }

    /**
     * A size past the largest unit is shown in that unit, not past the end of the array.
     *
     * `TB` is the last one, so a petabyte is four figures of terabytes rather than an undefined
     * index. Wrong-looking, and legible; the alternative is a warning printed over the log the
     * reader came for.
     */
    public function testASizePastTheLargestUnitStaysInIt(): void
    {
        // Arrange — a petabyte and a bit
        $petabyte = 1024 ** 5;

        // Act
        $formatted = $this->format($petabyte);

        // Assert
        $this->assertStringEndsWith(' TB', $formatted);
        $this->assertSame('1024 TB', $formatted);
    }

    /**
     * A negative size is treated as nothing rather than as a negative.
     *
     * `max($bytes, 0)`. A size cannot be negative, so this is only reachable from a caller that
     * subtracted two numbers in the wrong order — and `-1 B` in a viewer sends somebody looking
     * for a corrupt file that is fine.
     */
    public function testANegativeSizeIsTreatedAsNothing(): void
    {
        // Act + Assert
        $this->assertSame('0 B', $this->format(-500));
    }

    /**
     * The precision is the caller's, and zero decimals is a whole number.
     *
     * The parameter exists so a narrow column can ask for fewer digits; a `round()` that ignored
     * it would make the argument a lie.
     */
    public function testThePrecisionIsHonoured(): void
    {
        // Act + Assert
        $this->assertSame('1.5 KB', $this->format(1536, 2));
        $this->assertSame('2 KB', $this->format(1536, 0), '0 decimals should round to a whole number');
        $this->assertSame('1.501 KB', $this->format(1537, 3));
    }
}
