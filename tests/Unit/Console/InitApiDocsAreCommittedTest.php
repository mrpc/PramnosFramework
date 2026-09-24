<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The artefacts a deploy cannot regenerate are committed, not ignored.
 *
 * `init` used to append `www/api/openapi*.json` and `www/api/docs/` to a new project's
 * `.gitignore` as "generated output". The result was `/api/openapi.json` and `/api/docs/`
 * answering **403 on every live site the scaffold has ever made** — the only two addresses
 * an integrator would ever open.
 *
 * Neither is build output in the sense that phrase usually carries. The document is written
 * by a CLI command that no deploy runs — the scaffolded deploy is `git fetch`,
 * `git reset --hard`, `composer install`, `migrate` — and the viewer is a static page with
 * a `<script src>` to a CDN and a `spec-url` to its sibling. **A file that is not in the
 * commit is a file production does not have.**
 *
 * The failure is quiet in the way that matters: nobody notices locally, because both files
 * are on disk and both work. It is only wrong on the server, where nobody is reading the
 * API documentation of their own application.
 *
 * The SPA bundle is the same defect with a larger blast radius, and it is here for the same
 * reason: `www/assets/spa/` was ignored as build output, and nothing in the deploy builds
 * it, so a scaffolded SPA project deployed with **no front end at all**.
 */
class InitApiDocsAreCommittedTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pf-apidocs-git-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tempDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** Scaffold a project and hand back its `.gitignore`. */
    private function scaffoldAndReadGitignore(): string
    {
        $application = new Application();
        $application->add(new Init());
        $command = $application->find('init');
        $command->targetBaseDir = $this->tempDir;
        $command->skipDockerRun = true;
        $tester = new CommandTester($command);
        $tester->setInputs(['DocsApp', 'DocsApp', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '']);
        $tester->execute([], ['interactive' => false]);

        return (string) @file_get_contents($this->tempDir . '/.gitignore');
    }

    /**
     * Neither artefact is ignored.
     */
    public function testTheApiDocumentAndItsViewerAreNotIgnored(): void
    {
        // Arrange + Act
        $gitignore = $this->scaffoldAndReadGitignore();

        // Assert — the file itself must exist, or this asserts nothing
        $this->assertNotSame('', $gitignore, 'init must scaffold a .gitignore');

        $this->assertStringNotContainsString(
            'api/openapi',
            $gitignore,
            'the OpenAPI document was ignored, so production serves 403 for it'
        );
        $this->assertStringNotContainsString(
            'api/docs',
            $gitignore,
            'the API documentation viewer was ignored, so production serves 403 for it'
        );
    }

    /**
     * The things that genuinely must not be committed still are not.
     *
     * The control. A change that emptied `.gitignore` would satisfy the test above and
     * commit the project's private keys.
     */
    public function testWhatMustNotBeCommittedIsStillIgnored(): void
    {
        // Arrange + Act
        $gitignore = $this->scaffoldAndReadGitignore();

        // Assert
        foreach (['/vendor/', '/var/', '/.env', 'node_modules/', '/app/keys/'] as $rule) {
            $this->assertStringContainsString($rule, $gitignore, $rule . ' stopped being ignored');
        }
    }

    /** Scaffold a SPA project and hand back its `.gitignore`. */
    private function scaffoldSpaAndReadGitignore(): string
    {
        $application = new Application();
        $application->add(new Init());
        $command = $application->find('init');
        $command->targetBaseDir = $this->tempDir;
        $command->skipDockerRun = true;
        $tester = new CommandTester($command);
        $tester->setInputs(['SpaApp', 'SpaApp', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '']);
        $tester->execute(
            ['--app-style' => 'spa', '--spa-stack' => 'svelte'],
            ['interactive' => false]
        );

        return (string) @file_get_contents($this->tempDir . '/.gitignore');
    }

    /**
     * The SPA bundle is committed, and the dev server's hot file is not.
     *
     * `www/assets/spa/` was ignored outright, as build output usually is — and **nothing
     * in a deploy builds it**: the scaffolded deploy is `git fetch`, `git reset --hard`,
     * `composer install`, `migrate`. An ignored bundle is a production site with no front
     * end, invisible locally where the files are on disk and everything works.
     *
     * `.vite/hot` is the exception and it is load-bearing: `npm run dev` writes this
     * machine's dev-server origin into it, and the shell then loads modules from whatever
     * it says. Committed, it would point every visitor's browser at a developer's laptop.
     * `.vite/manifest.json` beside it is the opposite — production reads it for the
     * content-hashed filenames — which is why the rule names one file, not the directory.
     */
    public function testTheSpaBundleIsCommittedButNotTheDevServersHotFile(): void
    {
        // Arrange + Act
        $gitignore = $this->scaffoldSpaAndReadGitignore();

        // Assert
        $this->assertNotSame('', $gitignore, 'init must scaffold a .gitignore');
        $this->assertStringContainsString(
            'assets/spa/.vite/hot',
            $gitignore,
            "the dev server's origin file must stay out of the repository"
        );
        // A line scan rather than a pattern: the rule to catch is a bare
        // `www/assets/spa/`, and comment lines legitimately mention the path.
        $rules = array_filter(
            array_map('trim', explode("\n", $gitignore)),
            static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#')
        );

        foreach ($rules as $rule) {
            // The whole directory, not a file inside it — `.vite/hot` is meant to be here.
            $this->assertNotSame(
                'assets/spa',
                rtrim(substr($rule, (int) strpos($rule, 'assets/spa')), '/'),
                'the SPA bundle was ignored by "' . $rule . '", so production has no front end'
            );
        }
    }
}
