<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\ThemeBuild;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `theme:build` — the palette turned into what a build without npm can read.
 *
 * A Tailwind project with npm needs none of this: its `app.css` imports
 * `app/theme.css` and the daisyUI plugin reads the blocks. Everything else — buildless
 * Tailwind, Bootstrap, plain CSS, a SPA — needs the same tokens in a form it can
 * consume, and the only alternative to generating them is a second hand-written copy
 * of the palette that stops agreeing with the first.
 */
class ThemeBuildTest extends TestCase
{
    private string $root = '';

    private const PALETTE = <<<'CSS'
    @plugin "daisyui/theme" {
        name: "acme";
        default: true;
        color-scheme: light;
        --color-primary: oklch(54.6% 0.215 262.9);
    }
    CSS;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pf-theme-build-' . getmypid() . '-' . uniqid();
        mkdir($this->root . '/app/themes', 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $this->removeTree($this->root);
        }
    }

    /**
     * A palette produces both outputs, in the places the theme links them from.
     */
    public function testItWritesTheStylesheetAndTheJson(): void
    {
        // Arrange
        file_put_contents($this->root . '/app/themes/theme.css', self::PALETTE);

        // Act
        $tester = $this->build([]);

        // Assert
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $css = (string) file_get_contents($this->root . '/www/assets/css/theme-tokens.css');
        $this->assertStringContainsString('--color-primary: oklch(54.6% 0.215 262.9);', $css);
        $this->assertStringContainsString(':root', $css);

        $json = json_decode(
            (string) file_get_contents($this->root . '/www/assets/theme-tokens.json'),
            true
        );
        $this->assertSame('acme', $json['acme']['name'] ?? null);
    }

    /**
     * A second run writes nothing and says so.
     *
     * A build that rewrites identical files churns a repository's history and, worse,
     * makes "the theme changed" indistinguishable from "the build ran".
     */
    public function testASecondRunReportsUnchanged(): void
    {
        // Arrange
        file_put_contents($this->root . '/app/themes/theme.css', self::PALETTE);
        $this->build([]);

        // Act
        $tester = $this->build([]);

        // Assert
        $this->assertStringContainsString('unchanged', $tester->getDisplay());
    }

    /**
     * `--check` fails on a stale output and writes nothing.
     *
     * For CI: a generated file in a repository can go stale, and a stale palette is
     * invisible until somebody opens the theme nobody develops in.
     */
    public function testCheckFailsWhenTheOutputIsStaleAndWritesNothing(): void
    {
        // Arrange — an output from an older palette
        file_put_contents($this->root . '/app/themes/theme.css', self::PALETTE);
        mkdir($this->root . '/www/assets/css', 0777, true);
        file_put_contents($this->root . '/www/assets/css/theme-tokens.css', '/* old */');

        // Act
        $tester = $this->build(['--check' => true]);

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('theme-tokens.css', $tester->getDisplay());
        $this->assertSame(
            '/* old */',
            file_get_contents($this->root . '/www/assets/css/theme-tokens.css'),
            '--check must not write'
        );
    }

    /**
     * `--check` passes once the outputs match.
     */
    public function testCheckPassesOnAFreshBuild(): void
    {
        // Arrange
        file_put_contents($this->root . '/app/themes/theme.css', self::PALETTE);
        $this->build([]);

        // Act
        $tester = $this->build(['--check' => true]);

        // Assert
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
    }

    /**
     * No palette is a failure that says what to write, not a stack trace.
     */
    public function testAMissingPaletteExplainsItself(): void
    {
        // Act
        $tester = $this->build([]);

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('No palette at', $tester->getDisplay());
    }

    /**
     * A file with no `@plugin` block is refused rather than silently emptying the
     * theme.
     *
     * The likely cause is a paste that lost its outer block — and overwriting a
     * working `theme-tokens.css` with nothing turns that typo into a colourless site.
     */
    public function testAPaletteWithNoThemesIsRefused(): void
    {
        // Arrange
        file_put_contents($this->root . '/app/themes/theme.css', "body { color: red; }\n");

        // Act
        $tester = $this->build([]);

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('declares no themes', $tester->getDisplay());
    }

    /**
     * An empty `--json` skips that output for a project with no JavaScript.
     */
    public function testTheJsonOutputCanBeSkipped(): void
    {
        // Arrange
        file_put_contents($this->root . '/app/themes/theme.css', self::PALETTE);

        // Act
        $tester = $this->build(['--json' => '']);

        // Assert
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertFileDoesNotExist($this->root . '/www/assets/theme-tokens.json');
        $this->assertFileExists($this->root . '/www/assets/css/theme-tokens.css');
    }

    /**
     * Run the command against this test's temporary project.
     *
     * `ROOT` is a constant the framework defines once per process, so the command's
     * root is overridden here instead — the same seam a project with an unusual layout
     * would use.
     *
     * @param array<string, mixed> $input
     */
    private function build(array $input): CommandTester
    {
        $command = new class ($this->root) extends ThemeBuild {
            public function __construct(private readonly string $projectRoot)
            {
                parent::__construct();
            }

            protected function projectRoot(): string
            {
                return $this->projectRoot;
            }
        };

        $application = new Application();
        $application->add($command);

        $tester = new CommandTester($command);
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }

    private function removeTree(string $path): void
    {
        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeTree($full) : @unlink($full);
        }
        @rmdir($path);
    }

    /**
     * A palette still at the path the feature first used is found.
     *
     * The file moved to `app/themes/` — beside the theme directories that read it, rather
     * than loose among `app.php` and `settings.php`. Not looking in the old place would
     * fail silently: the project would build with no palette and nothing to say why.
     */
    public function testAPaletteAtTheOlderPathIsStillFound(): void
    {
        // Arrange — only the old location exists
        file_put_contents($this->root . '/app/theme.css', self::PALETTE);

        // Act
        $tester = $this->build([]);

        // Assert
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString(
            '--color-primary',
            (string) file_get_contents($this->root . '/www/assets/css/theme-tokens.css')
        );
    }
}
