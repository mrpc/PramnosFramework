<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\DashboardController;
use Pramnos\Dashboard\Widget;

/** A card that draws one line, so a drawn card and an undrawn one can be told apart. */
final class OverrideTestCard extends Widget
{
    /** {@inheritdoc} */
    public function id(): string
    {
        return 'override.test';
    }

    /** {@inheritdoc} */
    public function title(): string
    {
        return 'Override test';
    }

    /** {@inheritdoc} */
    public function render(): string
    {
        return '<p>drawn</p>';
    }
}

/** An application's controller overriding cardFor() with its original, two-parameter signature. */
final class TwoParameterCardController extends DashboardController
{
    /** Without an application. */
    public function __construct()
    {
    }

    /** {@inheritdoc} */
    protected function cardFor(Widget $widget, array $item): array
    {
        return parent::cardFor($widget, $item) + ['ours' => true];
    }

    /** groupFor(), reachable. */
    public function group_(array $group, array $widgets): array
    {
        return $this->groupFor($group, $widgets);
    }
}

/** An application's controller that took a third `bool $draw` and passes it on. */
final class ThreeParameterCardController extends DashboardController
{
    /** Without an application. */
    public function __construct()
    {
    }

    /** {@inheritdoc} */
    protected function cardFor(Widget $widget, array $item, bool $draw = true): array
    {
        return parent::cardFor($widget, $item, $draw) + ['ours' => true];
    }

    /** groupFor(), reachable. */
    public function group_(array $group, array $widgets): array
    {
        return $this->groupFor($group, $widgets);
    }
}

/**
 * DashboardController::cardFor() keeps the signature applications override.
 *
 * A parameter added to an overridable method is a fatal at class load in every subclass that
 * overrides it with the old one ("Declaration … must be compatible"): an application's admin home
 * went down that way. Whether a tab is drawn travels in the item instead, and both an override with
 * the original signature and one that added `bool $draw` load and draw the tabs right.
 */
final class CardForOverrideTest extends TestCase
{
    /**
     * Both overrides load, and in a group of tabs only the active one is drawn.
     */
    public function testBothSignaturesLoadAndOnlyTheActiveTabIsDrawn(): void
    {
        // Arrange
        $widgets = ['a' => new OverrideTestCard(), 'b' => new OverrideTestCard()];
        $group   = ['id' => 'g', 'size' => 'm', 'title' => 'G', 'active' => 'b',
            'tabs' => [['id' => 'a', 'settings' => []], ['id' => 'b', 'settings' => []]]];

        foreach ([new TwoParameterCardController(), new ThreeParameterCardController()] as $controller) {
            // Act
            $tabs = $controller->group_($group, $widgets)['tabs'];

            // Assert — the override ran, the inactive tab is left for later, the active one drawn
            $name = $controller::class;
            $this->assertTrue($tabs[0]['ours'], $name . ': the override was called');
            $this->assertNull($tabs[0]['html'], $name . ': a tab not shown is not drawn');
            $this->assertStringContainsString('drawn', (string) $tabs[1]['html'], $name . ': the active tab is drawn');
        }
    }
}
