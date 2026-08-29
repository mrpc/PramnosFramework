<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Push;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\PushLogController;

/**
 * The screen exists, and something links to it.
 *
 * The recurring failure this whole area keeps producing: machinery that is complete and never
 * reached. A `PushLogController` with a view in every theme and no navigation item is a screen
 * only somebody who read the source can find — which, for a screen that exists to answer «τα
 * push που στάλθηκαν πού τα βλέπω;», is the same as not having built it.
 */
#[CoversClass(PushLogController::class)]
class PushLogScreenTest extends TestCase
{
    private const THEMES = ['tailwind', 'bootstrap', 'plain-css'];

    /**
     * Every theme has the view the controller asks for.
     *
     * `getView('pushlog')` resolves per theme, and a theme without the file gets the
     * framework's scaffold view — a different screen from the other two, with none of this on it.
     */
    public function testEveryThemeHasTheView(): void
    {
        foreach (self::THEMES as $theme) {
            // Assert
            $this->assertFileExists(
                dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme
                . '/views/pushlog/pushlog.html.php',
                $theme . ' has no push log view'
            );
        }
    }

    /**
     * The refusals are on the same screen as the deliveries.
     *
     * "Nothing subscribed" is the commonest answer to why a notification never arrived, and it
     * is only useful beside the sends that worked: a screen listing successes alone makes an
     * installation with no key pair look identical to one where everything is arriving.
     */
    public function testTheScreenShowsWhatWasNotSent(): void
    {
        foreach (self::THEMES as $theme) {
            // Act
            $view = (string) file_get_contents(
                dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme
                . '/views/pushlog/pushlog.html.php'
            );

            // Assert
            $this->assertStringContainsString('Not sent', $view, $theme);
            $this->assertStringContainsString('Subscription gone', $view, $theme);
            $this->assertStringContainsString("\$row['error']", $view,
                $theme . ' does not show why nothing was sent');
            $this->assertStringNotContainsString("\$row['endpoint']", $view,
                $theme . ' must not put the endpoint on a screen — it is a credential, and the '
                . 'table does not hold it');
        }
    }

    /**
     * The navigation reaches it.
     */
    public function testTheNavigationReachesIt(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Application/Application.php'
        );

        // Assert
        $this->assertStringContainsString("'admin.pushlog'", $source);
        $this->assertStringContainsString("\$admin('PushLog')", $source);
    }

    /**
     * And a scaffolded project gets the wrapper that puts it on an address.
     *
     * The area resolves `src/Admin/Controllers` first; without a wrapper there, `/admin/PushLog`
     * is a 404 in every project the framework generates.
     */
    public function testAScaffoldedProjectGetsTheWrapper(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Console/Commands/Init.php'
        );

        // Assert
        $this->assertStringContainsString("src/Admin/Controllers/PushLog.php", $source);
        $this->assertStringContainsString('class PushLog extends FrameworkPushLogController', $source);
    }

    /**
     * The user card links to this account's own notifications.
     *
     * «They say they did not get it» is asked about one person, on their screen, and a link that
     * lands on every notification the installation ever sent is a link nobody follows twice.
     */
    public function testTheUserCardLinksToThisAccountsPushes(): void
    {
        // Arrange
        $view = (string) file_get_contents(
            dirname(__DIR__, 3) . '/scaffolding/themes/tailwind/views/users/view.html.php'
        );

        // Assert
        $this->assertStringContainsString("adminUrl('PushLog')", $view);
        $this->assertStringContainsString('?userid=', $view);
        $this->assertStringContainsString('Recent pushes', $view);
    }
}
