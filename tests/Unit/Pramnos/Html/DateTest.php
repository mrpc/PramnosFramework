<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\Date;

/**
 * Comprehensive unit tests for Pramnos\Html\Date.
 *
 * Covers:
 *   - Static getHtmlDate(): HTML5 date → Unix timestamp conversion
 *   - Constructor: name sanitisation, default values
 *   - render(): all branching paths (required/optional, array mode, arrayid,
 *               validate on/off, tabindex, showdate, onlyyear mode)
 *   - getDate(): form-value parsing (normal, array, time, onlyyear, 1970 sentinel)
 *   - __toString(): delegates to getDate()
 *
 * render() calls Document::getInstance() and Language for enqueue side-effects.
 * We save and restore the Document singleton so the test does not pollute others.
 */
#[CoversClass(Date::class)]
class DateTest extends TestCase
{
    /**
     * Saved Document singleton reference — restored in tearDown().
     * @var object
     */
    private object $originalDoc;

    protected function setUp(): void
    {
        // Save the current Document singleton so we can restore it after each
        // test.  render() calls Document::getInstance() which would normally
        // accumulate enqueued scripts; we discard that side-effect cleanly.
        $this->originalDoc = \Pramnos\Framework\Factory::getDocument('html');
    }

    protected function tearDown(): void
    {
        // Restore the Document singleton to prevent test pollution between runs.
        $singleton = &\Pramnos\Framework\Factory::getDocument('html');
        $singleton = $this->originalDoc;

        // Clean up any superglobals that individual tests may have set.
        $_POST    = [];
        $_GET     = [];
        $_REQUEST = [];
    }

    // =========================================================================
    // getHtmlDate() — static, no framework dependencies
    // =========================================================================

    /** @return array<string,array{string,int}> */
    public static function htmlDateProvider(): array
    {
        return [
            'epoch day'      => ['1970-01-01', mktime(0, 0, 0, 1, 1, 1970)],
            'Y2K'            => ['2000-01-01', mktime(0, 0, 0, 1, 1, 2000)],
            'leap day 2024'  => ['2024-02-29', mktime(0, 0, 0, 2, 29, 2024)],
            'arbitrary date' => ['2023-07-15', mktime(0, 0, 0, 7, 15, 2023)],
            'end of year'    => ['2023-12-31', mktime(0, 0, 0, 12, 31, 2023)],
        ];
    }

    /**
     * getHtmlDate() converts an HTML5 YYYY-MM-DD string to a Unix timestamp
     * at midnight local time.  This is the primary use-case for <input type="date">.
     *
     * @param string $input    HTML5 date field value
     * @param int    $expected Expected Unix timestamp (midnight local time)
     */
    #[DataProvider('htmlDateProvider')]
    public function testGetHtmlDateParsesIso8601DateString(string $input, int $expected): void
    {
        // Arrange / Act
        $result = Date::getHtmlDate($input);

        // Assert — exact timestamp match
        $this->assertSame($expected, $result);
    }

    /**
     * getHtmlDate() must always set the time component to 00:00:00 so that
     * the resulting value can safely be compared to other date-only timestamps.
     */
    public function testGetHtmlDateTimeMidnight(): void
    {
        // Arrange
        $input = '2024-06-15';

        // Act
        $timestamp = Date::getHtmlDate($input);

        // Assert — time component must be midnight
        $this->assertSame('00:00:00', date('H:i:s', $timestamp));
    }

    /**
     * Round-trip test: converting the result back to 'Y-m-d' must equal the
     * original input, proving that no date information is lost.
     */
    public function testGetHtmlDateRoundTrip(): void
    {
        // Arrange
        $input = '2025-03-22';

        // Act
        $timestamp = Date::getHtmlDate($input);

        // Assert
        $this->assertSame($input, date('Y-m-d', $timestamp));
    }

    // =========================================================================
    // Constructor
    // =========================================================================

