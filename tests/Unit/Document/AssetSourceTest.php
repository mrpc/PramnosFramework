<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Document\Document;

/**
 * Whether the framework's three default scripts come from a CDN or from this server.
 *
 * `documentAssetSource` governs `jquery`, `bootstrap-datepicker` and `jquery-inputmask` — the only
 * three handles the constructor points anywhere but locally. Never executed, for a setting whose
 * default sends every visitor's browser to a third party.
 *
 * The shape is not a boolean, and that is the interesting part. All-or-nothing was tried and proved
 * wrong within a day by an installation that had `jquery` vendored and **no `plugins/` directory at
 * all**: switching to `'local'` would have 404'd two of the three scripts, so their choice was
 * between a privacy problem they wanted to fix and two broken scripts. A list of handles is what
 * lets them fix the one they can.
 *
 * Which leaves `servesDefaultsFromCdn()` answering a question that has no single answer under the
 * list form — so it answers `false` for any non-empty list, and the callers that need detail ask
 * `localHandles()` instead.
 */
#[CoversClass(Document::class)]
class AssetSourceTest extends TestCase
{
    private mixed $saved = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = Settings::getSetting('documentAssetSource');
    }

    protected function tearDown(): void
    {
        // `deleteSetting()`, never `clearSettings()`: the latter empties the whole store for the
        // process, including everything loaded from settings.php, and nothing reloads it.
        if ($this->saved === null || $this->saved === '') {
            Settings::deleteSetting('documentAssetSource');
        } else {
            Settings::setSetting('documentAssetSource', $this->saved, false);
        }

        parent::tearDown();
    }

    private function servesFromCdn(): bool
    {
        return (new \ReflectionMethod(Document::class, 'servesDefaultsFromCdn'))->invoke(null);
    }

    /**
     * With nothing configured, the scripts come from the CDN.
     *
     * The historical default, and worth stating plainly because it is a privacy decision an
     * installation inherits without making it: a fresh site sends every visitor's browser to a
     * third-party host.
     */
    public function testWithNothingConfiguredTheCdnIsUsed(): void
    {
        // Arrange
        Settings::deleteSetting('documentAssetSource');

        // Act + Assert
        $this->assertTrue($this->servesFromCdn());
    }

    /**
     * `'local'` turns it off, in whatever case and spacing somebody typed it.
     *
     * A setting edited by hand in a config file or typed into an admin form, so `' Local '` has to
     * mean the same thing — the alternative is a site that quietly keeps using the CDN because of
     * a capital letter.
     *
     * @return array<string, array{0: string}>
     */
    public static function localSpellings(): array
    {
        return [
            'lower case'     => ['local'],
            'capitalised'    => ['Local'],
            'upper case'     => ['LOCAL'],
            'with spaces'    => ['  local  '],
        ];
    }

    #[DataProvider('localSpellings')]
    public function testLocalTurnsOffTheCdnHoweverItIsSpelled(string $configured): void
    {
        // Arrange
        Settings::setSetting('documentAssetSource', $configured, false);

        // Act + Assert
        $this->assertFalse(
            $this->servesFromCdn(),
            var_export($configured, true) . ' should mean local'
        );
    }

    /**
     * Anything else means the CDN.
     *
     * Including a value somebody meant as local but did not spell that way. Failing *towards* the
     * CDN is the safe direction for availability — the scripts load — and the unsafe one for
     * privacy, which is why the setting is documented rather than guessed at.
     */
    public function testAnUnrecognisedValueMeansTheCdn(): void
    {
        // Arrange
        Settings::setSetting('documentAssetSource', 'self-hosted', false);

        // Act + Assert
        $this->assertTrue($this->servesFromCdn());
    }

    /**
     * A list of handles is not "the CDN", whatever is in it.
     *
     * The question has no single answer under the list form: some handles are local and some are
     * not. So this says `false` and the callers that matter ask `localHandles()` — a
     * `servesDefaultsFromCdn()` that answered `true` here would make a page emit CDN tags for
     * handles the installation had vendored.
     */
    public function testANonEmptyListIsNotTheCdn(): void
    {
        // Arrange
        Settings::setSetting('documentAssetSource', ['jquery'], false);

        // Act + Assert
        $this->assertFalse($this->servesFromCdn());
    }

    /**
     * The forms `Settings` actually hands a list back in.
     *
     * A list is stored and returned as an array, an `stdClass`, a JSON string or a
     * comma-separated string depending on how it got there. All four mean the same thing, and
     * three of them used to be answered wrongly: the object raised `Object of class stdClass
     * could not be converted to string` on every page build, and the two string forms were
     * reported as "on the CDN" — so a page emitted CDN tags for scripts the installation had
     * vendored.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function listForms(): array
    {
        $object = new \stdClass();
        $object->{'0'} = 'jquery';

        return [
            'array'            => [['jquery']],
            'stdClass'         => [$object],
            'JSON string'      => ['["jquery"]'],
            'comma separated'  => ['jquery, bootstrap-datepicker'],
        ];
    }

    #[DataProvider('listForms')]
    public function testEveryFormOfAListIsUnderstood(mixed $configured): void
    {
        // Arrange
        Settings::setSetting('documentAssetSource', $configured, false);

        // Act + Assert
        $this->assertFalse(
            $this->servesFromCdn(),
            'a configured list was read as "everything from the CDN"'
        );
    }

    /**
     * An empty list means nothing is local, so the CDN it is.
     *
     * The one array value that has a single answer. `[]` is what a form submits when somebody
     * unticks every box, and it has to mean the same as not configuring the setting at all rather
     * than something new.
     */
    public function testAnEmptyListMeansTheCdn(): void
    {
        // Arrange
        Settings::setSetting('documentAssetSource', [], false);

        // Act + Assert
        $this->assertTrue($this->servesFromCdn());
    }
}
