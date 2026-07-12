<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Console\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Make\MakeWebhook;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Unit tests for the make:webhook command.
 *
 * The command writes ROOT/www/webhook.php and optionally appends
 * WEBHOOK_SECRET to ROOT/.env.example. Tests run against the real project
 * ROOT (the framework checkout) so tearDown removes every artifact:
 * www/webhook.php is deleted and .env.example is only created by the test
 * that needs it (and removed afterwards).
 *
 * Covered paths:
 *  - fresh generation (file did not exist) → exit 0, stub content
 *  - existing file without --force → exit 1, file untouched
 *  - existing file with --force → exit 0, overwritten
 *  - --branch option propagated into the generated script
 *  - .env.example append branch (key absent → appended, present → skipped)
 *  - detectCliName() fallback to 'pramnos' when no app.php / root php file
 */
#[CoversClass(MakeWebhook::class)]
class MakeWebhookTest extends TestCase
{
    private string $target;
    private string $envExample;

    protected function setUp(): void
    {
        $this->target     = ROOT . '/www/webhook.php';
        $this->envExample = ROOT . '/.env.example';

        // Pre-condition: neither artifact may exist before a test runs.
        @unlink($this->target);
        @unlink($this->envExample);
    }

    protected function tearDown(): void
    {
        // Always remove generated artifacts so the repo stays clean.
        @unlink($this->target);
        @unlink($this->envExample);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Execute make:webhook with the given CLI parameters and return
     * [exitCode, display output].
     *
     * execute() is protected on Symfony commands, so it is invoked through
     * reflection after binding an ArrayInput against the command definition.
     *
     * @param array<string, mixed> $params e.g. ['--force' => true]
     * @return array{0:int, 1:string}
     */
    private function runCommand(array $params = []): array
    {
        $command = new MakeWebhook();
        $input   = new ArrayInput($params, $command->getDefinition());
        $output  = new BufferedOutput();

        $ref = new \ReflectionMethod($command, 'execute');
        $exit = $ref->invoke($command, $input, $output);

        return [$exit, $output->fetch()];
    }

    // =========================================================================
    // Fresh generation
    // =========================================================================

    /**
     * Running make:webhook when www/webhook.php does not exist must create the
     * file with the standard stub (WebhookHandler bootstrap, default branch
     * 'main', fallback CLI name 'pramnos') and exit 0.
     */
    public function testCreatesWebhookScriptWithDefaults(): void
    {
        // Arrange — setUp removed any pre-existing file.

        // Act
        [$exit, $out] = $this->runCommand();

        // Assert — success exit and confirmation message
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Created www/webhook.php', $out);
        $this->assertFileExists($this->target);

        $content = (string) file_get_contents($this->target);
        // The stub must bootstrap the WebhookHandler
        $this->assertStringContainsString('WebhookHandler', $content);
        $this->assertStringContainsString('WEBHOOK_SECRET', $content);
        // Default branch is main
        $this->assertStringContainsString("onBranch('main'", $content);
        $this->assertStringContainsString('git reset --hard origin/main', $content);
        // No app.php / root entry script in this project → 'pramnos' fallback
        $this->assertStringContainsString('php pramnos migrate', $content);
    }

    /**
     * The --branch option must replace 'main' in every generated command
     * sequence (onBranch target and the git reset line).
     */
    public function testBranchOptionIsPropagated(): void
    {
        // Arrange / Act
        [$exit] = $this->runCommand(['--branch' => 'develop']);

        // Assert
        $this->assertSame(0, $exit);
        $content = (string) file_get_contents($this->target);
        $this->assertStringContainsString("onBranch('develop'", $content);
        $this->assertStringContainsString('git reset --hard origin/develop', $content);
    }

    // =========================================================================
    // Overwrite protection
    // =========================================================================

    /**
     * When www/webhook.php already exists and --force is not given, the
     * command must refuse to overwrite it and exit 1.
     */
    public function testExistingFileWithoutForceFails(): void
    {
        // Arrange — pre-create a sentinel file
        file_put_contents($this->target, 'SENTINEL');

        // Act
        [$exit, $out] = $this->runCommand();

        // Assert — exit 1, warning printed, file untouched
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('already exists', $out);
        $this->assertSame('SENTINEL', file_get_contents($this->target),
            'Existing file must not be overwritten without --force');
    }

    /**
     * --force must overwrite an existing www/webhook.php and exit 0.
     */
    public function testForceOverwritesExistingFile(): void
    {
        // Arrange
        file_put_contents($this->target, 'SENTINEL');

        // Act
        [$exit] = $this->runCommand(['--force' => true]);

        // Assert — file replaced with the generated stub
        $this->assertSame(0, $exit);
        $content = (string) file_get_contents($this->target);
        $this->assertStringNotContainsString('SENTINEL', $content);
        $this->assertStringContainsString('WebhookHandler', $content);
    }

    // =========================================================================
    // .env.example handling
    // =========================================================================

    /**
     * When .env.example exists without a WEBHOOK_SECRET key, the command must
     * append the key (with explanatory comment) and report it.
     */
    public function testAppendsWebhookSecretToEnvExample(): void
    {
        // Arrange — .env.example without the key
        file_put_contents($this->envExample, "APP_DEBUG=0\n");

        // Act
        [$exit, $out] = $this->runCommand();

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Appended WEBHOOK_SECRET= to .env.example', $out);
        $env = (string) file_get_contents($this->envExample);
        $this->assertStringContainsString('WEBHOOK_SECRET=', $env);
        $this->assertStringContainsString('APP_DEBUG=0', $env,
            'Existing .env.example content must be preserved');
    }

    /**
     * When .env.example already contains WEBHOOK_SECRET, the command must not
     * append a duplicate entry.
     */
    public function testDoesNotDuplicateWebhookSecretInEnvExample(): void
    {
        // Arrange — key already present
        file_put_contents($this->envExample, "WEBHOOK_SECRET=existing\n");

        // Act
        [$exit, $out] = $this->runCommand();

        // Assert — no "Appended" message and only one occurrence of the key
        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('Appended WEBHOOK_SECRET', $out);
        $env = (string) file_get_contents($this->envExample);
        $this->assertSame(1, substr_count($env, 'WEBHOOK_SECRET'),
            'WEBHOOK_SECRET must not be appended twice');
    }

    /**
     * Next-step instructions must always be printed on success so the user
     * knows how to wire the webhook up in GitHub/Bitbucket.
     */
    public function testPrintsNextSteps(): void
    {
        // Arrange / Act
        [, $out] = $this->runCommand();

        // Assert
        $this->assertStringContainsString('Next steps:', $out);
        $this->assertStringContainsString('webhook.php', $out);
    }

    // =========================================================================
    // detectCliName() — private helper coverage
    // =========================================================================

    /**
     * detectCliName() must return the lowercase namespace when app/app.php
     * exists and returns an array with a 'namespace' key that has a matching
     * entry-point .php file in root.
     *
     * Covers the "namespace found + matching root PHP file exists" branch
     * inside detectCliName() (lines 97–100 of MakeWebhook).
     */
    public function testDetectCliNameUsesNamespaceWhenAppPhpExistsAndEntryFilePresent(): void
    {
        // Arrange — build a synthetic root that has:
        //   app/app.php  → returns ['namespace' => 'MyApp']
        //   myapp.php    → the entry-point file
        $root = sys_get_temp_dir() . '/mkwh_test_' . bin2hex(random_bytes(4));
        mkdir($root . '/app', 0777, true);
        file_put_contents($root . '/app/app.php', '<?php return ["namespace" => "MyApp"];');
        file_put_contents($root . '/myapp.php', '<?php // entry point');

        try {
            // Act — call via reflection
            $command  = new MakeWebhook();
            $ref      = new \ReflectionMethod($command, 'detectCliName');
            $cliName  = $ref->invoke($command, $root);

            // Assert — namespace 'MyApp' lowercased → 'myapp', matching myapp.php
            $this->assertSame('myapp', $cliName,
                'detectCliName() must return the lowercased namespace when a matching entry file exists');
        } finally {
            @unlink($root . '/app/app.php');
            @unlink($root . '/myapp.php');
            @rmdir($root . '/app');
            @rmdir($root);
        }
    }

    /**
     * detectCliName() must return the namespace (without an entry-point file)
     * when app/app.php has a namespace key but no matching .php file in root.
     *
     * Covers the branch at line 102: `return $candidate ?: 'pramnos'`.
     */
    public function testDetectCliNameReturnsNamespaceWhenEntryFileAbsent(): void
    {
        // Arrange — app.php exists with namespace, but no matching root .php file
        $root = sys_get_temp_dir() . '/mkwh_test_' . bin2hex(random_bytes(4));
        mkdir($root . '/app', 0777, true);
        file_put_contents($root . '/app/app.php', '<?php return ["namespace" => "Acme"];');
        // No acme.php in root

        try {
            // Act
            $command  = new MakeWebhook();
            $ref      = new \ReflectionMethod($command, 'detectCliName');
            $cliName  = $ref->invoke($command, $root);

            // Assert — namespace used even though file doesn't exist
            $this->assertSame('acme', $cliName,
                'detectCliName() must return the lowercased namespace even when no entry file exists');
        } finally {
            @unlink($root . '/app/app.php');
            @rmdir($root . '/app');
            @rmdir($root);
        }
    }

    /**
     * detectCliName() must fall through to the glob path and pick the first
     * non-excluded .php file in root when app/app.php is absent.
     *
     * Covers lines 109–116 in detectCliName(): glob scan, excluded-list check,
     * return by filename stem.
     */
    public function testDetectCliNameUsesGlobWhenNoAppPhpExists(): void
    {
        // Arrange — root with one non-excluded .php file and no app/app.php
        $root = sys_get_temp_dir() . '/mkwh_test_' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        file_put_contents($root . '/mywebapp.php', '<?php // entry point');

        try {
            // Act
            $command = new MakeWebhook();
            $ref     = new \ReflectionMethod($command, 'detectCliName');
            $cliName = $ref->invoke($command, $root);

            // Assert — file mywebapp.php found → returns 'mywebapp'
            $this->assertSame('mywebapp', $cliName,
                'detectCliName() must use the stem of the first non-excluded .php file when app.php is absent');
        } finally {
            @unlink($root . '/mywebapp.php');
            @rmdir($root);
        }
    }
}