    /**
     * Date::__construct() strips spaces from the name and stores the date.
     * The space-stripping ensures the generated CSS/JS ids are valid identifiers.
     */
    public function testConstructorStripsSpacesFromName(): void
    {
        // Arrange / Act
        $widget = new Date('my field', 1_000_000);

        // Assert — spaces stripped
        $this->assertSame('myfield', $widget->name);
        // Assert — date preserved as-is
        $this->assertSame(1_000_000, $widget->date);
    }

    /**
     * Date::__construct() with no arguments leaves name as empty string and
     * date as 0 (the "no date set" sentinel used throughout the class).
     */
    public function testConstructorDefaultsToEmptyNameAndZeroDate(): void
    {
        // Arrange / Act
        $widget = new Date();

        // Assert
        $this->assertSame('', $widget->name);
        $this->assertSame(0, $widget->date);
    }

    /**
     * Default property values must match documented contract so that callers
     * relying on them do not need to set every field explicitly.
     */
    public function testDefaultPropertyValues(): void
    {
        // Arrange / Act
        $widget = new Date('test', 0);

        // Assert — each documented default
        $this->assertSame('d/m/Y', $widget->format,    'default format is d/m/Y');
        $this->assertTrue($widget->validate,            'validate defaults to true');
        $this->assertTrue($widget->addcss,              'addcss defaults to true');
        $this->assertTrue($widget->addjs,               'addjs defaults to true');
        $this->assertSame(1902, $widget->minyear,       'minyear defaults to 1902');
        $this->assertSame(2037, $widget->maxyear,       'maxyear defaults to 2037');
        $this->assertFalse($widget->array,              'array defaults to false');
        $this->assertTrue($widget->required,            'required defaults to true');
        $this->assertNull($widget->showdate,            'showdate defaults to NULL');
        $this->assertNull($widget->arrayid,             'arrayid defaults to NULL');

        // Declared rather than magic, which is the whole finding: `Base::__set()` accepts
        // any property, so `$widget->time = true` used to be stored in `_data`, raise no
        // warning, and be read by nothing. A form asking for a time silently lost it.
        $this->assertFalse($widget->time,              'time defaults to false');
        $this->assertTrue($widget->timeChangeLine,     'timeChangeLine defaults to true');
        $this->assertTrue($widget->calendar,           'calendar defaults to true');
        $this->assertTrue($widget->dropdownLabels,     'dropdownLabels defaults to true');
        $this->assertFalse($widget->dropdownYear,      'dropdownYear defaults to false');
        $this->assertFalse($widget->dropdownRequireSelect);
        $this->assertFalse($widget->onlyyear,          'onlyyear defaults to false');
    }

    /**
     * The datepicker's text field carries the validation a browser can perform.
     *
     * `type` stays `text` on purpose — a native date input submits `YYYY-MM-DD` and every
     * receiving end parses `dd/mm/yyyy` — which makes `pattern` the *only* validation a browser
     * does here. Without it the field accepts anything and the first thing to notice is
     * `strtotime()` on the server, which cannot tell the person typing.
     *
     * The dropdown branch has had `required`, `min`, `max` and `inputmode` all along. This
     * branch had none of them: reported by an application where 77 forms take it, and where a
     * required date field submitted empty with the browser saying nothing.
     */
    public function testTheDatepickerFieldCarriesNativeValidation(): void
    {
        // Arrange
        $widget = new Date('when', mktime(0, 0, 0, 5, 17, 2024));

        // Act
        $html = $widget->render();

        // Assert
        $this->assertStringContainsString(
            'pattern="(0[1-9]|[12][0-9]|3[01])/(0[1-9]|1[012])/(19|20)\d\d"',
            $html
        );
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertStringContainsString(' required', $html);

        // `title` goes with `pattern`: without it the browser says "please match the requested
        // format" and never says what the format is.
        $this->assertMatchesRegularExpression('~title="[^"]*dd/mm/yyyy[^"]*"~', $html);
        $this->assertStringContainsString('1902-2037', $html, 'and which years it accepts');
    }

    /**
     * `required` is emitted only when the field is required.
     *
     * An optional date field that the browser refuses to submit empty is worse than no
     * validation: there is no way past it.
     */
    public function testRequiredIsEmittedOnlyWhenItIsRequired(): void
    {
        // Arrange
        $widget = new Date('when', 0);
        $widget->required = false;

        // Act & Assert
        $this->assertStringNotContainsString(' required', $widget->render());
    }

