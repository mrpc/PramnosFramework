<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Pramnos\Console\Commands\ProjectResync;

/**
 * `project:resync --spa-components`, the group that hands a project newer shared Svelte components.
 *
 * Twenty-five statements, none of them ever executed. Which is a poor place for that, because this
 * is the one group in the command that writes files a project has been *editing*: the value of
 * shipping a `DataTable` is that projects extend it, so `--spa-components` is opt-in and `--all`
 * then decides whether local work is overwritten.
 *
 * Two refusals guard it, and both are about not making a mess of somebody's front end:
 *
 * - a project with no SPA at all gets nothing;
 * - a project whose stack is not Svelte gets nothing, because a component library for a no-build
 *   stack is a different project and writing `.svelte` files into one would be worse than doing
 *   nothing.
 *
 * Driven against a real project directory, because the decision reads `app/app.php` from disk and a
 * fake would be asserting the fake. Nothing is written: the method returns what *would* be written,
 * which is the seam the command is built around.
 */
#[CoversClass(ProjectResync::class)]
class ResyncSpaComponentsTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/pramnos-resync-spa-' . getmypid();
        @mkdir($this->base . '/app', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->base . '/app/app.php');
        @rmdir($this->base . '/app');
        @rmdir($this->base);
        parent::tearDown();
    }

    /**
     * Writes the project's `app.php` and returns a command pointed at it.
     *
     * @param array<string, mixed> $config
     */
    private function project(array $config): ProjectResync
    {
        file_put_contents(
            $this->base . '/app/app.php',
            '<?php return ' . var_export($config, true) . ';'
        );

        $command = new ProjectResync();
        // A public property the command exposes for exactly this: the framework's own scaffolding,
        // so the stubs it renders are the ones it ships.
        $command->scaffoldingDir = ROOT . '/scaffolding';

        return $command;
    }

    /**
     * What the command would write, as `[dest => content]`.
     *
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    private function wouldWrite(array $config): array
    {
        $command = $this->project($config);

        $files = (new \ReflectionMethod(ProjectResync::class, 'collectSpaComponentFiles'))
            ->invoke($command, $command->scaffoldingDir, $this->base);

        $byDest = [];
        foreach ($files as $file) {
            $byDest[$file['dest']] = $file['content'];
        }

        return $byDest;
    }

    /**
     * A plain MVC project gets nothing, whatever else its configuration says.
     *
     * `app_style` is what decides, and `mvc` is the default — so a project that has never asked
     * for a front end has no source directory, and creating a tree for one would be the resync
     * deciding it should have an SPA. Asserted with a Svelte stack configured, because that is
     * the combination where a laxer check would write files: the stack is right and the style
     * says there is nowhere to put them.
     */
    public function testAPlainMvcProjectGetsNothing(): void
    {
        // Act + Assert
        $this->assertSame([], $this->wouldWrite([
            'name'           => 'Nothing',
            'spa_stack'      => 'svelte',
            'spa_source_dir' => 'frontend/',
        ]));
    }

    /**
     * A project on a no-build stack gets nothing, even though it has an SPA.
     *
     * The refusal that matters. These components are Svelte; a project running plain JavaScript
     * has an SPA and no build step, so `.svelte` files would sit there as text nothing compiles —
     * and the resync would have "succeeded".
     */
    public function testANoBuildStackGetsNothing(): void
    {
        // Act
        $files = $this->wouldWrite([
            'name'           => 'Plain',
            'app_style'      => 'spa',
            'spa_stack'      => 'vanilla',
            'spa_source_dir' => 'frontend/',
        ]);

        // Assert
        $this->assertSame([], $files, 'Svelte components were offered to a no-build stack');
    }

    /**
     * A Svelte project is offered every shared component and every shared test.
     *
     * Counted against the constants rather than a literal, so a component added to `Init` without
     * being added here shows up as a failure instead of as a project that never receives it — the
     * failure this group exists to prevent.
     */
    public function testASvelteProjectIsOfferedEveryComponentAndTest(): void
    {
        // Act
        $files = $this->wouldWrite([
            'name'           => 'Sveltey',
            'app_style'      => 'spa',
            'spa_stack'      => 'svelte',
            'spa_source_dir' => 'frontend/',
        ]);

        // Assert
        $expected = count(Init::SPA_SHARED_COMPONENTS) + count(Init::SPA_SHARED_COMPONENT_TESTS);
        $this->assertCount($expected, $files);

        foreach (array_keys(Init::SPA_SHARED_COMPONENTS) as $relative) {
            $this->assertArrayHasKey(
                'frontend/' . $relative,
                $files,
                $relative . ' would not be written'
            );
        }

        foreach (array_keys(Init::SPA_SHARED_COMPONENT_TESTS) as $relative) {
            $this->assertArrayHasKey('frontend/' . $relative, $files);
        }
    }

    /**
     * The components land in the source directory the project actually uses.
     *
     * An explicit `spa_source_dir` wins, so a project whose front end lives somewhere else can be
     * helped without a repo-wide rename — which is the reason that rule exists at all.
     */
    public function testTheComponentsFollowAnExplicitSourceDirectory(): void
    {
        // Act
        $files = $this->wouldWrite([
            'name'           => 'Moved',
            'app_style'      => 'spa',
            'spa_stack'      => 'svelte',
            'spa_source_dir' => 'admin-ui/',
        ]);

        // Assert
        $this->assertArrayHasKey('admin-ui/components/DataTable.svelte', $files);
        $this->assertArrayNotHasKey('frontend/components/DataTable.svelte', $files);
    }

    /**
     * The rendered files carry the project's own name and API prefix.
     *
     * `lib/i18n.svelte.js` is the shared file with tokens in it, so it is the one that shows the
     * substitution happened. An unrendered `{{ appName }}` reaching a project is a syntax error in
     * its build, which is a confusing way to find out a resync went wrong.
     */
    public function testTheRenderedFilesCarryTheProjectsOwnValues(): void
    {
        // Act
        $files = $this->wouldWrite([
            'name'           => 'Kifisia',
            'app_style'      => 'spa',
            'api_prefix'     => '/api/2.0/',
            'spa_stack'      => 'svelte',
            'spa_source_dir' => 'frontend/',
        ]);

        // Assert
        $i18n = $files['frontend/lib/i18n.svelte.js'] ?? '';
        $this->assertNotSame('', $i18n, 'the i18n file would not be written');

        $this->assertStringContainsString('Kifisia', $i18n);
        $this->assertStringNotContainsString('{{ appName }}', $i18n, 'a token was left unrendered');
        $this->assertStringNotContainsString('{{ apiPrefix }}', $i18n);

        /*
         * The trailing slash is trimmed, because every call site appends one.
         *
         * Asserted on the double slash rather than on `'/api/2.0/'`: the trimmed prefix followed
         * by a path is `/api/2.0/strings`, which contains that string quite legitimately. What a
         * prefix that kept its own slash produces is `//`, and that is the thing to look for.
         */
        $this->assertStringContainsString('/api/2.0', $i18n);
        $this->assertStringNotContainsString(
            '/api/2.0//',
            $i18n,
            'the API prefix kept its trailing slash, so every URL has a double slash in it'
        );
    }

    /**
     * Nothing offered here is marked executable.
     *
     * The command uses the flag for the scripts it also writes; a `.svelte` file arriving with the
     * execute bit set is harmless and wrong, and the sort of thing that only shows up in somebody's
     * `git status` months later.
     */
    public function testNothingIsMarkedExecutable(): void
    {
        // Arrange
        $command = $this->project([
            'name'           => 'Sveltey',
            'app_style'      => 'spa',
            'spa_stack'      => 'svelte',
            'spa_source_dir' => 'frontend/',
        ]);

        // Act
        $files = (new \ReflectionMethod(ProjectResync::class, 'collectSpaComponentFiles'))
            ->invoke($command, $command->scaffoldingDir, $this->base);

        // Assert
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $this->assertFalse($file['exec'], $file['dest'] . ' would be written executable');
        }
    }
}
