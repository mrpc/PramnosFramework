<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Translator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Translator\Language;

/**
 * `Language::using()` — render something in a language that is not the request's.
 *
 * Which is what a notification needs and nothing else does. The language of a request
 * belongs to whoever made it; the language of an email belongs to whoever receives it, and
 * those are different people whenever an operator resets somebody's password or a queue
 * worker sends a code.
 *
 * The half worth testing hardest is the restore. `load()` *merges* — `addlang()` is an
 * `array_merge` — so anything that switched languages by loading twice left the second
 * language's translations in place, and the next message in the first language came out in
 * the second. A test that only checked "the callback saw Greek" would pass with that bug
 * fully present.
 */
#[CoversClass(Language::class)]
class LanguageUsingTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();

        Language::resetInstance();

        // Two catalogues on disk, because `using()` resolves a language by name through
        // `getLanguages()` and `load()`, and stubbing those out would leave the resolution
        // itself untested — which is where the name comes from `users.language` and can be
        // anything.
        $this->directory = sys_get_temp_dir() . '/pf-lang-using-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
        file_put_contents(
            $this->directory . '/english.php',
            '<?php $lang = ["Hello" => "Hello", "Bye" => "Bye"]; return $lang;'
        );
        file_put_contents(
            $this->directory . '/greek.php',
            '<?php $lang = ["Hello" => "Γεια"]; return $lang;'
        );
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->directory . '/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->directory);

        Language::resetInstance();

        parent::tearDown();
    }

    /**
     * A Language whose catalogues are the two files this test wrote.
     */
    private function language(): Language
    {
        $language = new class ($this->directory) extends Language {
            private string $directory;

            public function __construct(string $directory)
            {
                $this->directory = $directory;
                parent::__construct('english');
            }

            protected function languageDirectories(): array
            {
                return [$this->directory];
            }

            public static function getLanguages()
            {
                return ['english', 'greek'];
            }
        };

        $language->load('english');
        Language::setInstance($language);

        return $language;
    }

    /**
     * The callback runs in the language it asked for.
     */
    public function testTheCallbackRunsInTheRequestedLanguage(): void
    {
        // Arrange
        $language = $this->language();

        // Act
        $translated = Language::using('greek', static fn (): string => $language->_('Hello'));

        // Assert
        $this->assertSame('Γεια', $translated);
    }

    /**
     * And afterwards nothing has changed — including the strings.
     *
     * The second assertion is the one that matters. `load()` merges, so a naive switch
     * leaves `Hello => Γεια` in the catalogue and every subsequent English message says
     * Γεια. Nothing raises, nothing is logged, and it is only noticed by a recipient.
     */
    public function testAfterwardsTheLanguageAndTheStringsAreBackAsTheyWere(): void
    {
        // Arrange
        $language = $this->language();

        // Act
        Language::using('greek', static fn (): string => $language->_('Hello'));

        // Assert
        $this->assertSame('english', $language->currentlang());
        $this->assertSame('Hello', $language->_('Hello'),
            'the borrowed catalogue must not survive the call');
    }

    /**
     * The language is restored even when the callback raises.
     */
    public function testAFailingCallbackStillRestoresTheLanguage(): void
    {
        // Arrange
        $language = $this->language();

        // Act
        try {
            Language::using('greek', static function (): void {
                throw new \RuntimeException('while rendering');
            });
            $this->fail('the exception must reach the caller');
        } catch (\RuntimeException) {
            // expected — what is asserted is the state it left behind
        }

        // Assert
        $this->assertSame('english', $language->currentlang());
        $this->assertSame('Hello', $language->_('Hello'));
    }

    /**
     * A language that is not installed changes nothing.
     *
     * The name usually comes from `users.language`, which is a column somebody can write,
     * and `load()` builds a path out of it. An unknown name renders in the current language
     * rather than in none: the message still has to go out.
     */
    public function testAnUnknownLanguageIsIgnoredRatherThanAttempted(): void
    {
        // Arrange
        $language = $this->language();

        // Act
        $translated = Language::using('../etc/passwd', static fn (): string => $language->_('Hello'));

        // Assert
        $this->assertSame('Hello', $translated);
        $this->assertSame('english', $language->currentlang());
    }

    /**
     * So does an empty one, which is the common case.
     */
    public function testAnEmptyLanguageIsNoChange(): void
    {
        // Arrange
        $language = $this->language();

        // Act
        $translated = Language::using('', static fn (): string => $language->_('Hello'));

        // Assert
        $this->assertSame('Hello', $translated);
    }
}
