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
        // Arrange — published as resync publishes them: copies of the shipped files
        $source  = \Pramnos\Application\ScaffoldingHelper::resolveScaffoldingDir() . '/assets/js/';
        $shipped = FrameworkScripts::shipped();
        foreach ($shipped as $name) {
            if ($name !== 'pf-chat.js') {
                copy($source . $name, $this->root . '/www/assets/js/' . $name);
            }
        }

        // Act
        $missing  = (new FrameworkScriptsCheck(FrameworkScripts::webRoot($this->root)))->run();
        copy($source . 'pf-chat.js', $this->root . '/www/assets/js/pf-chat.js');
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
     * A script only a feature's screens load is not missing where that feature is off — an
     * application without the conversations is not told to publish their client — and is where it
     * is on; a script every page may load is missing whatever the features. Without a list, all.
     */
    public function testAFeaturesOwnScriptIsMissingOnlyWithTheFeature(): void
    {
        // Arrange — an empty web root
        $root = $this->root . '/www';

        // Act
        $none          = FrameworkScripts::missing($root, []);
        $conversations = FrameworkScripts::missing($root, ['conversations']);
        $all           = FrameworkScripts::missing($root);

        // Assert
        $this->assertNotContains('pf-conversations.js', $none, 'the feature is off');
        $this->assertNotContains('pf-chat.js', $none);
        $this->assertContains('pf-utils.js', $none, 'every page\'s script, whatever the features');
        $this->assertContains('pf-conversations.js', $conversations);
        $this->assertNotContains('pf-assist.js', $conversations, 'neither help nor llm is on');
        $this->assertTrue(FrameworkScripts::wanted('pf-assist.js', ['help']), 'either of its features');
        $this->assertSame(FrameworkScripts::shipped(), $all);
    }

    /**
     * A copy older than the framework's — what a `composer update` leaves in the web root — is
     * named as outdated, with the same fix; a missing one is still named as missing.
     */
    public function testTheHealthCheckNamesAnOutdatedCopy(): void
    {
        // Arrange — every script current but one, which holds last month's code
        $source = \Pramnos\Application\ScaffoldingHelper::resolveScaffoldingDir() . '/assets/js/';
        $shipped = FrameworkScripts::shipped();
        $this->assertNotEmpty($shipped, 'the sweep found nothing to check');
        foreach ($shipped as $name) {
            copy($source . $name, $this->root . '/www/assets/js/' . $name);
        }
        file_put_contents($this->root . '/www/assets/js/pf-assist.js', '/* an older pf-assist.js */');
        unlink($this->root . '/www/assets/js/pf-chat.js');

        // Act
        $result = (new FrameworkScriptsCheck($this->root . '/www'))->run();

        // Assert
        $this->assertSame('degraded', $result->status->value);
        $this->assertSame(['pf-assist.js'], $result->details['outdated']);
        $this->assertSame(['pf-chat.js'], $result->details['missing']);
        $this->assertStringContainsString('1 differ from the framework', $result->message);
        $this->assertStringContainsString('project:resync --js --all', $result->message);
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
