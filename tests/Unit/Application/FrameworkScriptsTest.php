<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\FrameworkScripts;
use Pramnos\Health\Checks\FrameworkScriptsCheck;

/**
 * The framework's page scripts reach a project only through `project:resync --js --all`, never
 * through `composer update` — so a view that loads one must name a script that command
 * publishes, and a project without it must be told.
 *
 * It happened: the conversation page started loading `pf-chat.js`, an application updated, and
 * the page ran as a plain form with a 404 nobody saw until its owner did.
 */
#[CoversClass(FrameworkScripts::class)]
#[CoversClass(FrameworkScriptsCheck::class)]
#[CoversFunction('assetUrl')]
class FrameworkScriptsTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pramnos-scripts-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/www/assets/js', 0777, true);
        file_put_contents($this->root . '/www/index.php', '<?php');
        FrameworkScripts::reset();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        FrameworkScripts::reset();
        parent::tearDown();
    }

    /**
     * Every `assetUrl('assets/js/pf-…')` in the framework's views and layouts names a script the
     * framework ships — and `project:resync --js` publishes everything shipped there.
     */
    public function testEveryReferencedScriptIsOneResyncPublishes(): void
    {
        // Arrange
        $shipped = FrameworkScripts::shipped();
        $sources = array_merge(
            glob(ROOT . '/scaffolding/themes/*/views/*/*.php') ?: [],
            glob(ROOT . '/scaffolding/themes/*/views/*.php') ?: [],
            glob(ROOT . '/scaffolding/themes/*/*.php') ?: [],
            [ROOT . '/src/Pramnos/Console/Commands/Init.php']
        );

        // Act
        $referenced = [];
        foreach ($sources as $file) {
            preg_match_all("#assetUrl\\(\\s*'assets/js/(pf-[\\w.-]+\\.js)'#", (string) file_get_contents($file), $m);
            foreach ($m[1] as $name) {
                $referenced[$name][] = basename(dirname($file)) . '/' . basename($file);
            }
        }

        // Assert
        $this->assertNotEmpty($referenced, 'the sweep found nothing to check');
        $this->assertContains('pf-chat.js', array_keys($referenced));
        foreach ($referenced as $name => $where) {
            $this->assertContains($name, $shipped, $name . ', loaded by ' . implode(', ', $where) . ', is not a script the framework ships');
        }
        // What resync --js copies: every *.js in scaffolding/assets/js.
        $this->assertStringContainsString("glob(\$scaffoldingDir . '/assets/js/*.js')", (string) file_get_contents(ROOT . '/src/Pramnos/Console/Commands/ProjectResync.php'));
    }

    /** The check is ok with every script published, and degraded — naming them — without. */
    public function testTheHealthCheckNamesWhatIsMissing(): void
    {
        // Arrange
        $shipped = FrameworkScripts::shipped();
        foreach ($shipped as $name) {
            if ($name !== 'pf-chat.js') {
                touch($this->root . '/www/assets/js/' . $name);
            }
        }

        // Act
        $missing  = (new FrameworkScriptsCheck(FrameworkScripts::webRoot($this->root)))->run();
        touch($this->root . '/www/assets/js/pf-chat.js');
        $complete = (new FrameworkScriptsCheck($this->root . '/www'))->run();

        // Assert
        $this->assertSame('degraded', $missing->status->value);
        $this->assertSame(['pf-chat.js'], $missing->details['missing']);
        $this->assertStringContainsString('project:resync --js --all', $missing->message);
        $this->assertSame('ok', $complete->status->value);
        $this->assertSame('framework_scripts', $complete->name);
        $this->assertSame($this->root . '/nowhere/www', FrameworkScripts::webRoot($this->root . '/nowhere'), 'no front controller: www');
    }

    /**
     * A page loading a framework script the web root lacks is reported once, naming the fix; a
     * published script, an application's own and another path are not.
     */
    public function testAPageLoadingAMissingScriptIsReported(): void
    {
        // Arrange
        $script = $_SERVER['SCRIPT_FILENAME'] ?? null;
        $_SERVER['SCRIPT_FILENAME'] = $this->root . '/www/index.php';
        touch($this->root . '/www/assets/js/pf-auth.js');
        $warned = new \ReflectionProperty(FrameworkScripts::class, 'warned');

        try {
            // Act
            $missing   = assetUrl('assets/js/pf-chat.js', '/');
            assetUrl('assets/js/pf-chat.js', '/');
            $published = assetUrl('assets/js/pf-auth.js', '/');
            assetUrl('assets/js/app.js', '/');
            assetUrl('assets/js/pf-nonexistent.js', '/');
        } finally {
            $_SERVER['SCRIPT_FILENAME'] = $script;
        }

        // Assert
        $this->assertSame('/assets/js/pf-chat.js', $missing, 'still a URL: the page works without it');
        $this->assertMatchesRegularExpression('#^/assets/js/pf-auth\.js\?v=\d+$#', $published);
        $this->assertSame(['pf-chat.js' => true], $warned->getValue(), 'once, and only the framework\'s');
    }
}
