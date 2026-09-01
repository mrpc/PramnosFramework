<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** A concrete command exposing the form-field builder. */
class WizardFormFieldsDummyCommand extends MakeCommandBase
{
    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return 0;
    }

    public function callBuildWizardFormFields(
        array $columns,
        array $fkByColumn,
        string $primaryKey,
        string $themeKey,
        bool $useSelect2,
        string $className = ''
    ): string {
        return $this->buildWizardFormFields(
            $columns,
            $fkByColumn,
            $primaryKey,
            $themeKey,
            $useSelect2,
            $className
        );
    }
}

/**
 * The edit form `create:crud` writes into a view — 16 statements, never executed.
 *
 * A pure function: column definitions in, HTML out, no database and no filesystem. Which is why
 * having it uncovered is the least defensible kind of gap, and why the tests can be specific about
 * what the markup has to be rather than that it is non-empty.
 *
 * Three decisions here are the interesting ones, and all three are about a generated file nobody
 * reads until it misbehaves:
 *
 * - **the primary key gets no field.** A form that posts it hands the visitor the row's identity as
 *   an editable value, and a save that trusts what it receives writes over another row.
 * - **`nullable` decides `required`.** Wrong in the permissive direction gives a database error on
 *   submit — a page that looks fine, fails on save and blames the column; wrong in the strict
 *   direction makes an optional field impossible to leave blank, which is a form nobody can
 *   complete.
 * - **a foreign key with Select2 loads its options over AJAX.** Eagerly rendering every related row
 *   is fine on the developer's ten-row table and produces a megabyte of `<option>` on the
 *   customer's, so only the currently-selected one is pre-rendered — which is the part that has to
 *   be there, or an existing value disappears the moment the form opens.
 *
 * The theme keys are `bootstrap`, `tailwind` and `plain` — `plain`, not `plain-css`: the caller's
 * `match` maps the `plain-css` scaffold theme onto it, and the comment in the builder saying
 * `// plain-css` describes the theme rather than the key.
 */
#[CoversClass(MakeCommandBase::class)]
class WizardFormFieldsTest extends TestCase
{
    private WizardFormFieldsDummyCommand $command;

    protected function setUp(): void
    {
        $this->command = new WizardFormFieldsDummyCommand();
    }

    /** @return array<int, array<string, mixed>> */
    private function columns(): array
    {
        return [
            ['name' => 'deviceid', 'type' => 'integer', 'nullable' => false],
            ['name' => 'name', 'type' => 'string', 'nullable' => false],
            ['name' => 'nickname', 'type' => 'string', 'nullable' => true],
            ['name' => 'serial_number', 'type' => 'string', 'nullable' => true],
            ['name' => 'notes', 'type' => 'text', 'nullable' => true],
            ['name' => 'is_active', 'type' => 'boolean', 'nullable' => true],
            ['name' => 'installed_on', 'type' => 'date', 'nullable' => true],
            ['name' => 'last_seen', 'type' => 'datetime', 'nullable' => true],
            ['name' => 'reading', 'type' => 'decimal', 'nullable' => true],
            [
                'name' => 'label',
                'type' => 'string',
                'nullable' => true,
                'comment' => 'What the operator calls it',
            ],
        ];
    }

    private function build(
        string $theme = 'plain',
        bool $select2 = false,
        array $fks = [],
        string $className = ''
    ): string {
        return $this->command->callBuildWizardFormFields(
            $this->columns(),
            $fks,
            'deviceid',
            $theme,
            $select2,
            $className
        );
    }

    // ── What is and is not a field ────────────────────────────────────────────

    /**
     * The primary key is not on the form.
     *
     * Nobody edits an identity, and a form that posts one invites a save to target a different
     * row than the one being edited. It is skipped by name, so the check is that no input carries
     * it — not merely that the label is absent.
     */
    public function testThePrimaryKeyGetsNoField(): void
    {
        // Act
        $html = $this->build();

        // Assert
        $this->assertStringNotContainsString('name="deviceid"', $html);
        $this->assertStringNotContainsString('id="deviceid"', $html);
        $this->assertStringContainsString('name="name"', $html, 'the other columns are still there');
    }

