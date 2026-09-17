<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Testing;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A test whose assertions all live inside a loop has to say the loop ran.
 *
 * WHAT: when every `assert*` in a test method sits inside a `foreach` over a **call**, the
 *       method must also assert that the collection is not empty.
 * WHY:  the loop body is the test. If the collection comes back empty the body never runs,
 *       no assertion is evaluated, and PHPUnit reports a pass — not even a risky test,
 *       because the `foreach` line itself counts as work.
 *
 *       Not hypothetical, twice over. `create:crud` registered every admin search source
 *       against a column called `name` for months: the helper that picks the columns
 *       destructured a pair into one variable and returned `[]` every time, and the test
 *       for it asserted "at most two" and "no empty strings" — both true of nothing. And a
 *       `glob()` over a directory that briefly vanished during a run turned two theme
 *       sweeps into no-ops in exactly the same way.
 *
 * Restricted to a **call**, deliberately. A `foreach` over a literal array or a variable
 * the test built above it cannot be empty by surprise, and flagging those would report
 * three hundred sites of which almost none is a risk. A guard nobody believes is a guard
 * somebody deletes.
 */
#[CoversNothing]
class ASweepOverNothingDoesNotPassTest extends TestCase
{
    /** Assertions that already establish the collection has something in it. */
    private const ESTABLISHES_SIZE =
        '/assert(NotEmpty|Count|GreaterThan|NotSame\(\s*\[\s*\]|LessThan)/';

    public function testEverySweepSaysItSwept(): void
    {
        // Arrange
        $root  = dirname(__DIR__, 3);
        $files = $this->testFiles($root);
        $this->assertNotEmpty($files, 'the sweep found nothing to check');

        // Act
        $offenders = [];
        foreach ($files as $file) {
            foreach ($this->unguardedSweeps((string) file_get_contents($file)) as $found) {
                $offenders[] = substr($file, strlen($root) + 1) . ': ' . $found;
            }
        }

        // Assert
        $this->assertSame(
            [],
            $offenders,
            "every assertion in these sits inside a foreach over a call, and nothing says the\n"
            . "call returned anything — so an empty result is a silent pass:\n"
            . implode("\n", $offenders)
            . "\n\nAdd assertNotEmpty(\$collection, 'the sweep found nothing to check'); before the loop."
        );
    }

    /**
     * Method names in one file whose only assertions are inside an unguarded loop.
     *
     * @return list<string>
     */
    private function unguardedSweeps(string $source): array
    {
        $found = [];

        if (!preg_match_all('/public function (test\w+)\s*\([^)]*\)\s*:?\s*\w*\s*\{/', $source, $methods, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $found;
        }

        foreach ($methods as $method) {
            $body = $this->bodyAfter($source, (int) $method[0][1] + strlen($method[0][0]));

            $assertions = preg_match_all('/\$this->assert\w+/', $body);
            if ($assertions === 0) {
                continue;
            }

            if (preg_match('/\n\s*foreach\s*\(\s*([^\n]*?)\s+as\s/', $body, $loop, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $subject = trim($loop[1][0]);

            // A literal, or something built in the test — cannot be empty by surprise.
            if (preg_match('/\w\s*\(/', $subject) !== 1
                || str_starts_with($subject, 'array(')
                || str_starts_with($subject, '[')
            ) {
                continue;
            }

            // Some assertion outside the loop already carries the test.
            $inside = substr($body, (int) $loop[0][1]);
            if (preg_match_all('/\$this->assert\w+/', $inside) !== $assertions) {
                continue;
            }

            if (preg_match(self::ESTABLISHES_SIZE, $body) === 1) {
                continue;
            }

            $found[] = $method[1][0] . ' — foreach (' . $subject . ')';
        }

        return $found;
    }

    /** The balanced body starting at an opening brace's offset. */
    private function bodyAfter(string $source, int $start): string
    {
        $depth  = 1;
        $length = strlen($source);

        for ($i = $start; $i < $length && $depth > 0; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
            }
        }

        return substr($source, $start, $i - $start);
    }

    /** @return list<string> */
    private function testFiles(string $root): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/tests', \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        return $files;
    }
}
