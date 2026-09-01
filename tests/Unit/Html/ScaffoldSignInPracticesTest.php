<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Html;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The mobile-keyboard practices a scaffolded sign-in form starts with.
 *
 * Three of the eight findings from an evaluation against
 * <https://web.dev/articles/sign-in-form-best-practices>, and all three are invisible on a desktop —
 * which is why they were missing everywhere and why they need a test rather than a review:
 *
 * - **the username field must not be «corrected».** iOS capitalises the first letter and applies
 *   autocorrect to a field it thinks is prose. A username that changed silently is, to the person
 *   typing it, a wrong password.
 * - **a six-digit code deserves the numeric keypad.** The OTP fields carry `pattern="[0-9]{6}"`,
 *   which validates but does not change the keyboard; without `inputmode` a phone opens the
 *   alphabetic one for a field that accepts only digits.
 * - **the last field of a form should say `enterkeyhint="go"`**, so the keyboard's action key submits
 *   instead of offering a newline or a next-field arrow.
 *
 * ## Why this reads by proximity rather than parsing the tag
 *
 * The obvious implementation — match `<input\b[^>]*?>` and inspect the attributes — is **wrong for a
 * PHP template**, and it broke about a hundred of these files when it was tried: an attribute value
 * here routinely contains `<?php echo … ?>`, whose `>` ends the match early, so the "tag" is a
 * fragment and anything appended to it lands in the middle of PHP code.
 *
 * So the checks anchor on an attribute that is *certainly* inside the tag — `autocomplete="…"` — and
 * look for its companions within a few hundred characters either side, comfortably a whole tag and
 * never across two inputs on their own lines. Less precise, and it cannot corrupt a file.
 */
class ScaffoldSignInPracticesTest extends TestCase
{
    private const THEMES = ['tailwind', 'bootstrap', 'plain-css'];

    /** Characters either side of the anchor: a whole tag, and less than the gap between two. */
    private const WINDOW = 300;

    private static function viewsDirectory(string $theme): string
    {
        return dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/views';
    }

    /** @return list<string> */
    private static function viewFiles(string $theme): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::viewsDirectory($theme))
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Every place an anchor appears, with the text around it.
     *
     * @return list<array{file: string, near: string}>
     */
    private static function occurrences(string $theme, string $anchor): array
    {
        $found = [];

        foreach (self::viewFiles($theme) as $path) {
            $source = (string) file_get_contents($path);
            $offset = 0;

            while (($at = strpos($source, $anchor, $offset)) !== false) {
                $found[] = [
                    'file' => str_replace(self::viewsDirectory($theme) . '/', '', $path),
                    'near' => substr(
                        $source,
                        max(0, $at - self::WINDOW),
                        strlen($anchor) + 2 * self::WINDOW
                    ),
                ];
                $offset = $at + strlen($anchor);
            }
        }

        return $found;
    }

    /** @return array<string, array{0: string}> */
    public static function themes(): array
    {
        $cases = [];

        foreach (self::THEMES as $theme) {
            $cases[$theme] = [$theme];
        }

        return $cases;
    }

    /**
     * The username field is not capitalised or corrected.
     *
     * All three attributes, because they are three different behaviours: `autocapitalize` is the
     * first letter, `autocorrect` is the whole word, and `spellcheck` is the red underline that
     * invites a person to "fix" a username that was right.
     */
    #[DataProvider('themes')]
    public function testTheUsernameFieldIsNotCapitalisedOrCorrected(string $theme): void
    {
        // Arrange
        // Both spellings: the sign-in screen's field carries the `webauthn` token so a passkey can
        // be offered inside its autofill, and anchoring on `autocomplete="username"` alone stopped
        // matching it the day that was added — which would have read as «no username field found».
        $occurrences = array_merge(
            self::occurrences($theme, 'autocomplete="username"'),
            self::occurrences($theme, 'autocomplete="username webauthn"')
        );
        $this->assertNotSame([], $occurrences, 'no username field found in ' . $theme);

        // Act
        $missing = [];

        foreach ($occurrences as $occurrence) {
            foreach (['autocapitalize="none"', 'autocorrect="off"', 'spellcheck="false"'] as $needed) {
                if (!str_contains($occurrence['near'], $needed)) {
                    $missing[] = $occurrence['file'] . ' — ' . $needed;
                }
            }
        }

        // Assert
        $this->assertSame(
            [],
            $missing,
            "A username field iOS will capitalise or autocorrect. Add these beside\n"
            . "autocomplete=\"username\":\n  " . implode("\n  ", $missing)
        );
    }

    /**
     * A one-time code opens the numeric keypad and its action key submits.
     *
     * `pattern="[0-9]{6}"` validates the value and does nothing to the keyboard.
     */
    #[DataProvider('themes')]
    public function testAOneTimeCodeGetsTheNumericKeypad(string $theme): void
    {
        // Arrange
        $occurrences = self::occurrences($theme, 'autocomplete="one-time-code"');
        $this->assertNotSame([], $occurrences, 'no one-time-code field found in ' . $theme);

        // Act
        $missing = [];

        foreach ($occurrences as $occurrence) {
            foreach (['inputmode="numeric"', 'enterkeyhint="go"'] as $needed) {
                if (!str_contains($occurrence['near'], $needed)) {
                    $missing[] = $occurrence['file'] . ' — ' . $needed;
                }
            }
        }

        // Assert
        $this->assertSame(
            [],
            $missing,
            "A digits-only field that opens the alphabetic keyboard:\n  "
            . implode("\n  ", $missing)
        );
    }

    /**
     * The screens a person signs in through tell the keyboard its action key submits.
     *
     * Asserted per screen rather than per field: which input is *last* cannot be decided by reading
     * literals, and the useful guarantee is that the form has the hint at all — a form with none is
     * one where the keyboard offers «next» on the field that ends it.
     *
     * @return list<string>
     */
    public static function authScreens(): array
    {
        return [
            'login/login.html.php',
            'login/login_2fa.html.php',
            'login/forgotpassword.html.php',
            'login/resetpassword.html.php',
            'register/register.html.php',
            'OAuth2/change_password.html.php',
        ];
    }

    #[DataProvider('themes')]
    public function testEveryAuthScreenTellsTheKeyboardItsActionKeySubmits(string $theme): void
    {
        // Act
        $without = [];

        foreach (self::authScreens() as $screen) {
            $path = self::viewsDirectory($theme) . '/' . $screen;

            if (!is_file($path)) {
                continue;
            }

            if (!str_contains((string) file_get_contents($path), 'enterkeyhint="go"')) {
                $without[] = $screen;
            }
        }

        // Assert
        $this->assertSame(
            [],
            $without,
            "These sign-in screens do not tell the keyboard that its action key submits:\n  "
            . implode("\n  ", $without)
        );
    }
}
