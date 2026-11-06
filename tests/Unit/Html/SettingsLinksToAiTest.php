<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Html;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The AI's settings are always one click from Settings: the admin menu's *AI* is Ask, shown only
 * once there is a key, so without this link nobody could reach the screen where the key is set.
 */
class SettingsLinksToAiTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function themes(): array
    {
        return ['bootstrap' => ['bootstrap'], 'tailwind' => ['tailwind'], 'plain-css' => ['plain-css']];
    }

    /** Each theme's Settings links to the AI settings, with the `llm` feature. */
    #[DataProvider('themes')]
    public function testSettingsLinksToTheAiSettings(string $theme): void
    {
        // Arrange
        // As PHP: a template compiled, so the link reads the same in either kind.
        $view = (string) file_get_contents(\Pramnos\Tests\Support\ScaffoldView::run(ROOT . '/scaffolding/themes/' . $theme . '/views/settings/settings.html.php'));

        // Act
        $found = preg_match("/FeatureRegistry::isEnabled\\('llm'\\)\\): \\?><a href=\"<\\?php echo adminUrl\\('Ai\\/settings'\\); \\?>\"[^>]*data-settings-ai/", $view);

        // Assert
        $this->assertSame(1, $found, $theme . ': the link to the AI settings, behind the llm feature');
    }
}
