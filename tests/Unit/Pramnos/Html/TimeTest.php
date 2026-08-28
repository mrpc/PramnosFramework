<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\Time;

/**
 * The time widget, which is what `Date::$time` renders.
 *
 * A native `<input type="time">`: the browser draws the clock, validates the value and
 * localises how it is shown, and it submits `HH:MM` — which is what `Date::getDate()` has
 * always parsed. What it replaces was three select boxes or a text field with a Spry validator
 * attached, a library that has not shipped in years and whose stylesheet was the only thing
 * keeping its four error messages off the page.
 *
 * The field name is the contract, and it is asserted here rather than assumed: `getDate()`
 * reads `{name}_timepicker`, and a widget that renders a time nobody can read back is the
 * same defect as one that renders nothing.
 */
#[CoversClass(Time::class)]
class TimeTest extends TestCase
{
    /**
     * The rendered field is a native time input carrying the current value.
     */
    public function testItRendersANativeTimeInput(): void
    {
        // Arrange
        $widget = new Time('meeting', mktime(14, 30, 0, 5, 17, 2024));

        // Act
        $html = $widget->render();

        // Assert
        $this->assertStringContainsString('type="time"', $html);
        $this->assertStringContainsString('name="meeting_timepicker"', $html);
        $this->assertStringContainsString('id="meeting_timepicker"', $html);
        $this->assertStringContainsString('value="14:30"', $html);
        $this->assertStringContainsString('step="60"', $html);
    }

    /**
     * A time given as a string is accepted, not silently turned into zero.
     *
     * Callers pass both: a timestamp from a record, and `'09:30'` from a config value or a
     * default written by hand.
     */
    public function testATimeGivenAsAStringIsUnderstood(): void
    {
        // Arrange & Act
        $widget = new Time('open', '09:30');

        // Assert
        $this->assertStringContainsString('value="09:30"', $widget->render());
    }

    /**
     * Spaces are stripped from the name, because it becomes an id.
     */
    public function testSpacesAreStrippedFromTheName(): void
    {
        // Arrange & Act
        $widget = new Time('start time', 0);

        // Assert
        $this->assertSame('starttime', $widget->name);
        $this->assertStringContainsString('name="starttime_timepicker"', $widget->render());
    }

    /**
     * `required`, `class` and `tabindex` reach the markup.
     *
     * They are how a form asks the browser to validate the field, so a widget that accepted
     * them and dropped them would be a form that silently stopped checking.
     */
    public function testTheFormAttributesAreRendered(): void
    {
        // Arrange
        $widget = new Time('meeting', 0);
        $widget->required = true;
        $widget->class = 'form-control';
        $widget->tabindex = 4;

        // Act
        $html = $widget->render();

        // Assert
        $this->assertStringContainsString(' required', $html);
        $this->assertStringContainsString('class="form-control"', $html);
        $this->assertStringContainsString('tabindex="4"', $html);
    }

    /**
     * And they are absent when not asked for, rather than rendered empty.
     */
    public function testTheAttributesAreAbsentByDefault(): void
    {
        // Act
        $html = (new Time('meeting', 0))->render();

        // Assert
        $this->assertStringNotContainsString('required', $html);
        $this->assertStringNotContainsString('class=', $html);
        $this->assertStringNotContainsString('tabindex', $html);
    }

    /**
     * A quote in the name cannot break out of the attribute.
     *
     * The name is usually a developer's literal, but it is not always: array-driven forms
     * build field names from data.
     */
    public function testAQuoteInTheNameIsEscaped(): void
    {
        // Arrange
        $widget = new Time('a"onfocus=alert(1)', 0);

        // Act
        $html = $widget->render();

        // Assert
        $this->assertStringNotContainsString('"onfocus=alert', $html);
        $this->assertStringContainsString('&quot;onfocus', $html);
    }
}
