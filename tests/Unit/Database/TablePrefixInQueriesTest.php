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
     * Tables a framework migration creates under a plain name — which `SchemaBuilder` prefixes —
     * written without `#PREFIX#` in a query, or as a class's `TABLE`.
     *
     * The test above derives "prefixed" from names written with `#PREFIX#` somewhere, so a
     * feature that never once wrote its tables that way was invisible to it: every `llm` table
     * was queried bare, and `/admin/Ai` answered 500 on the first installation with a prefix.
     * The migrations are the other source of truth, and this reads them.
     *
     * Only migrations after the 2020-01-01 baseline: a legacy installation skips the baseline
     * (`migration_cutoff`) and keeps those tables under the names it already had.
     *
     * Known and not yet converted — enumerated, so a new table cannot join them silently:
     * `organizations`, `notifications` and `queuestats`, whose bare names an application may
     * also own.
     */
    public function testTablesTheMigrationsCreateAreQueriedWithThePrefix(): void
    {
        // Arrange
        $known   = ['organizations', 'notifications', 'queuestats'];
        $created = [];
        foreach (glob(dirname(__DIR__, 3) . '/database/migrations/framework/*/*.php') ?: [] as $migration) {
            if (str_starts_with(basename($migration), '2020_01_01_')) {
                continue;
            }
            preg_match_all("/createTable\\(\\s*'([A-Za-z_][A-Za-z0-9_]*)'/", (string) file_get_contents($migration), $m);
            foreach ($m[1] as $name) {
                $created[$name] = true;
            }
        }
        $names = array_diff(array_keys($created), $known);
        $this->assertNotEmpty($names, 'the sweep found nothing to check');
        $pattern = '/(?:->(?:table|from|join|leftJoin|rightJoin)\(\s*|const\s+TABLE\s*=\s*)[\'"]('
            . implode('|', array_map('preg_quote', $names))
            . ')(?:[\'"]|\s)/';

        // Act
        $offenders = [];
        foreach ($this->frameworkSources() as $file) {
            foreach (preg_split('/\r?\n/', (string) file_get_contents($file)) ?: [] as $number => $line) {
                if (preg_match($pattern, $line, $hit)) {
                    $offenders[] = basename($file) . ':' . ($number + 1) . " → '" . $hit[1] . "'";
                }
            }
        }

        // Assert
        $this->assertSame([], $offenders,
            "a migration creates these with the installation's prefix, and these read them without it:\n  "
            . implode("\n  ", $offenders));
    }

    /**
     * No migration names one of the framework's tables in raw SQL — `public.users`,
     * `` `usertokens` ``, `applications.oauth2_application_grants` — instead of through
     * `$schema->quoteTable()`.
     *
     * A name written out is the name on an installation without a prefix, and on MySQL not
     * even that for a schema-qualified one. Every such statement failed on the first
     * installation with a prefix — nineteen migrations' worth. `PrefixedFrameworkMigrationsTest`
     * runs them; this also reaches the statements a fresh run does not, `down()` and the other
     * dialect's branch.
     */
    public function testNoMigrationNamesAFrameworkTableInRawSql(): void
    {
        // Arrange
        $files  = glob(dirname(__DIR__, 3) . '/database/migrations/framework/*/*.php') ?: [];
        $tables = [];
        foreach ($files as $file) {
            preg_match_all("/(?:createTable|quoteTable|hasTable)\\(\\s*'([a-z_][a-z0-9_.]*)'/", (string) file_get_contents($file), $m);
            foreach ($m[1] as $name) {
                $tables[$name] = true;
            }
        }
        $plain     = array_keys(array_filter($tables, static fn (bool $t, string $n): bool => !str_contains($n, '.'), ARRAY_FILTER_USE_BOTH));
        $qualified = array_keys(array_filter($tables, static fn (bool $t, string $n): bool => str_contains($n, '.'), ARRAY_FILTER_USE_BOTH));
        $keyword   = '(?:FROM|JOIN|TABLE|INTO|ON|REFERENCES|UPDATE)\\s+';
        $pattern   = '/' . $keyword . '(?:public\\.(?:' . implode('|', $plain) . ')\\b'
            . '|`(?:' . implode('|', $plain) . ')`'
            . '|(?:' . implode('|', array_map('preg_quote', $qualified)) . ')\\b)/i';
        $this->assertNotEmpty($files, 'the sweep found nothing to check');

        // Act
        $offenders = [];
        foreach ($files as $file) {
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && preg_match($pattern, $token[1], $hit) === 1) {
                    $offenders[] = basename($file) . ':' . $token[2] . ' → ' . trim($hit[0]);
                }
            }
        }

        // Assert
        $this->assertSame([], $offenders,
            "these name a framework table in raw SQL; use \$schema->quoteTable():\n  " . implode("\n  ", $offenders));
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
