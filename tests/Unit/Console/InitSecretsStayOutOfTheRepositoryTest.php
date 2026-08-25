<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Nothing `init` writes into version control contains a credential.
 *
 * A real scaffolded project was found with the database password in plain text in
 * **three** committed files — `app/config/settings.php`,
 * `app/config/testsettings.php` and `docker-compose.yml` — and `'development' => true`
 * beside it. `.gitignore` had covered `/.env` from the beginning; there was simply
 * nothing in it.
 *
 * The test is written the way it is on purpose: it searches every scaffolded file for
 * the password string rather than checking the three known ones. The three were found
 * by reading a project, and a fourth would be found the same way — by somebody, later,
 * in a repository that had already been pushed.
 */
#[CoversClass(Init::class)]
class InitSecretsStayOutOfTheRepositoryTest extends TestCase
{
    private string $tmpDir = '';

    /** A password unlikely to occur by accident, so a hit is a real hit. */
    private const SECRET = 'Zx7QpasswordLeakCanary41';

    /** The MySQL root password is generated, so it is captured from the .env instead. */
    private Init $command;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pramnos-secrets-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0777, true);
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));

        $this->command = new Init();
        $this->command->targetBaseDir  = $this->tmpDir;
        $this->command->skipDockerRun  = true;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpDir);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function scaffold(array $extra = []): void
    {
        $app = new Application();
        $app->add($this->command);
        (new CommandTester($this->command))->execute(array_merge([
            '--app-name'    => 'SecretsApp',
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'   => 'SecretsApp',
            '--features'    => '',
            '--ui-system'   => 'plain-css',
            '--docker'      => 'y',
            '--libraries'   => '',
            '--db-type'     => 'postgresql',
            '--db-host'     => 'db',
            '--db-name'     => 'secrets_db',
            '--db-user'     => 'secrets',
            '--db-pass'     => self::SECRET,
            '--db-prefix'   => '',
        ], $extra), ['interactive' => false]);
    }

    /**
     * Every file that would be committed, with `.gitignore`'s own entries removed.
     *
     * Deliberately crude — a prefix match against the ignore list rather than real
     * gitignore semantics. The scaffolded list is short and flat, and a check that is
     * simple enough to be obviously right beats one that models the whole spec.
     *
     * @return list<string> Paths relative to the project root
     */
    private function committedFiles(): array
    {
        $ignored = ['vendor/', 'var/', '.env', 'node_modules/', 'app/keys/private.key'];

        $found = [];
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($items as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace($this->tmpDir, '', $item->getPathname()), '/');

            // .env.example must NOT be excluded by the '.env' entry — it is committed,
            // and it is one of the files most worth checking.
            if ($relative === '.env.example') {
                $found[] = $relative;
                continue;
            }

            foreach ($ignored as $prefix) {
                if (str_starts_with($relative, $prefix)) {
                    continue 2;
                }
            }
            $found[] = $relative;
        }

        return $found;
    }

    /**
     * The database password appears in no committed file.
     *
     * The assertion names every offending file, because "a secret leaked" is not
     * actionable and "docker-compose.yml line 14" is.
     */
    public function testTheDatabasePasswordIsInNoCommittedFile(): void
    {
        // Arrange & Act
        $this->scaffold();

        // Assert
        $leaking = [];
        foreach ($this->committedFiles() as $relative) {
            if (str_contains((string) file_get_contents($this->tmpDir . '/' . $relative), self::SECRET)) {
                $leaking[] = $relative;
            }
        }

        $this->assertSame([], $leaking,
            'these committed files carry the database password: ' . implode(', ', $leaking));
    }

    /**
     * It is in `.env`, which is ignored — so the value did not merely vanish.
     *
     * Without this the test above passes just as well on a scaffolder that forgot to
     * record the password anywhere, and the developer's first run fails to connect.
     */
    public function testThePasswordIsRecordedInTheIgnoredEnvFile(): void
    {
        // Arrange & Act
        $this->scaffold();

        // Assert
        $env = (string) file_get_contents($this->tmpDir . '/.env');
        $this->assertStringContainsString('APP_DB_PASSWORD=' . self::SECRET, $env);
        $this->assertStringContainsString('/.env', (string) file_get_contents($this->tmpDir . '/.gitignore'),
            '.env has to be ignored, or this moved the secret rather than protecting it');
    }

    /**
     * `.env.example` lists every key and blanks the secrets.
     *
     * It is the answer to "I have just cloned this, what do I need?", so a key missing
     * from it is a value the next developer discovers from a connection error.
     */
    public function testTheExampleListsEveryKeyAndBlanksTheSecrets(): void
    {
        // Arrange & Act
        $this->scaffold();

        // Assert
        $env     = (string) file_get_contents($this->tmpDir . '/.env');
        $example = (string) file_get_contents($this->tmpDir . '/.env.example');

        // Every key in .env is in the example. Compared as key sets, because the
        // *values* are exactly what must differ.
        $keys = static function (string $contents): array {
            preg_match_all('/^([A-Z0-9_]+)=/m', $contents, $m);
            sort($m[1]);
            return $m[1];
        };
        $this->assertSame($keys($env), $keys($example),
            '.env.example must list every key .env does — a missing one is discovered by a stack trace');

        // …and the secret is blank in the committed copy.
        $this->assertStringContainsString("APP_DB_PASSWORD=\n", $example);
        $this->assertStringNotContainsString(self::SECRET, $example);
    }

    /**
     * `docker-compose.yml` interpolates rather than embedding.
     *
     * Compose reads the same `.env` the application does, so there is one place to
     * change a credential and one place it can leak from. An unset variable
     * interpolates empty and the database image refuses to start — loud, which is the
     * right failure: a silently password-less database would be worse.
     */
    public function testComposeInterpolatesTheCredentials(): void
    {
        // Arrange & Act
        $this->scaffold();

        // Assert
        $compose = (string) file_get_contents($this->tmpDir . '/docker-compose.yml');
        $this->assertStringContainsString('POSTGRES_PASSWORD: ${APP_DB_PASSWORD}', $compose);
        $this->assertStringContainsString('POSTGRES_DB: ${APP_DB_NAME}', $compose);
    }

    /**
     * MySQL's generated root password does not reach a committed file either.
     *
     * A separate test because the value is generated inside the command — there is no
     * canary to search for, so it is read back out of `.env` and looked for from there.
     * It was previously written straight into `docker-compose.yml`.
     */
    public function testTheMysqlRootPasswordIsNotCommitted(): void
    {
        // Arrange & Act
        $this->scaffold(['--db-type' => 'mysql']);

        // Assert — recover the generated value from the ignored file…
        $env = (string) file_get_contents($this->tmpDir . '/.env');
        $this->assertSame(1, preg_match('/^APP_DB_ROOT_PASSWORD=(.+)$/m', $env, $m),
            'the generated root password must be recorded in .env');
        $rootPass = trim($m[1]);
        $this->assertNotSame('', $rootPass);

        // …and confirm it is nowhere else.
        $leaking = [];
        foreach ($this->committedFiles() as $relative) {
            if (str_contains((string) file_get_contents($this->tmpDir . '/' . $relative), $rootPass)) {
                $leaking[] = $relative;
            }
        }
        $this->assertSame([], $leaking,
            'these committed files carry the MySQL root password: ' . implode(', ', $leaking));
    }

    /**
     * `development` is read from the environment, not frozen into the file.
     *
     * The other half of the report: the committed `settings.php` said
     * `'development' => true`, so a deployment of that repository served debug output
     * until somebody noticed and edited a tracked file on the server.
     */
    public function testDevelopmentComesFromTheEnvironment(): void
    {
        // Arrange & Act
        $this->scaffold();

        // Assert
        $settings = (string) file_get_contents($this->tmpDir . '/app/config/settings.php');
        $this->assertStringContainsString("'development' => envvar('APP_DEBUG', false)", $settings);
        $this->assertStringNotContainsString("'development' => true", $settings);

        // false by default in the example, true in the local file init just wrote:
        // init is setting up a development machine, the next copy might not be one.
        $this->assertStringContainsString('APP_DEBUG=true', (string) file_get_contents($this->tmpDir . '/.env'));
        $this->assertStringContainsString('APP_DEBUG=false', (string) file_get_contents($this->tmpDir . '/.env.example'));
    }

    /**
     * The test settings point at their own database, not the development one.
     *
     * Both files read the environment now, so they would have collapsed onto one
     * database had they shared a key — and the suite truncates tables.
     */
    public function testTheTestSettingsReadTheirOwnDatabaseKey(): void
    {
        // Arrange & Act
        $this->scaffold();

        // Assert
        $test = (string) file_get_contents($this->tmpDir . '/app/config/testsettings.php');
        $this->assertStringContainsString("envvar('APP_DB_TEST_NAME', 'secrets_db_test')", $test);
        $this->assertStringNotContainsString("envvar('APP_DB_NAME'", $test);

        // And it is never the development environment: debug output in the middle of a
        // suite is noise at best.
        $this->assertStringContainsString("'development' => false", $test);
    }

    /**
     * The keys are `APP_DB_*`, not `DB_*`.
     *
     * Not a style choice. A real environment variable deliberately wins over `.env`,
     * so a bare `DB_HOST` set by a hosting image, a CI runner or a sibling container
     * silently connects the application to a different database. This framework's own
     * dev container exports `DB_HOST`, `DB_USER` and `DB_NAME`, and the first run of
     * these tests read `pramnos_test` out of a project configured for something else —
     * the collision happened inside the repository that introduced the convention.
     */
    public function testTheKeysArePrefixedToAvoidCollidingWithTheHost(): void
    {
        // Arrange & Act
        $this->scaffold();

        // Assert
        $settings = (string) file_get_contents($this->tmpDir . '/app/config/settings.php');
        $this->assertStringContainsString("envvar('APP_DB_HOST'", $settings);
        $this->assertStringNotContainsString("envvar('DB_HOST'", $settings);
    }
}
