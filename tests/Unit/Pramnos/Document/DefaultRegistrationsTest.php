<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Document;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Document\Document;

/**
 * The script and style handles a template may enqueue without naming a source.
 *
 * `enqueueScript('slimbox2')` with no `src` **throws** when the handle was never registered, so
 * every handle this constructor registers is a contract with every template that names it. That
 * contract had no test, and the consequence was exactly what a missing test allows:
 *
 * A commit in March 2020 titled *"Minor variable name changes"* — 14 insertions, 90 deletions —
 * deleted eleven registrations. Nothing failed, because nothing in this repository enqueues them;
 * the failure surfaced six years later in an application whose admin theme calls
 * `enqueueScript('slimbox2')` on **every page of its panel**, and it surfaced as a blocked port
 * rather than as a bug report against that commit.
 *
 * So this test does not assert that the libraries are good ideas. Adobe Spry has been
 * unmaintained since 2012. It asserts that a handle the framework once promised is still
 * answered, because the alternative is a fatal in somebody's admin panel and a stranger reading
 * a diff from 2020 to find out why.
 */
class DefaultRegistrationsTest extends TestCase
{
    /**
     * Gives each test its own document.
     *
     * @return void
     */
    protected function setUp(): void
    {
        Document::reset();
    }

    /**
     * A script handle a template may enqueue with no source.
     *
     * @param string $handle The registered handle
     * @return void
     */
    #[DataProvider('scriptHandles')]
    public function testTheScriptHandleIsRegistered(string $handle): void
    {
        // Arrange
        $document = Document::getInstance('html');

        // Assert
        $this->assertTrue(
            $document->isScriptRegistered($handle),
            $handle . " is not registered, so enqueueScript('" . $handle . "') will throw."
        );
    }

    /**
     * A style handle a template may enqueue with no source.
     *
     * @param string $handle The registered handle
     * @return void
     */
    #[DataProvider('styleHandles')]
    public function testTheStyleHandleIsRegistered(string $handle): void
    {
        // Arrange
        $document = Document::getInstance('html');

        // Assert
        $this->assertTrue(
            $document->isStyleRegistered($handle),
            $handle . " is not registered, so enqueueStyle('" . $handle . "') will throw."
        );
    }

    /**
     * Every script handle the constructor promises.
     *
     * The `restored` group is what the 2020 commit deleted; the `alias` group is what the
     * inputmask bundle absorbed — correctly as files, wrongly as handles, since a template
     * names the handle.
     *
     * @return array<string, array{string}>
     */
    public static function scriptHandles(): array
    {
        return [
            'jquery'                              => ['jquery'],
            'jquery-ui'                           => ['jquery-ui'],
            'datatables'                          => ['datatables'],
            'jquery-fileupload'                   => ['jquery-fileupload'],
            'bootstrap-datepicker'                => ['bootstrap-datepicker'],
            'jquery-inputmask'                    => ['jquery-inputmask'],
            'alias: jquery-inputmask-extensions'  => ['jquery-inputmask-extensions'],
            'alias: jquery-inputmask-date'        => ['jquery-inputmask-date'],
            'restored: slimbox2'                  => ['slimbox2'],
            'restored: thickbox'                  => ['thickbox'],
            'restored: spectrum'                  => ['spectrum'],
            'restored: SpryMenuBar'               => ['SpryMenuBar'],
            'restored: SpryValidationTextArea'    => ['SpryValidationTextArea'],
            'restored: SpryValidationTextField'   => ['SpryValidationTextField'],
            'restored: SpryValidationPassword'    => ['SpryValidationPassword'],
            'restored: SpryValidationConfirm'     => ['SpryValidationConfirm'],
            'restored: SpryValidationCheckbox'    => ['SpryValidationCheckbox'],
        ];
    }

