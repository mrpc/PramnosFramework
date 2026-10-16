<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Push;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Push\BrowserScript;
use Pramnos\Translator\Language;

/**
 * The words the browser half of push says, handed to it in the page's language.
 *
 * `push.js` is a static file and says English; the page puts each sentence's translation on its
 * tag, so a Greek page says Greek. A sentence the script says that the list misses would stay
 * English for ever, so the list is checked against the script itself.
 */
#[CoversClass(BrowserScript::class)]
class BrowserScriptTest extends TestCase
{
    protected function tearDown(): void
    {
        Language::setInstance(null);
    }

    /**
     * Only the sentences the language has are handed over, as JSON escaped for an attribute; with
     * none, no attribute at all.
     */
    public function testTheTranslationsGoOnTheTag(): void
    {
        // Arrange — a language with one of the sentences
        Language::setInstance(new class extends Language {
            public function __construct()
            {
            }

            /** One translation, everything else as given. */
            public function _($string = '', $args = '')
            {
                return $string === 'Asking…' ? 'Ρωτάω "τώρα"…' : $string;
            }
        });

        // Act
        $words      = BrowserScript::words();
        $attributes = BrowserScript::attributes();
        Language::setInstance(new class extends Language {
            public function __construct()
            {
            }

            /** Nothing translated. */
            public function _($string = '', $args = '')
            {
                return $string;
            }
        });
        $english = [BrowserScript::words(), BrowserScript::attributes()];

        // Assert
        $this->assertSame(['Asking…' => 'Ρωτάω "τώρα"…'], json_decode($words, true));
        $this->assertStringStartsWith(' data-push-words="', $attributes);
        $this->assertStringContainsString('&quot;', $attributes, 'escaped for the attribute');
        $this->assertSame(['{}', ''], $english);
    }

    /**
     * Every sentence on the list is one the script says, and every theme's tag carries them —
     * the list and the script are two files, and nothing else would notice them part.
     */
    public function testTheListIsWhatTheScriptSays(): void
    {
        // Arrange — the script, its split sentences joined as the engine joins them
        $stub   = (string) file_get_contents(dirname(__DIR__, 3) . '/scaffolding/templates/push-notifications.js.stub');
        $joined = str_replace("\\'", "'", (string) preg_replace("/'\\s*\\+\\s*'/", '', $stub));

        // Act & Assert
        foreach (BrowserScript::WORDS as $sentence) {
            $this->assertStringContainsString("'" . $sentence . "'", $joined, $sentence);
        }
        foreach (glob(dirname(__DIR__, 3) . '/scaffolding/themes/*/{footer.php,views/OAuth2/privacy_settings.html.php}', GLOB_BRACE) ?: [] as $view) {
            $source = (string) file_get_contents($view);
            if (str_contains($source, 'assets/js/push.js')) {
                $this->assertStringContainsString('BrowserScript::attributes()', $source, $view);
            }
        }
    }
}
