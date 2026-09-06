<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;

/**
 * Reaching Adminer while the site is stopped.
 *
 * Maintenance exists to keep traffic off a schema in flux — and the person who
 * raised it is usually the one who needs to *look* at that schema. Adminer behind
 * the same page as everybody else meant the tool for inspecting a half-finished
 * migration was unreachable for exactly the duration of one.
 *
 * The exemption grants one thing: that the application **boots** for that path.
 * Every route's own authorisation still runs — for `adminer` that is signed in,
 * plus `usertype >= 99` or a development environment — so the looseness of the
 * matching here is not a hole. The worst a stray URL can do is boot an
 * application that then refuses it, which is what an ordinary request does.
 */
#[CoversClass(Application::class)]
class MaintenanceExemptPathsTest extends TestCase
{
    private ?string $savedUri = null;

    protected function setUp(): void
    {
        $this->savedUri = $_SERVER['REQUEST_URI'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->savedUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->savedUri;
        }

        parent::tearDown();
    }

    /**
     * @param array<string, mixed>|null $maintenance The `maintenance` block, or none
     */
    private function exempt(string $uri, ?array $maintenance = null): bool
    {
        $_SERVER['REQUEST_URI'] = $uri;

        $app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $app->applicationInfo = $maintenance === null
            ? array()
            : array('maintenance' => $maintenance);

        return (bool) (new \ReflectionMethod(Application::class, 'requestIsMaintenanceExempt'))
            ->invoke($app);
    }

    /**
     * Adminer answers by default, wherever it is mounted.
     *
     * A subdirectory install and a query string are the two shapes a real URL arrives
     * in, and neither should need configuring.
     */
    public function testAdminerIsExemptOutOfTheBox(): void
    {
        // Assert
        $this->assertTrue($this->exempt('/adminer'));
        $this->assertTrue($this->exempt('/adminer/'));
        $this->assertTrue($this->exempt('/adminer?db=app&select=users'));
        $this->assertTrue($this->exempt('/mysite/adminer'));
        $this->assertTrue($this->exempt('/ADMINER'));
    }

    /**
     * Everything else still meets the maintenance page.
     *
     * The assertion that matters: this must not become "the site is up again". A
     * substring match would have made `/adminerator` and `/my-adminer-notes` exempt,
     * which is a page nobody meant to serve during a deploy.
     */
    public function testEverythingElseIsNotExempt(): void
    {
        // Assert
        $this->assertFalse($this->exempt('/'));
        $this->assertFalse($this->exempt('/orders'));
        $this->assertFalse($this->exempt('/devpanel'));
        $this->assertFalse($this->exempt('/adminerator'));
        $this->assertFalse($this->exempt('/my-adminer-notes'));
        $this->assertFalse($this->exempt('/x?next=/adminer'), 'the query string is not the path');
    }

    /**
     * The list is configurable, and naming one replaces the default rather than adding
     * to it.
     *
     * Stated in a test because it is the surprising half: an installation that lists
     * `devpanel` and means "and Adminer too" has to say both.
     */
    public function testTheListIsConfigurableAndReplacesTheDefault(): void
    {
        // Arrange
        $onlyPanel = array('exempt' => array('devpanel'));

        // Assert
        $this->assertTrue($this->exempt('/devpanel', $onlyPanel));
        $this->assertFalse($this->exempt('/adminer', $onlyPanel), 'the default was merged in');

        $this->assertTrue($this->exempt('/adminer', array('exempt' => array('devpanel', 'adminer'))));
    }

    /**
     * An empty list stops everything, which is how an installation says "no exceptions".
     */
    public function testAnEmptyListExemptsNothing(): void
    {
        // Assert
        $this->assertFalse($this->exempt('/adminer', array('exempt' => array())));
    }

    /**
     * A malformed list is ignored rather than coerced.
     *
     * `app.php` is hand-written; a stray nested array must not become a path named
     * `Array`, and a non-array `exempt` must not stop the guard working.
     */
    public function testAMalformedListIsIgnored(): void
    {
        // Assert
        $this->assertFalse($this->exempt('/adminer', array('exempt' => 'adminer')));
        $this->assertTrue(
            $this->exempt('/adminer', array('exempt' => array(array('nested'), 'adminer'))),
            'a stray entry stopped the valid one beside it from being read'
        );
    }

    /**
     * With no request at all — a console run, a test — nothing is exempt.
     *
     * Not that it matters there: `inConsoleContext()` already decides for the console.
     * This is about the guard not depending on a superglobal being present.
     */
    public function testWithNoRequestUriNothingIsExempt(): void
    {
        // Arrange
        unset($_SERVER['REQUEST_URI']);

        $app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $app->applicationInfo = array();

        // Act + Assert
        $this->assertFalse(
            (bool) (new \ReflectionMethod(Application::class, 'requestIsMaintenanceExempt'))
                ->invoke($app)
        );
    }

    /**
     * The exemption is wired into the constructor's own condition, not merely present.
     *
     * Read rather than executed: constructing an `Application` boots a database and a
     * session, and the thing worth pinning is one line of control flow. Without this,
     * deleting `&& !$this->requestIsMaintenanceExempt()` leaves every test above green
     * — the method correct, complete, and reached by nothing.
     */
    public function testTheConstructorConsultsIt(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            (new \ReflectionClass(Application::class))->getFileName()
        );

        // Assert — the three conditions together, in the order they are cheapest
        $this->assertMatchesRegularExpression(
            '/!self::inConsoleContext\(\)\s*\n\s*&&\s*file_exists\(\$this->maintenanceFlagFile\(\)\)'
            . '\s*\n\s*&&\s*!\$this->requestIsMaintenanceExempt\(\)/',
            $source,
            'the maintenance guard no longer consults the exemption'
        );
    }
}
