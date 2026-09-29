<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Html\MessageEditor;

/**
 * The message editor an application chose, and the scripts that bring it.
 *
 * Chosen in `app.php` (`messaging.editor`), `builtin` by default. The mode decides which script
 * a form prints and which fields it enhances; an unknown value must not leave a form with no
 * editor at all, nor with a script for an editor that is not there.
 */
#[CoversClass(MessageEditor::class)]
class MessageEditorTest extends TestCase
{
    private mixed $saved = null;

    protected function tearDown(): void
    {
        if ($this->saved !== null) {
            (new \ReflectionProperty(Application::class, 'appInstances'))->setValue(null, $this->saved);
            $this->saved = null;
        }
    }

    private function withConfig(array $messaging): void
    {
        $stub = new class extends Application {
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = ['messaging' => $messaging];
        $property = new \ReflectionProperty(Application::class, 'appInstances');
        $this->saved ??= $property->getValue() ?? [];
        $property->setValue(null, ['default' => $stub] + $this->saved);
    }

    /**
     * Unset, and set to something unknown, the mode is the framework's own editor.
     */
    public function testTheDefaultIsTheBuiltinEditor(): void
    {
        // Act & Assert
        $this->withConfig([]);
        $this->assertSame(MessageEditor::BUILTIN, MessageEditor::mode());
        $this->withConfig(['editor' => 'word']);
        $this->assertSame(MessageEditor::BUILTIN, MessageEditor::mode());
        $this->withConfig(['editor' => 'none']);
        $this->assertSame(MessageEditor::NONE, MessageEditor::mode());
    }

    /**
     * Each mode prints its own script: pf-editor.js, TinyMCE with the application's source and
     * licence key, or nothing.
     */
    public function testEachModePrintsItsScripts(): void
    {
        // Arrange
        $this->withConfig(['editor' => 'tinymce', 'tinymce' => ['src' => '/vendor/tinymce.min.js', 'license_key' => 'abc']]);

        // Act
        $builtin = MessageEditor::scripts(MessageEditor::BUILTIN);
        $tinymce = MessageEditor::scripts(MessageEditor::TINYMCE);
        $none    = MessageEditor::scripts(MessageEditor::NONE);

        // Assert
        $this->assertStringContainsString('assets/js/pf-editor.js', $builtin);
        $this->assertStringContainsString('.pf-editor-area h2{font-size:1.5em', $builtin, 'headings look like headings whatever the theme resets');
        $this->assertStringContainsString('src="/vendor/tinymce.min.js"', $tinymce);
        $this->assertStringContainsString('"license_key":"abc"', $tinymce);
        $this->assertStringContainsString('textarea[data-pf-editor=\"tinymce\"]', $tinymce);
        $this->assertSame('', $none);

        // Act & Assert — TinyMCE with nothing configured comes from the CDN, under the GPL key
        $this->withConfig(['editor' => 'tinymce']);
        $default = MessageEditor::scripts(MessageEditor::TINYMCE);
        $this->assertStringContainsString(MessageEditor::TINYMCE_CDN, $default);
        $this->assertStringContainsString('"license_key":"gpl"', $default);
    }

    /**
     * The forms mark their field and print the scripts, and the one-account form is always builtin.
     */
    public function testTheFormsUseIt(): void
    {
        foreach (['bootstrap', 'tailwind', 'plain-css'] as $theme) {
            // Arrange
            $root = dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/views/';

            // Act
            $compose = (string) file_get_contents($root . 'massmessages/edit.html.php');
            $notify  = (string) file_get_contents($root . 'users/notify.html.php');

            // Assert
            $this->assertStringContainsString('data-pf-editor="<?php echo $e(\Pramnos\Html\MessageEditor::mode()); ?>"', $compose, $theme);
            $this->assertStringContainsString('MessageEditor::scripts(\Pramnos\Html\MessageEditor::mode())', $compose, $theme);
            $this->assertStringContainsString('window.PfEditor.plain(body, plain)', $compose, $theme . ': push is written plain');
            $this->assertStringContainsString('data-pf-editor="builtin"', $notify, $theme);
            $this->assertStringContainsString('MessageEditor::scripts(\Pramnos\Html\MessageEditor::BUILTIN)', $notify, $theme);
        }
        $this->assertFileExists(dirname(__DIR__, 3) . '/scaffolding/assets/js/pf-editor.js');
    }
}
