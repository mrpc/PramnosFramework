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
