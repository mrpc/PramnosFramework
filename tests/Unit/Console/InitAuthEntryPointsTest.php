<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Every auth page a scaffolded project links to has something behind it.
 *
 * Three bundled themes shipped `register/register.html.php` and `sso/sso.html.php`
 * with no controller able to render either, and the discovery document advertised
 * `registration_endpoint` as `/register` — a 404. Meanwhile the views linked to
 * `Home/login`, `Home/register` and `logout`, none of which the scaffold creates:
 * the real routes are `/login`, `/register` and `/login/logout`.
 *
 * A dead link in a scaffold is worse than a missing feature, because it looks
 * like a feature until somebody clicks it. These tests assert on the scaffolded
 * tree and on the shipped views, since neither problem is visible in the diff of
 * a single file.
 */
class InitAuthEntryPointsTest extends TestCase
{
    /** @var string Temporary project root */
    private string $tmpDir;

    /** @var Init The command under test */
    private Init $command;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pramnos_authentry_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));

        $this->command                 = new Init();
        $this->command->targetBaseDir  = $this->tmpDir;
        $this->command->skipDockerRun  = true;
        $this->command->scaffoldingDir = dirname(__DIR__, 3) . '/scaffolding';
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
    }

    /**
     * Scaffolds a project with the auth feature enabled.
     *
     * @param array<string, mixed> $options Merged over the non-interactive set
     */
    private function scaffold(array $options = []): void
    {
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        $tester->execute(array_merge([
            '--app-name'      => 'EntryApp',
            '--namespace'     => 'EntryApp',
            '--features'      => 'auth,authserver',
            '--ui-system'     => 'tailwind',
            '--docker'        => 'n',
            '--cache-system'  => 'none',
            '--libraries'     => '',
            '--db-type'       => 'mysql',
            '--db-host'       => 'db',
            '--db-name'       => 'entry_db',
            '--db-user'       => 'root',
            '--db-pass'       => 'secret',
            '--db-prefix'     => '',
            '--rest-api'      => 'n',
            '--api-docs'      => 'n',
            '--webhook'       => 'n',
            '--app-style'     => 'mvc',
            '--no-install'    => true,
            '--no-download'   => true,
            '--no-migrations' => true,
        ], $options));
    }

    /**
     * The `/register` and `/sso` controllers are scaffolded with the auth feature.
     *
     * Without them the shipped views cannot be reached at all, and the discovery
     * document's registration endpoint points at nothing.
     */
    public function testTheRegisterAndSsoControllersAreScaffolded(): void
    {
        // Act
        $this->scaffold();

        // Assert
        $this->assertFileExists($this->tmpDir . '/src/Controllers/Register.php');
        $this->assertFileExists($this->tmpDir . '/src/Controllers/Sso.php');
    }

    /**
     * The generated controllers are valid PHP that binds the right action.
     *
     * They are produced from a heredoc with several levels of escaping, so "it
     * was written" is not the same as "it parses" — a stray backslash produces a
     * file that exists and fatals on the first request.
     */
    public function testTheGeneratedControllersParseAndDelegate(): void
    {
        // Act
        $this->scaffold();

        // Assert
        foreach (['Register' => 'register', 'Sso' => 'sso'] as $class => $action) {
            $path = $this->tmpDir . '/src/Controllers/' . $class . '.php';
            $code = (string) file_get_contents($path);

            $lint = [];
            exec('php -l ' . escapeshellarg($path) . ' 2>&1', $lint, $status);
            $this->assertSame(0, $status, $class . '.php must be valid PHP: ' . implode("\n", $lint));

            $this->assertStringContainsString('class ' . $class . ' extends Account', $code);
            $this->assertStringContainsString('$this->' . $action . '()', $code);
            $this->assertStringContainsString("namespace EntryApp\\Controllers;", $code);
        }
    }

    /**
     * Registration is scaffolded closed.
     *
     * Scaffolding a project must not open a public sign-up page on it. The
     * generated controller says so in prose and the behaviour comes from the
     * `auth_allow_registration` setting being absent, which reads as off.
     */
    public function testRegistrationIsScaffoldedClosed(): void
    {
        // Act
        $this->scaffold();

        // Assert
        $code = (string) file_get_contents($this->tmpDir . '/src/Controllers/Register.php');
        $this->assertStringContainsString('auth_allow_registration', $code);
    }

    /**
     * No bundled view links to a route the scaffold does not create.
     *
     * `Home/login`, `Home/register` and a bare `logout` were all dead: the real
     * routes are `/login`, `/register` and `/login/logout`. Checked over the
     * shipped views in `scaffolding/themes` rather than over a generated project,
     * because that is where they live — `init` does not copy them, the view
     * resolver falls back to them, and `project:publish-views` copies them on
     * request. A project therefore inherits whatever is wrong in there.
     *
     * All three themes at once, since the same three links were duplicated
     * through eighteen files.
     *
     * `glob()` per theme rather than a recursive directory walk: the walk is
     * lazy, so it resolves paths while the rest of the suite is running, and it
     * failed intermittently on a directory that was plainly there when the test
     * was run alone. A shared live tree is not a thing to iterate lazily.
     */
    public function testNoBundledViewLinksToADeadAuthRoute(): void
    {
        // Arrange
        $themes    = dirname(__DIR__, 3) . '/scaffolding/themes';
        $offenders = [];
        $checked   = 0;

        // Act
        foreach (['plain-css', 'bootstrap', 'tailwind'] as $theme) {
            foreach (glob($themes . '/' . $theme . '/views/*/*.php') ?: [] as $path) {
                $checked++;
                $code = (string) file_get_contents($path);
                if (
                    str_contains($code, 'Home/login')
                    || str_contains($code, 'Home/register')
                    || preg_match('/sURL; \?>logout(?![a-z\/])/', $code)
                ) {
                    $offenders[] = $theme . '/' . basename(dirname($path)) . '/' . basename($path);
                }
            }
        }

        // Assert — and prove the sweep actually read something, so an empty
        // result cannot be mistaken for a clean one.
        $this->assertGreaterThan(50, $checked, 'the bundled views should have been found');
        $this->assertSame([], $offenders, 'these views link to routes the scaffold does not create');
    }

    /**
     * The password hint every register form shows matches the enforced policy.
     *
     * The forms advertised six characters; `validatePasswordPolicy()` requires
     * eight plus a digit plus a symbol. A form that accepts what the server will
     * reject sends the user back to a page that had already told them they were
     * fine. Checked in all three themes, because the wrong number was in all
     * three.
     */
    public function testEveryRegisterFormAdvertisesTheEnforcedPolicy(): void
    {
        // Arrange
        $themes = dirname(__DIR__, 3) . '/scaffolding/themes';

        foreach (['plain-css', 'bootstrap', 'tailwind'] as $theme) {
            // Act
            $path = $themes . '/' . $theme . '/views/register/register.html.php';
            $this->assertFileExists($path);
            $code = (string) file_get_contents($path);

            // Assert
            $this->assertStringContainsString('minlength="8"', $code, $theme);
            $this->assertStringNotContainsString('minlength="6"', $code, $theme);
            $this->assertStringContainsString('digit', $code, $theme);
        }
    }

    /**
     * Every register form knows the error keys the controller sends.
     *
     * `renderRegister()` passes a key, not a sentence, so the controller does not
     * decide wording and the view does not decide policy. A key with no entry in
     * the view's map renders as the raw key — `username_taken` in a red box — so
     * the two lists have to stay in step.
     */
    public function testEveryRegisterFormMapsTheControllerErrorKeys(): void
    {
        // Arrange — the keys renderRegister() can be handed
        $keys = [
            'registration_closed', 'invalid_token', 'username_required',
            'username_length', 'username_invalid', 'username_taken',
            'invalid_email', 'email_unavailable', 'password_required',
            'password_too_short', 'password_needs_digit', 'password_needs_symbol',
            'passwords_do_not_match', 'registration_failed',
        ];
        $themes = dirname(__DIR__, 3) . '/scaffolding/themes';

        foreach (['plain-css', 'bootstrap', 'tailwind'] as $theme) {
            // Act
            $code = (string) file_get_contents(
                $themes . '/' . $theme . '/views/register/register.html.php'
            );

            // Assert
            foreach ($keys as $key) {
                $this->assertStringContainsString(
                    "'" . $key . "'",
                    $code,
                    $theme . ' has no message for ' . $key
                );
            }
        }
    }
}