    /**
     * A NOT NULL column is required and a nullable one is not.
     *
     * Both directions are asserted because both are wrong in a way that looks like something else:
     * a missing `required` gives a database error on submit that reads as a bug in the save, and a
     * spurious one gives a form that cannot be completed.
     */
    public function testNullableDecidesWhetherAFieldIsRequired(): void
    {
        // Act
        $html = $this->build();
        $lines = explode("\n", $html);

        $lineFor = static function (string $field) use ($lines): string {
            foreach ($lines as $line) {
                if (str_contains($line, 'name="' . $field . '"')) {
                    return $line;
                }
            }

            return '';
        };

        // Assert
        $this->assertStringContainsString(' required', $lineFor('name'));
        $this->assertStringNotContainsString(' required', $lineFor('nickname'));
    }

    /**
     * A column comment becomes the label; without one the column name is made readable.
     *
     * The comment is the only place a schema says what a column *means*, so using it is what makes
     * a generated form legible instead of a list of identifiers — and `serial_number` becoming
     * "Serial Number" is what the fallback is for.
     */
    public function testTheLabelComesFromTheCommentOrTheColumnName(): void
    {
        // Act
        $html = $this->build();

        // Assert
        $this->assertStringContainsString('>What the operator calls it</label>', $html);
        $this->assertStringContainsString('>Serial Number</label>', $html);
        $this->assertStringNotContainsString('>serial_number</label>', $html);
    }

    // ── The input a type gets ─────────────────────────────────────────────────

    /**
     * Each column type gets the control it should.
     *
     * `type="number"` on a decimal and `datetime-local` on a datetime are the difference between a
     * form a phone can fill in with the right keyboard and one it cannot. A `text` column getting a
     * single-line input is the one people notice, because it truncates what they were writing.
     */
    public function testEachTypeGetsTheRightControl(): void
    {
        // Act
        $html = $this->build();

        // Assert
        $this->assertMatchesRegularExpression('/<textarea[^>]*name="notes"/', $html);
        $this->assertMatchesRegularExpression('/<input type="checkbox"[^>]*name="is_active"/', $html);
        $this->assertMatchesRegularExpression('/<input type="date"[^>]*name="installed_on"/', $html);
        $this->assertMatchesRegularExpression(
            '/<input type="datetime-local"[^>]*name="last_seen"/',
            $html,
            'a datetime got a date-only input'
        );
        $this->assertMatchesRegularExpression('/<input type="number"[^>]*name="reading"/', $html);
        $this->assertMatchesRegularExpression('/<input type="text"[^>]*name="name"/', $html);
    }

    /**
     * An unknown type falls back to a text input rather than to nothing.
     *
     * The wizard's vocabulary grows, and a column type this builder has not heard of — an enum, a
     * `jsonb`, something a project added — must still get a field. A column silently missing from
     * the form is the failure that is found by a save that loses data.
     */
    public function testAnUnknownTypeStillGetsAField(): void
    {
        // Act
        $html = $this->command->callBuildWizardFormFields(
            [['name' => 'metadata', 'type' => 'jsonb', 'nullable' => true]],
            [],
            'id',
            'plain',
            false
        );

        // Assert
        $this->assertMatchesRegularExpression('/<input type="text"[^>]*name="metadata"/', $html);
    }

    // ── The three themes ──────────────────────────────────────────────────────

    /**
     * Bootstrap gets Bootstrap's classes, and its checkbox gets Bootstrap's shape.
     *
     * The checkbox is the one that differs structurally rather than by class name — Bootstrap wants
     * the input and its label inside a `form-check` wrapper, and without it the control renders
     * unstyled next to fields that are styled, which reads as a broken form.
     */
    public function testTheBootstrapThemeUsesBootstrapMarkup(): void
    {
        // Act
        $html = $this->build('bootstrap');

        // Assert
        $this->assertStringContainsString('class="form-label"', $html);
        $this->assertStringContainsString('class="form-control"', $html);
        $this->assertStringContainsString('class="mb-3"', $html);
        $this->assertStringContainsString('class="form-check"', $html, 'the checkbox is unstyled');
        $this->assertStringContainsString('class="form-check-input"', $html);
        $this->assertStringNotContainsString('style="', $html, 'inline styles leaked into a themed form');
    }

    /** Tailwind gets utility classes, and no inline styles either. */
    public function testTheTailwindThemeUsesUtilityClasses(): void
    {
        // Act
        $html = $this->build('tailwind');

        // Assert
        $this->assertStringContainsString('class="mb-4"', $html);
        $this->assertStringContainsString('block text-sm font-medium', $html);
        $this->assertStringContainsString('flex items-center gap-2', $html, 'the checkbox row');
        $this->assertStringNotContainsString('style="', $html);
    }

