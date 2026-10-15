<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The scheduled AI tasks on the delete-account form, as each theme draws them.
 *
 * What holds: each organisation's tasks are named, escaped; where somebody can take them, a choice
 * of whom — the first proposed when there are shared tasks, nobody when there are only one's own —
 * and a box for one's own tasks; where nobody can, the page says they go with the account; and
 * the delete form carries the section, so the choice is sent with it.
 */
class DeleteAccountTasksViewTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function themes(): array
    {
        return ['tailwind' => ['tailwind'], 'bootstrap' => ['bootstrap'], 'plain-css' => ['plain-css']];
    }

    /**
     * The partial drawn with `$this->taskHandover` as given.
     *
     * @param list<array<string, mixed>> $handover
     */
    private static function draw(string $theme, array $handover): string
    {
        $view = new class ($handover) {
            /** @param list<array<string, mixed>> $taskHandover */
            public function __construct(public array $taskHandover)
            {
            }
        };
        ob_start();
        (function (string $__file): void {
            include $__file;
        })->call($view, ROOT . '/scaffolding/themes/' . $theme . '/views/OAuth2/delete_account_tasks.html.php');

        return (string) ob_get_clean();
    }

    /** One organisation with shared and own tasks and two successors, one outside any with nobody. */
    #[DataProvider('themes')]
    public function testTheChoices(string $theme): void
    {
        // Arrange
        $handover = [
            ['organization_id' => 3, 'name' => 'Acme <Ltd>', 'shared' => [['routine_id' => 1, 'title' => 'Team <week>']],
                'personal' => [['routine_id' => 2, 'title' => 'Mine']], 'successors' => [8 => 'Maria', 9 => 'Nikos']],
            ['organization_id' => 4, 'name' => 'Solo', 'shared' => [], 'personal' => [['routine_id' => 4, 'title' => 'Note']], 'successors' => [8 => 'Maria']],
            ['organization_id' => null, 'name' => '', 'shared' => [], 'personal' => [['routine_id' => 3, 'title' => 'Elsewhere']], 'successors' => []],
        ];

        // Act
        $html = self::draw($theme, $handover);

        // Assert
        $this->assertStringContainsString('Your scheduled tasks in Acme &lt;Ltd&gt;', $html);
        $this->assertStringContainsString('Team &lt;week&gt;, Mine', $html);
        $this->assertStringContainsString('name="tasks[3][to]"', $html);
        $this->assertMatchesRegularExpression('#<option value="8">Maria</option>#', $html, 'shared tasks: the first successor proposed');
        $this->assertMatchesRegularExpression('#name="tasks\[4\]\[to\]">.*<option value="" selected>#s', $html, 'only one\'s own: nobody, unless chosen');
        $this->assertStringContainsString('name="tasks[3][personal]"', $html);
        $this->assertStringContainsString('Nobody else can take them over', $html, 'outside any organisation');
        $this->assertStringNotContainsString('tasks[none][to]', $html);
        $this->assertSame('', self::draw($theme, []), 'no tasks, nothing drawn');
        $this->assertStringContainsString("insert('delete_account_tasks')", (string) file_get_contents(ROOT . '/scaffolding/themes/' . $theme . '/views/OAuth2/delete_account.html.php'));
    }
}
