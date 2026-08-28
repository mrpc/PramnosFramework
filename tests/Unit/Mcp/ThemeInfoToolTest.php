<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Mcp\Tools\ThemeInfoTool;
use Pramnos\Theme\ThemeTokens;

/**
 * `theme-info` — the palette, and whether the stylesheet on disk was built from it.
 *
 * The second half is why it exists. daisyUI is a Tailwind **plugin**, so a project that edits
 * `app.css` and does not rebuild serves a stylesheet in which its component classes resolve to
 * nothing: the page renders, unstyled, with no error anywhere. And the compiled file is
 * committed on purpose — so a checkout serves the site without npm — which makes it exactly
 * the artifact somebody forgets to regenerate.
 *
 * Everything here runs against a project built in a temporary directory, so the assertions are
 * about behaviour rather than about the state of this repository's own CSS.
 */
#[CoversClass(ThemeInfoTool::class)]
class ThemeInfoToolTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/theme-info-' . bin2hex(random_bytes(5));

        foreach (['app/themes/site', 'assets/src', 'www/assets/css', 'src/Views'] as $directory) {
            mkdir($this->root . '/' . $directory, 0777, true);
        }

        ThemeTokens::flush();
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        ThemeTokens::flush();

        parent::tearDown();
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..' || !is_string($entry)) {
                continue;
            }

            $this->remove($path . '/' . $entry);
        }

        @rmdir($path);
    }

    private function write(string $relative, string $contents, ?int $mtime = null): void
    {
        $path = $this->root . '/' . $relative;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $contents);

        if ($mtime !== null) {
            touch($path, $mtime);
        }
    }

    /** A palette with two themes, as daisyUI declares them. */
    private function palette(): void
    {
        $this->write('app/themes/theme.css', <<<'CSS'
        @plugin "daisyui/theme" {
          name: "site";
          default: true;
          color-scheme: light;
          --color-base-100: oklch(100% 0 0);
          --color-primary: oklch(60% 0.1 220);
        }
        @plugin "daisyui/theme" {
          name: "site-dark";
          prefersdark: true;
          color-scheme: dark;
          --color-base-100: oklch(21% 0.059 258);
        }
        CSS);
    }

    /** @return array<string, mixed> */
    private function ask(array $input = []): array
    {
        /** @var array<string, mixed> $answer */
        $answer = (new ThemeInfoTool($this->root))->execute($input);

        return $answer;
    }

    // ── the palette ──────────────────────────────────────────────────────────

    /**
     * The themes come back summarised, not as sixty tokens.
     *
     * The answer to "what themes are there" should not be every token of every theme. Which
     * are declared, which is the default, which one dark mode picks — and the three colours a
     * reader is actually placing.
     */
    public function testTheThemesAreSummarised(): void
    {
        // Arrange
        $this->palette();

        // Act
        $answer = $this->ask();

        // Assert
        $this->assertTrue($answer['palette']['exists']);
        $this->assertSame('site', $answer['palette']['default']);
        $this->assertCount(2, $answer['palette']['themes']);

        $light = $answer['palette']['themes'][0];
        $this->assertSame('site', $light['name']);
        $this->assertTrue($light['default']);
        $this->assertSame('light', $light['color_scheme']);
        $this->assertSame(2, $light['tokens']);
        $this->assertSame('oklch(60% 0.1 220)', $light['key_colours']['--color-primary']);

        $dark = $answer['palette']['themes'][1];
        $this->assertTrue($dark['prefersdark']);
        $this->assertArrayNotHasKey('default', $dark);
    }

    /**
     * One token across every theme, with a null where it is not declared.
     *
     * The question behind it is almost always "is this readable in the other theme", and a
     * token declared in one theme and missing from the other is the answer — so the null is
     * kept and explained rather than omitted, because an absent key reads as "not asked".
     */
    public function testOneTokenAcrossThemesKeepsTheGaps(): void
    {
        // Arrange
        $this->palette();

        // Act
        $answer = $this->ask(['token' => 'color-primary']);

        // Assert
        $this->assertSame('--color-primary', $answer['token'], 'the -- prefix is optional');
        $this->assertSame('oklch(60% 0.1 220)', $answer['values']['site']);
        $this->assertNull($answer['values']['site-dark']);
        $this->assertStringContainsString('falls back', $answer['note']);
    }

    /**
     * A missing palette names the place to create one.
     */
    public function testAMissingPaletteSaysWhereItGoes(): void
    {
        // Act — nothing written
        $answer = $this->ask();

        // Assert
        $this->assertFalse($answer['palette']['exists']);
        $this->assertStringContainsString(
            ThemeTokens::DEFAULT_PATH,
            $answer['palette']['note']
        );
    }

    /**
     * An unknown theme lists the ones that exist.
     */
    public function testAnUnknownThemeListsTheRealOnes(): void
    {
        // Arrange
        $this->palette();

        // Act
        $answer = $this->ask(['theme' => 'nope']);

        // Assert
        $this->assertStringContainsString('No theme named nope', $answer['error']);
        $this->assertSame(['site', 'site-dark'], $answer['themes']);
    }

    // ── the build ────────────────────────────────────────────────────────────

    /**
     * A never-built stylesheet is reported as inert, not merely absent.
     *
     * "The file does not exist" is a fact; "every daisyUI class on every page does nothing" is
     * the consequence, and it is the one that explains what somebody is looking at.
     */
    public function testANeverBuiltStylesheetIsReportedAsInert(): void
    {
        // Arrange
        $this->palette();
        $this->write('package.json', json_encode([
            'scripts' => [
                'css:build' => 'tailwindcss -i assets/src/app.css -o www/assets/css/style.css --minify',
            ],
        ]));
        $this->write('assets/src/app.css', '@import "tailwindcss";');

        // Act
        $build = $this->ask()['build'];

        // Assert
        $this->assertSame('tailwind', $build['pipeline']);
        $this->assertSame('npm run css:build', $build['command']);
        $this->assertSame('assets/src/app.css', $build['input']);
        $this->assertFalse($build['freshness']['built']);
        $this->assertStringContainsString('inert', $build['freshness']['why']);
    }

    /**
     * A source changed after the build makes it stale — including a **view**.
     *
     * The case that surprises people. Tailwind generates only the classes it finds, so adding
     * `btn-primary` to a template means the bundle has to be rebuilt: an untouched `app.css`
     * is not evidence of a current stylesheet. The `@source` directories are read from the
     * entry file for exactly this.
     */
    public function testAChangedViewMakesTheBuildStale(): void
    {
        // Arrange — built an hour ago, a view touched since
        $this->palette();
        $this->write('package.json', json_encode([
            'scripts' => [
                'css:build' => 'tailwindcss -i assets/src/app.css -o www/assets/css/style.css',
                'css:dev'   => 'tailwindcss -i assets/src/app.css -o www/assets/css/style.css --watch',
            ],
        ]));
        $this->write('assets/src/app.css', "@import \"tailwindcss\";\n@source \"../../src\";", time() - 7200);
        $this->write('www/assets/css/style.css', '.btn{}', time() - 3600);
        $this->write('src/Views/thing.html.php', '<div class="btn btn-primary"></div>', time() - 60);

        // Act
        $freshness = $this->ask()['build']['freshness'];

        // Assert
        $this->assertTrue($freshness['built']);
        $this->assertTrue($freshness['stale']);
        $this->assertContains('src/Views/thing.html.php', $freshness['newer_than_the_build']);
        $this->assertStringContainsString('does not reflect', $freshness['why']);

        // …and the watcher is offered, because it is the next thing anybody wants
        $this->assertSame('npm run css:dev', $this->ask()['build']['watch']);
    }

    /**
     * A build newer than everything is not called stale.
     *
     * A tool that always says "rebuild" is a tool nobody rebuilds for.
     */
    public function testAFreshBuildIsNotReportedAsStale(): void
    {
        // Arrange
        $this->palette();
        touch($this->root . '/app/themes/theme.css', time() - 7200);
        $this->write('package.json', json_encode([
            'scripts' => ['css:build' => 'tailwindcss -i assets/src/app.css -o www/assets/css/style.css'],
        ]), time() - 7200);
        $this->write('assets/src/app.css', "@import \"tailwindcss\";\n@source \"../../src\";", time() - 7200);
        $this->write('src/Views/thing.html.php', '<div class="btn"></div>', time() - 7200);
        $this->write('www/assets/css/style.css', '.btn{}', time() - 60);

        // Act
        $freshness = $this->ask()['build']['freshness'];

        // Assert — `stale: false` rather than a missing key: an affirmative "this is current"
        // is a different statement from silence, and silence is what a caller has to guess at.
        $this->assertTrue($freshness['built']);
        $this->assertFalse($freshness['stale']);
        $this->assertArrayNotHasKey('newer_than_the_build', $freshness);
        $this->assertArrayNotHasKey('why', $freshness, 'nothing to explain');
    }

    /**
     * The palette it imports counts as a source.
     *
     * Editing a colour and not rebuilding is the most likely way to end up with a stylesheet
     * that disagrees with the repository.
     */
    public function testTheImportedPaletteCountsAsASource(): void
    {
        // Arrange
        $this->palette();
        touch($this->root . '/app/themes/theme.css', time() - 60);
        $this->write('package.json', json_encode([
            'scripts' => ['css:build' => 'tailwindcss -i assets/src/app.css -o www/assets/css/style.css'],
        ]), time() - 7200);
        $this->write(
            'assets/src/app.css',
            "@import \"tailwindcss\";\n@import \"../../app/themes/theme.css\";",
            time() - 7200
        );
        $this->write('www/assets/css/style.css', '.btn{}', time() - 3600);

        // Act
        $freshness = $this->ask()['build']['freshness'];

        // Assert
        $this->assertTrue($freshness['stale']);
        $this->assertContains('app/themes/theme.css', $freshness['newer_than_the_build']);
    }

    /**
     * Without an npm pipeline it names the framework's own command instead.
     *
     * `theme:build` turns the same palette into plain custom properties, and that output has
     * no freshness problem the way a Tailwind bundle does — so claiming one would be a warning
     * about nothing.
     */
    public function testWithoutNpmTheFrameworkCommandIsNamed(): void
    {
        // Arrange
        $this->palette();

        // Act
        $build = $this->ask()['build'];

        // Assert
        $this->assertSame('framework', $build['pipeline']);
        $this->assertStringContainsString('theme:build', $build['command']);
        $this->assertArrayNotHasKey('freshness', $build);
    }

    /**
     * The watcher script is not mistaken for the build.
     *
     * `--watch` never finishes, and offering it as the build command would hang whoever ran it.
     */
    public function testTheWatcherIsNotOfferedAsTheBuild(): void
    {
        // Arrange — the watcher declared first
        $this->palette();
        $this->write('package.json', json_encode([
            'scripts' => [
                'css:dev'   => 'tailwindcss -i assets/src/app.css -o www/assets/css/style.css --watch',
                'css:build' => 'tailwindcss -i assets/src/app.css -o www/assets/css/style.css --minify',
            ],
        ]));
        $this->write('assets/src/app.css', '@import "tailwindcss";');

        // Act
        $build = $this->ask()['build'];

        // Assert
        $this->assertSame('npm run css:build', $build['command']);
        $this->assertSame('npm run css:dev', $build['watch']);
    }

    /**
     * The theme directories are listed, and the chromeless auth layout is noted.
     *
     * `login.php` is the layout the framework switches to for sign-in screens; its absence is
     * why an auth page can come out wearing the full site chrome, which is a real thing to be
     * able to check.
     */
    public function testTheThemeDirectoriesAreListed(): void
    {
        // Arrange
        $this->palette();
        $this->write('app/themes/site/theme.html.php', 'layout');
        $this->write('app/themes/site/login.php', 'chromeless');
        mkdir($this->root . '/app/themes/bare', 0777, true);

        // Act
        $directories = $this->ask()['themeDirectories']['directories'];
        $names       = array_column($directories, 'name');

        // Assert
        $this->assertContains('site', $names);
        $this->assertContains('bare', $names);

        $site = $directories[array_search('site', $names, true)];
        $this->assertSame('theme.html.php', $site['layout']);
        $this->assertSame('login.php', $site['login']);

        $bare = $directories[array_search('bare', $names, true)];
        $this->assertArrayNotHasKey('layout', $bare, 'a directory with no layout says nothing');
    }
}