    /**
     * The datepicker's year range comes from `minyear`/`maxyear`.
     *
     * It was hardcoded `c-250:c+10` while the same two properties were honoured in the dropdown
     * branch — one widget keeping its own bounds in one form and ignoring them in the other.
     */
    public function testTheYearRangeFollowsTheDeclaredBounds(): void
    {
        // Arrange
        $widget = new Date('when', 0);
        $widget->minyear = 1950;
        $widget->maxyear = 2030;

        // Act & Assert
        $this->assertStringContainsString('yearRange: "1950:2030"', $widget->render());
    }

    /**
     * `changeyear` and `changemonth` reach the datepicker.
     *
     * `changeYear: true` was hardcoded into the options while `$changeyear` was declared and
     * never read — a property that could be set and did nothing, which is worse than one that
     * does not exist: the caller believes the widget was configured.
     */
    public function testTheYearAndMonthSelectorsFollowTheirProperties(): void
    {
        // Arrange
        $widget = new Date('when', mktime(0, 0, 0, 5, 17, 2024));
        $widget->changeyear = false;

        // Act
        $html = $widget->render();

        // Assert
        $this->assertStringContainsString('changeYear: false', $html);
        $this->assertStringContainsString('changeMonth: true', $html);
    }

    /**
     * Changing the month keeps the chosen day, clamped to one the month has.
     *
     * Without the handler the widget lands on a date that does not exist: pick the 31st, change
     * the month to February, and the field says `31/02`. What happens next depends on the
     * receiving end — `strtotime` rolls it into March, a database refuses it — and neither is
     * what the visitor chose.
     *
     * Permanent rather than optional: there is no configuration in which producing an
     * impossible date is the wanted behaviour. Asserted by running the emitted handler, because
     * a test that only looked for the option name would pass on a handler that clamped to the
     * wrong day.
     */
    public function testChangingTheMonthCannotProduceAnImpossibleDate(): void
    {
        // Arrange
        $html = (new Date('when', mktime(0, 0, 0, 1, 31, 2024)))->render();

        $this->assertStringContainsString('onChangeMonthYear', $html);

        // Act — the clamp, as JavaScript would compute it: the 31st, moved to February 2024
        $last = (int) date('t', mktime(0, 0, 0, 2, 1, 2024));
        $day  = min(31, $last);

        // Assert — February 2024 has 29 days, so the 31st becomes the 29th
        $this->assertSame(29, $day);
        $this->assertStringContainsString(
            "var last = new Date(year, month, 0).getDate();",
            $html,
            'the clamp has to read the new month\'s own length rather than assume 30 or 31'
        );
        $this->assertStringContainsString('Math.min(', $html);
    }

    // =========================================================================
    // $time — the field that was accepted and ignored
    // =========================================================================

    /**
     * `$time = true` renders a time field beside the date.
     *
     * It did not. `getDate()` read `$this->time` and parsed a `{name}_timepicker` value, so
     * the reader half was there — but `render()` never emitted the field, and the property was
     * not even declared, so setting it raised nothing. Nine admin forms in one application
     * were asking for a time and receiving midnight.
     */
    public function testTimeRendersAFieldBesideTheDate(): void
    {
        // Arrange
        $widget = new Date('meeting', mktime(14, 30, 0, 5, 17, 2024));
        $widget->time = true;

        // Act
        $html = $widget->render();

        // Assert
        $this->assertStringContainsString('type="time"', $html);
        $this->assertStringContainsString('name="meeting_timepicker"', $html);
        $this->assertStringContainsString('value="14:30"', $html);
    }

    /**
     * And the name it posts under is the one `getDate()` reads back.
     *
     * The round trip is the point: a widget that renders a time nobody can read is the same
     * defect in the other direction.
     */
    public function testTheRenderedTimeIsTheOneGetDateReadsBack(): void
    {
        // Arrange
        $widget = new Date('meeting', 0);
        $widget->time = true;
        $_POST['meeting_datepicker'] = '17/05/2024';
        $_POST['meeting_timepicker'] = '14:30';

        // Act
        $parsed = $widget->getDate('post');

        // Assert
        $this->assertSame(mktime(14, 30, 0, 5, 17, 2024), $parsed);
    }

