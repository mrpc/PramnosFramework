<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\DevPanel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Pramnos\DevPanel\AdminerBridge;

/**
 * The hook Adminer looks for, and the subclass it gets back — both at 0%.
 *
 * `adminer-object.php` is two statements, and the reason it is a file at all is the reason it is
 * worth a test: Adminer's bootstrap asks `function_exists('adminer_object')` with an **unqualified
 * string**, which PHP resolves in the global namespace only. So the hook cannot be a namespaced
 * function, a closure or a method — and a refactor that tidied it into any of those would break
 * nothing visibly. Adminer would simply build its own object and show its login form, which is a
 * working page: the form that this whole arrangement exists to remove.
 *
 * Which is the real subject here. The subclass replaces four of Adminer's defaults, and each
 * replacement is a refusal:
 *
 *   - **`credentials()`** answers from configuration, never the query string. Adminer's default
 *     reads the server and username out of the URL, which behind a single-purpose gate makes a
 *     link enough to point this at another host.
 *   - **`loginForm()`** prints a sentence instead of a form. Adminer's form takes a driver, a
 *     server, a username, a password and a database — that is, it lets whoever reached this URL
 *     connect to anything reachable from this machine with any credentials they know.
 *   - **`login()`** returns true, because the route's gate already decided, and the default would
 *     open a second connection with an empty password to work out whether one is required.
 *   - **`name()`** says whose database this is. Adminer's own name is a link to adminer.org, the
 *     least useful thing to read on a page that can drop a table — and a production database and a
 *     local copy are otherwise identical on screen.
 *
 * Separate processes: the file defines a global function, the bridge `eval`s a class, and the parent
 * class is stubbed here. None of those can be undone within a process.
 */
#[CoversClass(AdminerBridge::class)]
#[RunTestsInSeparateProcesses]
class AdminerObjectHookTest extends TestCase
{
    /** The connection the installation declared, as `remember()` stores it. */
    private const CONNECTION = [
        'driver'   => 'server',
        'server'   => 'db.internal:3306',
        'user'     => 'app',
        'password' => 'the-real-secret',
        'name'     => 'Production',
    ];

    /**
     * Adminer's `Adminer` class, stubbed.
     *
     * It is defined by an include partway through Adminer's own bootstrap, and the point of the
     * `eval` in the bridge is that the subclass cannot be written before that moment. The stub
     * stands in for it so the subclass can be built at all — and it gives `credentials()` a
     * recognisable answer, because the subclass falls back to `parent::credentials()` when no
     * connection was declared and that fallback is one of the things worth asserting.
     */
    private function stubAdminerParent(): void
    {
        if (class_exists('Adminer\\Adminer', false)) {
            return;
        }

        eval(
            'namespace Adminer; class Adminer {'
            . ' public function credentials(): array { return ["from-the-url", "from-the-url", ""]; }'
            . '}'
        );
    }

    /** Include the hook file the way Adminer's bootstrap would, and call it. */
    private function hook(): object
    {
        $this->stubAdminerParent();

        require_once dirname(__DIR__, 3) . '/src/Pramnos/DevPanel/adminer-object.php';

        $this->assertTrue(
            function_exists('adminer_object'),
            'Adminer asks for this name unqualified, in the global namespace'
        );

        return \adminer_object();
    }

    protected function setUp(): void
    {
        $_SESSION = [];
        $_GET = [];
    }

    // ── The hook itself ───────────────────────────────────────────────────────

    /**
     * The function lands in the global namespace, not this file's.
     *
     * The one property the file exists to have. `function_exists()` on the qualified name is the
     * assertion that catches the tidy-up: a namespaced `adminer_object()` exists as far as PHP is
     * concerned and is invisible to the unqualified lookup Adminer does.
     */
    public function testTheHookIsGlobal(): void
    {
        // Act
        $this->hook();

        // Assert
        $this->assertTrue(function_exists('adminer_object'));
        $this->assertFalse(
            function_exists('Pramnos\\DevPanel\\adminer_object'),
            'a namespaced hook is invisible to the lookup Adminer performs'
        );
    }

    /**
     * It hands back the framework's subclass, not Adminer's own object.
     *
     * If it returned anything that was not a `\Adminer\Adminer`, the bootstrap would use it as its
     * Adminer object and fail on the first method it called.
     */
    public function testItReturnsTheFrameworkSubclass(): void
    {
        // Act
        $object = $this->hook();

        // Assert
        $this->assertInstanceOf('Adminer\\Adminer', $object);
        $this->assertInstanceOf('Pramnos\\DevPanel\\PramnosAdminer', $object);
    }

    /**
     * Including the file twice is not fatal.
     *
     * A redeclared function is a fatal error, not an exception — it would take the request down
     * with a blank page. The route includes this immediately before Adminer, and Adminer's own
     * bootstrap is not the only thing that can have been included first.
     */
    public function testIncludingItTwiceIsNotFatal(): void
    {
        // Act
        $this->hook();
        include dirname(__DIR__, 3) . '/src/Pramnos/DevPanel/adminer-object.php';

        // Assert
        $this->assertTrue(function_exists('adminer_object'));
    }

