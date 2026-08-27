<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Email\EmailTheme;

/**
 * The HTML shell a sent email is wrapped in.
 *
 * The behaviour that matters is mostly about what it *does not* do: an installation that
 * has not asked for a wrapper must send exactly the body it always sent, and a wrapper that
 * is missing or broken must not stop the mail. A code somebody is waiting for is worth more
 * than a footer.
 */
#[CoversClass(EmailTheme::class)]
class EmailThemeTest extends TestCase
{
    private string $directory = '';

    private mixed $originalSetting = null;

    private mixed $originalSitename = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalSetting  = Settings::getSetting(EmailTheme::SETTING);
        $this->originalSitename = Settings::getSetting('sitename');
        $this->directory = sys_get_temp_dir() . '/pf-email-themes-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        Settings::setSetting(
            EmailTheme::SETTING,
            is_string($this->originalSetting) ? $this->originalSetting : '',
            false
        );

        Settings::setSetting(
            'sitename',
            is_string($this->originalSitename) ? $this->originalSitename : '',
            false
        );
        EmailThemeProbe::$extra = '';

        foreach ((array) glob($this->directory . '/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->directory);

        parent::tearDown();
    }

    /**
     * Write a wrapper into a directory this test owns, and point the class at it.
     */
    private function withWrapper(string $name, string $php): void
    {
        file_put_contents($this->directory . '/' . $name . '.html.php', $php);

        // `directories()` reads constants that a running application defines; a test cannot
        // redefine them, so the lookup is exercised through a subclass that adds the
        // temporary directory in front. What is under test is the resolution *order* and the
        // rendering, not how APP_PATH is spelt.
        EmailThemeProbe::$extra = $this->directory;
    }

    /**
     * An installation that has not named a wrapper sends the body it was given.
     *
     * The whole feature has to be invisible until it is asked for: every application already
     * sending mail through this framework has bodies that are complete messages, and
     * wrapping them on an upgrade would put a second `<html>` inside the first.
     */
    public function testNothingIsWrappedUntilAWrapperIsNamed(): void
    {
        // Arrange
        Settings::setSetting(EmailTheme::SETTING, '', false);

        // Act & Assert
        $this->assertSame('<p>Hello</p>', EmailTheme::wrap('<p>Hello</p>'));
    }

    /**
     * With one named, the body is rendered inside it.
     */
    public function testANamedWrapperSurroundsTheBody(): void
    {
        // Arrange
        $this->withWrapper('branded', '<div id="shell"><?php echo $content; ?></div>');

        // Act
        $html = EmailThemeProbe::wrap('<p>Your code is 123456</p>', 'branded');

        // Assert
        $this->assertStringContainsString('<div id="shell">', $html);
        $this->assertStringContainsString('<p>Your code is 123456</p>', $html);
    }

    /**
     * The subject and the site's own details reach the wrapper.
     *
     * A shell with no site name in it is a shell nobody wants; passing them here is what
     * keeps every wrapper from having to read the settings itself.
     */
    public function testTheWrapperReceivesTheSubjectAndTheSiteDetails(): void
    {
        // Arrange
        $this->withWrapper(
            'tokens',
            '<title><?php echo $subject; ?></title><footer><?php echo $sitename; ?></footer>'
        );
        Settings::setSetting('sitename', 'Example Sign-in', false);

        // Act
        $html = EmailThemeProbe::wrap('<p>body</p>', 'tokens', ['subject' => 'Your code']);

        // Assert
        $this->assertStringContainsString('<title>Your code</title>', $html);
        $this->assertStringContainsString('<footer>Example Sign-in</footer>', $html);
    }

    /**
     * A wrapper cannot replace the body it was handed.
     *
     * `content` is the one variable the caller owns: a wrapper that received something else
     * would send a message nobody wrote, and the audit log would record the same string, so
     * there would be no trace of what happened.
     */
    public function testAWrapperCannotOverrideTheBody(): void
    {
        // Arrange
        $this->withWrapper('shell', '<?php echo $content; ?>');

        // Act
        $html = EmailThemeProbe::wrap('<p>real</p>', 'shell', ['content' => '<p>substituted</p>']);

        // Assert
        $this->assertStringContainsString('<p>real</p>', $html);
        $this->assertStringNotContainsString('substituted', $html);
    }

    /**
     * An empty name sends the body bare, even where everything else is wrapped.
     *
     * Distinct from null, which asks for the installation's default. Without the
     * distinction there is no way to send one unwrapped message — a body that is already a
     * whole document, or one meant to be parsed rather than read.
     */
    public function testAnEmptyNameIsADecisionRatherThanADefault(): void
    {
        // Arrange
        $this->withWrapper('branded', '<div id="shell"><?php echo $content; ?></div>');
        Settings::setSetting(EmailTheme::SETTING, 'branded', false);

        // Act & Assert
        $this->assertStringContainsString('shell', EmailThemeProbe::wrap('<p>x</p>', null),
            'null takes the installation default');
        $this->assertSame('<p>x</p>', EmailThemeProbe::wrap('<p>x</p>', ''),
            'and an empty string refuses it');
    }

    /**
     * A name that is not a name resolves to nothing.
     *
     * The name comes from a database column an administrator edits, and it is used to build
     * a path. Refused rather than sanitised: there is no correct number of `..` segments to
     * strip, and a name with a separator in it was never a wrapper name.
     */
    public function testANameWithAPathInItIsRefused(): void
    {
        // Act & Assert
        foreach (['../../etc/passwd', 'sub/dir', 'name.with.dots', '', 'name with spaces'] as $name) {
            $this->assertNull(EmailTheme::locate($name), $name . ' must not resolve');
        }
    }

    /**
     * A named wrapper that does not exist sends the message anyway.
     *
     * This is the branch that decides whether a typo in a settings field costs an
     * unbranded email or every email. It has to be the first one.
     */
    public function testAMissingWrapperStillSendsTheBody(): void
    {
        // Act & Assert
        $this->assertSame('<p>Hello</p>', EmailTheme::wrap('<p>Hello</p>', 'nothing-here'));
    }

    /**
     * And so does one that raises while rendering.
     */
    public function testAWrapperThatFailsStillSendsTheBody(): void
    {
        // Arrange
        $this->withWrapper('broken', '<?php throw new \RuntimeException("no"); ?>');

        // Act & Assert
        $this->assertSame('<p>Hello</p>', EmailThemeProbe::wrap('<p>Hello</p>', 'broken'));
    }

    /**
     * The framework's own `default` resolves with nothing published.
     *
     * Otherwise the column's default value — `default`, since 2020 — would name a wrapper
     * that does not exist, and switching the feature on would do nothing at all.
     */
    public function testTheBundledDefaultIsFound(): void
    {
        // Act
        $file = EmailTheme::locate('default');

        // Assert
        $this->assertNotNull($file, 'the bundled wrapper must be findable');
        $this->assertStringContainsString('scaffolding', (string) $file);
    }

    /**
     * An application's own wrapper wins over the bundled one of the same name.
     */
    public function testAnApplicationsOwnWrapperWins(): void
    {
        // Arrange
        $this->withWrapper('default', '<div id="mine"><?php echo $content; ?></div>');

        // Act
        $html = EmailThemeProbe::wrap('<p>x</p>', 'default');

        // Assert
        $this->assertStringContainsString('id="mine"', $html);
    }
}

/**
 * Adds a directory in front of the search path, so a test can own a wrapper file.
 */
class EmailThemeProbe extends EmailTheme
{
    public static string $extra = '';

    public static function directories(): array
    {
        return self::$extra === ''
            ? parent::directories()
            : array_merge([self::$extra], parent::directories());
    }
}