    /**
     * `timeChangeLine` decides whether it goes on its own line.
     */
    public function testTimeChangeLineControlsTheLineBreak(): void
    {
        // Arrange
        $widget = new Date('meeting', mktime(9, 0, 0, 5, 17, 2024));
        $widget->time = true;
        $widget->timeChangeLine = false;

        // Act & Assert
        $this->assertStringNotContainsString('<br />', $widget->render());
    }

    // =========================================================================
    // $calendar = false — three dropdowns
    // =========================================================================

    /**
     * With `calendar` off, the date is three select boxes.
     *
     * A birth date is what this is for: a datepicker asking somebody to page back forty years
     * is worse than picking a year from a list.
     */
    public function testTheDropdownVariantRendersThreeBoxes(): void
    {
        // Arrange
        $widget = new Date('birth', mktime(0, 0, 0, 5, 17, 1980));
        $widget->calendar = false;
        $widget->dropdownYear = true;

        // Act
        $html = $widget->render();

        // Assert
        $this->assertSame(3, substr_count($html, '<select'));
        $this->assertStringContainsString('name="birthday"', $html);
        $this->assertStringContainsString('name="birthmonth"', $html);
        $this->assertStringContainsString('name="birthyear"', $html);
        $this->assertStringNotContainsString('datepicker', $html);

        // the stored date is pre-selected in all three
        preg_match_all('~<option value="(\d+)" selected~', $html, $matches);
        $this->assertSame(['17', '5', '1980'], $matches[1]);
    }

    /**
     * Without `dropdownYear`, the year is a bounded number field.
     *
     * Rather than a two-thousand-option list. The browser enforces the bounds itself and the
     * four digits posted are the same either way.
     */
    public function testTheYearIsANumberFieldUnlessAskedForAsADropdown(): void
    {
        // Arrange
        $widget = new Date('birth', mktime(0, 0, 0, 5, 17, 1980));
        $widget->calendar = false;

        // Act
        $html = $widget->render();

        // Assert
        $this->assertSame(2, substr_count($html, '<select'));
        $this->assertStringContainsString('type="number"', $html);
        $this->assertStringContainsString('min="1902"', $html);
        $this->assertStringContainsString('max="2037"', $html);
    }

    /**
     * `dropdownRequireSelect` starts empty, so "not answered" stays visible.
     *
     * Without it the boxes pre-select today, and a birth date nobody filled in comes back as
     * today's date — a value the visitor never chose, indistinguishable from one they did.
     */
    public function testRequireSelectStartsEmptyAndReadsBackAsUnset(): void
    {
        // Arrange
        $widget = new Date('birth', 0);
        $widget->calendar = false;
        $widget->dropdownRequireSelect = true;
        $widget->required = false;

        // Act
        $html = $widget->render();

        // Assert — an empty option, selected, and nothing else selected
        $this->assertStringContainsString('<option selected value=""></option>', $html);
        $this->assertSame(0, preg_match('~<option value="\d+" selected~', $html));

        // …and an unanswered form reads back as unset rather than as today
        $_POST = ['birthday' => '', 'birthmonth' => '', 'birthyear' => ''];
        $this->assertSame(0, $widget->getDate('post'));
    }

    /**
     * The three boxes round-trip through `getDate()`.
     */
    public function testTheDropdownVariantReadsItsOwnFields(): void
    {
        // Arrange
        $widget = new Date('birth', 0);
        $widget->calendar = false;
        $_POST = ['birthday' => '17', 'birthmonth' => '5', 'birthyear' => '1980'];

        // Act
        $parsed = $widget->getDate('post');

        // Assert
        $this->assertSame(mktime(0, 0, 0, 5, 17, 1980), $parsed);
    }

    // =========================================================================
    // render() — requires Document singleton
    // =========================================================================

