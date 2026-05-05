<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Style;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Style\PramnosStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Unit tests for PramnosStyle.
 *
 * PramnosStyle extends SymfonyStyle with framework-specific helpers. These
 * tests verify that the new methods produce the expected output fragments
 * without exercising SymfonyStyle internals (those are tested by Symfony).
 *
 * Strategy: use BufferedOutput to capture written content, then assert on
 * key strings. Terminal-control characters (ANSI codes) are present in the
 * output but do not interfere with assertStringContainsString checks.
 */
class PramnosStyleTest extends TestCase
{
    private PramnosStyle $io;
    private BufferedOutput $output;

    protected function setUp(): void
    {
        // Arrange — minimal input (no args), buffered output for capture
        $this->output = new BufferedOutput();
        $this->io     = new PramnosStyle(new ArrayInput([]), $this->output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // frameworkTitle()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * frameworkTitle() must write the given title string to the output.
     *
     * The exact block styling is delegated to SymfonyStyle::block(); we only
     * assert that the title text is present and output is non-empty.
     */
    public function testFrameworkTitleWritesTitleText(): void
    {
        // Act
        $this->io->frameworkTitle('Pramnos Framework v1.2');

        // Assert — title text is present in captured output
        $this->assertStringContainsString('Pramnos Framework v1.2', $this->output->fetch());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // step()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * step() formats the step number, total, and label into a section header
     * in the form "Step N/T: label".
     *
     * The section header is used for multi-step wizards (e.g. init) so
     * developers can immediately see progress through the wizard.
     */
    public function testStepFormatsAsStepNOfTotal(): void
    {
        // Act
        $this->io->step(2, 6, 'Framework features');

        // Assert — the step string must appear
        $output = $this->output->fetch();
        $this->assertStringContainsString('Step 2/6', $output);
        $this->assertStringContainsString('Framework features', $output);
    }

    /**
     * step() with step number 1 and total 1 still renders correctly.
     *
     * Edge case: single-step wizards must not crash or produce malformed output.
     */
    public function testStepHandlesSingleStepWizard(): void
    {
        // Act
        $this->io->step(1, 1, 'Only step');

        // Assert
        $this->assertStringContainsString('Step 1/1', $this->output->fetch());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // summaryTable()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * summaryTable() renders each key-value pair so both the label and value
     * appear in the output.
     *
     * Used after a wizard completes to print the chosen configuration.
     */
    public function testSummaryTableContainsLabelsAndValues(): void
    {
        // Act
        $this->io->summaryTable([
            'App name'  => 'MyApp',
            'Namespace' => 'MyApp',
            'Database'  => 'mysql',
        ]);

        // Assert
        $output = $this->output->fetch();
        $this->assertStringContainsString('App name', $output);
        $this->assertStringContainsString('MyApp', $output);
        $this->assertStringContainsString('Database', $output);
        $this->assertStringContainsString('mysql', $output);
    }

    /**
     * summaryTable() with an empty array produces output without errors
     * (defensive: callers may pass an empty set after a no-op step).
     */
    public function testSummaryTableWithEmptyArrayIsHarmless(): void
    {
        // Act & Assert — must not throw
        $this->io->summaryTable([]);
        $this->output->fetch();  // just drain; no assertion on content
        $this->assertTrue(true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // statusTag()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * statusTag('Ran') wraps the text in a green tag — used in migrate:status
     * to highlight successful migrations.
     */
    public function testStatusTagRanIsGreen(): void
    {
        // Act & Assert
        $tag = PramnosStyle::statusTag('Ran');
        $this->assertStringContainsString('green', $tag);
        $this->assertStringContainsString('Ran', $tag);
    }

    /**
     * statusTag('Failed') wraps the text in a red tag.
     */
    public function testStatusTagFailedIsRed(): void
    {
        // Act & Assert
        $tag = PramnosStyle::statusTag('Failed');
        $this->assertStringContainsString('red', $tag);
        $this->assertStringContainsString('Failed', $tag);
    }

    /**
     * statusTag('Pending') wraps the text in a yellow tag.
     */
    public function testStatusTagPendingIsYellow(): void
    {
        // Act & Assert
        $tag = PramnosStyle::statusTag('Pending');
        $this->assertStringContainsString('yellow', $tag);
        $this->assertStringContainsString('Pending', $tag);
    }

    /**
     * statusTag() with an unrecognised status returns the string unchanged —
     * no wrapping tag, no exception, no substitution.
     */
    public function testStatusTagUnknownReturnsRawString(): void
    {
        // Act & Assert
        $tag = PramnosStyle::statusTag('Custom');
        $this->assertSame('Custom', $tag);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // hint(), check(), cross()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * hint() writes the message to the output (dimmed styling).
     *
     * It is used for secondary guidance text that should not distract from
     * the primary flow.
     */
    public function testHintWritesMessage(): void
    {
        // Act
        $this->io->hint('This is a hint');

        // Assert
        $this->assertStringContainsString('This is a hint', $this->output->fetch());
    }

    /**
     * check() writes a success check-mark followed by the message.
     *
     * Used inside wizard steps to confirm individual items passed.
     */
    public function testCheckWritesCheckmarkAndMessage(): void
    {
        // Act
        $this->io->check('Composer installed');

        // Assert — both the checkmark and the message are present
        $output = $this->output->fetch();
        $this->assertStringContainsString('✓', $output);
        $this->assertStringContainsString('Composer installed', $output);
    }

    /**
     * cross() writes a failure cross mark followed by the message.
     *
     * Used inside wizard steps to report individual items that failed.
     */
    public function testCrossWritesCrossMarkAndMessage(): void
    {
        // Act
        $this->io->cross('Docker not running');

        // Assert
        $output = $this->output->fetch();
        $this->assertStringContainsString('✗', $output);
        $this->assertStringContainsString('Docker not running', $output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Inherited SymfonyStyle surface (smoke tests)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * PramnosStyle inherits SymfonyStyle methods — spot-check success()
     * to confirm the inheritance chain works correctly.
     */
    public function testInheritedSuccessMethodWorks(): void
    {
        // Act
        $this->io->success('All done.');

        // Assert
        $this->assertStringContainsString('All done.', $this->output->fetch());
    }

    /**
     * PramnosStyle inherits SymfonyStyle error() method.
     */
    public function testInheritedErrorMethodWorks(): void
    {
        // Act
        $this->io->error('Something went wrong.');

        // Assert
        $this->assertStringContainsString('Something went wrong.', $this->output->fetch());
    }
}
