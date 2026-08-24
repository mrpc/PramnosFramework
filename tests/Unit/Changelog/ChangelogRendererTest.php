<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Changelog;

use PHPUnit\Framework\TestCase;
use Pramnos\Changelog\ChangelogRenderer;
use Pramnos\Event\ModelChange;

/**
 * Nothing in the change log stores prose; this is where a row becomes a sentence.
 *
 * The point of rendering at read time is that a wording can change without a migration
 * and without reinterpreting rows written years ago — and that it can be translated. The
 * reference application renders from a `switch` returning hardcoded English keyed on two
 * magic numbers, which is the same idea frozen into PHP.
 */
class ChangelogRendererTest extends TestCase
{
    protected function setUp(): void
    {
        ChangelogRenderer::reset();
    }

    protected function tearDown(): void
    {
        ChangelogRenderer::reset();
    }

    /**
     * An update renders each changed field as before → after.
     */
    public function testAnUpdateRendersTheDiff(): void
    {
        // Arrange
        $row = [
            'origin'  => 'feed',
            'entity'  => 'wcm-device',
            'op'      => ModelChange::UPDATED,
            'changes' => ['status' => ['old' => 1, 'new' => 3]],
        ];

        // Act & Assert
        $this->assertSame('status: 1 → 3', ChangelogRenderer::describe($row));
    }

    /**
     * A registered label replaces the column name.
     */
    public function testARegisteredLabelIsUsed(): void
    {
        // Arrange
        ChangelogRenderer::label('wcm-device', ['status' => 'Status']);
        $row = [
            'origin'  => 'feed',
            'entity'  => 'wcm-device',
            'op'      => ModelChange::UPDATED,
            'changes' => ['status' => ['old' => 1, 'new' => 3]],
        ];

        // Act & Assert
        $this->assertSame('Status: 1 → 3', ChangelogRenderer::describe($row));
    }

    /**
     * Without a label, the column name is shown rather than nothing.
     *
     * Deliberate: a missing label produces something ugly that names exactly what to
     * register. An empty string would produce ": 1 → 3", which reads as a rendering bug
     * and tells the reader nothing.
     */
    public function testAnUnlabelledFieldFallsBackToItsName(): void
    {
        // Arrange
        $row = [
            'origin'  => 'feed',
            'entity'  => 'wcm-device',
            'op'      => ModelChange::UPDATED,
            'changes' => ['conditionid' => ['old' => 1, 'new' => 2]],
        ];

        // Act & Assert
        $this->assertStringStartsWith('conditionid:', ChangelogRenderer::describe($row));
    }

    /**
     * A null value renders as a word, not as a gap.
     *
     * "status: → 3" reads as a broken renderer; "status: (none) → 3" reads as what
     * happened. A column cleared to NULL is one of the most common things in a diff.
     */
    public function testANullValueRendersAsAWord(): void
    {
        // Arrange
        $row = [
            'origin'  => 'feed',
            'entity'  => 'wcm-device',
            'op'      => ModelChange::UPDATED,
            'changes' => ['conditionid' => ['old' => 5, 'new' => null]],
        ];

        // Act & Assert
        $this->assertSame('conditionid: 5 → (none)', ChangelogRenderer::describe($row));
    }

    /**
     * Several changed fields are joined.
     */
    public function testSeveralFieldsAreJoined(): void
    {
        // Arrange
        $row = [
            'origin'  => 'feed',
            'entity'  => 'wcm-device',
            'op'      => ModelChange::UPDATED,
            'changes' => [
                'status' => ['old' => 1, 'new' => 3],
                'eui'    => ['old' => 'AAAA', 'new' => 'BBBB'],
            ],
        ];

        // Act & Assert
        $this->assertSame(
            'status: 1 → 3, eui: AAAA → BBBB',
            ChangelogRenderer::describe($row)
        );
    }

    /**
     * Create and delete say so, without a diff.
     */
    public function testCreateAndDeleteRenderWithoutADiff(): void
    {
        // Arrange
        $base = ['origin' => 'feed', 'entity' => 'wcm-device', 'changes' => []];

        // Act & Assert
        $this->assertSame(
            'wcm-device created',
            ChangelogRenderer::describe($base + ['op' => ModelChange::CREATED])
        );
        $this->assertSame(
            'wcm-device deleted',
            ChangelogRenderer::describe($base + ['op' => ModelChange::DELETED])
        );
    }

    /**
     * An update whose diff is missing still says something true.
     *
     * A row whose `changes` aged out, or was never readable, must not render as an empty
     * line in a timeline. "updated" is honest; inventing a field list would not be.
     */
    public function testAnUpdateWithNoReadableDiffStillRenders(): void
    {
        // Arrange
        $row = ['origin' => 'feed', 'entity' => 'wcm-device', 'op' => ModelChange::UPDATED];

        // Act & Assert
        $this->assertSame('wcm-device updated', ChangelogRenderer::describe($row));
    }

    /**
     * An application event renders from its code.
     */
    public function testAnEventRendersFromItsCode(): void
    {
        // Arrange
        ChangelogRenderer::label('wcm-device', [
            'device.assigned_on_finalize' => 'Assigned on finalize',
        ]);
        $row = [
            'origin' => 'events',
            'entity' => 'wcm-device',
            'event'  => 'device.assigned_on_finalize',
        ];

        // Act & Assert
        $this->assertSame('Assigned on finalize', ChangelogRenderer::describe($row));
    }

    /**
     * Free text wins over the code.
     *
     * The only reason to write a description is that no code described the thing, so
     * preferring the code would discard the more specific of the two.
     */
    public function testFreeTextWinsOverTheCode(): void
    {
        // Arrange
        ChangelogRenderer::label('wcm-device', ['device.note' => 'A note']);
        $row = [
            'origin'      => 'events',
            'entity'      => 'wcm-device',
            'event'       => 'device.note',
            'description' => 'Replaced after the flood',
        ];

        // Act & Assert
        $this->assertSame('Replaced after the flood', ChangelogRenderer::describe($row));
    }

    /**
     * Labels for one entity do not leak into another.
     *
     * `status` means different things on a device and on an invoice, and two applications
     * sharing this process must not rename each other's columns.
     */
    public function testLabelsAreScopedToTheirEntity(): void
    {
        // Arrange
        ChangelogRenderer::label('wcm-device', ['status' => 'Device status']);
        $row = [
            'origin'  => 'feed',
            'entity'  => 'invoice',
            'op'      => ModelChange::UPDATED,
            'changes' => ['status' => ['old' => 1, 'new' => 2]],
        ];

        // Act & Assert
        $this->assertSame('status: 1 → 2', ChangelogRenderer::describe($row));
    }
}