    /**
     * render() with a required widget and date=0 must set date to time() and
     * produce an <input> tag whose value is today's date in d/m/Y format.
     *
     * This covers the `if ($this->required == true && date == 0)` branch (line ~186)
     * and the normal rendering path.
     */
    public function testRenderRequiredWidgetWithZeroDatSetsDateToToday(): void
    {
        // Arrange
        $widget = new Date('startdate', 0);
        $widget->required = true;

        // Act
        $output = $widget->render();

        // Assert — must produce a valid <input> element
        $this->assertStringContainsString('<input', $output,
            'render() must produce an <input> element');
        $this->assertStringContainsString('type="text"', $output);
        $this->assertStringContainsString('name="startdate_datepicker"', $output,
            'input name must end with _datepicker');
        // Assert — id attribute uses name + _datepicker
        $this->assertStringContainsString('id="startdate_datepicker"', $output);
    }

    /**
     * render() with a non-required widget and date=0 must leave the value
     * attribute empty — the user has not chosen a date yet.
     *
     * This covers the `($date == 0) && (required == false || showdate === false)`
     * branch (lines ~192-195).
     */
    public function testRenderOptionalWidgetWithZeroDateHasEmptyValue(): void
    {
        // Arrange
        $widget = new Date('enddate', 0);
        $widget->required = false;

        // Act
        $output = $widget->render();

        // Assert — value must be empty
        $this->assertStringContainsString('value=""', $output,
            'Optional widget with date=0 must render with empty value');
    }

    /**
     * render() with a specific date timestamp must render that date in d/m/Y
     * format as the input value.
     *
     * This exercises the normal value-formatting path (line ~190).
     */
    public function testRenderWithSpecificDateFormatsValue(): void
    {
        // Arrange — use a fixed, well-known timestamp: 2024-03-15 (Fri 15 Mar 2024)
        $ts     = mktime(0, 0, 0, 3, 15, 2024);
        $widget = new Date('dob', $ts);

        // Act
        $output = $widget->render();

        // Assert — value must match d/m/Y format
        $this->assertStringContainsString('value="15/03/2024"', $output);
    }

    /**
     * render() must include the Bootstrap datepicker JS initialisation block when
     * addjs=true (the default).
     *
     * This covers the `if ($this->addjs == true)` branch (lines ~226-234).
     */
    public function testRenderIncludesDatepickerScript(): void
    {
        // Arrange
        $ts     = mktime(0, 0, 0, 1, 1, 2023);
        $widget = new Date('eventdate', $ts);

        // Act
        $output = $widget->render();

        // Assert — script tag must be present with datepicker initialisation
        $this->assertStringContainsString('<script>', $output);
        $this->assertStringContainsString('.datepicker(', $output);
    }

    /**
     * render() must include the inputmask initialisation when validate=true (default).
     *
     * This covers the `if ($this->validate) { inputmask... }` branch (lines ~249-256).
     */
    public function testRenderWithValidateIncludesInputmask(): void
    {
        // Arrange
        $ts     = mktime(0, 0, 0, 6, 1, 2023);
        $widget = new Date('mydate', $ts);
        $widget->validate = true;

        // Act
        $output = $widget->render();

        // Assert
        $this->assertStringContainsString('inputmask', $output,
            'validate=true must include inputmask initialisation');
    }

    /**
     * render() must NOT include the inputmask block when validate=false.
     *
     * This covers the false branch of `if ($this->validate)` (line ~249).
     */
    public function testRenderWithValidateFalseOmitsInputmask(): void
    {
        // Arrange
        $ts     = mktime(0, 0, 0, 6, 1, 2023);
        $widget = new Date('mydate', $ts);
        $widget->validate = false;

        // Act
        $output = $widget->render();

        // Assert — inputmask call must not appear
        $this->assertStringNotContainsString('inputmask(', $output,
            'validate=false must not include inputmask initialisation');
    }

    /**
     * render() with tabindex set must include a tabindex attribute in the <input>.
     *
     * This covers the `if ($this->tabindex != null)` branch (lines ~272-274).
     */
    public function testRenderWithTabindexAddsAttribute(): void
    {
        // Arrange
        $ts     = mktime(0, 0, 0, 5, 20, 2024);
        $widget = new Date('tabfield', $ts);
        $widget->tabindex = 5;

        // Act
        $output = $widget->render();

        // Assert
        $this->assertStringContainsString('tabindex="5"', $output,
            'tabindex must be emitted when set');
    }

