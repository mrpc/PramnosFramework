<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Testing;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A `fail()` this suite writes has to be able to fail.
 *
 * WHAT: that no `$this->fail()` sits inside a `try` whose own `catch` would swallow it.
 * WHY:  `PHPUnit\Framework\AssertionFailedError` extends `\RuntimeException`. So the shape
 *
 *       ```php
 *       try {
 *           $subject->mustThrow();
 *           $this->fail('it did not throw');      // ← throws AssertionFailedError
 *       } catch (\Exception $e) {                  // ← catches it
 *           $this->assertStringContainsString('…', $e->getMessage());
 *       }
 *       ```
 *
 *       turns the one branch that says "this must not happen" into an assertion about a
 *       message **PHPUnit wrote**. The test passes whether the subject throws or not, and
 *       the reader has every reason to believe otherwise: the `fail()` is right there.
 *
 *       Found forty of these in one sweep, in twenty-four files, written by several
 *       people over a long time — including two I wrote myself today, in two different
 *       files, after fixing the first one. That is what makes it worth a guard rather than
 *       a round of review: it is invisible at the point of writing and it reads correctly.
 *
 *       All forty branches turned out to be sound once they could fail. The value is not
 *       what it caught — it is that forty tests now mean what they say.
 *
 * The fix, everywhere, is to let PHPUnit's error through first:
 *
 * ```php
 * } catch (\PHPUnit\Framework\AssertionFailedError $assertionFailure) {
 *     throw $assertionFailure;
 * } catch (\Exception $e) {
 *     …
 * }
 * ```
 *
 * or to capture the outcome and assert after the `try` — which reads better when there is
 * more than one thing to check.
 */
#[CoversNothing]
class FailIsNotSwallowedTest extends TestCase
{
    /**
     * Only `\Throwable`, `\Exception` and `\RuntimeException` can swallow it.
     *
     * A `catch (ValidationException $e)` cannot: the error is a `RuntimeException` and
     * nothing else. Being narrow here is what keeps the guard honest — a sweep that
     * flagged every `catch` around a `fail()` would report 128 sites, of which 88 are
     * correct code, and a guard nobody believes is a guard somebody deletes.
     */
    private const SWALLOWS = '/^\s*\\\\?(Throwable|Exception|RuntimeException)\s*(\$\w+)?\s*$/';

    public function testNoFailSitsInsideACatchThatSwallowsIt(): void
    {
        // Arrange
        $root = dirname(__DIR__, 3);

        // Act
        $offenders = [];
        // A sweep over nothing passes. The collection is asserted non-empty first, because a
        // moved directory or a renamed helper turns this guard into a no-op that still reports
        // success — which is how a guard stops guarding without anybody noticing.
        $this->assertNotEmpty($this->testFiles($root), 'the sweep found nothing to check');
        foreach ($this->testFiles($root) as $file) {
            $source = (string) file_get_contents($file);

            if (!preg_match_all('/try\s*\{(.*?)\}\s*catch\s*\(([^)]*)\)/s', $source, $blocks, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($blocks as $block) {
                [$body]   = $block[1];
                [$caught] = $block[2];

                if (!str_contains($body, '->fail(')) {
                    continue;
                }
                // An explicit re-throw of PHPUnit's error is the fix, not the fault.
                if (str_contains($body, 'AssertionFailedError')) {
                    continue;
                }
                foreach (explode('|', $caught) as $alternative) {
                    if (preg_match(self::SWALLOWS, $alternative) === 1) {
                        $line = substr_count(substr($source, 0, (int) $block[0][1]), "\n") + 1;
                        $offenders[] = substr($file, strlen($root) + 1) . ':' . $line
                            . ' — catch(' . trim($caught) . ')';
                        break;
                    }
                }
            }
        }

        // Assert
        $this->assertSame(
            [],
            $offenders,
            "a fail() here cannot fail — its own catch swallows it:\n"
            . implode("\n", $offenders)
            . "\n\nLet PHPUnit's error through first:\n"
            . "    } catch (\\PHPUnit\\Framework\\AssertionFailedError \$assertionFailure) {\n"
            . "        throw \$assertionFailure;\n"
            . "    } catch (…) {\n"
            . 'or capture the outcome and assert after the try.'
        );
    }

    /**
     * Every test file in the suite, this one included.
     *
     * @return list<string>
     */
    private function testFiles(string $root): array
    {
        $files    = [];
        // CATCH_GET_CHILD: a directory the iterator cannot open — a stale symlink, a
        // case-folded duplicate on a case-insensitive mount — must not take the whole
        // guard with it. A sweep that throws is a sweep that gets deleted.
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
