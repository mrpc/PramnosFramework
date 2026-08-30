<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\SettingsController;
use Pramnos\Auth\NewSignInAlert;

/**
 * A setting the controller saves must have a field on every theme's screen.
 *
 * The failure this prevents is silent and one-directional. `$request->get($key, $default)`
 * answers the default for a field the form never rendered, so a controller that writes it back
 * replaces an installation's choice on every unrelated save — an operator changing an SMTP
 * password resets the sign-in policy without touching it.
 *
 * It has happened twice. `devpanel.mount` and `devpanel.min_usertype` were lost this way, and
 * the new-sign-in **action** had a field in the tailwind theme and in neither of the other two,
 * so a settings save from those reset `require_2fa` to `notify` — the strict reading quietly
 * becoming the permissive one, which is the direction that matters.
 *
 * And the **trigger** had a field in no theme at all, so `SignInRisk` — the whole advanced-rule
 * engine, written and wired — could only be reached by hand-writing a settings row. A rule
 * nobody can enable is a rule nobody has.
 */
#[CoversClass(SettingsController::class)]
class SettingsFieldsMatchWhatIsSavedTest extends TestCase
{
    private const THEMES = ['tailwind', 'bootstrap', 'plain-css'];

    /**
     * Every sign-in alert setting is on every theme's screen.
     */
    public function testEverySignInSettingHasAFieldInEveryTheme(): void
    {
        $settings = [
            'POLICY_SETTING'  => NewSignInAlert::POLICY_SETTING,
            'TRIGGER_SETTING' => NewSignInAlert::TRIGGER_SETTING,
            'ACTION_SETTING'  => NewSignInAlert::ACTION_SETTING,
        ];

        foreach (self::THEMES as $theme) {
            // Act
            $view = $this->view($theme);

            // Assert
            foreach ($settings as $name => $key) {
                $this->assertMatchesRegularExpression(
                    '~NewSignInAlert::' . $name . '|' . preg_quote($key, '~') . '~',
                    $view,
                    $theme . ' has no field for ' . $key
                        . ' — the controller writes it on every save, so this theme resets it'
                );
            }
        }
    }

    /**
     * And each one is a `name=` on a control, not only a mention.
     *
     * A view that reads the value to display it, without posting it back, is the same failure
     * with an extra step: the screen shows the current setting and the save resets it.
     */
    public function testEachSettingIsPostedBackAndNotOnlyDisplayed(): void
    {
        foreach (self::THEMES as $theme) {
            // Act
            $view = $this->view($theme);

            // Assert
            foreach (['policyKey', 'triggerKey', 'actionKey'] as $variable) {
                $this->assertStringContainsString(
                    'name="<?php echo $' . $variable . '; ?>"',
                    $view,
                    $theme . ': $' . $variable . ' is read but never posted back'
                );
            }
        }
    }

    /**
     * An absent field leaves the setting alone rather than overwriting it.
     *
     * The guard itself. Without it, adding a setting to the controller and forgetting one
     * theme is enough to start silently resetting it — which is exactly how this was found.
     */
    public function testAnAbsentFieldDoesNotOverwriteTheSetting(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            (new \ReflectionClass(SettingsController::class))->getFileName()
        );
        $start  = (int) strpos($source, 'protected function saveChoice');
        $body   = substr($source, $start, 900);

        // Assert
        $this->assertGreaterThan(0, $start, 'the absent-field guard is its own method');
        $this->assertStringContainsString("'__KEEP__'", $body);
        $this->assertMatchesRegularExpression(
            "~__KEEP__'\\)?\\s*\\{?\\s*\\n?\\s*return~",
            $body,
            'an absent field has to return before writing anything'
        );
    }

    /**
     * The three settings go through that guard, not through a bare read-and-write.
     */
    public function testTheSignInSettingsUseTheGuard(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            (new \ReflectionClass(SettingsController::class))->getFileName()
        );

        // Assert
        foreach (['POLICY_SETTING', 'TRIGGER_SETTING', 'ACTION_SETTING'] as $name) {
            $this->assertMatchesRegularExpression(
                '~saveChoice\(\s*\$request,\s*\\\\Pramnos\\\\Auth\\\\NewSignInAlert::' . $name . '~',
                $source,
                $name . ' is written without the absent-field guard'
            );
        }
    }

    private function view(string $theme): string
    {
        $path = dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme
            . '/views/settings/settings.html.php';

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
