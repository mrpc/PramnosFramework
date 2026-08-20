<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;

/**
 * Every fully-qualified `\Pramnos\…` name in `src/` is a class that exists.
 *
 * WHAT: no production file writes `\Pramnos\Some\Thing::method()` or `new \Pramnos\Some\Thing`
 *       for a `Some\Thing` the autoloader cannot resolve.
 *
 * WHY:  it is {@see LegacyClassReferenceTest} in modern clothes. That guard catches the
 *       CMS-era `pramnos_theme::getTheme()` shape; this one catches the same mistake made
 *       with a namespace that looks entirely plausible:
 *
 *       ```php
 *       $loader = new \Pramnos\Database\Migrations\MigrationLoader();   // no such namespace
 *       $runner = new \Pramnos\Database\Migrations\MigrationRunner($db); // it is \Pramnos\Database\
 *       ```
 *
 *       Those two lines sat in `DevPanelController::fetchMigrationStatus()` inside a
 *       `try { … } catch (\Throwable) { return ['—', '—', '—']; }`. The `Error: Class not
 *       found` was caught by the same handler written for a missing history table, so the
 *       Migrations card on the dashboard read "— / — / —" on every installation since it
 *       was written, and looked exactly like a card for a feature nobody had configured.
 *
 *       Review does not catch this: the name is well-formed, the file it should be in
 *       exists, and the class it should name exists one segment away. Only resolution
 *       catches it, and a caught `Error` is resolution being thrown away.
 *
 * Read from the source rather than executed, for the reason the sibling guard gives: a
 * fatal on a branch nothing exercises is what behavioural tests do not reach.
 */
class MissingClassReferenceTest extends TestCase
{
    /**
     * The repository root.
     *
     * @return string
     */
    private function root(): string
    {
        // tests/Unit/Framework -> tests/Unit -> tests -> the repository
        return dirname(__DIR__, 3);
    }

