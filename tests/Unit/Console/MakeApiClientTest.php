<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Make\MakeApiClient;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `create:api-client` — the command around the generator.
 *
 * What belongs here rather than in the generator's own tests: where the files land,
 * what happens when the document is missing or unreadable, and whether the messages
 * name the command that fixes the situation. A generator that produces perfect code
 * into the wrong directory is no use, and neither is one that fails with "could not".
 */
#[CoversClass(MakeApiClient::class)]
class MakeApiClientTest extends TestCase
{
    private string $tmpDir;
    private MakeApiClient $command;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pramnos_api_client_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/app', 0777, true);
        mkdir($this->tmpDir . '/www/api', 0777, true);

        $this->command = new MakeApiClient();
        $this->command->projectRoot = $this->tmpDir;
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tmpDir);
    }

    /** A SPA project, with whatever extra configuration a test needs. */
    private function seedProject(string $extra = "    'app_style' => 'spa',\n    'spa_stack' => 'svelte',\n"): void
    {
        file_put_contents(
            $this->tmpDir . '/app/app.php',
            "<?php\nreturn [\n    'name' => 'Acme',\n" . $extra . "];\n"
        );
    }

    /** An OpenAPI document at the conventional path. */
    private function seedDocument(): void
    {
        file_put_contents($this->tmpDir . '/www/api/openapi.json', json_encode([
            'info'  => ['title' => 'Acme API'],
            'paths' => [
                '/things' => ['get' => ['operationId' => 'listThings', 'responses' => []]],
            ],
        ]));
    }

    /**
     * @param  array<string, string|bool> $options
     * @return CommandTester
     */
    private function generate(array $options = []): CommandTester
    {
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);
        $tester->execute($options, ['interactive' => false]);

        return $tester;
    }

    /** Read a generated file. */
    private function read(string $path): string
    {
        $full = $this->tmpDir . '/' . $path;
        $this->assertFileExists($full, "expected $path");
        return (string) file_get_contents($full);
    }

    /** Remove a directory tree. */
    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Both files land in the SPA's lib/ directory.
     */
    public function testItWritesTheModuleAndItsTypes(): void
    {
        // Arrange
        $this->seedProject();
        $this->seedDocument();

        // Act
        $tester = $this->generate();

        // Assert
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('export function listThings', $this->read('frontend/lib/endpoints.js'));
        $this->assertStringContainsString('listThings', $this->read('frontend/lib/endpoints.d.ts'));
        // The count is reported, because "it wrote a file" is not the useful fact
        $this->assertStringContainsString('1 operation(s)', $tester->getDisplay());
    }

    /**
     * The generated module says it is generated, and delegates rather than replacing.
     *
     * `lib/api.js` holds the apiKey header, the token, the ApiError and the debug
     * recording — none of which a document describes.
     */
    public function testTheModuleIsMarkedGeneratedAndDelegatesToTheProjectsClient(): void
    {
        // Arrange
        $this->seedProject();
        $this->seedDocument();

        // Act
        $this->generate();
        $module = $this->read('frontend/lib/endpoints.js');

        // Assert
        $this->assertStringContainsString('GENERATED, do not edit', $module);
        $this->assertStringContainsString("import { api } from './api.js'", $module);
    }

    /**
     * A project whose sources live elsewhere is served without a rename.
     */
    public function testItHonoursSpaSourceDir(): void
    {
        // Arrange
        $this->seedProject(
            "    'app_style' => 'spa',\n    'spa_stack' => 'svelte',\n"
            . "    'spa_source_dir' => 'admin-ui/',\n"
        );
        $this->seedDocument();

        // Act
        $this->generate();

        // Assert
        $this->assertFileExists($this->tmpDir . '/admin-ui/lib/endpoints.js');
        $this->assertFileDoesNotExist($this->tmpDir . '/frontend/lib/endpoints.js');
    }

    /**
     * `--output` wins, so the command is usable in a project the framework has no
     * opinion about.
     */
    public function testAnExplicitOutputDirectoryIsUsed(): void
    {
        // Arrange — an MVC project, which has no SPA directory at all
        $this->seedProject("");
        $this->seedDocument();

        // Act
        $tester = $this->generate(['--output' => 'src/Client']);

        // Assert
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertFileExists($this->tmpDir . '/src/Client/endpoints.js');
    }

    /**
     * A missing document names the command that produces one.
     *
     * "No OpenAPI document" without the next step is a dead end, and the next step is
     * not guessable — it is an npm script in one project shape and a console command
     * in another.
     */
    public function testAMissingDocumentNamesTheCommandThatMakesOne(): void
    {
        // Arrange — a project, no document
        $this->seedProject();

        // Act
        $tester = $this->generate();

        // Assert
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('No OpenAPI document', $display);
        $this->assertStringContainsString('docs:build', $display);
        $this->assertStringContainsString('api:docs', $display);
    }

    /**
     * An unreadable document is refused rather than producing an empty module.
     */
    public function testInvalidJsonIsRefused(): void
    {
        // Arrange
        $this->seedProject();
        file_put_contents($this->tmpDir . '/www/api/openapi.json', '{not json');

        // Act
        $tester = $this->generate();

        // Assert
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('not valid JSON', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->tmpDir . '/frontend/lib/endpoints.js');
    }

    /**
     * A document with no operations says the document is out of date.
     *
     * The likely cause, and it is not "the command is broken": an API with endpoints
     * whose document describes none has simply not been regenerated.
     */
    public function testADocumentWithNoOperationsExplainsItself(): void
    {
        // Arrange
        $this->seedProject();
        file_put_contents($this->tmpDir . '/www/api/openapi.json', json_encode(['paths' => []]));

        // Act
        $tester = $this->generate();

        // Assert
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('out of date', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->tmpDir . '/frontend/lib/endpoints.js');
    }

    /**
     * An MVC project with no `--output` is told what to do instead of being guessed at.
     */
    public function testAnMvcProjectWithNoOutputIsRefusedWithTheFix(): void
    {
        // Arrange
        $this->seedProject("");
        $this->seedDocument();

        // Act
        $tester = $this->generate();

        // Assert
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('no SPA front end', $display);
        $this->assertStringContainsString('scaffold:spa', $display);
        $this->assertStringContainsString('--output', $display);
    }

    /**
     * `--dry-run` writes nothing but reports what it would.
     */
    public function testDryRunWritesNothing(): void
    {
        // Arrange
        $this->seedProject();
        $this->seedDocument();

        // Act
        $tester = $this->generate(['--dry-run' => true]);

        // Assert
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('would write', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->tmpDir . '/frontend/lib/endpoints.js');
    }

    /**
     * Regenerating overwrites, because the file is the framework's.
     *
     * The opposite of `scaffold:spa`, and for the opposite reason: staying in step
     * with the backend means being rewritten, not preserved.
     */
    public function testRegeneratingOverwrites(): void
    {
        // Arrange
        $this->seedProject();
        $this->seedDocument();
        $this->generate();
        file_put_contents($this->tmpDir . '/frontend/lib/endpoints.js', '// hand-edited');

        // Act
        $this->generate();

        // Assert
        $this->assertStringContainsString('export function listThings', $this->read('frontend/lib/endpoints.js'));
    }
}