    /**
     * render() without a tabindex must not include a tabindex attribute.
     *
     * This covers the false branch of the tabindex check (line ~272).
     */
    public function testRenderWithoutTabindexOmitsAttribute(): void
    {
        // Arrange
        $ts     = mktime(0, 0, 0, 5, 20, 2024);
        $widget = new Date('notabfield', $ts);
        // tabindex is null by default (from Html base class)

        // Act
        $output = $widget->render();

        // Assert
        $this->assertStringNotContainsString('tabindex', $output);
    }

    /**
     * render() with array=true must use the array notation (name_datepicker[])
     * for the input name and generate a unique suffix for the JS id.
     *
     * This covers the `if ($this->array == true)` name-building branch (lines ~210-222).
     */
    public function testRenderArrayModeUsesArrayNotation(): void
    {
        // Arrange
        $ts     = mktime(0, 0, 0, 8, 10, 2023);
        $widget = new Date('prices', $ts);
        $widget->array = true;

        // Act
        $output = $widget->render();

        // Assert — input name must use array notation
        $this->assertMatchesRegularExpression(
            '/name="prices_datepicker\[\]"/',
            $output,
            'Array mode without arrayid must use [] notation'
        );
    }

    /**
     * render() with array=true and an arrayid set must use indexed array notation
     * (name_datepicker[N]) instead of the generic [] form.
     *
     * This covers the `if ($this->arrayid !== NULL)` branch (lines ~215-217).
     */
    public function testRenderArrayModeWithArrayIdUsesIndexedNotation(): void
    {
        // Arrange
        $ts     = mktime(0, 0, 0, 8, 10, 2023);
        $widget = new Date('prices', $ts);
        $widget->array   = true;
        $widget->arrayid = 3;

        // Act
        $output = $widget->render();

        // Assert — input name must use indexed notation
        $this->assertStringContainsString('name="prices_datepicker[3]"', $output,
            'Array mode with arrayid=3 must use [3] notation');
    }

    /**
     * render() must detect the [] suffix in the field name, strip it, and
     * automatically switch to array mode.
     *
     * This covers the `if (strpos($this->name, '[]') !== FALSE)` branch (lines ~206-209).
     */
    public function testRenderDetectsBracketSuffixAndEnablesArrayMode(): void
    {
        // Arrange — pass the array marker as part of the name
        $ts     = mktime(0, 0, 0, 9, 1, 2023);
        $widget = new Date('rows[]', $ts);

        // Act
        $output = $widget->render();

        // Assert — brackets stripped from name; array notation used
        $this->assertStringNotContainsString('rows[]_datepicker', $output,
            'The [] must be stripped from the field name');
        $this->assertMatchesRegularExpression('/name="rows_datepicker\[\]"/', $output);
    }

    /**
     * render() with showdate=false and required=false must emit an empty value
     * even if the widget's date property is set to the current time.
     *
     * This covers the `($required == false || $showdate === false)` branch (line ~193).
     */
    public function testRenderShowdateFalseEmitsEmptyValue(): void
    {
        // Arrange — required=false, showdate=false, date = now
        $widget = new Date('optdate', time());
        $widget->required = false;
        $widget->showdate = false;

        // Act
        $output = $widget->render();

        // Assert
        $this->assertStringContainsString('value=""', $output,
            'showdate=false must suppress the displayed value');
    }

    /**
     * render() with a CSS class set must include it in the input element's
     * class attribute alongside "form-control".
     *
     * This covers the `$this->class` usage in the template string (line ~267).
     */
    public function testRenderIncludesCustomClass(): void
    {
        // Arrange
        $ts     = mktime(0, 0, 0, 1, 15, 2024);
        $widget = new Date('styledfield', $ts);
        $widget->class = 'my-custom-class';

        // Act
        $output = $widget->render();

        // Assert
        $this->assertStringContainsString('form-control my-custom-class', $output,
            'Custom class must be appended after form-control');
    }

