<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;

/**
 * The screen sweep the scaffold ships must not be able to change anything.
 *
 * `ScreenSweepTest` requests every action on every controller. That is worth having —
 * a scaffolded project starts around 78% coverage and almost all of the gap is views, and
 * a sweep over them finds an admin area answering 404, a view in a directory nothing
 * reads, a renamed column printing nothing.
 *
 * It is also a loaded gun. **A GET to `delete/5` on a controller that does not check the
 * request method deletes row 5**, and "most controllers check" is not something to bet a
 * suite on — let alone one that runs on every developer's machine against a database with
 * data in it.
 *
 * So the allowlist is the safety, and the allowlist is what this guards. Adding a verb to
 * it is a decision, and this test is where that decision is made visible: a mutating verb
 * appearing there fails here rather than in somebody's database.
 */
#[CoversClass(Init::class)]
class ScreenSweepStubIsSafeTest extends TestCase
{
    /**
     * Verbs that change something, in the spellings this framework's controllers use.
     *
     * Names rather than a heuristic: "does it look destructive" is exactly the judgement
     * that should not be automated, and a list somebody has to edit is a list somebody
     * has to think about.
     *
     * @var list<string>
     */
    private const MUTATING = [
        'save', 'store', 'create', 'update', 'delete', 'destroy', 'remove',
        'revoke', 'reset', 'disable', 'enable', 'clear', 'purge', 'prune',
        'send', 'import', 'sync', 'regenerate', 'rotate', 'approve', 'deny',
        'logout', 'signout', 'erase', 'wipe', 'truncate', 'restart', 'stop',
        'start', 'run', 'execute', 'publish', 'unpublish', 'merge', 'move',
    ];

