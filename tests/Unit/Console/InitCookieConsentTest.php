<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `init` asks whether the project gets the EU cookie banner, and a "no" sticks.
 *
 * Yes is the default, because a site that sets analytics or marketing cookies needs the
 * banner in the EU and learning that after launch is the expensive way. "No" has to mean
 * the feature is really absent: `app/app.php` switches it off, so neither the tag nor the
 * settings screen can bring it back by accident, and the SPA shell does not even fetch its
 * configuration.
 */
#[CoversClass(Init::class)]
class InitCookieConsentTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pramnos-cc-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0777, true);
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->tmpDir);
    }

    /** @param array<string,mixed> $extra */
    private function scaffold(array $extra = []): string
    {
        $command = new Init();
        $command->targetBaseDir = $this->tmpDir;
        $command->skipDockerRun = true;
        $app = new Application();
        $app->add($command);
        $tester = new CommandTester($command);
        $tester->execute(array_merge([
            '--app-name'    => 'Consent App',
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'   => 'ConsentApp',
            '--features'    => '',
            '--ui-system'   => 'plain-css',
            '--docker'      => 'n',
            '--libraries'   => '',
            '--db-type'     => 'mysql',
            '--db-host'     => 'localhost',
            '--db-name'     => 'consent_db',
            '--db-user'     => 'consent',
            '--db-pass'     => 'pass',
            '--db-prefix'   => '',
        ], $extra), ['interactive' => false]);

        return $tester->getDisplay();
    }

    /** @return array<string, mixed> The generated app/app.php, loaded. */
    private function appConfig(): array
    {
        return require $this->tmpDir . '/app/app.php';
    }

    /**
     * With no answer the banner is on: `app.php` carries no `cookie_consent` key, so
     * the settings screen decides, and the script is in the web root.
     */
    public function testTheBannerIsOnByDefault(): void
    {
        // Act
        $this->scaffold();

        // Assert
        $this->assertArrayNotHasKey('cookie_consent', $this->appConfig());
        $this->assertFileExists($this->tmpDir . '/www/assets/js/pf-consent.js');
    }

    /**
     * `--cookie-consent=n` writes `'cookie_consent' => false` — a valid PHP value the
     * framework reads, with a comment saying how to undo it.
     */
    public function testNoWritesTheSwitchIntoAppConfig(): void
    {
        // Act
        $this->scaffold(['--cookie-consent' => 'n']);

        // Assert
        $this->assertFalse($this->appConfig()['cookie_consent']);
        $this->assertStringContainsString('Delete this line to turn it on', (string) file_get_contents($this->tmpDir . '/app/app.php'));
    }

    /**
     * A SPA shell loads the banner by default and fetches its settings from
     * `/cookieconsent` — which the shell cannot read itself.
     */
    public function testTheSpaShellLoadsTheBannerByDefault(): void
    {
        // Act
        $this->scaffold(['--app-style' => 'spa', '--spa-stack' => 'vanilla']);

        // Assert
        $shell = (string) file_get_contents($this->tmpDir . '/www/spa.php');
        $this->assertStringContainsString("assets/js/pf-consent.js", $shell);
        $this->assertStringContainsString("\$siteUrl . 'cookieconsent'", $shell);
        $this->assertStringNotContainsString('{{', $shell, 'every token was rendered');
    }

    /**
     * A declined SPA shell still loads the script, configured off inline: no request for a
     * configuration that would only say "off", and every `data-consent` script is released,
     * as on an MVC page. Without the script those never ran in a SPA.
     */
    public function testADeclinedSpaShellLoadsTheScriptConfiguredOff(): void
    {
        // Act
        $this->scaffold(['--app-style' => 'spa', '--spa-stack' => 'vanilla', '--cookie-consent' => 'no']);

        // Assert
        $shell = (string) file_get_contents($this->tmpDir . '/www/spa.php');
        $this->assertStringContainsString('pf-consent.js', $shell);
        $this->assertStringContainsString('data-config="{&quot;enabled&quot;:false}"', $shell);
        $this->assertStringNotContainsString('data-config-url', $shell, 'it would fetch a configuration it already has');
        $this->assertFalse($this->appConfig()['cookie_consent']);
    }

    /**
     * Asked interactively, the question is shown and Enter keeps the banner.
     */
    public function testTheQuestionIsAskedAndEnterMeansYes(): void
    {
        // Arrange
        $command = new Init();
        $command->targetBaseDir = $this->tmpDir;
        $command->skipDockerRun = true;
        $method = new \ReflectionMethod(Init::class, 'askCookieConsent');
        $input = new \Symfony\Component\Console\Input\ArrayInput([], $command->getDefinition());
        $input->setStream(self::stream("\n"));
        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        // Act
        $answer = $method->invoke($command, $input, $output, new \Symfony\Component\Console\Helper\QuestionHelper());

        // Assert
        $this->assertTrue($answer);
        $this->assertStringContainsString('Show an EU cookie consent banner? [Y/n]', $output->fetch());
    }

    /** @return resource */
    private static function stream(string $text)
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $text);
        rewind($stream);

        return $stream;
    }
}