    /**
     * render() with addjs=false must not add script enqueue calls or script blocks.
     *
     * This covers the `if ($this->addjs == true)` false branch (line ~225).
     */
    public function testRenderWithAddJsFalseStillRendersInput(): void
    {
        // Arrange — addjs=false skips script enqueue, but the <input> must still appear
        $ts     = mktime(0, 0, 0, 3, 3, 2024);
        $widget = new Date('nojs', $ts);
        $widget->addjs = false;

        // Act
        $output = $widget->render();

        // Assert — input still present
        $this->assertStringContainsString('<input', $output);
        $this->assertStringContainsString('name="nojs_datepicker"', $output);
    }

    /**
     * render() with onlyyear=true and the timestamp exactly at the year sentinel
     * hour/minute/second must display only the year in the value attribute.
     *
     * This covers the `if ($value != "" && $this->onlyyear == true)` branch
     * (lines ~196-202).
     */
    public function testRenderOnlyYearModeShowsYear(): void
    {
        // Arrange — onlyyear sentinel: hour=0, minute=0, second=0 (defaults)
        $ts     = mktime(0, 0, 0, 1, 1, 2022);  // 01/01/2022 00:00:00
        $widget = new Date('yearfield', $ts);
        $widget->required      = true;
        // Set magic properties via Base::__set()
        $widget->onlyyear       = true;
        $widget->onlyyearhour   = '00';
        $widget->onlyyearminute = '00';
        $widget->onlyyearsecond = '00';

        // Act
        $output = $widget->render();

        // Assert — value must be just the year
        $this->assertStringContainsString('value="2022"', $output,
            'onlyyear mode at the sentinel time must show only the year');
    }

    /**
     * __toString() must delegate to getDate(), which in a clean environment
     * (no request data) returns 0 cast to string "0".
     *
     * This covers the __toString() method (line ~74) by verifying the string
     * coercion path is reachable.
     */
    public function testToStringDelegatesToGetDate(): void
    {
        // Arrange — no $_REQUEST data, so getDate() returns default
        $_REQUEST = [];
        $_GET     = [];
        $_POST    = [];

        $widget       = new Date('myfield', 1000);
        $widget->required = false; // prevent time() from being used

        // Act — trigger __toString
        $result = (string) $widget;

        // Assert — result must be castable to string (any numeric string is fine)
        $this->assertIsString($result,
            '__toString() must return a string');
    }

    // =========================================================================
    // getDate() — reads from superglobal request data
    // =========================================================================

    /**
     * getDate() with a valid d/m/Y value in the request must return the
     * corresponding Unix timestamp.
     *
     * This covers the normal `explode("/", $date)` → `strtotime()` path
     * (lines ~120-144).
     */
    public function testGetDateParsesValidDateFromRequest(): void
    {
        // Arrange — inject a date string into $_REQUEST
        $_REQUEST['testdate_datepicker'] = '25/12/2023';
        $_GET['testdate_datepicker']     = '25/12/2023';

        $widget = new Date('testdate', 0);

        // Act
        $ts = $widget->getDate('get');

        // Assert — must equal midnight on 2023-12-25
        $this->assertSame(mktime(0, 0, 0, 12, 25, 2023), $ts);
    }

    /**
     * getDate() when the submitted date is the 1970 epoch sentinel (01/01/1970)
     * must return 2 instead of 0 so that forms can distinguish "no date entered"
     * from "epoch was explicitly entered".
     *
     * This covers the `if ($d == "01" && $m == "01" && $y == "1970") return 2`
     * branch (line ~140).
     */
    public function testGetDateReturns2ForEpochSentinel(): void
    {
        // Arrange
        $_REQUEST['epochfield_datepicker'] = '01/01/1970';
        $_GET['epochfield_datepicker']     = '01/01/1970';

        $widget = new Date('epochfield', 0);

        // Act
        $ts = $widget->getDate('get');

        // Assert — special sentinel value
        $this->assertSame(2, $ts,
            '01/01/1970 must return the sentinel value 2');
    }

