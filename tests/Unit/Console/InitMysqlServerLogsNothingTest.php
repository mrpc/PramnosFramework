<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A scaffolded MySQL server does not write every statement to a log.
 *
 * The generated `docker-compose.yml` started MySQL with `--general-log=1`: one write per
 * statement, to a file nothing rotated, on every project — including the test runs, which
 * issue tens of thousands. Turning it on is a debugging step, and the file says how.
 */
#[CoversClass(Init::class)]
class InitMysqlServerLogsNothingTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pramnos-mysqllog-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0777, true);
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
    }

    /**
     * The `db` service's command has no general log, and a comment says how to add one.
     */
    public function testTheGeneratedMysqlServerHasNoGeneralLog(): void
    {
        // Arrange
        $command = new Init();
        $command->targetBaseDir = $this->tmpDir;
        $command->skipDockerRun = true;
        (new Application())->add($command);

        // Act
        (new CommandTester($command))->execute([
            '--app-name'    => 'LogApp',
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'   => 'LogApp',
            '--features'    => '',
            '--ui-system'   => 'plain-css',
            '--docker'      => 'y',
            '--libraries'   => '',
            '--db-type'     => 'mysql',
            '--db-host'     => 'db',
            '--db-name'     => 'log_db',
            '--db-user'     => 'log',
            '--db-pass'     => 'secret',
            '--db-prefix'   => '',
        ], ['interactive' => false]);
        $compose = (string) file_get_contents($this->tmpDir . '/docker-compose.yml');

        // Assert — the server command, and only it, is what decides
        $this->assertSame(1, preg_match('/^\s*command: mysqld.*$/m', $compose, $m), 'no mysqld command was generated');
        $this->assertStringNotContainsString('--general-log', $m[0]);
        // The way to turn it on is in the file, commented out, for whoever needs it.
        $this->assertMatchesRegularExpression('/^\s*#\s+--general-log=1/m', $compose);
    }
}
