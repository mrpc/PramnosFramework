<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Messaging;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Messaging\Controllers\MassMessagesController;

/**
 * The compose screen offers what the controller can answer, in every theme.
 *
 * The recurring failure in this area is machinery that is complete and never reached: a filter
 * the resolver understands with no field on the form, or a field on one theme's form and not
 * the other two. Nothing fails — the screen renders, the send works, and the capability is
 * simply invisible to whoever is using that theme.
 *
 * Asserted on the templates rather than on rendered HTML: rendering one needs a document, a
 * theme, a session and a database, and what has to hold here is only that the field is in the
 * file and points at the action that reads it.
 */
#[CoversClass(MassMessagesController::class)]
class MassMessageComposeScreenTest extends TestCase
{
    /** The themes that ship a compose screen. */
    private const THEMES = ['tailwind', 'bootstrap', 'plain-css'];

    /**
     * Every audience filter the resolver reads has a field on the form.
     *
     * The list on the left is the one `criteriaFrom()` posts back; a filter missing from a
     * theme is a filter that theme's operators cannot use and have no way to discover.
     */
    public function testEveryAudienceFilterHasAFieldInEveryTheme(): void
    {
        // Arrange
        $fields = [
            'usertype_min', 'usertype_max', 'language', 'twofactor',
            'last_login_after', 'last_login_before', 'validated_only', 'active_only',
            'exclude_optouts', 'groups[]', 'organizations[]', 'only_ids', 'exclude_ids',
        ];

        foreach (self::THEMES as $theme) {
            // Act
            $template = $this->template($theme);

            // Assert
            foreach ($fields as $field) {
                $this->assertStringContainsString(
                    'name="' . $field . '"',
                    $template,
                    $theme . ' has no field for ' . $field
                );
            }
        }
    }

    /**
     * And the preview is reachable from the form, in every theme.
     *
     * The whole point of the action. A `preview()` on the controller that no button posts to is
     * the same thing as not having written it.
     */
    public function testThePreviewIsReachableFromEveryTheme(): void
    {
        foreach (self::THEMES as $theme) {
            // Act
            $template = $this->template($theme);

            // Assert
            $this->assertStringContainsString(
                "adminUrl('MassMessages/preview')",
                $template,
                $theme . ' cannot reach the audience preview'
            );
            $this->assertStringContainsString(
                'formnovalidate',
                $template,
                $theme . ' would refuse to preview an audience before the subject is written'
            );
        }
    }

    /**
     * A picker with nothing in it is not rendered.
     *
     * An installation with no groups was shown a disabled empty box and a sentence saying so —
     * a control that exists to tell you it does nothing. Reported from a real screen.
     */
    public function testAnEmptyPickerIsNotRenderedAtAll(): void
    {
        foreach (self::THEMES as $theme) {
            // Act
            $template = $this->template($theme);

            // Assert
            $this->assertStringContainsString('if ($groups !== [])', $template, $theme);
            $this->assertStringContainsString('if ($organizations !== [])', $template, $theme);
            $this->assertStringNotContainsString('This installation has no groups', $template, $theme);
        }
    }

    /**
     * The controller answers the four things the screen reads.
     *
     * A form field posting to a criteria reader that drops it is the same invisibility from the
     * other end.
     */
    public function testTheControllerReadsWhatTheFormPosts(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Messaging/Controllers/MassMessagesController.php'
        );

        // Assert
        foreach (['groups', 'organizations', 'only_ids', 'exclude_ids'] as $key) {
            $this->assertStringContainsString("'" . $key . "'", $source);
        }

        $this->assertStringContainsString('public function preview(', $source);
        $this->assertStringContainsString("'preview'", $source,
            'and the action is authorised, or it answers with a redirect to the login screen');
    }

    private function template(string $theme): string
    {
        $path = dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme
            . '/views/massmessages/edit.html.php';

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
