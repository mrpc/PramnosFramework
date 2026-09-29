<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Email;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The mailing-lists screen renders in every theme, and the menu reaches it.
 *
 * A controller with no view in a theme gets the scaffold fallback — a different screen — and
 * one with no menu item is a screen only somebody who read the source can find. Rendered here
 * with the data the controller hands it, so a typo in a theme is a failure rather than a blank
 * page on an installation.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\Pramnos\Application\Controllers\MailingListsController::class)]
class MailingListsScreenTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function themes(): array
    {
        return ['tailwind' => ['tailwind'], 'bootstrap' => ['bootstrap'], 'plain-css' => ['plain-css']];
    }

    /** Render one theme's view with `$vars` as the view's properties. */
    private function render(string $theme, array $vars): string
    {
        $file  = dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/views/mailinglists/mailinglists.html.php';
        $view  = (object) $vars;
        $run   = \Closure::bind(function (string $file): void {
            include $file;
        }, $view, null);

        ob_start();
        $run($file);

        return (string) ob_get_clean();
    }

    /**
     * The counts, the rows with their actions, and the pager.
     *
     * A pending row offers "send again" and "unsubscribe"; a row that has left offers neither,
     * and every action is a form carrying the session's token.
     */
    #[DataProvider('themes')]
    public function testTheListRendersWithItsActions(string $theme): void
    {
        // Arrange
        $vars = [
            'lists'   => ['newsletter'],
            'counts'  => ['newsletter' => ['pending' => 3, 'confirmed' => 40, 'unsubscribed' => 2]],
            'list'    => 'newsletter', 'status' => '', 'search' => '', 'page' => 1, 'perPage' => 1,
            'result'  => ['total' => 2, 'rows' => [
                ['subscriberid' => 7, 'email' => 'waiting@example.com', 'userid' => null, 'status' => 'pending',
                 'source' => 'landing', 'language' => 'el', 'created_at' => 1_800_000_000,
                 'confirmed_at' => null, 'unsubscribed_at' => null, 'confirmation_sent_at' => 1_800_000_000],
                ['subscriberid' => 8, 'email' => 'gone@example.com', 'userid' => 12, 'status' => 'unsubscribed',
                 'source' => 'account', 'language' => '', 'created_at' => 1_800_000_000,
                 'confirmed_at' => 1_800_000_100, 'unsubscribed_at' => 1_800_000_200, 'confirmation_sent_at' => null],
            ]],
        ];

        // Act
        $html = $this->render($theme, $vars);

        // Assert
        $this->assertStringContainsString('waiting@example.com', $html);
        $this->assertStringContainsString('<strong>40</strong>', $html, 'the confirmed count');
        $this->assertSame(1, substr_count($html, 'MailingLists/resend/'), 'only the pending row can be sent again');
        $this->assertSame(1, substr_count($html, 'MailingLists/unsubscribe/'), 'a row that has left cannot leave');
        $this->assertStringContainsString('MailingLists/export?list=newsletter', $html);
        $this->assertStringContainsString('users/view/12', $html, 'an account is linked');
        $this->assertStringContainsString('page 1 of 2', $html);
    }

    /**
     * With no opt-in list registered, the screen says how to make one rather than showing a table.
     */
    #[DataProvider('themes')]
    public function testNoListSaysHowToMakeOne(string $theme): void
    {
        // Act
        $html = $this->render($theme, ['lists' => [], 'counts' => [], 'result' => ['rows' => [], 'total' => 0]]);

        // Assert
        $this->assertStringContainsString('optIn: true', $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    /**
     * The menu reaches it, and `init` writes the wrapper that gives it an address.
     */
    public function testTheMenuAndTheScaffoldReachIt(): void
    {
        // Arrange
        $root = dirname(__DIR__, 3) . '/src/Pramnos';

        // Assert
        $this->assertStringContainsString("'admin.mailinglists'", (string) file_get_contents($root . '/Application/Application.php'));
        $this->assertStringContainsString("src/Admin/Controllers/MailingLists.php", (string) file_get_contents($root . '/Console/Commands/Init.php'));
    }

    /**
     * The controller declares its row actions as writes, reads the real lists, and refuses
     * before reading anything when the floor says no.
     */
    public function testTheControllerDeclaresItsWritesAndRefusesBelowTheFloor(): void
    {
        // Arrange
        $refused = new class (null) extends \Pramnos\Application\Controllers\MailingListsController {
            protected function requireMinUserType(int $minType): bool
            {
                return true;
            }

            public function writes(): array
            {
                return $this->writeActions;
            }

            public function lists(): \Pramnos\Email\MailingList
            {
                return $this->mailingList();
            }
        };

        // Act & Assert
        $this->assertSame(['resend', 'unsubscribe'], $refused->writes());
        $this->assertInstanceOf(\Pramnos\Email\MailingList::class, $refused->lists());
        $this->assertNull($refused->display(), 'refused before the lists are read');
        $this->assertNull($refused->export());
    }
}
