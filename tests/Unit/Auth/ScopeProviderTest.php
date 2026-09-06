<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Scopes;
use Pramnos\Mcp\McpServiceProvider;
use Pramnos\Mcp\PublicRegistry;

/**
 * An installation's own scope catalogue, answering the framework's own questions.
 *
 * `Scopes` has always said to subclass it, and every internal call is `static::`,
 * so overriding `getScopes()` gave the subclass's own callers a consistent view.
 * Ten sites in the framework then wrote `Scopes::` literally — and those are the
 * ones that face outward: `scopes_supported` in the four `/.well-known/`
 * documents, the consent screen's descriptions, the OAuth2 `ScopeRepository`,
 * what `init` offers.
 *
 * So an installation could define forty-four scopes, publish fifteen, render a
 * consent screen with no description beside the grant the user is being asked
 * for, and have its own identifiers refused as unknown. Reported by an
 * application carrying a hand-maintained copy of the same catalogue in two
 * repositories against one database — the duplication the subclass is supposed
 * to end.
 */
#[CoversClass(Scopes::class)]
class ScopeProviderTest extends TestCase
{
    protected function setUp(): void
    {
        Scopes::resetProvider();
        PublicRegistry::reset();
    }

    protected function tearDown(): void
    {
        Scopes::resetProvider();
        PublicRegistry::reset();
        parent::tearDown();
    }

    /**
     * With nothing registered, the framework answers for itself.
     *
     * The control, and the guarantee for every installation that never reads any of
     * this: the default has to be exactly what it was.
     */
    public function testWithNoProviderTheFrameworkAnswers(): void
    {
        // Act
        $names = array_keys(Scopes::getScopeDescriptions());

        // Assert
        $this->assertSame(Scopes::class, Scopes::provider());
        $this->assertContains('openid', $names);
        $this->assertContains('system:admin', $names);
    }

    /**
     * A registered subclass answers the framework's own call, not just its own.
     *
     * `Scopes::hasInvalidScopes()` is what the authorize endpoint and the OAuth2
     * `ScopeRepository` ask. Before the resolution point it said an installation's
     * own identifier was unknown — a client refused the scope its own row grants.
     */
    public function testARegisteredSubclassAnswersTheFrameworksOwnCalls(): void
    {
        // Arrange
        Scopes::loadFromConfig(array('provider' => MergingScopes::class));

        // Act
        $names = array_keys(Scopes::getScopeDescriptions());
        [$invalid] = Scopes::hasInvalidScopes('wcm:devices_read');

        // Assert
        $this->assertSame(MergingScopes::class, Scopes::provider());
        $this->assertContains('wcm:devices_read', $names);
        $this->assertFalse($invalid, 'the installation\'s own scope was refused as unknown');
    }

    /**
     * Merging keeps the standard scopes, because the subclass asked for them.
     *
     * `parent::getScopes()` is how a subclass says "the framework's, plus mine", and
     * it must not recurse — the delegation in the base sees `static::class` equal to
     * the provider and takes the framework branch.
     */
    public function testMergingKeepsTheStandardScopes(): void
    {
        // Arrange
        Scopes::loadFromConfig(array('provider' => MergingScopes::class));

        // Act
        $names = array_keys(Scopes::getScopeDescriptions());

        // Assert
        $this->assertContains('openid', $names);
        $this->assertContains('email', $names);
        $this->assertContains('wcm:devices_read', $names);
    }

    /**
     * Replacing drops them, because the subclass did not ask for them.
     *
     * Asserted rather than prevented. A catalogue is not reliably a superset, so the
     * framework cannot pick; what it can do is make the consequence visible, which
     * is that an installation replacing the list without declaring `openid` has no
     * `openid` and its OIDC requests will say so.
     */
    public function testReplacingDropsTheStandardScopesAndSaysSo(): void
    {
        // Arrange
        Scopes::loadFromConfig(array('provider' => ReplacingScopes::class));

        // Act
        $names = array_keys(Scopes::getScopeDescriptions());

        // Assert
        $this->assertSame(array('customer_export'), $names);
        $this->assertNotContains('openid', $names);
    }

