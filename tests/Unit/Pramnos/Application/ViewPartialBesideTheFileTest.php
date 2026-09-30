<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controller;
use Pramnos\Application\View;

/**
 * A view's partials are found beside the view file itself.
 *
 * An application with a view directory of its own — `src/Views/OAuth2/` holding one
 * override — gets the framework's scaffold for every other view of that name, and the
 * scaffold's `insert('../partials/account_breadcrumb')` was looked for under the
 * application's `src/Views/`, which has no partials. The breadcrumb and the account
 * sidebar vanished from the security page with nothing in any log a person reads.
 */
class ViewPartialBesideTheFileTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/pramnos_partials_' . bin2hex(random_bytes(5));
        // The application's view directory: exists, holds nothing this test draws.
        mkdir($this->tmp . '/app/OAuth2', 0775, true);
        // The scaffold: a view, and the partial it includes beside its directory.
        mkdir($this->tmp . '/scaffold/OAuth2', 0775, true);
        mkdir($this->tmp . '/scaffold/partials', 0775, true);
        file_put_contents($this->tmp . '/scaffold/partials/crumb.html.php', '[crumb]');
        file_put_contents($this->tmp . '/scaffold/OAuth2/security.html.php', "<?php \$this->insert('../partials/crumb'); ?>[page]");
    }

    protected function tearDown(): void
    {
        foreach (['app/OAuth2', 'scaffold/OAuth2', 'scaffold/partials', 'app', 'scaffold'] as $dir) {
            foreach (glob($this->tmp . '/' . $dir . '/*.php') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tmp . '/' . $dir);
        }
        @rmdir($this->tmp);
    }

    /** Includes a template file the way the render does, with the view as `$this`. */
    private function draw(View $view, string $file): string
    {
        $method = new \ReflectionMethod(View::class, 'insert');
        ob_start();
        $method->invoke($view, $file);

        return (string) ob_get_clean();
    }

    /**
     * A scaffold view drawn for an application whose own directory has no partials finds
     * its partial beside itself.
     */
    public function testAPartialIsFoundBesideTheFileBeingDrawn(): void
    {
        // Arrange — the view rooted at the application's directory
        $view = new View(new Controller(), $this->tmp . '/app/OAuth2', 'OAuth2');

        // Act — the scaffold's file, by absolute path, as the fallback resolves it
        $out = $this->draw($view, $this->tmp . '/scaffold/OAuth2/security.html.php');

        // Assert
        $this->assertSame('[crumb][page]', $out);
    }

    /** The application's own partial still wins over the scaffold's. */
    public function testTheApplicationsOwnPartialStillWins(): void
    {
        // Arrange
        mkdir($this->tmp . '/app/partials', 0775, true);
        file_put_contents($this->tmp . '/app/partials/crumb.html.php', '[app crumb]');
        $view = new View(new Controller(), $this->tmp . '/app/OAuth2', 'OAuth2');

        try {
            // Act
            $out = $this->draw($view, $this->tmp . '/scaffold/OAuth2/security.html.php');

            // Assert
            $this->assertSame('[app crumb][page]', $out);
        } finally {
            @unlink($this->tmp . '/app/partials/crumb.html.php');
            @rmdir($this->tmp . '/app/partials');
        }
    }
}
