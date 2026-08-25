<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The `.htaccess` `init` writes has to be able to reach what `init` scaffolds.
 *
 * Two rules were missing from every application style, and both fail quietly:
 *
 *  - Apache does not pass `Authorization` to PHP-FPM or CGI unless it is copied
 *    into the environment. A bearer token then arrives as no token, which reads
 *    as a rejected credential rather than a lost header.
 *  - `/.well-known/openid-configuration` and its siblings are named by
 *    specification and do not fit the controller/action URL shape. The
 *    `Discovery` controller `init` scaffolds for an authserver project answered
 *    404 on every address its own docblock advertised.
 *
 * These tests assert on the generated file rather than on the helper, because
 * the bug was not in producing the rules — there were none — but in three
 * separate places each writing their own web root config.
 */
class InitRewriteRulesTest extends TestCase
{
    /** @var string Temporary project root */
    private string $tmpDir;

    /** @var Init The command under test */
    private Init $command;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pramnos_rewrites_' . bin2hex(random_bytes(4));
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
     * Scaffolds a project and returns the generated web root config.
     *
     * @param array<string, mixed> $options Merged over the non-interactive set
     * @return string Contents of www/.htaccess
     */
    private function scaffoldHtaccess(array $options = []): string
    {
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        $tester->execute(array_merge([
            '--app-name'      => 'RewriteApp',
            '--namespace'     => 'RewriteApp',
            '--features'      => '',
            '--ui-system'     => 'plain-css',
            '--docker'        => 'n',
            '--cache-system'  => 'none',
            '--libraries'     => '',
            '--db-type'       => 'mysql',
            '--db-host'       => 'db',
            '--db-name'       => 'rw_db',
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

        $path = $this->tmpDir . '/www/.htaccess';
        $this->assertFileExists($path, 'init must write a web root config');

        return (string) file_get_contents($path);
    }

    /**
     * The Authorization header is forwarded regardless of which features are on.
     *
     * It is not an authserver concern: any project with a REST API authenticates
     * with a bearer token, and this is what makes the token visible to PHP.
     */
    public function testTheAuthorizationHeaderIsForwardedInEveryProject(): void
    {
        // Arrange / Act — no features at all, the most minimal project there is
        $htaccess = $this->scaffoldHtaccess();

        // Assert
        $this->assertStringContainsString('RewriteCond %{HTTP:Authorization} .', $htaccess);
        $this->assertStringContainsString(
            'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
            $htaccess
        );
    }

    /**
     * A project with no authserver feature gets no well-known rules.
     *
     * The rules name a controller (`Discovery`) that only the authserver feature
     * scaffolds. Emitting them anyway would turn a 404 into a "controller not
     * found" — a worse answer, and a confusing one on a project that never asked
     * to be an authorization server.
     */
    public function testWellKnownRulesAreAbsentWithoutTheAuthserverFeature(): void
    {
        // Act
        $htaccess = $this->scaffoldHtaccess();

        // Assert
        $this->assertStringNotContainsString('.well-known', $htaccess);
    }

    /**
     * Every specification-fixed discovery path reaches its Discovery action.
     *
     * Asserted per path, and per target action, because a rule that exists and
     * points at the wrong action fails in exactly the way a missing rule does
     * not: with a 200 carrying the wrong document.
     */
    public function testEveryWellKnownPathReachesTheRightDiscoveryAction(): void
    {
        // Act
        $htaccess = $this->scaffoldHtaccess(['--features' => 'auth,authserver']);

        // Assert
        $expected = [
            '^\.well-known/openid-configuration$'        => 'Discovery/configuration',
            '^\.well-known/openid_configuration$'        => 'Discovery/configuration',
            '^\.well-known/jwks\.json$'                  => 'Discovery/jwks',
            '^\.well-known/oauth-authorization-server$'   => 'Discovery/oauth2Metadata',
            '^\.well-known/health$'                       => 'Discovery/health',
        ];
        foreach ($expected as $pattern => $action) {
            $this->assertStringContainsString(
                'RewriteRule ' . $pattern . ' index.php?r=' . $action . ' [L]',
                $htaccess,
                $pattern . ' must route to ' . $action
            );
        }
    }

    /**
     * The specific rules come before the catch-all.
     *
     * `mod_rewrite` runs rules in order and the catch-all matches everything, so
     * a well-known rule placed after it never fires. This is the ordering bug
     * that would make the previous test pass while the endpoint still 404s, so
     * it is asserted on positions rather than on presence.
     */
    public function testSpecificRulesPrecedeTheCatchAll(): void
    {
        // Act
        $htaccess = $this->scaffoldHtaccess(['--features' => 'auth,authserver']);

        // Assert
        $authorization = strpos($htaccess, 'E=HTTP_AUTHORIZATION');
        $wellKnown     = strpos($htaccess, '.well-known/openid-configuration');
        $catchAll      = strpos($htaccess, 'RewriteRule ^(.*)$ index.php?r=$1');

        $this->assertIsInt($authorization);
        $this->assertIsInt($wellKnown);
        $this->assertIsInt($catchAll);
        $this->assertLessThan($catchAll, $authorization, 'the header rule must run first');
        $this->assertLessThan($catchAll, $wellKnown, 'a rule after the catch-all never fires');
    }

    /**
     * A hybrid project gets the same rules as an MVC one.
     *
     * The three application styles each wrote their own web root config, so a
     * fix applied to one left the others as they were. Hybrid keeps the MVC front
     * controller, so its discovery endpoints must work identically.
     */
    public function testAHybridProjectGetsTheSameRules(): void
    {
        // Act
        $htaccess = $this->scaffoldHtaccess([
            '--features'  => 'auth,authserver',
            '--app-style' => 'hybrid',
            '--spa-stack' => 'vanilla',
        ]);

        // Assert
        $this->assertStringContainsString('E=HTTP_AUTHORIZATION', $htaccess);
        $this->assertStringContainsString(
            'RewriteRule ^\.well-known/jwks\.json$ index.php?r=Discovery/jwks [L]',
            $htaccess
        );
    }

    /**
     * A SPA project gets them too, and keeps its shell fallback.
     *
     * The SPA config answers every unmatched path with the shell, so a discovery
     * request without an explicit rule returns the application's HTML with a 200.
     * That is the worst of the three failures: a client parsing it sees malformed
     * JSON rather than a missing endpoint.
     */
    public function testASpaProjectGetsThemBeforeTheShellFallback(): void
    {
        // Act
        $htaccess = $this->scaffoldHtaccess([
            '--features'  => 'auth,authserver',
            '--app-style' => 'spa',
            '--spa-stack' => 'vanilla',
        ]);

        // Assert
        $wellKnown = strpos($htaccess, '.well-known/openid-configuration');
        $shell     = strrpos($htaccess, 'RewriteRule ^(.*)$');

        $this->assertIsInt($wellKnown);
        $this->assertIsInt($shell);
        $this->assertLessThan($shell, $wellKnown, 'the shell fallback would swallow the request');
        $this->assertStringContainsString('E=HTTP_AUTHORIZATION', $htaccess);
    }
}
