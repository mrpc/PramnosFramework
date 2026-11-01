<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\Hreflang;

/**
 * The registry behind hreflang for a page the router cannot derive
 * alternates for ({@see \Pramnos\Routing\Router::getLocaleSiblings()} is the
 * one that can, tested separately in LocalizedRouteGroupsTest).
 *
 * Verifies the three declaration kinds (group / resolver / singleLanguage),
 * that a resolver fails closed rather than taking a page down, and the
 * loadDefinitions() idempotency convention shared with Search\Registry.
 */
#[CoversClass(Hreflang::class)]
class HreflangTest extends TestCase
{
    protected function setUp(): void
    {
        Hreflang::reset();
    }

    protected function tearDown(): void
    {
        Hreflang::reset();
    }

    // -------------------------------------------------------------------------
    // group() / alternatesForPath()
    // -------------------------------------------------------------------------

    /**
     * A path that is one of a group's values gets the whole group back,
     * itself included — every member needs the full map to emit a
     * self-referencing hreflang entry.
     */
    public function testAlternatesForPathReturnsTheWholeGroup(): void
    {
        // Arrange
        Hreflang::group(['el' => '/syxnes-erwthseis', 'en' => '/faqs']);

        // Act
        $alternates = Hreflang::alternatesForPath('/faqs');

        // Assert
        $this->assertSame(['el' => '/syxnes-erwthseis', 'en' => '/faqs'], $alternates);
    }

    /**
     * A path not in any registered group answers with no alternates at
     * all — "not covered" rather than guessing.
     */
    public function testAlternatesForPathIsEmptyWhenNothingMatches(): void
    {
        // Arrange
        Hreflang::group(['el' => '/syxnes-erwthseis', 'en' => '/faqs']);

        // Act
        $alternates = Hreflang::alternatesForPath('/about');

        // Assert
        $this->assertSame([], $alternates);
    }

    /**
     * Registration and lookup normalize paths the same way, so a trailing
     * slash on one side and not the other still matches.
     */
    public function testAlternatesForPathNormalizesTrailingSlashes(): void
    {
        // Arrange
        Hreflang::group(['el' => '/syxnes-erwthseis/', 'en' => '/faqs']);

        // Act
        $alternates = Hreflang::alternatesForPath('faqs/'); // no leading slash, trailing slash

        // Assert — the group is still found, and its stored form is normalized
        $this->assertSame(['el' => '/syxnes-erwthseis', 'en' => '/faqs'], $alternates);
    }

    // -------------------------------------------------------------------------
    // resolver() / resolve()
    // -------------------------------------------------------------------------

    /**
     * A registered resolver is called with the params handed to resolve(),
     * and its return value is passed straight through.
     */
    public function testResolveCallsTheRegisteredResolverWithParams(): void
    {
        // Arrange
        Hreflang::resolver('Jobs@view', function (array $params): array {
            return ['el' => '/thesi/' . $params['id'], 'en' => '/job/' . $params['id']];
        });

        // Act
        $alternates = Hreflang::resolve('Jobs@view', ['id' => 42]);

        // Assert
        $this->assertSame(['el' => '/thesi/42', 'en' => '/job/42'], $alternates);
    }

    /**
     * No resolver registered under that key answers with no alternates,
     * never an error — a page without a resolver simply has none.
     */
    public function testResolveIsEmptyForAnUnregisteredKey(): void
    {
        // Act
        $alternates = Hreflang::resolve('Nothing@here');

        // Assert
        $this->assertSame([], $alternates);
    }

    /**
     * A resolver that throws must not take the page down with it — hreflang
     * is a <head> enhancement, not something a broken registration should
     * be able to fatal a page over.
     */
    public function testResolveFailsClosedWhenTheResolverThrows(): void
    {
        // Arrange
        Hreflang::resolver('Broken@view', function (): array {
            throw new \RuntimeException('database is down');
        });

        // Act
        $alternates = Hreflang::resolve('Broken@view');

        // Assert
        $this->assertSame([], $alternates);
    }

    /**
     * A resolver that returns something other than an array — a bug, a
     * stray return of $this, null and so on — is coerced to no alternates
     * rather than propagated into a foreach that would fatal instead.
     */
    public function testResolveFailsClosedWhenTheResolverReturnsSomethingNotAnArray(): void
    {
        // Arrange
        Hreflang::resolver('Wrong@view', fn (): ?string => null);

        // Act
        $alternates = Hreflang::resolve('Wrong@view');

        // Assert
        $this->assertSame([], $alternates);
    }

    // -------------------------------------------------------------------------
    // singleLanguage()
    // -------------------------------------------------------------------------

    /**
     * A path declared single-language is reported as such — the third
     * state, distinct from "not covered".
     */
    public function testIsSingleLanguageTrueForADeclaredPath(): void
    {
        // Arrange
        Hreflang::singleLanguage('/oroi-xrisis');

        // Act / Assert
        $this->assertTrue(Hreflang::isSingleLanguage('/oroi-xrisis'));
        $this->assertFalse(Hreflang::isSingleLanguage('/something-else'));
    }

    // -------------------------------------------------------------------------
    // loadDefinitions()
    // -------------------------------------------------------------------------

    /**
     * A missing app/hreflang.php is not an error — an application with
     * nothing registered simply has no hreflang from this registry.
     */
    public function testLoadDefinitionsReturnsFalseWhenNoFileExists(): void
    {
        // Act
        $loaded = Hreflang::loadDefinitions('/nonexistent/path/hreflang.php');

        // Assert
        $this->assertFalse($loaded);
        $this->assertFalse(Hreflang::hasDefinitions());
    }

    /**
     * With no $file argument, the default path is ROOT/app/hreflang.php —
     * exercised here because the test application has no such file, so
     * this is also the "nothing to load" case for the default path.
     */
    public function testLoadDefinitionsUsesTheDefaultPathWhenNoneIsGiven(): void
    {
        // Act
        $loaded = Hreflang::loadDefinitions();

        // Assert
        $this->assertFalse($loaded);
    }

    /**
     * An existing file is required and its registrations take effect;
     * loadDefinitions() is idempotent within a process — a second call does
     * not require() the file again (which would re-register and, for a
     * callable closure, create a second distinct instance doing the same
     * thing — harmless here, but the guard is what the convention promises).
     */
    public function testLoadDefinitionsRequiresTheFileOnce(): void
    {
        // Arrange
        $tmpFile = tempnam(sys_get_temp_dir(), 'hreflang_test_') . '.php';
        file_put_contents($tmpFile, <<<'PHP'
<?php
\Pramnos\Html\Hreflang::singleLanguage('/oroi-xrisis');
PHP);

        // Act
        $first  = Hreflang::loadDefinitions($tmpFile);
        unlink($tmpFile); // if loadDefinitions() required it again, this would throw
        $second = Hreflang::loadDefinitions($tmpFile);

        // Assert
        $this->assertTrue($first);
        $this->assertTrue($second);
        $this->assertTrue(Hreflang::isSingleLanguage('/oroi-xrisis'));
    }

    /**
     * hasDefinitions() is true when any of the three registration kinds has
     * something in it — not only groups.
     */
    public function testHasDefinitionsIsTrueForAnyRegistrationKind(): void
    {
        // Assert — nothing registered yet
        $this->assertFalse(Hreflang::hasDefinitions());

        // Act
        Hreflang::singleLanguage('/oroi-xrisis');

        // Assert
        $this->assertTrue(Hreflang::hasDefinitions());
    }
}
