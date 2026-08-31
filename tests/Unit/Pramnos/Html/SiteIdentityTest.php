<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Html\SiteIdentity;

/**
 * What the site says it is, to a machine.
 *
 * Every page carried a `BreadcrumbList` and nothing else — the shape of a page's position in a
 * hierarchy, with no statement of what the hierarchy belonged to. `addStructuredData()` existed
 * the whole time and the framework called it from nowhere.
 *
 * Structured data is a set of assertions, so the tests that matter here are the ones about what
 * it refuses to assert. An empty field is not a blank; it is a claim that the thing is absent.
 */
#[CoversClass(SiteIdentity::class)]
class SiteIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        Settings::setSetting('sitename', 'Example');
        Settings::setSetting('sitelogo', '');
        Settings::setSetting('social_profiles', '');
    }

    /**
     * Both objects, and both named.
     *
     * `Organization` answers who publishes this; `WebSite` answers what the site is as a thing
     * on the web. They are different questions and a knowledge graph wants both.
     */
    public function testItDescribesThePublisherAndTheSite(): void
    {
        // Act
        $graph = SiteIdentity::graph();

        // Assert
        $types = array_column($graph['@graph'], '@type');
        $this->assertSame(['Organization', 'WebSite'], $types);
        $this->assertSame('Example', $graph['@graph'][0]['name']);
        $this->assertSame('Example', $graph['@graph'][1]['name']);
    }

    /**
     * No name, nothing said.
     *
     * A name is the one field both objects need. An `Organization` without one asserts that
     * something publishes this site and refuses to say what, which is worse than silence.
     */
    public function testWithoutANameItSaysNothingAtAll(): void
    {
        // Arrange
        Settings::setSetting('sitename', '');

        // Assert
        $this->assertSame([], SiteIdentity::graph());
        $this->assertSame('', SiteIdentity::jsonLd());
    }

    /**
     * An unconfigured logo is absent, not empty.
     *
     * `"logo": ""` states that this organisation has no logo. That is not what an unset setting
     * meant, and it is the same mistake the head renderer was making with its meta tags.
     */
    public function testAnUnsetLogoIsNotAssertedAsEmpty(): void
    {
        // Act
        $organization = SiteIdentity::graph()['@graph'][0];

        // Assert
        $this->assertArrayNotHasKey('logo', $organization);
    }

    /**
     * A relative logo path is made absolute.
     *
     * The value is read away from the page that carried it, so a path relative to nothing is a
     * path to nothing.
     */
    public function testARelativeLogoIsMadeAbsolute(): void
    {
        // Arrange
        Settings::setSetting('sitelogo', '/assets/logo.png');

        // Act
        $organization = SiteIdentity::graph()['@graph'][0];

        // Assert
        $this->assertStringEndsWith('/assets/logo.png', $organization['logo']);
        $this->assertStringNotContainsString('//assets', $organization['logo'],
            'the site url and the path must not both bring a slash');
    }

    /**
     * A malformed social profile is dropped rather than published.
     *
     * `sameAs` is how an organisation is joined to the profiles describing it elsewhere, and a
     * malformed entry does not fail loudly — it quietly stops the object matching the entity it
     * names.
     */
    public function testAMalformedProfileIsDropped(): void
    {
        // Arrange
        Settings::setSetting(
            'social_profiles',
            "https://example.com/us\nnot a url\nhttps://example.com/us"
        );

        // Act
        $organization = SiteIdentity::graph()['@graph'][0];

        // Assert
        $this->assertSame(['https://example.com/us'], $organization['sameAs'],
            'invalid dropped, and the duplicate collapsed');
    }

    /**
     * A `SearchAction` is offered only where there is something to search.
     *
     * One that leads to a page returning nothing is worse than none: it is offered to a reader,
     * tried once, and teaches them the site is broken.
     */
    public function testTheSearchActionFollowsTheSearchRegistry(): void
    {
        // Arrange
        \Pramnos\Search\Registry::reset();

        // Act
        $website = SiteIdentity::graph()['@graph'][1];

        // Assert
        $this->assertArrayNotHasKey('potentialAction', $website);
    }

    /**
     * The rendered block is a script tag with the framework's own escaping.
     */
    public function testItRendersThroughTheEscapingHelper(): void
    {
        // Act
        $html = SiteIdentity::jsonLd();

        // Assert
        $this->assertStringStartsWith('<script type="application/ld+json">', $html);
        $this->assertStringContainsString('"@context"', $html);
    }
}