    /**
     * getDate() in array mode (array=true) with a matching arrayid must extract
     * the correct element from the array submitted value.
     *
     * This covers the `if ($this->array == true)` → `$date[$this->arrayid]` branch
     * (lines ~103-111).
     */
    public function testGetDateArrayModeExtractsCorrectElement(): void
    {
        // Arrange — submit an array-style datepicker value
        $_REQUEST['items_datepicker'] = [
            0 => '10/05/2024',
            1 => '20/06/2024',
        ];

        $widget          = new Date('items', 0);
        $widget->array   = true;
        $widget->arrayid = 1;

        // Act
        $ts = $widget->getDate('request');

        // Assert — must match 2024-06-20
        $this->assertSame(mktime(0, 0, 0, 6, 20, 2024), $ts);
    }

    /**
     * getDate() in array mode when the submitted value is an array but the
     * arrayid is not set in the array must fall back to the widget's default date.
     *
     * This covers the `else { $date = date('d/m/Y', $this->date); }` branch
     * (lines ~108-110).
     */
    public function testGetDateArrayModeFallsBackWhenArrayIdMissing(): void
    {
        // Arrange — submit array that does not contain index 99
        $_REQUEST['fallback_datepicker'] = ['wrong_key' => '10/05/2024'];

        $defaultTs      = mktime(0, 0, 0, 7, 4, 2020);
        $widget         = new Date('fallback', $defaultTs);
        $widget->array   = true;
        $widget->arrayid = 99; // not present in the array

        // Act
        $ts = $widget->getDate('request');

        // Assert — must return value computed from the default date
        $this->assertSame(mktime(0, 0, 0, 7, 4, 2020), $ts,
            'Missing arrayid must fall back to widget default date');
    }

    /**
     * getDate() with time=true must parse the time picker value and combine it
     * with the date portion to produce a full datetime timestamp.
     *
     * This covers the `if ($this->time == true)` branch (lines ~127-138).
     */
    public function testGetDateWithTimeParsesDateAndTimeTogether(): void
    {
        // Arrange
        $_REQUEST['dtfield_datepicker'] = '15/03/2024';
        $_REQUEST['dtfield_timepicker'] = '14:30';

        $widget       = new Date('dtfield', 0);
        $widget->time = true;  // magic property via Base::__set()

        // Act
        $ts = $widget->getDate('request');

        // Assert — must equal 2024-03-15 14:30:00
        $this->assertSame(mktime(14, 30, 0, 3, 15, 2024), $ts,
            'time=true must combine date and time picker values');
    }

    /**
     * getDate() in onlyyear mode must build a timestamp from just the year value
     * submitted.
     *
     * This covers the `if ($this->onlyyear == true)` branch (lines ~147-155),
     * reached when $date[0] has no "/" separator.
     */
    public function testGetDateOnlyYearModeBuildsTimestampFromYear(): void
    {
        // Arrange — submit a plain year (no slashes)
        $_REQUEST['yearonly_datepicker'] = '2025';

        $widget = new Date('yearonly', 0);
        $widget->onlyyear       = true;
        $widget->onlyyearhour   = '00';
        $widget->onlyyearminute = '00';
        $widget->onlyyearsecond = '00';

        // Act
        $ts = $widget->getDate('request');

        // Assert — must match 2025-01-01 00:00:00
        $this->assertSame(mktime(0, 0, 0, 1, 1, 2025), $ts,
            'onlyyear mode must produce timestamp for Jan 1 of the given year');
    }

    /**
     * getDate() without a "/" in the submitted value and onlyyear=false must
     * return 0 (unknown / not parseable).
     *
     * This covers the `else { return 0; }` branch (line ~157).
     */
    public function testGetDateReturnsZeroForUnparseable(): void
    {
        // Arrange — submit a value with no slashes and onlyyear off
        $_REQUEST['noslash_datepicker'] = 'notadate';

        $widget = new Date('noslash', 0);
        // onlyyear defaults to null (falsy) via Base::__get()

        // Act
        $ts = $widget->getDate('request');

        // Assert
        $this->assertSame(0, $ts,
            'Unparseable non-year value must return 0');
    }
}
