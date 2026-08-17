<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Theme;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Document\Document;
use Pramnos\Theme\Theme;

/**
 * The two legacy-class fatals are gone, proved by executing the lines that carried them.
 *
 * `LegacyClassReferenceTest` guards the *shape* — no `pramnos_*` class may be named anywhere
 * in `src/`. That is the durable half, and it would pass equally well if the two lines had
 * been deleted rather than corrected. This is the other half: the branches that used to fatal
 * now run and produce the right thing.
 *
 * Both fatals sat where nothing exercised them, which is why they lasted:
 *
 *   - `Amp::render()` named `\pramnos_request` in the branch that builds a canonical when the
 *     document has none. So **every AMP page that did not set one explicitly** died on
 *     `Class "pramnos_request" not found` — the precise case the branch exists to handle.
 *   - `Theme::saveSettings()` named `pramnos_settings`, so a theme's settings could be
 *     collected and never stored.
 */
class LegacyFatalsFixedTest extends TestCase
{
    /** @var string|null The request URI these tests forge */
    private ?string $originalUri = null;

    /**
     * Remembers the request state and clears the shared content buffer.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->originalUri = \Pramnos\Http\Request::$originalRequestNoChange;
        Document::_setContent('');
    }

    /**
     * Restores it.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        \Pramnos\Http\Request::$originalRequestNoChange = (string) $this->originalUri;
        Document::_setContent('');
    }

    /**
     * An AMP document with no canonical renders, and builds one from the request.
     *
     * The assertion that matters is simply that `render()` returns — before the fix this
     * threw `Error: Class "pramnos_request" not found`. The canonical's content is checked
     * too, because a fix that merely stopped throwing while producing an empty canonical
     * would satisfy "no fatal" and still leave every AMP page telling search engines nothing.
     *
     * @return void
     */
    public function testAnAmpDocumentWithNoCanonicalRenders(): void
    {
        // Arrange — the state the dead branch was written for
        \Pramnos\Http\Request::$originalRequestNoChange = 'stations/athens/format/amp';

        $document = Document::getInstance('amp');
        $document->themeObject = null;
        $document->canonical   = '';

        // Act
        $rendered = $document->render();

        // Assert — it rendered at all…
        $this->assertIsString($rendered);
        $this->assertNotSame('', $rendered);

        // …and the canonical was built from the request, with /format/amp stripped, which is
        // the whole purpose of the branch
        $this->assertStringContainsString('stations/athens', $document->canonical);
        $this->assertStringNotContainsString('/format/amp', $document->canonical);
    }

    /**
     * A second render does not repeat the work, because a canonical is already set.
     *
     * Guards the `if` around the fixed line rather than only the line: an unconditional build
     * would overwrite a canonical the application set deliberately, which is a different bug
     * with the same visible symptom of "the canonical is wrong".
     *
     * @return void
     */
    public function testAnExplicitCanonicalIsNotOverwritten(): void
    {
        // Arrange
        \Pramnos\Http\Request::$originalRequestNoChange = 'stations/athens/format/amp';

        $document = Document::getInstance('amp');
        $document->themeObject = null;
        $document->canonical   = 'https://example.test/chosen-by-the-application';

        // Act
        $document->render();

        // Assert
        $this->assertSame(
            'https://example.test/chosen-by-the-application',
            $document->canonical
        );
    }

    /**
     * `Theme::saveSettings()` stores the settings instead of fatalling.
     *
     * A stub form is supplied because **nothing in `Theme` assigns `$_form`** — a separate and
     * larger finding, reported rather than changed here: the whole theme-settings API
     * (`addSetting()`, `hasSettings()`, `getSetting()`, `renderSettingsForm()`,
     * `saveSettings()`) calls into that property, and a base `Theme` has it as null. Deciding
     * what those methods should do instead of fatalling is a design question, not a typo.
     *
     * What this test proves is narrower and is exactly what was claimed fixed: given a form,
     * the method now reaches `Settings::setSetting()` rather than a class that does not exist.
     *
     * @return void
     */
    public function testSaveSettingsReachesTheSettingsStore(): void
    {
        // Arrange — a theme carrying a minimal form stub
        $theme = new Theme('legacyfatalfixture', sys_get_temp_dir());

        $form = new class {
            /**
             * The values a settings form would have collected.
             *
             * @return array<string, string>
             */
            public function getData(): array
            {
                return ['accent' => '#ff0000'];
            }
        };

        $property = new \ReflectionProperty(Theme::class, '_form');
        $property->setValue($theme, $form);

        // Act — this threw `Class "pramnos_settings" not found` before the fix.
        // `writeToDatabase` is left at its default; `setSetting()` stores in memory first and
        // only touches the database when one is configured, so the assertion holds either way.
        $theme->saveSettings();

        // Assert
        $this->assertSame(
            serialize(['accent' => '#ff0000']),
            Settings::getSetting('theme_legacyfatalfixture_settings')
        );
    }
}
