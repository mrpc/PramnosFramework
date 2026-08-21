<?php

declare(strict_types=1);

namespace Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * Every fully-qualified `{@see \Some\Class}` in `src/` must name something that
 * exists.
 *
 * This is the same class of problem as {@see \Tests\Unit\Docs\DocsRetrievabilityTest}
 * and it is here for the same reason: **it is not visible in the diff of a single
 * change.** A docblock can point at a class that was planned and never written, or
 * at one that has since been renamed, and nothing fails — the reference reads as
 * authoritative right up until somebody goes looking.
 *
 * That happened. `WebSocketClient` shipped pointing at a
 * `\Pramnos\Broadcasting\PusherProtocolClient` that was described in the guide and
 * never built; a consuming project found it by grepping all 489 files in `src/`,
 * having first gone looking for the class. The cost was somebody else's afternoon,
 * and the fix was one docblock.
 *
 * Only **fully-qualified** references are checked. A relative `{@see Drivers\Foo}`
 * resolves against the namespace of the file it appears in, and resolving those
 * properly means parsing `use` statements — worth doing if relative references ever
 * cause the same problem, and not worth pre-empting.
 */
class SeeReferencesResolveTest extends TestCase
{
    /**
     * No `{@see}` in the framework points at a class, interface or trait that does
     * not exist.
     *
     * The failure message lists the file, because the reference is the only place
     * the mistake is visible.
     */
    public function testEveryFullyQualifiedSeeReferenceResolves(): void
    {
        // Arrange
        $root    = dirname(__DIR__, 3) . '/src';
        $missing = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        // Act
        foreach ($files as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match_all('/\{@see\s+(\\\\[A-Za-z][A-Za-z0-9_\\\\]*)/', $source, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $reference) {
                $symbol = ltrim($reference, '\\');

                // A method reference is trimmed to its class: the class existing is
                // what a reader needs, and asserting on methods would mean loading
                // every one of them via reflection.
                $symbol = explode('::', $symbol)[0];

                if (
                    class_exists($symbol)
                    || interface_exists($symbol)
                    || trait_exists($symbol)
                    || enum_exists($symbol)
                ) {
                    continue;
                }

                $relative = str_replace(dirname($root) . '/', '', $file->getPathname());
                $missing[$symbol][$relative] = true;
            }
        }

        // Assert
        $report = [];
        foreach ($missing as $symbol => $inFiles) {
            $report[] = '  ' . $symbol . "\n    referenced by: " . implode(', ', array_keys($inFiles));
        }

        $this->assertSame(
            [],
            $report,
            "These {@see} references name something that does not exist:\n" . implode("\n", $report)
        );
    }

    /**
     * The check would actually catch a dangling reference.
     *
     * A guard that cannot fail is the thing it is guarding against, one level up —
     * so the regex and the existence test are exercised against a deliberate
     * miss rather than only against a clean tree.
     */
    public function testTheCheckDetectsADanglingReference(): void
    {
        // Arrange — a docblock of the shape the sweep looks for
        $source = '/** {@see \Pramnos\Nothing\ThatExists} and {@see \Pramnos\Http\WebSocketClient} */';

        // Act
        preg_match_all('/\{@see\s+(\\\\[A-Za-z][A-Za-z0-9_\\\\]*)/', $source, $matches);

        $unresolved = array_values(array_filter(
            array_map(static fn (string $r): string => ltrim($r, '\\'), $matches[1]),
            static fn (string $s): bool => !class_exists($s) && !interface_exists($s) && !trait_exists($s)
        ));

        // Assert
        $this->assertSame(['Pramnos\Nothing\ThatExists'], $unresolved);
    }
}
