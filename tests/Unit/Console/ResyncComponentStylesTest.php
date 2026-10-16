<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\ProjectResync;
use Pramnos\Console\ShippedFiles;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `project:resync --css` brings the theme's components.css up to date.
 *
 * A component the framework adds — a crop dialog, a calendar — is styled there, and a project
 * scaffolded before it kept its old copy: the dialog rendered as bare text in a corner. So the
 * file is synced from the theme app.php names, replaced while it is what the framework wrote, and
 * kept, and named, once the project changed it — short of `--overwrite`.
 */
#[CoversClass(ProjectResync::class)]
final class ResyncComponentStylesTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/pramnos-resync-css-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/app', 0777, true);
        mkdir($this->base . '/www/assets/css', 0777, true);
        file_put_contents($this->base . '/www/index.php', '<?php');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    /** The command over this project, with the framework's own scaffolding. */
    private function resync(array $options): string
    {
        $command = new ProjectResync();
        $command->targetBaseDir  = $this->base;
        $command->scaffoldingDir = ROOT . '/scaffolding';
        $console = new Application();
        $console->add($command);
        $tester = new CommandTester($console->find('project:resync'));
        $tester->execute($options);

        return $tester->getDisplay();
    }

    private function theme(string $theme): void
    {
        file_put_contents($this->base . '/app/app.php', "<?php return ['scaffold_theme' => '" . $theme . "'];");
    }

    /**
     * A copy still as the framework wrote it takes the newer one; a changed copy is kept until
     * `--overwrite`; and the framework's copy is the one of the project's theme.
     */
    public function testTheCopyIsRefreshedUnlessTheProjectChangedIt(): void
    {
        // Arrange
        $this->theme('tailwind');
        $rel = 'www/assets/css/components.css';
        file_put_contents($this->base . '/' . $rel, '/* old */');
        ShippedFiles::record($this->base, $rel, '/* old */');
        $framework = (string) file_get_contents(ROOT . '/scaffolding/themes/tailwind/components.css');

        // Act
        $first = $this->resync(['--css' => true]);
        $taken = (string) file_get_contents($this->base . '/' . $rel);
        file_put_contents($this->base . '/' . $rel, $framework . "\n.pf-mine { color: red; }");
        ShippedFiles::record($this->base, $rel, '/* old */');
        $kept      = $this->resync(['--css' => true]);
        $afterKeep = (string) file_get_contents($this->base . '/' . $rel);
        $this->resync(['--css' => true, '--overwrite' => true]);

        // Assert
        $this->assertStringContainsString('components.css', $first);
        $this->assertSame($framework, $taken, 'the tailwind theme\'s copy, with .pf-image-dialog in it');
        $this->assertStringContainsString('.pf-image-dialog', $taken);
        $this->assertStringContainsString('locally changed', $kept);
        $this->assertStringContainsString('.pf-mine', $afterKeep, 'the project\'s change survives a plain resync');
        $this->assertSame($framework, (string) file_get_contents($this->base . '/' . $rel), '--overwrite takes the framework\'s');
    }

    /** No theme named, or one the framework does not ship: nothing to sync, nothing written. */
    public function testWithoutAKnownThemeNothingIsWritten(): void
    {
        // Arrange
        $this->theme('my-own');

        // Act
        $display = $this->resync(['--css' => true, '--all' => true]);

        // Assert
        $this->assertFileDoesNotExist($this->base . '/www/assets/css/components.css');
        $this->assertStringContainsString('Nothing to sync', $display);
    }
}
