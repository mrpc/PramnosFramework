<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Translator;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The framework's Greek catalogue against the screens it translates: every phrase the sign-in,
 * account and status screens pass to `t()`, and every message the auth controllers flash, has a
 * Greek line — and the line takes the same placeholders, since `sprintf` with one too few throws.
 *
 * A screen that gains a phrase without a line here shows that phrase in English on a Greek site,
 * which nobody notices until a user does.
 */
#[CoversNothing]
final class FrameworkGreekCatalogueTest extends TestCase
{
    /** Where the phrases are, relative to the repository. */
    private const SOURCES = [
        'scaffolding/themes/*/views/{login,OAuth2,twofactor,register,passkey,device,sso,profile,organization,home,status,partials,messages}/*.php',
        'scaffolding/themes/*/views/dashboard/dashboard.html.php',
        'src/Pramnos/Auth/Controllers/{Account,TwoFactorAuth,Device,Passkey}.php',
        'src/Pramnos/Auth/Notifications/{SecondFactorCode,NewSignIn,NewDeviceAuthLink,SecurityChange}Notification.php',
        'src/Pramnos/Security/CookieConsent.php',
        'src/Pramnos/Application/Controllers/Status.php',
        'src/Pramnos/Html/NotificationBell.php',
        'scaffolding/themes/*/views/queue/*.php',
        'src/Pramnos/Queue/Controllers/QueueController.php',
        'scaffolding/themes/*/views/emails/*.php',
        'src/Pramnos/Application/Controllers/EmailsController.php',
        'scaffolding/themes/*/views/services/{services,pools}.html.php',
        'scaffolding/themes/*/views/scheduler/*.php',
        'src/Pramnos/Application/Controllers/Scheduler.php',
    ];

    /**
     * Every literal phrase passed to `t()` in those files has a Greek line.
     */
    public function testEveryPhraseOfTheAuthScreensHasAGreekLine(): void
    {
        // Arrange
        $greek = include dirname(__DIR__, 3) . '/resources/language/el.php';

        // Act
        $missing = array_values(array_filter(self::phrases(), static fn (string $key): bool => !isset($greek[$key])));

        // Assert
        $this->assertSame([], $missing, 'Phrases with no Greek line in resources/language/el.php');
    }

    /**
     * Each Greek line takes the same placeholders, in the same order, as its English key, and
     * none is empty.
     */
    public function testEveryGreekLineTakesTheSamePlaceholders(): void
    {
        // Arrange
        $greek = include dirname(__DIR__, 3) . '/resources/language/el.php';
        $placeholders = static fn (string $s): array => preg_match_all('/%(?:\d+\$)?[sd]/', $s, $m) ? $m[0] : [];

        // Act
        $wrong = [];
        foreach ($greek as $key => $line) {
            if (trim((string) $line) === '' || $placeholders((string) $key) !== $placeholders((string) $line)) {
                $wrong[] = $key;
            }
        }

        // Assert
        $this->assertSame([], $wrong, 'Greek lines whose placeholders differ from the key, or that are empty');
    }

    /**
     * Every literal first argument of `t()` in the sources, read with the tokenizer so quoting
     * and concatenation are exact — a phrase split over `.` would never match, so it must not be.
     *
     * @return list<string>
     */
    private static function phrases(): array
    {
        $root = dirname(__DIR__, 3);
        $keys = [];
        foreach (self::SOURCES as $pattern) {
            foreach (glob($root . '/' . $pattern, GLOB_BRACE) ?: [] as $file) {
                $tokens = array_values(array_filter(
                    token_get_all((string) file_get_contents($file)),
                    static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
                ));
                foreach ($tokens as $i => $token) {
                    // `t(…)`, and `$t(…)` — the local closure over t() some views and classes use.
                    $named = is_array($token) && (($token[0] === T_STRING && $token[1] === 't') || ($token[0] === T_VARIABLE && $token[1] === '$t'));
                    if (!$named || ($tokens[$i + 1] ?? null) !== '(') {
                        continue;
                    }
                    $before = $tokens[$i - 1] ?? null;
                    if (is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                        continue;
                    }
                    $arg = $tokens[$i + 2] ?? null;
                    if (is_array($arg) && $arg[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $keys[stripcslashes(substr($arg[1], 1, -1))] = true;
                    }
                }
            }
        }
        self::assertNotEmpty($keys, 'No phrases found: the source patterns no longer match anything');

        return array_keys($keys);
    }
}
