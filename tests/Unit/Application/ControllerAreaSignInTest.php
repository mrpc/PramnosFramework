<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controller;
use Pramnos\Http\AdminArea;
use Pramnos\Http\RedirectException;

/**
 * A guest on a controller inside an administration area is sent to the area's sign-in, and
 * brought back to the area page — not to the site's page of the same name.
 *
 * `Request::$requestUri` has the area's prefix taken off, so `return=Ai` sent the guest from
 * `/admin/Ai` to the site's `/Ai` after signing in.
 */
#[CoversClass(Controller::class)]
#[CoversClass(AdminArea::class)]
class ControllerAreaSignInTest extends TestCase
{
    protected function setUp(): void
    {
        AdminArea::reset();
        $_GET = ['r' => 'admin/Ai'];
        $_SERVER['REQUEST_URI'] = '/admin/Ai';
    }

    protected function tearDown(): void
    {
        AdminArea::reset();
        $_GET = [];
        unset($_SERVER['REQUEST_URI']);
    }

    /**
     * The guest's redirect, from a protected action.
     *
     * The area is entered after the controller exists: the first controller of the run starts
     * the application, which resets the area as a new request would.
     */
    private function refusal(string $areaLogin = ''): string
    {
        $controller = new class (null) extends Controller {
            public function __construct($app)
            {
                parent::__construct($app);
                $this->addAuthAction('display');
            }

            public function display()
            {
            }
        };

        AdminArea::detect('admin');
        if ($areaLogin !== '') {
            AdminArea::useLogin($areaLogin);
        }
        $this->assertTrue(AdminArea::isActive(), 'inside the area');

        try {
            $controller->exec('display');
        } catch (RedirectException $e) {
            return $e->getUrl();
        }

        $this->fail('a guest was not redirected');
    }

    /** The site's sign-in by default, and back to the whole path. */
    public function testAGuestReturnsToTheAreaPage(): void
    {
        // Arrange: a guest on /admin/Ai, see setUp()

        // Act
        $url = $this->refusal();

        // Assert
        $this->assertSame(\sURL . 'login?return=' . urlencode('/admin/Ai'), $url);
    }

    /** The area's own sign-in, when it has one. */
    public function testTheAreasOwnSignIn(): void
    {
        // Arrange
        $login = \sURL . 'admin/login';

        // Act
        $url = $this->refusal($login);

        // Assert
        $this->assertSame($login . '?return=' . urlencode('/admin/Ai'), $url);
    }
}
