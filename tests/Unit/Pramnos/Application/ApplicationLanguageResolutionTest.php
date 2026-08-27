<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Http\AdminArea;

/**
 * Which language a request is served in.
 *
 * Nothing chose one before. `?lang=` was written into the session and the session was
 * never read again, so a choice held for exactly one page; a login wrote a `language`
 * cookie nobody read; and `Settings` has carried a `language` key in the settings file
 * of every scaffolded project, also unread. `Language` therefore used its own hardcoded
 * `'english'` on every request of every application.
 *
 * That failure is invisible from outside, which is why it survived: a missing key
 * renders as itself, and the framework's keys *are* the English wording. So a site whose
 * catalogue was never loaded looks like a site written in English — there is no error,
 * no empty string, nothing to grep for. It is reported as "the translations do not
 * work", and the answer is that no language was ever selected.
 *
 * Each test here pins one rung of the chain, in the order the resolver tries them.
 */
#[CoversClass(Application::class)]
class ApplicationLanguageResolutionTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $savedSettings = [];

    /** @var list<string> Catalogue files this test staged, to be removed again. */
    private array $stagedCatalogues = [];

    /** Did this test create `app/language/` itself? Then it has to take it away again. */
    private bool $createdLanguageDirectory = false;

    protected function setUp(): void
    {
        parent::setUp();
        AdminArea::reset();
        \Pramnos\Translator\Language::resetInstance();
        $_GET = [];
        $_COOKIE = [];
        $_SESSION = [];
        $this->savedSettings = ['language' => null, 'default_language' => null];
    }

    protected function tearDown(): void
    {
        foreach ($this->stagedCatalogues as $file) {
            @unlink($file);
        }
        $this->stagedCatalogues = [];

        // The *directory* matters as much as the files. `getLanguages()` throws only when
        // no language directory exists anywhere, and the test for that branch skips itself
        // when one does — so leaving an empty `app/language/` behind would silently retire
        // that test rather than break it.
        if ($this->createdLanguageDirectory) {
            @rmdir(ROOT . DS . 'app' . DS . 'language');
            $this->createdLanguageDirectory = false;
        }
        AdminArea::reset();
        \Pramnos\Translator\Language::resetInstance();
        $_GET = [];
        $_COOKIE = [];
        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * Put a real catalogue file where `load()` looks for one.
     *
     * `setLanguage()` reports whether the catalogue *loaded*, not merely whether the name
     * was acceptable — the whole point being that `load()` silently falls through to
     * English, so a picker that trusted a name would report success and change nothing.
     * Testing that honestly needs a file on disk rather than a stub.
     */
    private function stageCatalogue(string $language): void
    {
        $directory = ROOT . DS . 'app' . DS . 'language';
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
            $this->createdLanguageDirectory = true;
        }

        $file = $directory . DS . $language . '.php';
        file_put_contents($file, "<?php\n\$lang = ['Home' => 'Home'];\nreturn \$lang;\n");
        $this->stagedCatalogues[] = $file;
    }

    /**
     * An Application with the installed-language list and the settings stubbed out.
     *
     * Both are stubbed rather than staged on disk: the list comes from a directory scan
     * and the settings from a database, and neither is what these tests are about.
     *
     * @param list<string>          $installed
     * @param array<string,string>  $settings
     */
    private function app(array $installed, array $settings = []): object
    {
        return new class ($installed, $settings) extends Application {
            /** @param list<string> $installed @param array<string,string> $settings */
            public function __construct(private array $installedList, private array $stubSettings)
            {
                $this->applicationInfo = [];
                $this->session = \Pramnos\Http\Session::getInstance();
            }

            protected function installedLanguages(): array
            {
                return $this->installedList;
            }

            /** @param array<string,mixed> $info */
            public function withAdminArea(array $info): static
            {
                $this->applicationInfo = $info;

                return $this;
            }

            public function resolve(): string
            {
                // The settings stub is applied around the call rather than injected,
                // because `resolveLanguage()` asks `Settings` statically — as the rest
                // of the framework does — and pretending otherwise would test a seam
                // that does not exist in production.
                $previous = [];
                foreach ($this->stubSettings as $key => $value) {
                    $previous[$key] = Settings::getSetting($key);
                    Settings::setSetting($key, $value, false);
                }

                try {
                    return $this->resolveLanguage();
                } finally {
                    foreach ($previous as $key => $value) {
                        Settings::setSetting($key, $value, false);
                    }
                }
            }
        };
    }

    /**
     * `?lang=` wins, and is remembered — the half that was missing.
     *
     * The write was always here. Reading it back on the next request is what makes a
     * language picker work rather than flash.
     */
    public function testAnExplicitChoiceWinsAndIsRemembered(): void
    {
        // Arrange
        $_GET['lang'] = 'greek';

        // Act
        $resolved = $this->app(['english', 'greek'])->resolve();

        // Assert
        $this->assertSame('greek', $resolved);
        $this->assertSame('greek', $_SESSION['language'] ?? null, 'and remembered for the next page');
    }

    /**
     * A language that is not installed is refused, whoever asked for it.
     *
     * `Language::load()` interpolates the name into an `include` path, and `?lang=`
     * reached it unfiltered — so this is a validation test as much as a resolution one.
     */
    public function testAnUninstalledLanguageIsRefused(): void
    {
        // Arrange
        $_GET['lang'] = '../../../../etc/passwd';

        // Act
        $resolved = $this->app(['english', 'greek'])->resolve();

        // Assert
        $this->assertSame('english', $resolved);
        $this->assertArrayNotHasKey('language', $_SESSION, 'and nothing is remembered');
    }

    /**
     * With no explicit choice, the session's last one is used.
     */
    public function testTheRememberedChoiceIsUsed(): void
    {
        // Arrange
        $_SESSION['language'] = 'greek';

        // Act & Assert
        $this->assertSame('greek', $this->app(['english', 'greek'])->resolve());
    }

    /**
     * Then the cookie, which is where a login puts the account's own language.
     */
    public function testTheCookieFromAPreviousSessionIsUsed(): void
    {
        // Arrange
        $_COOKIE['language'] = 'greek';

        // Act & Assert
        $this->assertSame('greek', $this->app(['english', 'greek'])->resolve());
    }

    /**
     * Then the installation's own setting — the key every scaffolded project ships.
     */
    public function testTheConfiguredDefaultIsUsed(): void
    {
        // Act
        $resolved = $this->app(['el', 'en'], ['language' => 'el'])->resolve();

        // Assert
        $this->assertSame('el', $resolved);
    }

    /**
     * The administration area may be in another language than the site it administers.
     *
     * Ranked above the session on purpose: an area whose language a stale cookie can
     * override is not configured, it is suggested — and the panel would follow whatever
     * the front decided, which is exactly the report this answers.
     */
    public function testTheAdminAreaMayDeclareItsOwnLanguage(): void
    {
        // Arrange — `r` is the route the front controller hands the area
        $_SESSION['language'] = 'el';
        $_GET['r'] = 'admin/Dashboard';
        AdminArea::detect('admin', 0);
        $this->assertTrue(AdminArea::isActive(), 'the arrangement must actually be inside the area');
        $app = $this->app(['el', 'en'], ['language' => 'el'])
            ->withAdminArea(['admin' => ['prefix' => 'admin', 'language' => 'en']]);

        // Act & Assert
        $this->assertSame('en', $app->resolve());

        // …and outside it the site's own language stands
        AdminArea::reset();
        $this->assertSame('el', $app->resolve());
    }

    /**
     * With nothing configured, the first installed language — not `english`.
     *
     * `english` is the framework's default and is not necessarily a file that exists: a
     * project whose catalogues are `en.php` and `el.php` has no `english.php`, so asking
     * for it loaded nothing at all and every key rendered as itself. Falling back to a
     * language that is actually installed is the difference between a translated page
     * and a page that looks translated because the keys are English.
     */
    public function testTheFallbackIsALanguageThatExists(): void
    {
        // Act & Assert
        $this->assertSame('el', $this->app(['el', 'en'])->resolve());
        $this->assertSame('english', $this->app(['english', 'greek'])->resolve());
    }

    /**
     * `setLanguage()` applies a choice for the rest of the request, and remembers it.
     *
     * The entry point for a language picker: resolution happens once, in `init()`, so a
     * screen that changes the language after that has to say so.
     */
    public function testSetLanguageAppliesAndRemembersAnInstalledLanguage(): void
    {
        // Arrange
        $this->stageCatalogue('greek');
        $app = $this->app(['english', 'greek']);

        // Act
        $applied = $app->setLanguage('greek');

        // Assert
        $this->assertTrue($applied);
        $this->assertSame('greek', $app->language);
        $this->assertSame('greek', $_SESSION['language'] ?? null);
    }

    /**
     * And refuses one that is not installed, rather than loading nothing.
     *
     * `load()` falls through to English when the file is absent, so a silent failure here
     * looks exactly like success — the picker would report the language changed and the
     * page would come back in the language it already was.
     */
    public function testSetLanguageRefusesAnUninstalledLanguage(): void
    {
        // Arrange
        $app = $this->app(['english', 'greek']);

        // Act & Assert
        $this->assertFalse($app->setLanguage('klingon'));
        $this->assertFalse($app->setLanguage(''));
        $this->assertArrayNotHasKey('language', $_SESSION);
    }

    /**
     * An installation with no language directory behaves as it did before any of this.
     *
     * `getLanguages()` throws there — it is the normal state of a console application —
     * and an empty list means *unknown*, not *none*. So a candidate is accepted on
     * trust rather than a project without the directory losing its `?lang=`.
     */
    public function testAnUnknownInstallationAcceptsTheCandidate(): void
    {
        // Arrange
        $_SESSION['language'] = 'klingon';

        // Act & Assert
        $this->assertSame('klingon', $this->app([])->resolve());
    }
}
