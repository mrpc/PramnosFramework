<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Translator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Translator\Language;

/**
 * The framework's own translations, under the application's: the words of the screens the
 * framework draws — the sign-in, the second factor, the consent screen — come with it, found by
 * the language's code, and any phrase the application words itself keeps its wording.
 */
#[CoversClass(Language::class)]
final class LanguageFrameworkCatalogueTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pf-lang-' . bin2hex(random_bytes(3));
        mkdir($this->dir . '/language', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/language/*.php') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir . '/language');
        rmdir($this->dir);
    }

    /** An application catalogue named `$name` with these strings. */
    private function catalogue(string $name, array $strings): void
    {
        file_put_contents($this->dir . '/language/' . $name . '.php', '<?php $lang = ' . var_export($strings, true) . ';');
    }

    /**
     * A Greek application gets the framework's Greek for the framework's screens, and its own
     * wording wins where it has one — found by `LangShort`, or by the language's name.
     */
    public function testTheFrameworksWordsComeUnderTheApplications(): void
    {
        // Arrange
        $framework = include dirname(__DIR__, 3) . '/resources/language/el.php';
        $this->catalogue('greek', ['LangShort' => 'el', 'Sign in' => 'Είσοδος στο Example']);
        $this->catalogue('ellinika', ['LangShort' => 'el']);
        $this->catalogue('hellenic', []);

        // Act
        $short = new Language('greek', $this->dir . '/language');
        $named = new Language('ellinika', $this->dir . '/language');
        $byMap = new Language('english', $this->dir . '/language');
        $none  = new Language('hellenic', $this->dir . '/language');

        // Assert
        $this->assertIsArray($framework);
        $this->assertNotEmpty($framework);
        $this->assertSame('Είσοδος στο Example', $short->_('Sign in'), 'the application\'s wording wins');
        $this->assertSame($framework['Back to the home page'], $short->_('Back to the home page'), 'the framework\'s for the rest');
        $this->assertSame($framework['Back to the home page'], $named->_('Back to the home page'), 'by LangShort, whatever the file is called');
        $this->assertSame('Back to the home page', $byMap->_('Back to the home page'), 'English needs no catalogue');
        $this->assertSame('Back to the home page', $none->_('Back to the home page'), 'a language with no catalogue of the framework\'s keeps the key');
    }

    /** A code that is not one is never turned into a file name. */
    public function testACodeThatIsNotOneLoadsNothing(): void
    {
        // Arrange
        $this->catalogue('greek', ['LangShort' => '../../etc/passwd']);

        // Act
        $lang = new Language('greek', $this->dir . '/language');

        // Assert
        $this->assertSame('Back to the home page', $lang->_('Back to the home page'));
    }
}