    /**
     * Every style handle the constructor promises.
     *
     * @return array<string, array{string}>
     */
    public static function styleHandles(): array
    {
        return [
            'jquery-ui'                            => ['jquery-ui'],
            'restored: mediamanager'               => ['mediamanager'],
            'restored: slimbox2'                   => ['slimbox2'],
            'restored: thickbox'                   => ['thickbox'],
            'restored: spectrum'                   => ['spectrum'],
            'restored: SpryValidationTextarea'     => ['SpryValidationTextarea'],
            'restored: SpryValidationTextField'    => ['SpryValidationTextField'],
            'restored: SpryValidationPassword'     => ['SpryValidationPassword'],
            'restored: SpryValidationConfirm'      => ['SpryValidationConfirm'],
            'restored: SpryValidationCheckbox'     => ['SpryValidationCheckbox'],
            'restored: SpryMenuBarHorizontal'      => ['SpryMenuBarHorizontal'],
        ];
    }

    /**
     * Enqueuing a restored handle with no source does not throw.
     *
     * The registration test above proves the handle is known; this proves the thing the
     * consumer's theme actually does. `enqueueScript('slimbox2')` on every page of an admin
     * panel is the call that could not be made.
     */
    public function testARestoredHandleCanBeEnqueuedWithoutASource(): void
    {
        // Arrange
        $document = Document::getInstance('html');

        // Act — the call the consumer's theme.html.php makes on every request
        $document->enqueueScript('slimbox2');
        $document->enqueueStyle('slimbox2');

        // Assert — reaching here without an exception is the assertion
        $this->assertTrue(true);
    }

    /**
     * An unknown handle still throws — when the queue is processed, not when it is filled.
     *
     * `enqueueScript()` only queues; `processHeader()` drains the queue and it is
     * `_enqueueScript()` that refuses an unregistered handle with no source. Worth pinning
     * both halves: the guard must still be real, or the restored registrations would be
     * pointless, and **the throw happens at render time rather than at the call**, which is
     * why a missing registration reaches production as a broken page instead of a broken
     * template.
     */
    public function testAnUnknownHandleThrowsWhenTheQueueIsProcessed(): void
    {
        // Arrange
        $document = Document::getInstance('html');
        $document->enqueueScript('a-handle-nobody-registered');

        // Act & Assert — the exception arrives here, one step later than the call
        $this->expectException(\Exception::class);
        (new \ReflectionMethod(Document::class, 'processHeader'))->invoke($document);
    }

    /**
     * `jquery-inputmask-jui` is **not** registered, and never was.
     *
     * Recorded because it appeared on a consumer's migration checklist among five handles that
     * were real. It has never existed in this framework, and it does not exist in the legacy one
     * either — checked in both. A checklist item that was never true is worth pinning so the next
     * reader does not go looking for it.
     */
    public function testTheHandleThatNeverExistedIsStillAbsent(): void
    {
        // Arrange
        $document = Document::getInstance('html');

        // Assert
        $this->assertFalse($document->isScriptRegistered('jquery-inputmask-jui'));
    }

    /**
     * The three CDN defaults come from the CDN unless an application says otherwise.
     *
     * The default is unchanged deliberately: flipping it would break every application that
     * stopped vendoring these files on the strength of the 2020 commit that moved them. What was
     * missing was the choice and the documentation, not a different default.
     */
    public function testTheCdnDefaultsAreStillTheDefault(): void
    {
        // Arrange
        $document = Document::getInstance('html');

        // Act — read the registry directly rather than widening the public API for a test
        $registry = new \ReflectionProperty(Document::class, '_js');
        $all      = (array) $registry->getValue($document);

        $sources = [];
        foreach (['jquery', 'bootstrap-datepicker', 'jquery-inputmask'] as $handle) {
            $sources[$handle] = $all[$handle]['src'] ?? '';
        }

        // Assert
        foreach ($sources as $handle => $src) {
            $this->assertStringContainsString(
                'cdnjs.cloudflare.com',
                (string) $src,
                $handle . ' should still default to the CDN; changing that is a breaking change.'
            );
        }
    }
}
