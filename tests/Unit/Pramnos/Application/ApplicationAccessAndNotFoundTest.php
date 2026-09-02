<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\ApplicationClosedException;

/**
 * Who counts as signed in for the administration area, and what a missing page answers with.
 *
 * Two unrelated methods in one file because they have the same property: both are decisions the
 * framework makes on the way *out* of a request, both had never executed, and both are wrong in a way
 * that looks like nothing.
 *
 * `adminAreaUserIsSignedIn()` had zero hits across the suite. It is five statements and it is the gate
 * on an area whose whole purpose is to be closed — and the interesting part is the `> 1`.
 *
 * `notFound()` renders the framework's own 404. Six statements uncovered, on a page that exists
 * because the alternative — an empty 200 — tells a crawler the URL has content and tells a person
 * nothing.
 */
#[CoversClass(Application::class)]
class ApplicationAccessAndNotFoundTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('ROOT')) {
            define('ROOT', realpath(__DIR__ . '/../../../../'));
        }
        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }
    }

    /**
     * An application whose current user and session state are given rather than looked up.
     *
     * `adminAreaUser()` is the seam for the first and exists for this reason — «is there an account
     * behind this request» should be answerable without signing anybody in. The session answer is not
     * a seam, so it is set on the real static: `Session::staticIsLogged()` reads the session store, and
     * a test that stubbed it would be asserting against its own stub rather than against the two
     * independent questions this method deliberately asks.
     */
    private function application(mixed $user): Application
    {
        return new class ($user) extends Application {
            public array $closed = [];

            // Not `$currentUser`: Application already has a public property of that name, and a
            // promoted private one collides with it at class-load time.
            public function __construct(private mixed $whoIsHere)
            {
            }

            protected function adminAreaUser(): mixed
            {
                return $this->whoIsHere;
            }

            public function callIsSignedIn(): bool
            {
                return $this->adminAreaUserIsSignedIn();
            }
        };
    }

    /** A stand-in for a loaded user. */
    private function user(int $userid): object
    {
        return new class ($userid) {
            public function __construct(public int $userid)
            {
            }
        };
    }

    /**
     * `userid` 0 and 1 are not signed-in accounts, whatever the session says.
     *
     * The `> 1` is the whole method. Those two rows are the framework's guest and system entries, and
     * they carry a `usertype` like any other row — so a request judged with one of them is measured
     * against a number it has no claim to, on the way into an area gated by exactly that number. The
     * honest answer is «this is a guest, send them to sign in».
     *
     * Asserted as a list rather than one case, because the failure mode is a comparison that was
     * `> 0` or `>= 1` and reads perfectly well.
     *
     * @param mixed $user
     */
    #[DataProvider('notSignedIn')]
    public function testAGuestOrASystemRowIsNotSignedIn(string $label, $user): void
    {
        // Arrange
        $application = $this->application($user);

        // Act & Assert
        $this->assertFalse($application->callIsSignedIn(), $label . ' was treated as signed in');
    }

    /** @return array<string, array{string, mixed}> */
    public static function notSignedIn(): array
    {
        return [
            'nobody at all'   => ['a request with no user', null],
            'a false user'    => ['a failed load', false],
            'the guest row'   => ['userid 0', new \stdClass()],
        ];
    }

    /**
     * …including the two reserved ids specifically, which is the assertion the name is about.
     */
    public function testTheReservedIdsAreNotSignedIn(): void
    {
        // Act & Assert
        foreach ([0, 1] as $reserved) {
            $this->assertFalse(
                $this->application($this->user($reserved))->callIsSignedIn(),
                'userid ' . $reserved . ' was treated as a signed-in account'
            );
        }
    }

    /**
     * A real account with no session is not signed in either.
     *
     * The two questions are independent and the method asks both on purpose: an API-authenticated
     * request carries a real identity and no session, and this area is a browser tool. A gate that
     * accepted the identity alone would let a bearer token open the administration screens — which is
     * a credential nobody issued for that.
     */
    public function testARealAccountWithoutASessionIsNotSignedIn(): void
    {
        // Arrange — no session has been started in this process
        $application = $this->application($this->user(42));

        // Act & Assert
        $this->assertFalse(
            $application->callIsSignedIn(),
            'an identity with no session opened the administration area'
        );
    }

    /**
     * The 404 page says 404, says `noindex`, and offers a way back.
     *
     * Each of the three is there for a different reader. The status is for the crawler — an empty 200
     * is a soft 404, which keeps the URL in the index and spends the site's crawl budget on it. The
     * `noindex` is belt and braces for the same reader. The link is for the person, who otherwise has
     * the browser's back button and a blank page.
     */
    public function testTheNotFoundPageIsA404WithNoindexAndAWayBack(): void
    {
        // Arrange
        $application = $this->application(null);

        // Act
        try {
            $application->notFound();
            $body = '';
            $status = 0;
        } catch (ApplicationClosedException $closed) {
            $body = $closed->getBody();
            $status = $closed->getStatusCode();
        }

        // Assert
        $this->assertSame(404, $status, 'the page went out with a success status');
        $this->assertStringContainsString('noindex', $body);
        $this->assertStringContainsString('could not be found', $body);
        $this->assertStringContainsString('<a href=', $body, 'no way back to the site');
    }

    /**
     * A caller's message reaches the page, escaped.
     *
     * `notFound()` is called with a message by routing code and by controllers, and the messages that
     * reach it are not always constants — a controller name from the URL is a common one. So the value
     * is escaped, and this asserts the escaping rather than the feature: a 404 page that renders
     * attacker-supplied markup is a reflected-XSS on a URL anybody can hand out, on the one page a
     * site is most relaxed about.
     */
    public function testACallersMessageIsEscaped(): void
    {
        // Arrange
        $application = $this->application(null);

        // Act
        try {
            $application->notFound('<script>alert(1)</script>');
            $body = '';
        } catch (ApplicationClosedException $closed) {
            $body = $closed->getBody();
        }

        // Assert
        $this->assertStringNotContainsString('<script>alert(1)', $body, 'the message was not escaped');
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    /**
     * `forgetVerifiedMigrations()` removes the markers, and only its own.
     *
     * The cache invalidates itself when the migration files change, so this exists for the two cases
     * where that is not soon enough: a test that rewrites those files inside one process, and a deploy
     * that swaps the directory under a long-running worker. Both want it gone *now*.
     *
     * The second assertion is the one worth having. `glob('*.verified')` is a pattern, and a pattern
     * that had been `*` — or a directory that had been the parent — would delete somebody's data and
     * pass a test that only checked the markers were gone.
     */
    public function testForgettingTheMarkersRemovesOnlyTheMarkers(): void
    {
        // Arrange
        $directory = (defined('VAR_PATH') ? VAR_PATH : ROOT . DS . 'var') . DS . 'migrations';

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $marker = $directory . DS . 'probe_' . bin2hex(random_bytes(4)) . '.verified';
        $bystander = $directory . DS . 'probe_' . bin2hex(random_bytes(4)) . '.keepme';

        file_put_contents($marker, 'checked');
        file_put_contents($bystander, 'not a marker');

        try {
            // Act
            Application::forgetVerifiedMigrations();

            // Assert
            $this->assertFileDoesNotExist($marker, 'the marker survived');
            $this->assertFileExists($bystander, 'the sweep took a file that was not a marker');
        } finally {
            @unlink($marker);
            @unlink($bystander);
        }
    }
}
