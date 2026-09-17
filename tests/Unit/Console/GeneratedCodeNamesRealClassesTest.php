<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Every framework or vendor class named in generated code exists.
 *
 * WHAT: the scaffolding templates, and the heredocs `Init` writes files from, may only name
 *       `\Pramnos\…` or vendor classes the package actually ships.
 * WHY:  `MissingClassReferenceTest` has swept `src/` for a long time — and generated code is
 *       not in `src/`. It is written here, executed in somebody else's project, and this is
 *       the difference:
 *
 *       ```
 *       PHP Fatal error: Uncaught Error: Class "Dotenv\Dotenv" not found
 *         in /home/…/www/webhook.php:15
 *       ```
 *
 *       That is **vlucas/phpdotenv**, which neither a scaffolded project nor this framework
 *       requires; what ships is symfony/dotenv behind `loadDotenv()`. The file has exactly
 *       one caller and it is a machine in production: it cannot fail in development, nobody
 *       opens it in a browser, and the only evidence is a red tick in a delivery list on
 *       github.com. A project can be deployed, apparently working, with its deployment
 *       mechanism dead.
 *
 *       A syntax check does not catch it — the file parses perfectly. Only asking whether
 *       the class exists does.
 *
 * It found a second one on its first run: every generated view carried
 * `@var \Pramnos\View\View $this`, and the class is `\Pramnos\Application\View`. A docblock
 * naming a class that does not exist is the ORM guide's mistake in miniature — nothing
 * fatals, the IDE resolves nothing, and whoever reads it learns a name that is not real.
 */
#[CoversNothing]
class GeneratedCodeNamesRealClassesTest extends TestCase
{
    /**
     * Namespace roots that must resolve.
     *
     * A generated file also names the *application's* classes — `App\Controllers\Home`,
     * `{{ namespace }}\Models\Thing` — which by definition do not exist here. Listing the
     * roots that must resolve is narrower and does not need a rule about which ones may not.
     */
    private const MUST_RESOLVE = [
        'Pramnos', 'Symfony', 'League', 'Dotenv', 'Monolog', 'Psr',
        'Firebase', 'Ramsey', 'GuzzleHttp', 'Doctrine', 'Twig', 'Nyholm',
    ];

    public function testEveryClassGeneratedCodeNamesExists(): void
    {
        // Arrange
        $root    = dirname(__DIR__, 3);
        $sources = $this->generatedSources($root);
        $this->assertNotEmpty($sources, 'the sweep found nothing to check');

        // Act
        $missing = [];
        foreach ($sources as $label => $code) {
            foreach ($this->classReferences($code) as $name) {
                if (class_exists($name) || interface_exists($name) || trait_exists($name)
                    || enum_exists($name)
                ) {
                    continue;
                }

                $missing[] = $label . ' → ' . $name;
            }
        }

        // Assert
        $this->assertSame(
            [],
            array_values(array_unique($missing)),
            "generated code names classes this package does not ship:\n"
            . implode("\n", array_unique($missing))
            . "\n\nThe file will parse and then fatal in somebody else's project."
        );
    }

    /**
     * The templates, plus the generator itself — its heredocs are generated code too.
     *
     * `www/webhook.php` is written from a heredoc in `Init.php`, not from a `.stub`, which
     * is precisely where the Dotenv reference lived.
     *
     * @return array<string, string>
     */
    private function generatedSources(string $root): array
    {
        $sources = [];

        foreach (glob($root . '/scaffolding/templates/*.stub') ?: [] as $path) {
            $sources['templates/' . basename($path)] = (string) file_get_contents($path);
        }

        foreach (['Init.php', 'MakeCommandBase.php'] as $generator) {
            $path = $root . '/src/Pramnos/Console/Commands/' . $generator;
            if (is_file($path)) {
                $sources[$generator] = (string) file_get_contents($path);
            }
        }

        return $sources;
    }

    /**
     * Fully-qualified names under a root that must resolve.
     *
     * Both spellings are collected: a template writes `\Pramnos\Http\Response`, and a
     * heredoc inside the generator escapes it as `\\Pramnos\\Http\\Response`.
     *
     * @return list<string>
     */
    private function classReferences(string $code): array
    {
        $normalised = str_replace('\\\\', '\\', $code);

        /*
         * Comments out, then `namespace` and `use` statements.
         *
         * A namespace is not a class — `namespace Pramnos\Console\Commands;` would be
         * reported as a missing class for ever — and a comment naming a class is prose,
         * including the one above the webhook's `loadDotenv()` explaining what it replaced.
         * Without both, this guard reports four things nobody can fix and stops being read.
         */
        $normalised = (string) preg_replace('#/\*.*?\*/#s', '', $normalised);
        $normalised = (string) preg_replace('#^\s*(//|\*|\#).*$#m', '', $normalised);
        $normalised = (string) preg_replace('/^\s*(namespace|use)\s+[^;]+;/m', '', $normalised);

        $found = [];

        if (preg_match_all(
            '/\\\\?([A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)+)/',
            $normalised,
            $matches
        )) {
            foreach (array_unique($matches[1]) as $name) {
                if (in_array(explode('\\', $name)[0], self::MUST_RESOLVE, true)) {
                    $found[] = $name;
                }
            }
        }

        return $found;
    }
}
