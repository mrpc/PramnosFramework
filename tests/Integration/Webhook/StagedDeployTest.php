<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Webhook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Webhook\WebhookHandler;

/**
 * A staged deploy, run for real against a git checkout: the branch is exported beside the live
 * tree and its dependencies installed there, then the live tree is reset and `vendor/` replaced
 * by a rename — so a request never meets a half-written `vendor/`, and a composer that fails
 * leaves the live tree as it was.
 */
#[CoversClass(WebhookHandler::class)]
final class StagedDeployTest extends TestCase
{
    private string $dir = '';

    private string $live = '';

    protected function setUp(): void
    {
        foreach (['git', 'tar'] as $tool) {
            if (trim((string) shell_exec('command -v ' . $tool)) === '') {
                $this->markTestSkipped($tool . ' is not installed.');
            }
        }
        $this->dir  = sys_get_temp_dir() . '/pf-staged-' . bin2hex(random_bytes(3));
        $this->live = $this->dir . '/site';
        mkdir($this->dir);
        $this->sh('git init -q --bare -b main origin.git && git clone -q origin.git site 2>/dev/null', $this->dir);
        $this->commit('v1');
        // The live tree's vendor, as the last install left it.
        mkdir($this->live . '/vendor');
        file_put_contents($this->live . '/vendor/installed.txt', 'from before');
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            $this->sh('rm -rf ' . escapeshellarg($this->dir), sys_get_temp_dir());
        }
    }

    /** Run a shell command in `$cwd`, failing the test when it fails. */
    private function sh(string $command, string $cwd): string
    {
        exec('cd ' . escapeshellarg($cwd) . ' && ' . $command . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, $command . ': ' . implode("\n", $output));

        return implode("\n", $output);
    }

    /** Commit `index.php` saying `$version` to origin/main, from another clone. */
    private function commit(string $version): void
    {
        $work = $this->dir . '/work';
        if (!is_dir($work)) {
            $this->sh('git clone -q origin.git work 2>/dev/null', $this->dir);
        }
        file_put_contents($work . '/index.php', '<?php echo "' . $version . '";');
        file_put_contents($work . '/composer.json', '{"name": "probe/site", "version": "' . $version . '"}');
        $this->sh('git add -A && git -c user.email=t@t -c user.name=t commit -q -m ' . $version . ' && git push -q origin HEAD:main', $work);
        if (!is_file($this->live . '/index.php')) {
            $this->sh('git pull -q origin main', $this->live);
        }
    }

    /** A composer that writes the autoloader it would, marked with the version it installed — or fails. */
    private function composer(bool $fails = false): string
    {
        $script = $this->dir . '/composer';
        file_put_contents($script, "#!/bin/sh\n" . ($fails
            ? "echo 'Your requirements could not be resolved' >&2; exit 2\n"
            : "[ \"\$1\" = install ] || exit 3\nmkdir -p vendor && grep -o '\"v[0-9]*\"' composer.json > vendor/autoload.php\n"));
        chmod($script, 0755);

        return $script;
    }

    /** Run what the handler would for `main`; the results, as `executeCommands()` gives them. */
    private function deploy(WebhookHandler $handler): array
    {
        $run = new \ReflectionMethod(WebhookHandler::class, 'executeCommands');

        return $run->invoke($handler, $handler->getBranchMap()['main']);
    }

    /**
     * The new commit goes live with the vendor installed for it, the old vendor's files carried
     * into the install, and nothing left beside the checkout; the application's own commands run
     * after, in the live tree.
     */
    public function testTheBranchGoesLiveWithItsVendor(): void
    {
        // Arrange
        $this->commit('v2');
        $handler = (new WebhookHandler('secret', $this->live))
            ->onBranchStaged('main', ['touch deployed.txt'], null, $this->composer());

        // Act
        $results = $this->deploy($handler);

        // Assert
        $this->assertSame([0], array_values(array_unique(array_column($results, 'exit_code'))), json_encode($results));
        $this->assertSame('<?php echo "v2";', file_get_contents($this->live . '/index.php'), 'the new code is live');
        $this->assertSame('"v2"', trim((string) file_get_contents($this->live . '/vendor/autoload.php')), 'with the vendor installed for it');
        $this->assertFileExists($this->live . '/vendor/installed.txt', 'the install began from the current vendor');
        $this->assertDirectoryDoesNotExist($this->live . '/vendor.old');
        $this->assertDirectoryDoesNotExist($this->dir . '/.site-stage', 'the stage is gone');
        $this->assertFileExists($this->live . '/deployed.txt', 'the application\'s commands ran in the live tree');
    }

    /**
     * A composer that fails stops the deploy before the live tree is touched: the old code and
     * the old vendor go on serving. A `vendor.old` a failed deploy left behind does not swallow
     * the vendor at the next one.
     */
    public function testAComposerThatFailsLeavesTheLiveTreeAlone(): void
    {
        // Arrange
        $this->commit('v2');
        mkdir($this->live . '/vendor.old');
        $failing = (new WebhookHandler('secret', $this->live))->onBranchStaged('main', ['touch deployed.txt'], $this->dir . '/stage', $this->composer(true));

        // Act
        $failed = $this->deploy($failing);
        $code   = file_get_contents($this->live . '/index.php');
        mkdir($this->live . '/vendor.old');
        $results = $this->deploy((new WebhookHandler('secret', $this->live))->onBranchStaged('main', [], $this->dir . '/stage', $this->composer()));

        // Assert
        $this->assertSame(2, end($failed)['exit_code'], 'stopped at composer');
        $this->assertStringContainsString('could not be resolved', end($failed)['output']);
        $this->assertSame('<?php echo "v1";', $code, 'the old code went on serving');
        $this->assertFileDoesNotExist($this->live . '/deployed.txt');
        $this->assertSame([0], array_values(array_unique(array_column($results, 'exit_code'))), json_encode($results));
        $this->assertFileExists($this->live . '/vendor/autoload.php', 'the vendor is where it belongs, not inside vendor.old');
        $this->assertDirectoryDoesNotExist($this->live . '/vendor.old');
    }
}