    /**
     * Every PHP file under `src/`.
     *
     * @return array<string, string> Repository-relative path => source
     */
    private function sourceFiles(): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->root() . '/src',
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = @file_get_contents($file->getPathname());
            if ($source === false) {
                continue;
            }
            $files[str_replace($this->root() . '/', '', $file->getPathname())] = $source;
        }

        return $files;
    }

    /**
     * The `\Pramnos\…` names in one file that no autoloadable class answers to.
     *
     * Works on tokens rather than a regular expression over the text, which is what makes
     * the two classes of false positive impossible rather than merely unlikely:
     *
     *  - **imports.** `use \Pramnos\Document\DocumentTypes;` names a *namespace*, and there
     *    is no class by that name — correctly, since nothing is meant to instantiate it.
     *  - **strings.** `Console\Commands\Init` carries entire scaffolded templates in
     *    heredocs, docblocks included; those are string tokens, not name tokens, so they
     *    are never seen here.
     *
     * @param string $source PHP source
     * @return array<int, string> "line -> name" for each unresolvable reference
     */
    private function missingReferences(string $source): array
    {
        $missing = [];
        $inUse   = false;

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_USE) {
                $inUse = true;
                continue;
            }
            if ($token === ';' || $token === '{') {
                $inUse = false;
                continue;
            }
            if (!is_array($token) || $token[0] !== T_NAME_FULLY_QUALIFIED) {
                continue;
            }
            if ($inUse) {
                continue;
            }

            $name = $token[1];
            if (stripos($name, '\\Pramnos\\') !== 0) {
                continue;
            }
            if (class_exists($name)
                || interface_exists($name)
                || trait_exists($name)
                || enum_exists($name)
            ) {
                continue;
            }

            $missing[] = $token[2] . ' -> ' . $name;
        }

        return $missing;
    }

    /**
     * The scan reads a real number of files.
     *
     * **The assertion the rest depends on.** A wrong path yields an empty scan, and an
     * empty scan satisfies "every reference resolves" perfectly — which has happened in
     * this repository before, with `dirname(__DIR__, 5)` resolving outside the tree.
     *
     * @return void
     */
    public function testTheScanReadsTheSourceTree(): void
    {
        // Act
        $files = $this->sourceFiles();

        // Assert
        $this->assertGreaterThan(200, count($files), 'src/ is hundreds of files.');
        $this->assertArrayHasKey('src/Pramnos/DevPanel/DevPanelController.php', $files);
    }

    /**
     * No production file names a `\Pramnos\…` class that does not exist.
     *
     * @return void
     */
    public function testEveryFrameworkClassReferenceResolves(): void
    {
        // Arrange
        $offenders = [];

        // Act
        foreach ($this->sourceFiles() as $path => $source) {
            foreach ($this->missingReferences($source) as $reference) {
                $offenders[] = $path . ':' . $reference;
            }
        }

        // Assert
        $this->assertSame(
            [],
            $offenders,
            "These name classes the autoloader cannot resolve, so the line is a fatal —\n"
            . "and a fatal inside a catch(\\Throwable) is a panel that renders as empty:\n"
            . implode("\n", $offenders)
        );
    }

    /**
     * The guard detects the shape it claims to.
     *
     * Written because the assertion above passes trivially on a clean tree, which is
     * indistinguishable from a scan that matches nothing. This feeds it the real
     * historical line.
     *
     * @return void
     */
    public function testTheGuardDetectsAPlausibleButWrongNamespace(): void
    {
        // Arrange — the line as it stood in fetchMigrationStatus()
        $source = '<?php $loader = new \Pramnos\Database\Migrations\MigrationLoader();';

        // Act
        $missing = $this->missingReferences($source);

        // Assert
        $this->assertCount(1, $missing);
        $this->assertStringContainsString('MigrationLoader', $missing[0]);
    }

    /**
     * A static call to a missing class is caught too.
     *
     * The `new` form and the `::` form are written by different people on different days;
     * a guard that only saw one of them would have caught half of the pair that started
     * this.
     *
     * @return void
     */
    public function testTheGuardDetectsAStaticCall(): void
    {
        // Arrange
        $source = '<?php \Pramnos\Database\Migrations\MigrationRunner::getHistory();';

        // Act + Assert
        $this->assertCount(1, $this->missingReferences($source));
    }

    /**
     * A namespace import is not a class reference.
     *
     * `use \Pramnos\Document\DocumentTypes;` in `Document.php` imports a namespace, and no
     * class answers to that name. A guard that flagged it would be asking for the import
     * to be deleted, which would break the file.
     *
     * @return void
     */
    public function testTheGuardIgnoresNamespaceImports(): void
    {
        // Arrange
        $source = '<?php namespace X; use \Pramnos\Document\DocumentTypes;';

        // Act + Assert
        $this->assertSame([], $this->missingReferences($source));
    }

    /**
     * A name inside a string is not a class reference.
     *
     * `Console\Commands\Init` ships scaffolded file templates as heredocs, and their
     * docblocks name classes for the reader's IDE rather than for this runtime.
     *
     * @return void
     */
    public function testTheGuardIgnoresNamesInsideStrings(): void
    {
        // Arrange — a template that mentions a class in a docblock, as Init.php does
        $source = '<?php $tpl = <<<T' . "\n"
            . '<?php /** @var \Pramnos\Does\Not\Exist $this */ ?>' . "\n"
            . 'T;';

        // Act + Assert
        $this->assertSame([], $this->missingReferences($source));
    }

    /**
     * A class that does exist is left alone.
     *
     * The other half of "the guard works": one that flagged everything would also flag the
     * historical line, and prove nothing.
     *
     * @return void
     */
    public function testTheGuardPassesAResolvableReference(): void
    {
        // Arrange
        $source = '<?php $x = new \Pramnos\Database\MigrationLoader();';

        // Act + Assert
        $this->assertSame([], $this->missingReferences($source));
    }
}