    // ── What the object refuses ───────────────────────────────────────────────

    /**
     * The connection comes from configuration even when the URL names another one.
     *
     * This is the security property of the whole arrangement. Adminer's default `credentials()`
     * reads `$_GET`, so with it in place a link is enough to aim an authorised session at any host
     * this machine can reach — with credentials the visitor supplies. The hostile query string is
     * in place here and must be ignored entirely.
     */
    public function testTheUrlCannotChooseTheServer(): void
    {
        // Arrange
        AdminerBridge::remember(self::CONNECTION);
        $_GET = ['server' => 'evil.example.com:3306', 'username' => 'root'];

        // Act
        $credentials = $this->hook()->credentials();

        // Assert
        $this->assertSame(
            ['db.internal:3306', 'app', 'the-real-secret'],
            $credentials,
            'the query string was allowed to choose the host'
        );
    }

    /**
     * With nothing declared, it defers to Adminer rather than inventing a connection.
     *
     * The empty case is what an installation that has not configured a connection gets, and
     * answering with blanks would be a login attempt with an empty username — Adminer's own
     * behaviour is the right answer, and the branch that produces it is one line that no
     * configured installation ever runs.
     */
    public function testWithNothingDeclaredItDefersToAdminer(): void
    {
        // Arrange
        AdminerBridge::remember([]);

        // Act
        $credentials = $this->hook()->credentials();

        // Assert
        $this->assertSame(['from-the-url', 'from-the-url', ''], $credentials);
    }

    /**
     * The password is put where `get_password()` reads it, as a plain string.
     *
     * Adminer looks the password up in `$_SESSION['pwds'][driver][server][user]`, and the slot is
     * exact: anything else and it prompts. A plain string rather than the encrypted array form,
     * which is only for the permanent-login cookie — handing it the array shape would make it try
     * to decrypt a value that was never encrypted.
     */
    public function testThePasswordLandsInTheSlotAdminerReads(): void
    {
        // Arrange
        AdminerBridge::remember(self::CONNECTION);

        // Act
        $this->hook();

        // Assert
        $this->assertSame(
            'the-real-secret',
            $_SESSION['pwds']['server']['db.internal:3306']['app'] ?? null,
            'Adminer would show its password prompt'
        );
    }

    /** Nothing is written to the session when no connection was declared. */
    public function testNothingIsWrittenToTheSessionWithNoConnection(): void
    {
        // Arrange
        AdminerBridge::remember([]);

        // Act
        $this->hook();

        // Assert
        $this->assertArrayNotHasKey('pwds', $_SESSION);
    }

    /**
     * There is no login form — a sentence saying where the connection came from instead.
     *
     * Asserted by what is *absent*: an `<input>` is the thing that must not be there, because the
     * form is what turns one permission into a general-purpose database client pointed at the rest
     * of the network.
     */
    public function testThereIsNoLoginForm(): void
    {
        // Arrange
        AdminerBridge::remember(self::CONNECTION);
        $object = $this->hook();

        // Act
        ob_start();
        $object->loginForm();
        $printed = (string) ob_get_clean();

        // Assert
        $this->assertStringNotContainsString('<input', $printed, 'the login form came back');
        $this->assertStringNotContainsString('<form', $printed);
        $this->assertStringContainsString('from the configuration', $printed);
    }

    /** The route's gate is the lock, so the login always passes and opens no second connection. */
    public function testTheLoginAlwaysPasses(): void
    {
        // Arrange
        $object = $this->hook();

        // Act & Assert
        $this->assertTrue($object->login('app', 'the-real-secret'));
        $this->assertTrue($object->login('', ''), 'an empty password would trigger a probe connect');
    }

    /**
     * The header names the installation, and escapes the name.
     *
     * Which database you are looking at is the thing worth reading on a page that can drop a table,
     * and a production database and a local copy are otherwise identical on screen. The name comes
     * from configuration, so nothing hostile reaches it today — and it is printed into the page
     * header, which is where trusting that stops being free.
     */
    public function testTheHeaderNamesTheInstallationAndEscapesIt(): void
    {
        // Arrange
        AdminerBridge::remember(['driver' => 'server', 'server' => 'h', 'user' => 'u',
            'password' => 'p', 'name' => 'Prod <script>alert(1)</script>']);

        // Act
        $name = $this->hook()->name();

        // Assert
        $this->assertStringContainsString('Prod', $name);
        $this->assertStringNotContainsString('<script>', $name);
        $this->assertStringContainsString('&lt;script&gt;', $name);
        $this->assertStringContainsString('Adminer', $name);
    }

    /** With no name configured, the header is still Adminer's and not a stray separator. */
    public function testWithNoNameTheHeaderIsJustAdminer(): void
    {
        // Arrange
        AdminerBridge::remember([]);

        // Act
        $name = $this->hook()->name();

        // Assert
        $this->assertStringContainsString('Adminer', $name);
        $this->assertStringNotContainsString('&middot;', $name, 'a separator with nothing before it');
    }
}
