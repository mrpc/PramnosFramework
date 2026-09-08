<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\HypertableRegistry;

/**
 * A compression policy without a segment key makes the table bigger.
 *
 * Four of the framework's declared hypertables shipped in exactly that state, and
 * compression grew every one of them — at both volumes measured, and worse at the busy
 * one. Nothing reports it: the policy runs, no error is raised, the disk grows. It was
 * found only because a reported migration failure on a compressed hypertable prompted
 * someone to measure the compression itself.
 *
 * TimescaleDB compresses in batches of up to 1000 rows **per segment**. With no segment key
 * it warns that it cannot find a suitable indexed column and uses none, and a compressed
 * chunk's own overhead then exceeds what the batches save.
 *
 * So this asserts the rule rather than the four instances of it: **a table that declares
 * `compress_after` must declare a `segmentby`**. A fifth is then a red test rather than a
 * table that quietly doubles.
 *
 * The numbers themselves are in `HypertableRegistry` and were produced by
 * `tests/Benchmarks/hypertable_compression_sweep.php`.
 */
#[CoversClass(HypertableRegistry::class)]
class CompressionNeedsASegmentKeyTest extends TestCase
{
    /**
     * Every table with a compression policy names a segment key.
     *
     * The invariant, one case per table so a failure says which.
     *
     * @param string               $table The registry key
     * @param array<string,mixed>  $spec  Its declaration
     */
    #[DataProvider('compressedTables')]
    public function testACompressedTableDeclaresASegmentKey(string $table, array $spec): void
    {
        // Act & Assert
        $this->assertNotNull(
            $spec['segmentby'] ?? null,
            $table . ' has a compression policy and no segmentby. TimescaleDB will pick '
            . 'none, and compression will make the table larger — measure it with '
            . 'tests/Benchmarks/hypertable_compression_sweep.php and declare the winner'
        );
        $this->assertNotSame('', trim((string) $spec['segmentby']));
    }

    /**
     * And it names an ordering, because the default collides with the segment key.
     *
     * Left to TimescaleDB, `compress_orderby` includes the very column being segmented by,
     * and the engine then refuses: «cannot use column X for both ordering and segmenting».
     * Declaring it is what makes the layout applyable at all — and it was declaring it that
     * turned four "impossible" candidates in the benchmark into the winning ones.
     *
     * @param string              $table The registry key
     * @param array<string,mixed> $spec  Its declaration
     */
    #[DataProvider('compressedTables')]
    public function testACompressedTableDeclaresAnOrdering(string $table, array $spec): void
    {
        // Act & Assert
        $this->assertNotNull(
            $spec['orderby'] ?? null,
            $table . ' declares a segmentby and no orderby; the default ordering may name '
            . 'the same column, and TimescaleDB refuses to have a column in both'
        );
    }

    /**
     * The segment key is not the high-cardinality one.
     *
     * `userid` was measured on four of these tables and lost on every one — twenty
     * thousand distinct values make segments of a few rows, which is what "no segment key"
     * already amounts to. It is the plausible-looking wrong answer, so it is worth naming:
     * it is the column an author reaches for because it is the one the table is queried by.
     *
     * @param string              $table The registry key
     * @param array<string,mixed> $spec  Its declaration
     */
    #[DataProvider('compressedTables')]
    public function testTheSegmentKeyIsNotAPerRowIdentifier(string $table, array $spec): void
    {
        // Arrange — the columns that are one-per-entity rather than a vocabulary
        $perRow = ['userid', 'id', 'itemid', 'tokenid'];

        // Act
        $segments = array_map('trim', explode(',', (string) $spec['segmentby']));

        // Assert
        foreach ($segments as $segment) {
            $this->assertNotContains(
                $segment,
                $perRow,
                $table . ' segments compression by ' . $segment . ', which has one value '
                . 'per row or close to it. Measured on four tables, that is indistinguishable '
                . 'from declaring no segment key at all'
            );
        }
    }

    /**
     * @return array<string,array{string,array<string,mixed>}>
     */
    public static function compressedTables(): array
    {
        $cases = [];

        foreach (HypertableRegistry::all() as $table => $spec) {
            // No policy, nothing to get wrong. `pramnos.changelog_trace` is the one:
            // a debugging table with a retention policy and no compression.
            if (($spec['compress_after'] ?? null) === null) {
                continue;
            }
            $cases[$table] = [$table, $spec];
        }

        return $cases;
    }

    /**
     * A table with no compression policy is left out rather than exempted by name.
     *
     * The control for the provider: if it silently returned nothing, all three tests above
     * would pass while asserting nothing at all — which is the failure mode a
     * registry-driven test has.
     */
    public function testTheProviderActuallyFindsCompressedTables(): void
    {
        // Act
        $cases = self::compressedTables();

        // Assert
        $this->assertGreaterThanOrEqual(
            9,
            count($cases),
            'the registry declares nine compressed hypertables; a provider finding fewer '
            . 'is not exercising them'
        );
        $this->assertArrayNotHasKey(
            'pramnos.changelog_trace',
            $cases,
            'changelog_trace has no compression policy and must not be required to have a '
            . 'segment key'
        );
    }
}
