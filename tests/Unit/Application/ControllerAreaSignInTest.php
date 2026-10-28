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
#[CoversClass(\Pramnos\Auth\SecurityPolicy::class)]
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
        unset(\Pramnos\Application\Application::getInstance()->applicationInfo['auth']['login_url']);
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

    /**
     * A screen behind a usertype floor sends a guest to sign in and back too — somebody opening a
     * scheduled report's link from an email — rather than to the site's home page; somebody signed
     * in without the standing still goes to the site.
     */
    public function testAGuestAtAFlooredScreenSignsInAndComesBack(): void
    {
        // Arrange
        $controller = new class (null) extends Controller {
            /** @var list<string> */
            public array $went = [];

            public function __construct($app)
            {
                parent::__construct($app);
            }

            public function guard(int $floor): bool
            {
                return $this->requireMinUserType($floor);
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->went[] = (string) $url;
            }
        };
        AdminArea::detect('admin');

        // Act
        $refused = $controller->guard(90);

        // Assert
        $this->assertTrue($refused);
        $this->assertSame([\sURL . 'login?return=' . urlencode('/admin/Ai')], $controller->went);
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

    /**
     * An application whose sign-in is elsewhere names it once — `'auth' => ['login_url' =>
     * 'account/login']` — and a guest goes there, with the page to come back to: from inside an
     * area without a sign-in of its own, from a screen behind a floor, and from any other page.
     *
     * Every one of these sent guests to the site's `login`, which such an application does not
     * have: a link to a report in an email ended on a 404.
     */
    public function testTheApplicationsOwnSignIn(): void
    {
        // Arrange
        \Pramnos\Application\Application::getInstance()->applicationInfo['auth']['login_url'] = 'account/login';

        // Act — in the area; then outside it, from a page and from the site's root
        $inArea = $this->refusal();
        AdminArea::reset();
        \Pramnos\Http\Request::$requestUri = 'reports/7';
        $outside = \Pramnos\Auth\SecurityPolicy::loginUrl('reports/7');
        $root    = \Pramnos\Auth\SecurityPolicy::loginUrl('/');

        // Assert
        $this->assertSame(\sURL . 'account/login?return=' . urlencode('/admin/Ai'), $inArea);
        $this->assertSame(\sURL . 'account/login?return=reports%2F7', $outside);
        $this->assertSame(\sURL . 'account/login', $root, 'nothing to come back to from the root');
    }

    /**
     * A path is under the site; one starting with `/`, or a whole URL, is used as written; and a
     * sign-in address that already has a query string gets `&return=`, not a second `?`.
     *
     * An application on another host's sign-in (a single sign-on server) gives its whole URL; one
     * mounted at the root of a subfolder install gives a path.
     */
    public function testTheSignInAddressAsWritten(): void
    {
        // Arrange
        $config = &\Pramnos\Application\Application::getInstance()->applicationInfo['auth'];

        // Act
        $config['login_url'] = '/sso/login?app=uw';
        $absolute = \Pramnos\Auth\SecurityPolicy::loginUrl('chat');
        $config['login_url'] = 'https://id.example.com/login';
        $elsewhere = \Pramnos\Auth\SecurityPolicy::loginUrl();
        $config['login_url'] = '';
        $default = \Pramnos\Auth\SecurityPolicy::loginUrl();

        // Assert
        $this->assertSame('/sso/login?app=uw&return=chat', $absolute);
        $this->assertSame('https://id.example.com/login', $elsewhere);
        $this->assertSame(\sURL . 'login', $default, 'unset, the site\'s own');
    }
}
