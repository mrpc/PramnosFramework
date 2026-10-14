<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Assist;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Assist\Help;
use Pramnos\Assist\HelpBook;
use Pramnos\Assist\HelpGate;
use Pramnos\Assist\HelpReader;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\Tools\SiteHelpTool;

/**
 * The site's help, gated by what a reader's plan includes; one page for several screens; pages in
 * an order of their own; and the site's help as a tool for the application's assistant.
 *
 * The gate is the part that must not leak: a page or a block the plan does not include must be
 * absent from every path — list, search, page, a screen's page, the assistant — and absent too
 * when nothing can answer, because a requirement nobody can check is not a reason to show it.
 */
#[CoversClass(Help::class)]
#[CoversClass(HelpBook::class)]
#[CoversClass(HelpReader::class)]
#[CoversClass(SiteHelpTool::class)]
class HelpGateTest extends TestCase
{
    private string $base = '';

    /** The plan of organisation 7: what the test gate allows it. */
    private const PLAN = ['chat'];

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/pf-helpgate-' . bin2hex(random_bytes(4));
        $files = [
            '/editorial.md' => "---\ntitle: Editorial\norder: 2\nsection: Writing\nscreens: [editorial, draft, sources, series]\nrequires: editorial\n---\nDrafts, sources and series.",
            '/chat.md'      => "---\norder: 1\nsection: Writing\nscreens:\n  - assistant\n  - Draft\n---\n# Chat\n\nAsk anything.\n\n<!-- requires: chat -->\nScheduled questions run nightly.\n<!-- /requires -->\n\n<!-- requires: routines -->\nRoutines keep a zebra.\n<!-- /requires -->\n\nThe end.",
            '/sources.md'   => "# Sources\n\nA page of its own.",
            '/broken.md'    => "# Broken\n\nBefore.\n<!-- requires: routines -->\nAfter, never closed.",
            '/assistant.md' => "---\nrequires: [chat, routines]\n---\n# Assistant\n\nBoth.",
        ];
        mkdir($this->base, 0777, true);
        foreach ($files as $file => $content) {
            file_put_contents($this->base . $file, $content);
        }
        Help::useSiteDirectories([$this->base]);
        Help::useSiteGate(new class (self::PLAN) implements HelpGate {
            /** @var list<string> */
            public array $asked = [];

            /** @param list<string> $plan */
            public function __construct(private array $plan)
            {
            }

            public function allows(string $requirement, HelpReader $reader): bool
            {
                $this->asked[] = $requirement;
                if ($requirement === 'boom') {
                    throw new \RuntimeException('no plan service');
                }

                return $reader->organizationId === 7 && in_array($requirement, $this->plan, true);
            }
        });
        \Pramnos\Http\RequestIdentity::reset();
    }

    protected function tearDown(): void
    {
        Help::useSiteDirectories(null);
        Help::useSiteGate(null);
        \Pramnos\Http\RequestIdentity::reset();
        \Pramnos\Auth\OrganizationScope::reset();
        foreach (glob($this->base . '/*.md') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->base);
    }

    /** Organisation 7's view, and a guest's. */
    private function views(): array
    {
        return [Help::site()->for(new HelpReader(5, 7)), Help::site()->for(HelpReader::guest())];
    }

    /**
     * A page whose requirement is refused is absent from the list, the search, a page by name and
     * a screen's page — for organisation 7 and for a guest — and every requirement must hold.
     */
    public function testARefusedPageIsNowhere(): void
    {
        // Arrange
        [$member, $guest] = $this->views();

        // Act / Assert
        // By section, then `order:`, then title: the unsectioned first. Editorial is refused.
        $this->assertSame(['broken', 'sources', 'chat'], array_column($member->all(), 'slug'));
        $this->assertNull($member->page('editorial'));
        $this->assertNull($member->page('assistant'), 'chat holds, routines does not: all must');
        $this->assertSame([], $member->search('series'), 'its words do not find it');
        $this->assertSame('sources', $member->forScreen('sources')['slug']);
        $this->assertNotNull($guest->page('chat'), 'a page without requires is there for a guest too');
        $this->assertSame([], array_column($guest->search('editorial drafts'), 'slug'));
    }

    /**
     * A block is cut where it is refused and unwrapped where it is allowed; an unclosed one is cut
     * to the end; and its words do not make the page match.
     */
    public function testBlocksAreCutOrUnwrapped(): void
    {
        // Arrange
        [$member, $guest] = $this->views();

        // Act
        $mine   = $member->page('chat')['body'];
        $theirs = $guest->page('chat')['body'];
        $broken = $member->page('broken')['body'];

        // Assert
        $this->assertStringContainsString("Ask anything.\n\nScheduled questions run nightly.\n\n\nThe end.", $mine);
        $this->assertStringNotContainsString('<!--', $mine, 'the markers go either way');
        $this->assertStringNotContainsString('zebra', $mine);
        $this->assertStringNotContainsString('Scheduled', $theirs, 'a guest has no plan');
        $this->assertSame("# Broken\n\nBefore.", $broken);
        $this->assertSame([], $member->search('zebra'));
        $this->assertSame('chat', $member->search('scheduled nightly')[0]['slug']);
        $this->assertSame([], $guest->search('scheduled nightly'));
    }

    /**
     * Fail closed: with no gate every requirement is refused, a gate that throws refuses and is
     * logged, and the administration's pages are not gated at all.
     */
    public function testWithoutAnAnswerTheRequirementIsRefused(): void
    {
        // Arrange
        Help::useSiteGate(null);
        $app  = \Pramnos\Application\Application::getInstance();
        $info = $app->applicationInfo;

        try {
            // Act — none configured, then one that is not a gate
            $none = Help::site()->for(new HelpReader(5, 7));
            $app->applicationInfo['help'] = ['site' => ['gate' => \stdClass::class]];
            Help::useSiteGate(null);
            $wrong = Help::site()->for(new HelpReader(5, 7));
            $this->assertNull($none->page('editorial'));
            $this->assertStringNotContainsString('Scheduled', $none->page('chat')['body']);
            $this->assertNull($wrong->page('assistant'));

            // A gate named in app.php is built from it
            $app->applicationInfo['help'] = ['site' => ['gate' => OpenGate::class]];
            Help::useSiteGate(null);
            $this->assertInstanceOf(OpenGate::class, Help::siteGate());
            $this->assertSame('editorial', Help::site()->for(HelpReader::guest())->page('editorial')['slug']);
        } finally {
            $app->applicationInfo = $info;
        }

        // A gate that throws
        Help::useSiteGate(new class implements HelpGate {
            public function allows(string $requirement, HelpReader $reader): bool
            {
                throw new \RuntimeException('down');
            }
        });
        $this->assertNull(Help::site()->for(new HelpReader(5, 7))->page('editorial'));

        // The administration's level ignores `requires`
        Help::useDirectories([$this->base]);
        try {
            $this->assertSame('editorial', Help::admin()->page('editorial')['slug']);
        } finally {
            Help::useDirectories(null);
        }
    }

    /** The gate is asked once per requirement per view, however many pages carry it. */
    public function testEachRequirementIsAskedOnce(): void
    {
        // Arrange
        $gate = Help::siteGate();

        // Act
        Help::site()->for(new HelpReader(5, 7))->all();

        // Assert
        $counts = array_count_values($gate->asked);
        $this->assertSame(1, $counts['chat']);
        $this->assertSame(1, $counts['routines']);
    }

    /**
     * One page for several screens: a page named after the screen first, then one that lists it
     * — the first by name, among those the reader may see.
     */
    public function testOnePageForSeveralScreens(): void
    {
        // Arrange
        Help::useSiteGate(new OpenGate());
        $book = Help::site()->for(HelpReader::guest());

        // Act / Assert
        $this->assertSame('sources', $book->forScreen('sources')['slug'], 'its own page wins over the area\'s list');
        $this->assertSame('editorial', $book->forScreen('series')['slug']);
        $this->assertSame('chat', $book->forScreen('draft')['slug'], 'two claim it: the first by name, case folded');
        $this->assertSame('assistant', $book->forScreen('assistant', 'edit')['slug'], 'named after the screen, before chat\'s claim');
        $this->assertNull($book->forScreen('nowhere'));
        $this->assertSame(['editorial', 'draft', 'sources', 'series'], $book->page('editorial')['screens']);
        $this->assertSame(2, $book->page('editorial')['order']);
        $this->assertNull($book->page('sources')['order']);

        // Refused, a page does not claim
        Help::useSiteGate(null);
        $this->assertSame('chat', Help::site()->for(HelpReader::guest())->forScreen('draft')['slug']);
        $this->assertNull(Help::site()->for(HelpReader::guest())->forScreen('series'));
    }

    /**
     * The reader of a request is its sealed user and the organisation the resolver names; a
     * resolver that fails is no organisation. A scope's reader is its person and organisation.
     */
    public function testWhoIsReading(): void
    {
        // Arrange
        \Pramnos\Http\RequestIdentity::seal((object) ['userid' => 12, 'usertype' => 1], 'test');
        \Pramnos\Auth\OrganizationScope::resolveWith(static fn (): int => 7);

        // Act
        $request = HelpReader::fromRequest();
        \Pramnos\Auth\OrganizationScope::resolveWith(static fn (): string => 'nonsense');
        $failing = HelpReader::fromRequest();
        $scoped  = HelpReader::fromScope(new Scope(12, 7));
        \Pramnos\Http\RequestIdentity::reset();
        \Pramnos\Http\RequestIdentity::seal(null, 'test');
        $nobody  = HelpReader::fromRequest();

        // Assert
        $this->assertSame([12, 7], [$request->userId, $request->organizationId]);
        $this->assertSame(12, $request->user()->userid, 'the request\'s user, as it is');
        $this->assertNull($failing->organizationId);
        $this->assertSame([12, 7], [$scoped->userId, $scoped->organizationId]);
        $this->assertSame(7, $scoped->scope->organizationId);
        $this->assertSame([null, null, null], [$nobody->userId, $nobody->organizationId, $nobody->user()]);
        $this->assertNull(HelpReader::guest()->user());
    }

    /** Without a reader given, the site's pages are the request's reader's. */
    public function testTheRequestsReaderByDefault(): void
    {
        // Arrange
        \Pramnos\Http\RequestIdentity::seal((object) ['userid' => 12, 'usertype' => 1], 'test');
        \Pramnos\Auth\OrganizationScope::resolveWith(static fn (): int => 7);

        // Act / Assert
        $this->assertStringContainsString('Scheduled', Help::site()->page('chat')['body']);
        \Pramnos\Auth\OrganizationScope::reset();
        $this->assertStringNotContainsString('Scheduled', Help::site()->page('chat')['body']);
    }

    /**
     * The assistant's tool: the best page for a question with the next best by name, a page by
     * name, nothing for what is refused or missing — as the scope's organisation reads it.
     */
    public function testTheAssistantsTool(): void
    {
        // Arrange
        $tool    = new SiteHelpTool(new Scope(5, 7), static fn (string $slug): string => '/app/help?p=' . $slug);
        $outside = new SiteHelpTool(new Scope(5, 9));

        // Act
        $asked   = $tool->execute(['question' => 'scheduled questions']);
        $named   = $tool->execute(['page' => 'sources']);
        $refused = $tool->execute(['page' => 'editorial']);
        $none    = $tool->execute(['question' => 'xylophone']);
        $theirs  = $outside->execute(['page' => 'chat']);

        // Assert
        $this->assertSame('help', $tool->name());
        $this->assertArrayHasKey('question', $tool->inputSchema()['properties']);
        $this->assertStringContainsString('One page comes back', $tool->description());
        $this->assertSame('chat', $asked['page']);
        $this->assertSame('/app/help?p=chat', $asked['link']);
        $this->assertStringContainsString('Scheduled questions run nightly.', $asked['text']);
        $this->assertSame('sources', $named['page']);
        $this->assertArrayNotHasKey('others', $named);
        $this->assertSame('There is no help page called editorial.', $refused);
        $this->assertSame('No help page answers that.', $none);
        $this->assertStringNotContainsString('Scheduled', $theirs['text'], 'another organisation\'s plan');
        $this->assertStringEndsWith('Help/page?p=chat', $theirs['link'], 'the site\'s address by default');
    }

    /**
     * A long page is cut so that the whole answer, encoded, fits in one tool result — newlines and
     * quotes take two characters there — and the next best come by name.
     */
    public function testALongPageFitsInOneResult(): void
    {
        // Arrange
        file_put_contents($this->base . '/long.md', "# Long\n\nlong long\n" . str_repeat("\"q\"\n", 9000));
        file_put_contents($this->base . '/longer.md', "# Longer\n\nlong");
        Help::forget();
        $tool = SiteHelpTool::tool(new Scope(5, 7));

        // Act
        $result = ($tool['run'])(['question' => 'long']);

        // Assert
        $this->assertLessThanOrEqual(\Pramnos\Llm\Client::RESULT_LIMIT, mb_strlen($result));
        $decoded = json_decode($result, true);
        $this->assertSame('long', $decoded['page']);
        $this->assertStringEndsWith('[…]', $decoded['text']);
        $this->assertSame([['page' => 'longer', 'title' => 'Longer']], $decoded['others']);
        $this->assertArrayHasKey('question', $tool['parameters']['properties']);
    }
}

/** A gate that allows everything. */
final class OpenGate implements HelpGate
{
    public function allows(string $requirement, HelpReader $reader): bool
    {
        return true;
    }
}
