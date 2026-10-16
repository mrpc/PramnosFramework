<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Html\ImageUpload;
use Pramnos\User\ProfilePhoto;

/**
 * The markup `pf-image.js` reads, the scripts it needs, and the profile picture's own settings.
 *
 * What matters: an option becomes the attribute the script reads, escaped, and a misspelt option
 * is refused rather than ignored; Cropper.js is printed only where it is installed, so a project
 * without it gets a form that still works; and the profile picture is a square of the size the
 * administrator set, kept within what the server stores.
 */
#[CoversClass(ImageUpload::class)]
#[CoversClass(ProfilePhoto::class)]
final class ImageUploadTest extends TestCase
{
    /** The Cropper.js file a test put in the web root, removed afterwards. */
    private string $library = '';

    protected function tearDown(): void
    {
        if ($this->library !== '' && is_file($this->library)) {
            unlink($this->library);
        }
        Settings::deleteSetting(ProfilePhoto::UPLOAD_SIZE_SETTING);
    }

    /**
     * Each option is the attribute the script reads: booleans as 1/0, empty values left out, and
     * a value that would break out of the attribute escaped.
     */
    public function testOptionsBecomeTheScriptsAttributes(): void
    {
        // Arrange
        $options = ['aspect' => '16/9', 'maxWidth' => 1600, 'maxHeight' => null, 'width' => '', 'round' => true, 'crop' => false,
            'type' => 'image/png', 'quality' => 0.8, 'preview' => '#a"b', 'submit' => true, 'height' => 900];

        // Act
        $html = ImageUpload::attributes($options);

        // Assert
        $this->assertStringStartsWith(' data-pf-image ', $html);
        $this->assertStringContainsString('data-aspect="16/9" data-max-width="1600" data-round="1" data-crop="0" data-type="image/png" data-quality="0.8"', $html);
        $this->assertStringContainsString('data-preview="#a&quot;b"', $html, 'escaped');
        $this->assertStringContainsString('data-submit="1" data-height="900"', $html);
        $this->assertStringNotContainsString('data-max-height', $html, 'null is left out');
        $this->assertStringNotContainsString('data-width', $html, 'an empty value is left out');
        $this->assertSame(' data-pf-image', ImageUpload::attributes(), 'no options: only the hook');
    }

    /**
     * The dialog's words go through the application's translations: only those translated are
     * carried, as JSON the script reads, escaped for a single-quoted attribute.
     */
    public function testTheDialogsTranslatedWordsAreCarried(): void
    {
        // Arrange
        \Pramnos\Framework\Factory::getLanguage()->addlang(['Use this picture' => "Αυτή's"]);

        // Act
        $html = ImageUpload::attributes(['aspect' => 1]);

        // Assert
        $this->assertStringContainsString(" data-words='{&quot;Use this picture&quot;:&quot;Αυτή&#039;s&quot;}'", $html, 'unescaped Unicode, an apostrophe that cannot end the attribute');
        $this->assertStringNotContainsString('Rotate left', $html, 'an untranslated word stays the script\'s own');
    }

    /** A misspelt option is an error, not a crop that silently is not held to its ratio. */
    public function testAnUnknownOptionIsRefused(): void
    {
        // Arrange
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no option "ratio"');

        // Act
        ImageUpload::attributes(['ratio' => 1]);

        // Assert: the exception above
    }

    /**
     * Without Cropper.js in the web root only pf-image.js is printed; once it is synced, its
     * stylesheet and script come first, so the dialog is offered.
     */
    public function testCropperIsPrintedOnlyWhereItIsInstalled(): void
    {
        // Arrange
        $web  = \Pramnos\Application\FrameworkScripts::webRoot((string) ROOT);
        $file = $web . '/' . ImageUpload::LIBRARY . 'cropper.min.js';
        $this->assertFileDoesNotExist($file, 'the test needs a web root without Cropper.js');

        // Act
        $without = ImageUpload::assets();
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, '/* cropper */');
        $this->library = $file;
        $with = ImageUpload::assets();

        // Assert
        $this->assertFalse(str_contains($without, 'cropper'), 'nothing that would 404');
        $this->assertStringContainsString('assets/js/pf-image.js', $without);
        $this->assertTrue(ImageUpload::installed());
        $this->assertStringContainsString('<link rel="stylesheet" href="' . sURL . ImageUpload::LIBRARY . 'cropper.min.css', $with);
        $this->assertLessThan(strpos($with, 'pf-image.js'), strpos($with, 'cropper.min.js'), 'the library before the script that uses it');
    }

    /**
     * The profile picture: a square with a round guide, sent once cropped, at the size the
     * administrator set — never below the square the server keeps, nor above the original's
     * 1024 pixels — and 512 when the setting is empty or not a number.
     */
    public function testTheProfilePictureIsASquareOfTheAdministratorsSize(): void
    {
        // Arrange
        $sizes = [];

        // Act
        foreach (['', 'big', '800', '64', '5000'] as $value) {
            Settings::setSetting(ProfilePhoto::UPLOAD_SIZE_SETTING, $value, false);
            $sizes[$value] = ProfilePhoto::uploadSize();
        }
        Settings::setSetting(ProfilePhoto::UPLOAD_SIZE_SETTING, '800', false);
        $html = ProfilePhoto::uploadAttributes();

        // Assert
        $this->assertSame(['' => 512, 'big' => 512, '800' => 800, '64' => ProfilePhoto::SIZE, '5000' => 1024], $sizes);
        $this->assertStringContainsString('data-aspect="1" data-max-width="800" data-max-height="800" data-round="1" data-preview="#profile-photo-preview" data-submit="1"', $html);
    }

    /**
     * Each theme's profile page renders the picture input with the crop attributes, the preview
     * the script fills — hidden until there is a picture — and the scripts. Rendered rather than
     * read, so a theme that drops one of the three fails here.
     */
    public function testEveryThemesProfilePageOffersTheCrop(): void
    {
        // Arrange
        $themes = ['bootstrap', 'tailwind', 'plain-css'];
        $render = static function (string $theme, string $avatar): string {
            $view = new class () {
                public string $routeBase = 'Account';
                public object $user;
                public string $activeNav = '';

                /** Partials are not this test's subject. */
                public function insert(string $name): void
                {
                }

                /** No flash on this page. */
                public function hasMessages(): bool
                {
                    return false;
                }

                /** No flash on this page. */
                public function hasErrors(): bool
                {
                    return false;
                }
            };
            $view->user = (object) ['avatarurl' => $avatar, 'photo' => $avatar === '' ? 0 : 5, 'username' => 'maria'];
            $file = ROOT . '/scaffolding/themes/' . $theme . '/views/profile/profile.html.php';

            return (function () use ($file): string {
                ob_start();
                include $file;

                return (string) ob_get_clean();
            })->call($view);
        };

        foreach ($themes as $theme) {
            // Act
            $none = $render($theme, '');
            $one  = $render($theme, 'https://app.test/uploads/me.jpg');

            // Assert
            $this->assertStringContainsString('name="photo" accept="image/jpeg,image/png,image/gif,image/webp"' . ProfilePhoto::uploadAttributes(), $none, $theme);
            $this->assertMatchesRegularExpression('/<img id="profile-photo-preview" hidden alt=/', $none, $theme . ': hidden, with no address, until a picture');
            $this->assertStringContainsString('<img id="profile-photo-preview" src="https://app.test/uploads/me.jpg"', $one, $theme);
            $this->assertStringContainsString('assets/js/pf-image.js', $none, $theme);
        }
    }
}
