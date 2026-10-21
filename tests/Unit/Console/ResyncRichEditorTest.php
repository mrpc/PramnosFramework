<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\ProjectResync;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `project:resync --rich-editor`: the rich text editor for a Svelte SPA, on the same core as the
 * server-rendered pages.
 *
 * What holds: asked for, it writes the framework's core and stylesheet, the project's component,
 * and the TipTap packages — keeping a version the project chose; from then on a plain resync
 * refreshes the core and leaves the component alone; a project that never asked gets nothing from
 * a plain resync; and a stack that is not Svelte gets nothing at all.
 */
#[CoversClass(ProjectResync::class)]
final class ResyncRichEditorTest extends TestCase
{
    private string $base = '';

    /** A fresh project directory. */
    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/pramnos-resync-rich-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/app', 0777, true);
    }

    /** Remove it. */
    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir((string) $f) : unlink((string) $f);
        }
        rmdir($this->base);
    }

    /**
     * Run the command on the project, with `app.php` and `package.json` as given.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $options
     */
    private function resync(array $config, array $options, ?array $package = null): string
    {
        file_put_contents($this->base . '/app/app.php', '<?php return ' . var_export($config, true) . ';');
        if ($package !== null) {
            file_put_contents($this->base . '/package.json', json_encode($package));
        }
        $command = new ProjectResync();
        $command->scaffoldingDir = ROOT . '/scaffolding';
        $command->targetBaseDir  = $this->base;
        (new ConsoleApplication())->add($command);
        $tester = new CommandTester($command);
        $tester->execute($options);

        return $tester->getDisplay();
    }

    /** A Svelte SPA project's configuration. */
    private static function svelte(): array
    {
        return ['name' => 'Shop', 'app_style' => 'spa', 'spa_stack' => 'svelte'];
    }

    /**
     * Asked for: the core as the framework ships it, the stylesheet the server pages print, the
     * component, and the packages — a version the project already chose kept.
     */
    public function testAskedForItWritesTheCoreTheComponentAndThePackages(): void
    {
        // Act
        $this->resync(self::svelte(), ['--rich-editor' => true], ['devDependencies' => ['@tiptap/core' => '3.40.0', 'vite' => '^6']]);
        $src = $this->base . '/' . \Pramnos\Console\Commands\Init::spaSourceDirFor(self::svelte());
        $pkg = json_decode((string) file_get_contents($this->base . '/package.json'), true);

        // Assert
        $this->assertFileEquals(ROOT . '/resources/js/rich-editor/core.js', $src . 'lib/rich-editor/core.js');
        $this->assertFileEquals(ROOT . '/resources/js/rich-editor/extensions.js', $src . 'lib/rich-editor/extensions.js');
        $this->assertSame(\Pramnos\Html\MessageEditor::RICH_CSS . "\n", file_get_contents($src . 'lib/rich-editor/rich-editor.css'));
        $this->assertStringContainsString("import { createRichEditor } from '../lib/rich-editor/core.js';", (string) file_get_contents($src . 'components/RichEditor.svelte'));
        $this->assertSame('3.40.0', $pkg['devDependencies']['@tiptap/core'], 'the project\'s own version was replaced');
        $this->assertSame('^3.32.0', $pkg['devDependencies']['@tiptap/starter-kit']);
        $this->assertSame('^6', $pkg['devDependencies']['vite'], 'the rest of package.json was lost');
    }

    /**
     * Once taken, a plain resync refreshes the core — and leaves a component the project changed.
     */
    public function testAPlainResyncRefreshesTheCoreAndKeepsTheComponent(): void
    {
        // Arrange — taken, then the core gone stale and the component made the project's own
        $this->resync(self::svelte(), ['--rich-editor' => true], ['devDependencies' => []]);
        $src = $this->base . '/' . \Pramnos\Console\Commands\Init::spaSourceDirFor(self::svelte());
        file_put_contents($src . 'lib/rich-editor/core.js', '// last month');
        file_put_contents($src . 'components/RichEditor.svelte', '<!-- ours -->');

        // Act
        $this->resync(self::svelte(), []);

        // Assert
        $this->assertFileEquals(ROOT . '/resources/js/rich-editor/core.js', $src . 'lib/rich-editor/core.js', 'the core was not refreshed');
        $this->assertSame('<!-- ours -->', file_get_contents($src . 'components/RichEditor.svelte'), 'the project\'s component was overwritten');
    }

    /**
     * Not asked for, a plain resync writes none of it; on a stack that is not Svelte, not even
     * when asked.
     */
    public function testNotAskedForOrNotSvelteNothingIsWritten(): void
    {
        // Act
        $this->resync(self::svelte(), [], ['devDependencies' => []]);
        $plain = is_dir($this->base . '/' . \Pramnos\Console\Commands\Init::spaSourceDirFor(self::svelte()) . 'lib/rich-editor');
        $vanilla = ['name' => 'Shop', 'app_style' => 'spa', 'spa_stack' => 'vanilla'];
        $this->resync($vanilla, ['--rich-editor' => true]);
        $other = is_dir($this->base . '/' . \Pramnos\Console\Commands\Init::spaSourceDirFor($vanilla) . 'lib/rich-editor');

        // Assert
        $this->assertFalse($plain, 'a project that never asked got the editor');
        $this->assertFalse($other, 'Svelte code written into another stack');
    }
}
