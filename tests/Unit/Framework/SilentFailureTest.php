<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;

/**
 * Guards the two habits that let this framework's bugs hide for years.
 *
 * Every serious finding in the recent audit shared one shape: a failure that
 * looked like a result. A controller queried a table no migration creates and
 * the exception was swallowed; a dev panel asked for columns that do not exist
 * and rendered as empty; a permission check was skipped because the class it
 * asked for was absent. None of them crashed. All of them lied.
 *
 * These tests do not chase individual bugs — they make the *shape* costly. An
 * empty `catch` has to carry a sentence saying why the failure does not matter,
 * and a coverage exclusion has to be honest about why it is there. Neither can
 * be enforced by a linter, and both are cheap to check by reading the source.
 */
class SilentFailureTest extends TestCase
{
    /**
     * Every PHP file under src/.
     *
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $files = [];
        $root  = dirname(__DIR__, 3) . '/src';

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * No `catch` block is empty and silent.
     *
     * Swallowing an exception is sometimes right — instrumentation must not
     * break a response, a webhook must answer even if its log write fails. What
     * is never right is doing it without saying why, because the next reader
     * cannot tell a considered decision from an unfinished one. Four dev-panel
     * sections queried tables that do not exist and had been rendering empty
     * ever since, behind exactly this.
     *
     * A comment is the whole requirement. If one cannot be written, the failure
     * mattered and belongs in a log.
     */
    public function testNoCatchBlockSwallowsAnExceptionWithoutSayingWhy(): void
    {
        // Arrange
        $offenders = [];

        // Act
        foreach ($this->sourceFiles() as $file) {
            $source = (string) file_get_contents($file);

            preg_match_all('/catch\s*\(([^)]*)\)\s*\{([^{}]*)\}/', $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

            foreach ($matches as $match) {
                $body = $match[2][0];

                // Anything that acts — logs, rethrows, returns — is not silent.
                $stripped = preg_replace('#//[^\n]*#', '', $body);
                $stripped = trim((string) preg_replace('#/\*.*?\*/#s', '', (string) $stripped));
                if ($stripped !== '') {
                    continue;
                }

                if (str_contains($body, '//') || str_contains($body, '/*')) {
                    continue;
                }

                $line        = substr_count(substr($source, 0, (int) $match[0][1]), "\n") + 1;
                $offenders[] = str_replace(dirname(__DIR__, 3) . '/', '', $file) . ':' . $line;
            }
        }

        // Assert
        $this->assertSame(
            [],
            $offenders,
            "These catch blocks discard an exception without a word. Say why the "
            . "failure does not matter here, or log it:\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * No coverage exclusion claims to be covered by tests that do not exist.
     *
     * `@codeCoverageIgnore — exercised via integration, not unit tests` excuses
     * a method from coverage on the strength of a claim, and two such claims
     * turned out to be false: one for a block that could never run, another for
     * a method no integration test touched. An annotation that says "tested
     * elsewhere" is a promise, and this is the only thing that reads it.
     *
     * The check is deliberately narrow — it looks for the phrasings that make a
     * coverage promise, and requires a test file to mention the class.
     */
    public function testCoverageExclusionsThatClaimTestsElsewhereAreTrue(): void
    {
        // Arrange
        $promises  = '/@codeCoverageIgnore\w*\s*[—-]?\s*[^\n]*(exercised|covered)\s+(via|by|through)\s+([A-Za-z\/ ]+)/i';
        $testRoot  = dirname(__DIR__, 2);
        $unproven  = [];

        // Act
        foreach ($this->sourceFiles() as $file) {
            $source = (string) file_get_contents($file);
            if (!preg_match($promises, $source)) {
                continue;
            }

            // The promise is about this class being tested somewhere. Take the
            // class name and ask whether any test mentions it at all.
            if (!preg_match('/^\s*(?:final\s+|abstract\s+)*class\s+(\w+)/m', $source, $class)) {
                continue;
            }

            $mentioned = false;
            /** @var \SplFileInfo $test */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($testRoot)) as $test) {
                if ($test->getExtension() !== 'php') {
                    continue;
                }
                if (str_contains((string) file_get_contents($test->getPathname()), $class[1])) {
                    $mentioned = true;
                    break;
                }
            }

            if (!$mentioned) {
                $unproven[] = str_replace(dirname(__DIR__, 3) . '/', '', $file)
                    . ' (' . $class[1] . ')';
            }
        }

        // Assert
        $this->assertSame(
            [],
            $unproven,
            "These files exclude code from coverage on the grounds that it is "
            . "tested elsewhere, and no test mentions the class:\n  "
            . implode("\n  ", $unproven)
        );
    }
}
