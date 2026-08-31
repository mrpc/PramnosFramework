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
     * An absolute logo URL is left as it is.
     *
     * The counterpart of making a relative one absolute: a configured `https://cdn…/logo.png` must
     * not have the site root prepended to it.
     */
    public function testAnAbsoluteLogoIsNotRewritten(): void
    {
        // Arrange
        Settings::setSetting('sitelogo', 'https://cdn.example.com/logo.png');

        // Assert
        $this->assertSame(
            'https://cdn.example.com/logo.png',
            SiteIdentity::graph()['@graph'][0]['logo']
        );
    }

    /**
     * Social profiles are read from the newline- or comma-separated text a settings field gives.
     *
     * Asserted in that form only, because that is the form this actually arrives in: `setSetting`
     * writes a value to the database when it is a string, and a settings screen gives a textarea.
     * The array branch in `sameAs()` is defensive — it costs two lines and covers a value handed
     * in from a config file rather than the settings table — and is deliberately not asserted
     * here, because a test for a path I cannot demonstrate is a test that only looks like one.
     */
    public function testProfilesAreReadFromSeparatedText(): void
    {
        // Arrange
        Settings::setSetting(
            'social_profiles',
            "https://example.com/a\nhttps://example.com/b, https://example.com/c"
        );

        // Act
        $sameAs = SiteIdentity::graph()['@graph'][0]['sameAs'] ?? [];

        // Assert
        $this->assertSame(
            ['https://example.com/a', 'https://example.com/b', 'https://example.com/c'],
            $sameAs
        );
    }

    /**
     * A profiles setting that is neither text nor a list yields nothing.
     *
     * It is read from configuration, so it can be anything a config file contains. The answer to
     * "I cannot read this" is no `sameAs`, not a malformed one — a broken entry does not fail
     * loudly, it quietly stops the organisation matching the entity it names.
     */
    public function testAnUnreadableProfilesSettingYieldsNothing(): void
    {
        // Arrange
        Settings::setSetting('social_profiles', 42);

        // Assert
        $this->assertArrayNotHasKey('sameAs', SiteIdentity::graph()['@graph'][0]);
    }

    /**
     * No profiles configured means no `sameAs` at all.
     *
     * An empty `sameAs` asserts that this organisation appears nowhere else, which is not what an
     * unset setting meant.
     */
    public function testNoProfilesMeansNoSameAsKey(): void
    {
        // Assert
        $this->assertArrayNotHasKey('sameAs', SiteIdentity::graph()['@graph'][0]);
    }

    /**
     * A `SearchAction` appears once the registry has a source.
     *
     * The other half of the existing test: it asserts absence with no sources, and absence is easy
     * to achieve by never emitting anything.
     */
    public function testTheSearchActionAppearsWhenThereIsSomethingToSearch(): void
    {
        // Arrange
        \Pramnos\Search\Registry::reset();
        \Pramnos\Search\Registry::register('Users', \Pramnos\User\User::class, [
            'display' => ['username'],
            'url'     => '/admin/users/:id',
        ]);

        try {
            // Act
            $website = SiteIdentity::graph()['@graph'][1];

            // Assert
            $this->assertArrayHasKey('potentialAction', $website);
            $this->assertSame('SearchAction', $website['potentialAction']['@type']);
            $this->assertStringContainsString(
                '{search_term_string}',
                $website['potentialAction']['target']['urlTemplate']
            );
        } finally {
            \Pramnos\Search\Registry::reset();
        }
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