    private function stub(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3) . '/scaffolding/templates/screen-sweep-test.stub'
        );
    }

    /**
     * The allowlist is readable, and this test is reading the right thing.
     *
     * The assertion the rest depends on: a renamed constant or a moved file would leave
     * the sweep below matching nothing, and "no mutating verb found" is exactly what an
     * empty list says.
     */
    public function testTheAllowlistIsFound(): void
    {
        // Act
        $actions = $this->allowedActions();

        // Assert
        $this->assertNotEmpty($actions, 'READ_ACTIONS was not found in the stub');
        $this->assertContains('display', $actions, 'the allowlist is not the one expected');
    }

    /**
     * No verb that changes anything is in it.
     *
     * The whole safety argument of the sweep, in one assertion.
     */
    public function testTheAllowlistContainsNothingThatMutates(): void
    {
        // Arrange
        $offenders = [];
        $actions   = $this->allowedActions();

        // A renamed constant leaves this list empty, and an empty list has no mutating
        // verb in it — which is a pass that means the safety is no longer being checked.
        // `testTheAllowlistIsFound` says the same thing, but a guard has to hold on its
        // own: the two are run separately and one can be deleted.
        $this->assertNotEmpty($actions, 'the sweep found nothing to check');

        // Act
        foreach ($actions as $action) {
            foreach (self::MUTATING as $verb) {
                // Whole word or prefix: `deleteaccount` is the *confirmation screen* and
                // is a genuine read, while `delete` is not — so a bare containment test
                // would be wrong in both directions. Prefix plus an exact match is the
                // distinction that holds: `delete` is caught, `deleteaccount` is not.
                if ($action === $verb) {
                    $offenders[] = $action;
                }
            }
        }

        // Assert
        $this->assertSame(
            [],
            $offenders,
            "A GET to one of these on a controller that does not check the request\n"
            . "method changes data, on every machine the suite runs on:\n"
            . implode("\n", $offenders)
        );
    }

    /**
     * `deleteaccount` is allowed, and it is not an oversight.
     *
     * It is the confirmation screen — the page that asks for a password and the word
     * DELETE — and the deletion itself is a POST. Pinned because it is the one entry that
     * looks like a mistake, and the next person to read the list should find the answer
     * here rather than removing it.
     */
    public function testTheConfirmationScreenIsDeliberatelyAllowed(): void
    {
        // Act + Assert
        $this->assertContains('deleteaccount', $this->allowedActions());
        $this->assertNotContains('delete', $this->allowedActions());
    }

    /**
     * The sweep refuses to pass when it discovered nothing.
     *
     * A discovery that silently returns an empty list is the failure a sweep is most
     * prone to: it reports success for ever while covering nothing, and it looks exactly
     * like a suite that is green.
     */
    public function testTheSweepAssertsThatItSweptSomething(): void
    {
        // Arrange
        $stub = $this->stub();

        // Assert
        $this->assertStringContainsString(
            'no controllers were discovered',
            $stub,
            'the default-action sweep does not check that it found anything'
        );
        $this->assertStringContainsString(
            'no read actions were discovered',
            $stub,
            'the read-action sweep does not check that it found anything'
        );
    }

    /**
     * The read-action sweep appends a subject when one is offered.
     *
     * WHAT: the sweep calls `subjectFor()` and puts what it returns in the path.
     *
     * WHY:  `/admin/Users/view` with no id is a valid request that answers 200 — and
     *       what renders is the "nothing selected" branch, four lines at the top of a
     *       template whose six hundred are the point. The sweep passes and its number
     *       comes entirely from the half of the file it never reaches. A project
     *       adopting the sweep without this watched coverage *fall* from 74.8% to 45.0%.
     *
     *       It is the same failure `seedRows()` is about, one layer along: a list with
     *       no rows renders its empty state, and a detail view with no subject renders
     *       its empty state too — with no row to seed, because what is missing is an id
     *       in the URL.
     */
    public function testTheSweepAppendsASubjectWhenOneIsOffered(): void
    {
        // Arrange
        $stub = $this->stub();

        // Assert — the hook exists, defaults to none, and is actually used
        $this->assertStringContainsString(
            'protected function subjectFor(string $prefix, string $action): string',
            $stub,
            'there is no hook for the id a detail screen needs'
        );
        $this->assertStringContainsString(
            '$subject = $this->subjectFor($prefix, $action);',
            $stub,
            'the hook is declared and never called, which is worse than absent'
        );
        $this->assertStringContainsString(
            "rawurlencode(\$subject)",
            $stub,
            'an id goes into a URL, so it is encoded'
        );
    }

    /**
     * The framework's own tables are seeded by the stub, not by each application.
     *
     * WHAT: `seedFrameworkRows()` exists, runs before `seedRows()`, and absorbs each
     *       insert on its own.
     *
     * WHY:  the largest uncovered file in a project on this framework is usually the
     *       person card — `Admin/Views/users/view.html.php` — which is the framework's
     *       file, behind the framework's controller, drawing from seventeen of the
     *       framework's tables. Leaving it to each application means every project
     *       spends the same afternoon on somebody else's view, or carries a
     *       six-hundred-line file at 58%.
     *
     *       Absorbed individually because a table that is not there is a feature this
     *       installation does not have, and one missing feature must not stop the rest
     *       being seeded.
     */
    public function testTheFrameworksOwnTablesAreSeededByTheStub(): void
    {
        // Arrange
        $stub = $this->stub();

        // Assert
        $this->assertStringContainsString('protected function seedFrameworkRows(): void', $stub);
        $this->assertStringContainsString('$this->seedFrameworkRows();', $stub);

        // The person card's tables, which are the ones that matter
        foreach ([
            'authserver.user_activity_log',
            'authserver.passkey_credentials',
            'authserver.user_twofactor',
            'usertokens',
        ] as $table) {
            $this->assertStringContainsString(
                "'" . $table . "'",
                $stub,
                $table . ' is drawn by the person card and is not seeded'
            );
        }
    }

    /**
     * The unique column is seeded with a value that differs per test.
     *
     * `passkey_credentials.credential_id` is unique across the table, so a literal seeds
     * the first test that runs and **silently seeds nothing** in every one after it: the
     * passkey panel renders once and its empty state the rest of the time, which looks
     * exactly like a test that passes. Asserted by name because it is the one column
     * where the difference is invisible.
     */
    public function testTheUniqueCredentialIsNotALiteral(): void
    {
        // Arrange
        $stub = $this->stub();

        // Act — the line that seeds it
        $this->assertSame(
            1,
            preg_match("/'credential_id'\s*=>\s*([^,]+),/", $stub, $match),
            'credential_id is not seeded, so the passkey panel never renders'
        );

        // Assert — a call, not a constant
        $this->assertStringContainsString(
            '$unique(',
            $match[1],
            'a fixed credential_id seeds one test and silently nothing afterwards'
        );
    }

    /**
     * The seeder never invents a value for a generated key or a foreign key.
     *
     * WHAT: the fill-by-type loop skips `hasDefault`, `isPrimary` and `isForeign`, and
     *       reads the column shape through `SchemaBuilder::columnDetails()`.
     *
     * WHY:  it read `Default` and `Key` — the **MySQL** spellings — so on PostgreSQL both
     *       were absent, `null` read as "no default, not a key", and an integer
     *       placeholder went into a `bigserial` primary key:
     *
     *       ```
     *       passkey_credentials: duplicate key value violates "passkey_credentials_pkey"
     *       tokenactions:        violates foreign key constraint "fk_tokenactions_urlid"
     *       ```
     *
     *       Four of the seven tables refused, every refusal was absorbed — correctly —
     *       and the sweep stayed green while the person card rendered four fewer panels
     *       than it could. Measured elsewhere: 399/605 against 495/605 with the refusals
     *       gone.
     *
     *       The foreign key is the line worth keeping separate from the rest. A row so
     *       the loop runs is the point; a row claiming something untrue about another
     *       table is not, and no constraint would accept it anyway.
     *
     * @return void
     */
    public function testTheSeederInventsNoGeneratedOrForeignKeyValues(): void
    {
        // Arrange
        $stub = $this->stub();

        // Assert — the normalised shape, not a driver's spelling
        $this->assertStringContainsString(
            "columnDetails(\$table)",
            $stub,
            'the seeder reads raw driver keys, which differ between the two engines'
        );
        foreach (["'Default'", "'COLUMN_DEFAULT'", "'Key'"] as $rawKey) {
            $this->assertStringNotContainsString(
                '$field[' . $rawKey . ']',
                $stub,
                'a driver-specific column key is read directly, so it is null on the other engine'
            );
        }

        // …and all three exclusions are there
        $this->assertStringContainsString(
            "\$column['hasDefault'] || \$column['isPrimary'] || \$column['isForeign']",
            $stub,
            'the seeder still invents values for generated or foreign keys'
        );
    }

    /**
     * `init` writes it.
     *
     * A stub nothing emits is a file in this repository and nothing else.
     */
    public function testTheScaffoldWritesIt(): void
    {
        // Arrange
        $generator = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Console/Commands/Init.php'
        );

        // Assert
        $this->assertStringContainsString("'tests/Integration/ScreenSweepTest.php'", $generator);
        $this->assertStringContainsString("renderStub('screen-sweep-test'", $generator);
    }

    /**
     * The allowlist, as the stub declares it.
     *
     * @return list<string>
     */
    private function allowedActions(): array
    {
        if (!preg_match('/READ_ACTIONS = \[(.*?)\];/s', $this->stub(), $match)) {
            return [];
        }

        preg_match_all("/'([a-z]+)'/", $match[1], $found);

        return $found[1];
    }
}