    /**
     * The plain theme carries its styling inline, because there is no stylesheet to put it in.
     *
     * A scaffold with no CSS framework still has to produce a form somebody can use, and the
     * alternative — class names pointing at a stylesheet that does not exist — is an unstyled page
     * that looks like the generator half-finished.
     */
    public function testThePlainThemeStylesInline(): void
    {
        // Act
        $html = $this->build('plain');

        // Assert
        $this->assertStringContainsString('style="margin-bottom:12px"', $html);
        $this->assertStringContainsString('font-weight:600', $html);
        $this->assertStringNotContainsString('class="form-control"', $html);
    }

    // ── Foreign keys ──────────────────────────────────────────────────────────

    /**
     * Without Select2 a foreign key is a select the controller fills.
     *
     * The eager path, for the small tables it suits: the options come from a list variable the
     * generated controller provides, and the currently-stored value is marked selected so opening
     * an edit form shows what is there rather than the placeholder.
     */
    public function testAForeignKeyBecomesAnEagerlyFilledSelect(): void
    {
        // Act
        $html = $this->build('plain', false, ['reading' => ['on' => 'measurements']]);

        // Assert
        $this->assertMatchesRegularExpression('/<select[^>]*name="reading"/', $html);
        $this->assertStringContainsString('$this->measurementList', $html, 'the list variable is not named');
        $this->assertStringContainsString('-- Select Reading --', $html);
        $this->assertStringContainsString('selected', $html, 'the stored value would not show');
        $this->assertStringNotContainsString('select2(', $html);
    }

    /**
     * A foreign key onto `users` reads `userList`, with or without the prefix.
     *
     * Every generated screen that references a person hits this, and the table is spelled both ways
     * depending on whether the wizard came from a migration or from a live schema. Naming it
     * `usersList` in one of the two cases gives a select that is always empty, on the one field
     * most likely to be on the form.
     */
    public function testAForeignKeyOntoUsersReadsUserList(): void
    {
        // Act & Assert
        foreach (['users', '#PREFIX#users'] as $table) {
            $html = $this->build('plain', false, ['reading' => ['on' => $table]]);

            $this->assertStringContainsString('$this->userList', $html, $table);
        }
    }

    /**
     * With Select2 the options come over AJAX, and only the selected one is pre-rendered.
     *
     * The reason the eager path is not always right: rendering every related row is fine on the
     * developer's ten-row table and produces a megabyte of `<option>` on the customer's. What must
     * survive the change is the current value — pre-rendered inside a guard, so a new record shows
     * the placeholder and an existing one shows what it has rather than going blank the moment the
     * form opens.
     */
    public function testWithSelect2TheOptionsArriveOverAjax(): void
    {
        // Act
        $html = $this->build('plain', true, ['reading' => ['on' => 'measurements']], 'Devices');

        // Assert
        $this->assertStringContainsString('select2(', $html);
        $this->assertStringContainsString('Devices/fkOptions?field=reading', $html);
        $this->assertStringContainsString(
            'if (!empty($this->model->reading))',
            $html,
            'the stored value is not pre-rendered, so an edit form opens blank'
        );
        $this->assertStringContainsString('readingSelectedText', $html);
        $this->assertStringNotContainsString(
            '$this->measurementList',
            $html,
            'it eagerly renders as well, which is the cost the remote path exists to avoid'
        );
    }

    /**
     * On a class-based theme the `select2` class joins the existing ones instead of replacing them.
     *
     * A `class="select2"` that overwrote `form-select` would give a control Select2 initialises and
     * Bootstrap does not style — which looks like Select2 is broken rather than that the class
     * attribute was clobbered.
     */
    public function testTheSelect2ClassJoinsTheThemesOwn(): void
    {
        // Act
        $html = $this->build('bootstrap', true, ['reading' => ['on' => 'measurements']], 'Devices');

        // Assert
        $this->assertStringContainsString('form-select select2', $html);
    }

    /** No columns is an empty string, not a stray wrapper. */
    public function testNoColumnsProducesNothing(): void
    {
        // Act & Assert
        $this->assertSame(
            '',
            $this->command->callBuildWizardFormFields([], [], 'id', 'plain', false)
        );
    }
}
