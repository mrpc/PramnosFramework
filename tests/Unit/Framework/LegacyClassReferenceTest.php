<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;

/**
 * No production code references a legacy `pramnos_*` CMS class.
 *
 * WHAT: nothing under `src/` names a class of the form `pramnos_something` — as a static
 *       call, a `new`, or an instanceof.
 *
 * WHY:  those names come from the CMS this framework grew out of. Inside a namespaced file
 *       they resolve to nothing, so the line is a guaranteed `Class "pramnos_x" not found`
 *       fatal — and because each one sat on a branch that rarely executed, none of them was
 *       noticed. Three have been found:
 *
 *       - `pramnos_theme::getTheme()` in `Theme::getThemeObjects()`, fixed 2026-08-14. That
 *         method could never have run.
 *       - `pramnos_request::$originalRequestNoChange` in `Amp::render()`. It sits in the
 *         branch that builds a canonical when none was set — so **every AMP page without an
 *         explicit canonical** fatalled, which is the case the branch exists to handle.
 *       - `pramnos_settings::setSetting()` in `Theme::saveSettings()`. A theme's settings
 *         form could be rendered and never saved.
 *
 *       All three had a modern equivalent with the identical member name, one namespace
 *       away. That is what makes this worth a guard rather than three fixes: the mistake is
 *       mechanical, invisible in review, and the remedy is always the same.
 *
 * Read from the source rather than executed, like {@see ConnectionPathPurityTest} and
 * {@see ApplicationFactoryPurityTest}: a fatal on a branch nothing exercises is exactly what
 * behavioural tests do not reach — which is how all three survived this long.
 */
class LegacyClassReferenceTest extends TestCase
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
     * Every PHP file under `src/`, with comments stripped.
     *
     * Comments are removed with `token_get_all()` because the framework *documents* these
     * names — this class does, and so do the three fixes — and a guard that cannot tell a
     * call from an explanation of why not to make one would forbid writing the explanation.
     *
     * @return array<string, string> Repository-relative path => code without comments
     */
    private function sourceFiles(): array
    {
        $directory = $this->root() . '/src';
        $files     = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = @file_get_contents($file->getPathname());
            if ($source === false) {
                continue;
            }

            $code = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token)) {
                    if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                        continue;
                    }
                    $code .= $token[1];
                    continue;
                }
                $code .= $token;
            }

            $relative = str_replace($this->root() . '/', '', $file->getPathname());
            $files[$relative] = $code;
        }

        return $files;
    }

    /**
     * The scan reads a real number of files.
     *
     * **The assertion the rest depends on.** A wrong path yields an empty scan, and an empty
     * scan satisfies "nothing references a legacy class" perfectly. A structural guard in
     * this repository once did precisely that: `dirname(__DIR__, 5)` resolved outside the
     * tree, zero files were read, and every assertion passed.
     *
     * @return void
     */
    public function testTheScanReadsTheSourceTree(): void
    {
        // Act
        $files = $this->sourceFiles();

        // Assert
        $this->assertGreaterThan(200, count($files), 'src/ is hundreds of files.');
        $this->assertArrayHasKey('src/Pramnos/Theme/Theme.php', $files);
        $this->assertArrayHasKey('src/Pramnos/Document/DocumentTypes/Amp.php', $files);
    }

    /**
     * No file names a legacy `pramnos_*` class.
     *
     * Matched as a **class reference** — `pramnos_x::`, `new pramnos_x`, `instanceof
     * pramnos_x` — rather than as the string `pramnos_`, which appears legitimately in
     * table prefixes, setting keys, cache namespaces and package names throughout.
     *
     * @return void
     */
    public function testNoProductionCodeReferencesALegacyClass(): void
    {
        // Arrange
        $offenders = [];

        // Act
        foreach ($this->sourceFiles() as $path => $code) {
            if (preg_match_all(
                '/(?:\bnew\s+|\binstanceof\s+|(?<![\w$>])\\\\?)(pramnos_[a-z_]+)\s*(?:::|\()/i',
                $code,
                $matches
            )) {
                foreach (array_unique($matches[1]) as $name) {
                    $offenders[] = $path . ' → ' . $name;
                }
            }
        }

        // Assert
        $this->assertSame(
            [],
            $offenders,
            "These name classes that do not exist, so the line is a guaranteed fatal.\n"
            . "Each has a modern equivalent one namespace away, usually with the same "
            . "member name:\n" . implode("\n", $offenders)
        );
    }

    /**
     * The guard detects the shape it claims to.
     *
     * Written because the assertion above passes trivially on a clean tree, which is
     * indistinguishable from a regular expression that matches nothing. This feeds it the
     * three real historical forms and requires a hit for each.
     *
     * @return void
     */
    public function testTheGuardDetectsEachHistoricalForm(): void
    {
        $pattern = '/(?:\bnew\s+|\binstanceof\s+|(?<![\w$>])\\\\?)(pramnos_[a-z_]+)\s*(?:::|\()/i';

        // Arrange — the three forms actually found in this framework
        $samples = [
            'static call'            => '$x = pramnos_theme::getTheme($a, $b, false);',
            'namespaced static call' => '$y = \pramnos_request::$originalRequestNoChange;',
            'instantiation'          => '$z = new pramnos_settings();',
        ];

        foreach ($samples as $label => $code) {
            // Act & Assert
            $this->assertSame(
                1,
                preg_match($pattern, $code),
                "The guard must detect a legacy reference written as a {$label}."
            );
        }

        // And it must not fire on the legitimate uses of the same prefix
        foreach ([
            "\$prefix = 'pramnos_';",
            "Cache::getInstance('pramnos_settings');",
            "\$table = 'pramnos_users';",
            '$this->pramnos_thing = 1;',
        ] as $innocent) {
            $this->assertSame(
                0,
                preg_match($pattern, $innocent),
                "The guard must not fire on: {$innocent}"
            );
        }
    }
}
