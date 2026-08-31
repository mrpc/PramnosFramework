<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\MachineReadable;
use Pramnos\Document\Document;
use Pramnos\Framework\Factory;

/**
 * What the site tells a machine that arrives without being sent.
 *
 * Neither file existed, and absence is not neutrality: it means each visiting crawler decides for
 * itself what it may do here, and for the AI crawlers that is a decision nobody at this
 * installation ever made.
 */
#[CoversClass(MachineReadable::class)]
class MachineReadableTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        Document::reset();
    }

    private function render(string $action): string
    {
        (new MachineReadable(null))->{$action}();

        return (string) Factory::getDocument('raw')->render();
    }

    /**
     * Every AI crawler is named and answered.
     *
     * Naming them is the point. `Google-Extended` opts a site out of model training while leaving
     * Search alone — a distinction a site cannot express by saying nothing, and one that silently
     * defaults to whatever the crawler prefers.
     */
    public function testEveryAiCrawlerGetsAnExplicitAnswer(): void
    {
        // Act
        $robots = $this->render('robots');

        // Assert
        foreach (MachineReadable::AI_AGENTS as $agent) {
            $this->assertStringContainsString('User-agent: ' . $agent, $robots);
        }
    }

    /**
     * The default is allow, because a framework must not pick a site's licensing posture.
     *
     * It must make the decision visible and settable, which is what absence never did.
     */
    public function testTheDefaultIsAllowAndItIsSettable(): void
    {
        // Act
        $allowed = $this->render('robots');

        \Pramnos\Application\Settings::setSetting('ai_crawler_policy', 'disallow');
        Document::reset();
        $refused = $this->render('robots');

        \Pramnos\Application\Settings::setSetting('ai_crawler_policy', '');

        // Assert
        $this->assertStringContainsString("User-agent: GPTBot\nAllow: /", $allowed);
        $this->assertStringContainsString("User-agent: GPTBot\nDisallow: /", $refused);
    }

    /**
     * The paths a crawler must not fetch are disallowed, not merely noindexed.
     *
     * `noindex` keeps a page out of an index after it has been fetched; `Disallow` keeps it from
     * being fetched. On an authentication server every one of these costs a session, sends mail,
     * or answers differently per visitor — a crawler walking them generates side effects.
     */
    public function testTheSideEffectPathsAreDisallowed(): void
    {
        // Act
        $robots = $this->render('robots');

        // Assert
        foreach (['/admin/', '/oauth/', '/account/', '/devpanel/'] as $path) {
            $this->assertStringContainsString('Disallow: ' . $path, $robots);
        }
    }

    /**
     * `llms.txt` is markdown and leads with what the site is.
     *
     * A model arriving cold otherwise guesses, and guessing is how a site gets described wrongly
     * and confidently.
     */
    public function testLlmsTxtSaysWhatTheSiteIs(): void
    {
        // Arrange
        \Pramnos\Application\Settings::setSetting('sitename', 'Example');

        // Act
        $llms = $this->render('llms');

        // Assert
        $this->assertStringStartsWith('# Example', $llms);
        $this->assertStringContainsString('/docs', $llms);
    }

    /**
     * The `Sitemap:` line is absolute or absent.
     *
     * It is the one line in the file derived from the installation's own URL — the reason this is
     * generated rather than shipped, since a static robots.txt in a scaffold carries somebody
     * else's domain.
     *
     * And the protocol requires an **absolute** URL there. With no site URL configured the line is
     * omitted rather than emitted relative: `Sitemap: /sitemap.xml` is not a smaller version of
     * the right answer, it is an invalid directive a crawler discards along with any trust in the
     * rest of the file.
     */
    public function testTheSitemapLineIsAbsoluteOrAbsent(): void
    {
        // Act
        $robots = $this->render('robots');
        $url    = rtrim(defined('sURL') ? (string) sURL : '', '/');

        // Assert
        if ($url === '') {
            $this->assertStringNotContainsString('Sitemap:', $robots,
                'a relative Sitemap directive is invalid — omit it instead');

            return;
        }

        $this->assertStringContainsString('Sitemap: ' . $url . '/sitemap.xml', $robots);
    }

    /**
     * `llms.txt` carries the site's description when there is one.
     *
     * And omits the line entirely when there is not — the same absent-is-not-empty rule the head
     * renderer follows. A blockquote with nothing in it reads as a site with nothing to say.
     */
    public function testTheDescriptionAppearsOnlyWhenSet(): void
    {
        // Arrange
        \Pramnos\Application\Settings::setSetting('sitename', 'Example');
        \Pramnos\Application\Settings::setSetting('sitedescription', '');
        $without = $this->render('llms');

        \Pramnos\Application\Settings::setSetting('sitedescription', 'Single sign-on for everything.');
        Document::reset();
        $with = $this->render('llms');

        \Pramnos\Application\Settings::setSetting('sitedescription', '');

        // Assert
        $this->assertStringNotContainsString('> ', $without);
        $this->assertStringContainsString('> Single sign-on for everything.', $with);
    }

    /**
     * And it lists what is searchable, when anything is.
     *
     * A model reading this learns what the site holds without guessing from the URL structure.
     */
    public function testTheSearchableSourcesAreListedWhenThereAreAny(): void
    {
        // Arrange
        \Pramnos\Search\Registry::reset();
        $without = $this->render('llms');

        \Pramnos\Search\Registry::register('Users', \Pramnos\User\User::class, [
            'display' => ['username'],
            'url'     => '/admin/users/:id',
        ]);
        Document::reset();
        $with = $this->render('llms');

        \Pramnos\Search\Registry::reset();

        // Assert
        $this->assertStringNotContainsString('## Search', $without);
        $this->assertStringContainsString('## Search', $with);
        $this->assertStringContainsString('Users', $with);
    }

    /**
     * The MCP endpoint is announced there, or nowhere.
     *
     * The one honest link between the two halves of this work: a model reading this learns the
     * site has tools and where to authenticate. Without it the endpoint is a service nobody
     * discovers — machinery complete and unreachable, which is the failure this framework keeps
     * producing.
     */
    public function testTheMcpEndpointIsAnnouncedOnlyWhenSomethingIsOffered(): void
    {
        // Arrange — nothing offered
        \Pramnos\Mcp\PublicRegistry::reset();
        $silent = $this->render('llms');

        \Pramnos\Mcp\PublicRegistry::offer(
            name: 'thing', scope: 'user', description: 'A thing.',
            input: [], handler: static fn (): int => 1,
        );
        Document::reset();
        $announced = $this->render('llms');

        \Pramnos\Mcp\PublicRegistry::reset();

        // Assert
        $this->assertStringNotContainsString('/mcp', $silent,
            'an endpoint offering nothing is not worth pointing a model at');
        $this->assertStringContainsString('/mcp', $announced);
        $this->assertStringContainsString('oauth-protected-resource', $announced);
    }
}