    /**
     * The MCP scopes survive a replacing subclass, because they are not declared
     * anywhere for it to have known about.
     *
     * They are derived from the tools `PublicRegistry` actually offers, so a
     * catalogue written before the endpoint existed must not be able to make it
     * ungrantable — a token could not then be issued for a tool that is registered
     * and answering.
     */
    public function testTheMcpScopesAreAppendedWhicheverWayTheCatalogueGoes(): void
    {
        // Arrange
        McpServiceProvider::offerDiagnostics(null);
        Scopes::loadFromConfig(array('provider' => ReplacingScopes::class));

        // Act
        $names = array_keys(Scopes::getScopeDescriptions());
        [$invalid] = Scopes::hasInvalidScopes('mcp:logs');

        // Assert
        $this->assertContains('customer_export', $names);
        $this->assertContains('mcp:logs', $names);
        $this->assertFalse($invalid);
    }

    /**
     * Inheritance resolves through the registered catalogue too.
     *
     * `PublicRegistry::permits()` asks `resolveInheritedScopes()`, so a subclass whose
     * scopes inherit from each other has to be the one answering — otherwise a token
     * holding a parent scope reaches nothing.
     */
    public function testInheritanceResolvesThroughTheRegisteredCatalogue(): void
    {
        // Arrange
        Scopes::loadFromConfig(array('provider' => MergingScopes::class));

        // Act
        $resolved = Scopes::resolveInheritedScopes('wcm:devices_write');

        // Assert
        $this->assertContains('wcm:devices_read', $resolved);
        $this->assertContains('wcm:devices_write', $resolved);
    }

    /**
     * A class that is not a `Scopes` subclass is refused loudly.
     *
     * Dropping it quietly would leave the installation publishing the framework's
     * list while its own sat in a file nothing read — the exact failure this
     * resolution point exists to end, reached by a different route and just as
     * invisible.
     */
    public function testAClassThatCannotAnswerIsRefused(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is not a .*Scopes subclass/');

        // Act
        Scopes::loadFromConfig(array('provider' => \stdClass::class));
    }

    /**
     * An absent or empty block leaves the framework answering, so an application that
     * declares nothing is unaffected.
     */
    public function testAnEmptyConfigChangesNothing(): void
    {
        // Act
        Scopes::loadFromConfig(array());
        Scopes::loadFromConfig(array('provider' => ''));
        Scopes::loadFromConfig(array('provider' => null));

        // Assert
        $this->assertSame(Scopes::class, Scopes::provider());
        $this->assertContains('openid', array_keys(Scopes::getScopeDescriptions()));
    }

    /**
     * Defaults come from the registered catalogue, which is what
     * `addDefaultScopesToToken()` merges into every token it issues.
     */
    public function testDefaultsComeFromTheRegisteredCatalogue(): void
    {
        // Arrange
        Scopes::loadFromConfig(array('provider' => ReplacingScopes::class));

        // Act
        $defaults = Scopes::getDefaultScopes();

        // Assert — the replacing catalogue declares none, so there are none
        $this->assertSame(array(), $defaults);
        $this->assertSame('mcp:x', Scopes::addDefaultScopesToToken('mcp:x'));
    }
}

/** An installation whose catalogue extends the framework's. */
class MergingScopes extends Scopes
{
    public static function getScopes(): array
    {
        return parent::getScopes() + array(
            'Devices' => array(
                'wcm:devices_read' => array(
                    'description' => 'Read device readings.',
                    'is_default'  => false,
                    'inherits'    => array(),
                ),
                'wcm:devices_write' => array(
                    'description' => 'Change a device.',
                    'is_default'  => false,
                    'inherits'    => array('wcm:devices_read'),
                ),
            ),
        );
    }
}

/** An installation that has curated its own vocabulary and wants only that. */
class ReplacingScopes extends Scopes
{
    public static function getScopes(): array
    {
        return array(
            'Exports' => array(
                'customer_export' => array(
                    'description' => 'Export customer records.',
                    'is_default'  => false,
                    'inherits'    => array(),
                ),
            ),
        );
    }
}
