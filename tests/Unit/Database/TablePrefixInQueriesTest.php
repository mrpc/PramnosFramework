<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

/**
 * Every framework query names its tables with `#PREFIX#`.
 *
 * `QueryBuilder::table()` substitutes `#PREFIX#` at compile time and leaves a
 * bare name exactly as written. So on an installation with a table prefix — the
 * default, and what the framework's own `DB_USERSTABLE` resolves to — a query
 * written as `->table('users')` reads a table that does not exist.
 *
 * **The suite cannot catch this by running.** Both fixture configurations declare
 * `'prefix' => ''`, which makes `#PREFIX#users` and `users` the same string: every
 * test passes either way. That is how seventy-nine of these accumulated, ten of
 * them in `User\User` — three inside its constructor, so merely constructing a
 * user failed. A consuming application reported 97 failures, all
 * `Table '….users' doesn't exist`, on the first migration attempt.
 *
 * This is therefore a static check on the source, which is the only place the
 * difference is visible.
 */
class TablePrefixInQueriesTest extends TestCase
{
    /**
     * Tables the framework owns and prefixes.
     *
     * Derived from the source rather than listed by hand: a name is "prefixed"
     * when the framework writes it with `#PREFIX#` somewhere. A new table that
     * follows the convention is covered automatically; one that does not is not
     * this test's business.
     *
     * @return array<string, int>
     */
    private function prefixedTableNames(): array
    {
        $names = [];
        foreach ($this->frameworkSources() as $file) {
            preg_match_all(
                '/[\'"]#PREFIX#([A-Za-z_][A-Za-z0-9_]*)[\'"]/',
                (string) file_get_contents($file),
                $matches
            );
            foreach ($matches[1] as $name) {
                $names[$name] = ($names[$name] ?? 0) + 1;
            }
        }

        return $names;
    }

    /** @return string[] */
    private function frameworkSources(): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                dirname(__DIR__, 3) . '/src',
                \FilesystemIterator::SKIP_DOTS
            )
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $found[] = $entry->getPathname();
            }
        }

        return $found;
    }

    /**
     * No query names a prefixed table without its prefix.
     *
     * Matched in a `table()` / `from()` / join position only: a bare `'users'`
     * elsewhere is a column, an array key or a word in a sentence.
     */
    public function testNoQueryNamesAPrefixedTableWithoutItsPrefix(): void
    {
        // Arrange
        $names = array_keys($this->prefixedTableNames());
        $this->assertNotSame([], $names,
            'the framework must write at least one prefixed table, or this test is vacuous');
        $pattern = '/->(?:table|from|joinRaw|join|leftJoin|rightJoin)\(\s*[\'"]('
            . implode('|', array_map('preg_quote', $names))
            . ')[\'"]/';

        // Act
        $offenders = [];
        foreach ($this->frameworkSources() as $file) {
            $lines = preg_split('/\r?\n/', (string) file_get_contents($file)) ?: [];
            foreach ($lines as $number => $line) {
                if (preg_match($pattern, $line, $hit)) {
                    $offenders[] = basename($file) . ':' . ($number + 1)
                        . " → '" . $hit[1] . "'";
                }
            }
        }

        // Assert
        $this->assertSame([], $offenders,
            "these read a table that does not exist on a prefixed installation:\n  "
            . implode("\n  ", $offenders));
    }

    /**
     * The fixture prefix is empty, which is why the check above is static.
     *
     * Pinned so that nobody reads the test above and concludes the suite covers
     * this by running. If a fixture ever gains a prefix, this fails and the
     * comment gets rewritten — which is the right outcome either way.
     */
    public function testTheFixtureConfigurationsUseNoPrefix(): void
    {
        // Arrange
        $fixtures = glob(dirname(__DIR__, 2) . '/fixtures/app/*settings.php') ?: [];
        $this->assertNotSame([], $fixtures, 'the fixture settings must exist');

        // Act & Assert
        foreach ($fixtures as $fixture) {
            $this->assertStringContainsString("'prefix'", (string) file_get_contents($fixture),
                basename($fixture) . ' must declare a prefix, even an empty one');
        }
    }
}
