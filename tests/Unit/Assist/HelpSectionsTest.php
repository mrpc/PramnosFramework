<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Assist;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Assist\Help;
use Pramnos\Assist\HelpBook;
use Pramnos\Llm\Analyst\Tools\HelpTool;

/**
 * The help searched and read by section, not by page.
 *
 * Why: a page was the unit, and the assistant was given its first 6,000 characters. A quarter of
 * an installation's help lay past that on long pages and never reached the model, and a page of
 * 18,000 characters won nearly every question for being long. Now each `##` (and each `###` of a
 * long one) is searched on its own, scored by BM25 against its length, and an answer is the
 * section that answers, whole, with a link to its heading.
 */
#[CoversClass(Help::class)]
#[CoversClass(HelpBook::class)]
#[CoversClass(HelpTool::class)]
#[CoversClass(\Pramnos\Console\Commands\HelpEval::class)]
final class HelpSectionsTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/pf-help-sections-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/billing', 0777, true);
        // A long page: the section that answers lies past the first 6,000 characters.
        $filler = str_repeat("Orders are listed by date and can be filtered by status.\n", 120);
        file_put_contents($this->base . '/billing.md', "---\ntitle: Billing\nsection: Money\n---\n# Billing\n\nHow money comes in.\n\n## Orders\n\n" . $filler
            . "\n## Gateways\n<!-- questions: Which card processors are there? | Πώς συνδέω το Stripe; -->\n\nA gateway takes card payments. Stripe and PayPal are supported.\n");
        // A short page that mentions a gateway in passing.
        file_put_contents($this->base . '/billing/invoices.md', "---\nsection: Money\n---\n# Invoices\n\nAn invoice is issued after the gateway confirms.\n");
        file_put_contents($this->base . '/people.md', "---\nsection: People\n---\n# People\n\n## Phones\n\nA member's phone is on their card.\n");
        file_put_contents($this->base . '/_synonyms.php', "<?php return [['phone', 'mobile', 'contact']];");
        Help::useDirectories([$this->base]);
    }

    protected function tearDown(): void
    {
        Help::useDirectories(null);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->base);
    }

    /**
     * A page's body as sections: the opening, each `##`, and — for a `##` longer than the split
     * — its lead and each `###` apart; a short one keeps its `###` inside. Questions under a
     * heading are taken out of the text, fenced code is never split, and a heading that comes
     * twice gets `-2`, as its id on the page does.
     */
    public function testAPageIsCutIntoSections(): void
    {
        // Arrange
        $long = str_repeat('word ', 700);
        $body = "# Title\n\nOpening.\n\n## Short\n\n### Inside\n\nkept\n\n## Long\n\nlead\n\n### One\n\n" . $long
            . "\n\n### Two\n<!-- questions: a b c | d e f -->\n\nend\n\n```\n## not a heading\n```\n\n## Short\n\nagain";

        // Act
        $sections = Help::sections($body);
        $html     = Help::html($body);

        // Assert
        $this->assertSame(['', 'Short', 'Long', 'One', 'Two', 'Short'], array_column($sections, 'heading'));
        $this->assertSame([[], ['Short'], ['Long'], ['Long', 'One'], ['Long', 'Two'], ['Short']], array_column($sections, 'trail'));
        $this->assertStringContainsString('### Inside', $sections[1]['text'], 'a short section keeps its parts');
        $this->assertSame(['a b c', 'd e f'], $sections[4]['questions']);
        $this->assertStringNotContainsString('questions:', $sections[4]['text']);
        $this->assertStringContainsString('## not a heading', $sections[4]['text'], 'fenced code is not cut');
        $this->assertSame('short-2', $sections[5]['anchor']);
        // The page's ids are the sections' anchors, so a section's link lands on its heading.
        foreach (array_filter(array_column($sections, 'anchor')) as $anchor) {
            $this->assertStringContainsString('id="' . $anchor . '"', $html);
        }
        $this->assertSame('πληρωμες-gateways', Help::anchor('Πληρωμές & **Gateways**'));
        $this->assertSame('section', Help::anchor('***'));
    }

    /**
     * The section that answers is found wherever it is on its page — past the first 6,000
     * characters too — and beats a short page that mentions the word in passing; its own
     * questions put it first.
     */
    public function testTheAnsweringSectionIsFoundOnALongPage(): void
    {
        // Act
        $found  = Help::admin()->searchSections('gateway card payments');
        $listed = Help::admin()->searchSections('Which card processors are there?');
        $greek  = Help::admin()->searchSections('Πώς συνδέω το Stripe;');

        // Assert
        $this->assertSame('Billing › Gateways', $found[0]['path']);
        $this->assertSame('gateways', $found[0]['anchor']);
        $this->assertStringContainsString('Stripe and PayPal', $found[0]['text'], 'the section whole');
        $this->assertSame('gateways', $listed[0]['anchor']);
        $this->assertGreaterThan(1000, $listed[0]['score'], 'one of its questions, word for word');
        $this->assertSame('gateways', $greek[0]['anchor']);
        $this->assertSame([], Help::admin()->searchSections('the and how'), 'nothing but function words');
    }

    /**
     * BM25 holds a long section back for its length: of two sections with the word once each,
     * the short one comes first — while a section that says it many times still wins.
     */
    public function testALongSectionDoesNotWinForBeingLong(): void
    {
        // Arrange — "refund" once in the long Orders section, and once in a short page.
        $page = (string) file_get_contents($this->base . '/billing.md');
        file_put_contents($this->base . '/billing.md', str_replace("## Gateways", "A refund is possible.\n\n## Gateways", $page));
        file_put_contents($this->base . '/short.md', "# Short\n\nrefund here");
        Help::forget();

        // Act
        $once  = Help::admin()->searchSections('refund');
        $often = Help::admin()->searchSections('status');

        // Assert
        $this->assertSame('short', $once[0]['page'], 'the short section with the word first');
        $this->assertSame('Billing › Orders', $once[1]['path'], 'the long one still found');
        $this->assertSame('Billing › Orders', $often[0]['path'], 'said a hundred times, it wins');
    }

    /** A synonym brings its group in, at less weight than the word asked; a topic keeps one group. */
    public function testSynonymsAndTopics(): void
    {
        // Act
        $mobile  = Help::admin()->searchSections('mobile');
        $inMoney = Help::admin()->searchSections('gateway', 5, 'money');
        $inPeople = Help::admin()->searchSections('gateway', 5, 'People');

        // Assert
        $this->assertSame('People › Phones', $mobile[0]['path'], '"mobile" finds the phone section');
        $this->assertSame([['phone', 'mobil', 'conta']], Help::admin()->synonyms());
        $this->assertNotSame([], $inMoney);
        $this->assertSame([], $inPeople);
    }

    /** A page search is the pages by their best section, each with that section. */
    public function testAPageSearchGivesEachPageItsBestSection(): void
    {
        // Act
        $pages = Help::search('gateway card payments', 2);

        // Assert
        $this->assertSame('billing', $pages[0]['slug']);
        $this->assertSame('gateways', $pages[0]['best']['anchor']);
        $this->assertCount(2, $pages);
        $this->assertSame([], Help::search('zebra'));
    }

    /** The map is every page and its sections by title, grouped by the pages' `section:`. */
    public function testTheMap(): void
    {
        // Act
        $map   = Help::admin()->map();
        $money = Help::admin()->map('Money');

        // Assert
        $this->assertSame(['Money', 'People'], array_keys($map));
        $this->assertSame(['Orders', 'Gateways'], array_column($money['Money'][0]['sections'], 'heading'));
        $this->assertSame([], $money['Money'][1]['sections'], 'a page with no headings has none');
        $this->assertSame(['Money'], array_keys($money));
    }

    /**
     * The assistant's tool: a question brings the best section whole with its link to the
     * heading and the next by path; a page and a section read that one; a page alone its opening
     * and sections; the map by title; a section the page does not have says so.
     */
    public function testTheTool(): void
    {
        // Arrange
        $tool = new HelpTool('https://example.test/admin');

        // Act
        $asked    = $tool->execute(['question' => 'gateway card payments']);
        $section  = $tool->execute(['page' => 'billing', 'section' => 'Orders']);
        $page     = $tool->execute(['page' => 'billing']);
        $missing  = $tool->execute(['page' => 'billing', 'section' => 'Refunds']);
        $noPage   = $tool->execute(['page' => 'nothing']);
        $map      = $tool->execute(['map' => true]);
        $emptyMap = $tool->execute(['map' => true, 'topic' => 'Nowhere']);
        $none     = $tool->execute(['question' => 'zebra']);
        $bare     = $tool->execute(['page' => 'billing/invoices']);

        // Assert
        $this->assertSame('Billing › Gateways', $asked['best']['path']);
        $this->assertStringEndsWith('#gateways', $asked['best']['link']);
        $this->assertStringContainsString('Stripe', $asked['best']['text']);
        $this->assertArrayNotHasKey('text', $asked['also'][0]);
        $this->assertSame('orders', $section['section']);
        $this->assertStringContainsString('filtered by status', $section['text'], 'the whole section, past where a page was cut');
        $this->assertSame('', $page['section'], 'the opening');
        $this->assertSame(['Billing › Orders', 'Billing › Gateways'], array_column($page['sections'], 'path'));
        $this->assertSame('The page has no section called Refunds.', $missing['note']);
        $this->assertSame('There is no help page called nothing.', $noPage);
        $this->assertArrayHasKey('Money', $map['map']);
        $this->assertSame('There are no help pages in Nowhere.', $emptyMap);
        $this->assertSame('No help page answers that.', $none);
        $this->assertSame([], $bare['sections']);
        $this->assertArrayHasKey('map', $tool->inputSchema()['properties']);
    }

    /**
     * A page with no opening text before its first heading answers by its sections, the page's
     * own link in place of an opening.
     */
    public function testAPageWithoutAnOpening(): void
    {
        // Arrange
        file_put_contents($this->base . '/bare.md', "## Only\n\ntext");
        Help::forget();

        // Act
        $page = (new HelpTool())->execute(['page' => 'bare']);

        // Assert
        $this->assertSame('bare', $page['page']);
        $this->assertStringContainsString('Help/page?p=bare', $page['link']);
        $this->assertSame(['bare › Only'], array_column($page['sections'], 'path'));
    }

    /**
     * `help:eval` scores the search against the golden questions: recall@1 and @3, each miss with
     * what came first, and exit 1 under `--min`; a page alone counts its any section.
     */
    public function testHelpEval(): void
    {
        // Arrange
        file_put_contents($this->base . '/_golden.php', "<?php return ['gateway card payments' => 'billing#gateways', 'mobile' => 'people', 'zebra' => 'billing', 'status' => 'billing#gateways', '' => 'x'];");
        $command = new \Pramnos\Console\Commands\HelpEval();
        $tester  = new \Symfony\Component\Console\Tester\CommandTester($command);

        // Act
        $plain   = $tester->execute([]);
        $text    = $tester->getDisplay();
        $strict  = $tester->execute(['--min' => '0.9']);
        $wrong   = $tester->execute(['--level' => 'nowhere']);
        unlink($this->base . '/_golden.php');
        $without = $tester->execute([]);
        $nothing = $tester->getDisplay();

        // Assert
        $this->assertSame(0, $plain);
        $this->assertStringContainsString('4 questions: recall@1 0.50, recall@3 0.50', $text);
        $this->assertStringContainsString('MISS  zebra → billing, first was nothing', $text);
        $this->assertStringContainsString('MISS  status → billing#gateways, first was billing#orders', $text);
        $this->assertSame(1, $strict, 'under --min');
        $this->assertSame(2, $wrong);
        $this->assertSame(0, $without);
        $this->assertStringContainsString('No golden questions', $nothing);
    }

    /**
     * A page's own `questions:` lift the page, but the answer is its section that answers — not
     * the opening of one or two sentences above it. A page with nothing but an opening still
     * answers with it.
     */
    public function testAPagesQuestionsBringItsAnsweringSection(): void
    {
        // Arrange
        file_put_contents($this->base . '/card.md', "---\nquestions:\n  - Which form adds a professional's card?\n---\n# Adding a card\n\nCards are kept by the gateway.\n\n## History\n\nOlder notes.\n\n## The form that adds a new card\n\nThe form is at /billing/cards/new; send the professional this link.\n");
        file_put_contents($this->base . '/alone.md', "---\nquestions:\n  - Where is the lonely answer?\n---\n# Alone\n\nThe lonely answer is here.\n");
        Help::forget();

        // Act
        $card  = Help::admin()->searchSections("Which form adds a professional's card?");
        $alone = Help::admin()->searchSections('Where is the lonely answer?');

        // Assert
        $this->assertSame('card', $card[0]['page']);
        $this->assertSame('the-form-that-adds-a-new-card', $card[0]['anchor'], 'the section with the link, not the opening');
        $this->assertGreaterThan(1000, $card[0]['score'], 'the page\'s question, word for word');
        $this->assertSame('', $alone[0]['heading'], 'nothing but an opening: the opening');
        $this->assertGreaterThan(1000, $alone[0]['score']);
    }

    /**
     * A post of what changed (`source: changelog`) counts half against a guide — unless the
     * question asks what changed or when.
     */
    public function testAChangelogPostCountsLessUnlessAskedWhatChanged(): void
    {
        // Arrange — the same words in a guide and in a post.
        file_put_contents($this->base . '/unlock.md', "# Guide\n\nA professional unlocks a parent's phone from the job post.\n");
        file_put_contents($this->base . '/news.md', "---\nsource: changelog\n---\n# News\n\nA professional unlocks a parent's phone from the job post.\n");
        Help::forget();

        // Act
        $how     = Help::admin()->searchSections('Why can a professional not unlock the phone from the job post?');
        $changed = Help::admin()->searchSections('What changed about unlocking the phone from the job post?');
        $greek   = Help::admin()->searchSections('Πότε άλλαξε το unlock phone job post;');

        // Assert
        $this->assertSame('unlock', $how[0]['page'], 'the guide before the post');
        $this->assertEqualsWithDelta($how[0]['score'] / 2, $how[1]['score'], 0.01, 'the post at half');
        // Asked what changed — in English or in Greek — the two count the same.
        $this->assertEqualsWithDelta($changed[0]['score'], $changed[1]['score'], 0.01);
        $this->assertSame(['changelog', ''], [max($changed[0]['source'], $changed[1]['source']), min($changed[0]['source'], $changed[1]['source'])]);
        $this->assertEqualsWithDelta($greek[0]['score'], $greek[1]['score'], 0.01);
    }

    /**
     * A section's questions are for the search, never for the reader: the page drawn as HTML (the
     * help screen, the side panel, the API) leaves out every line that is only a comment, and
     * keeps a comment inside a line of text as the text it is.
     */
    public function testTheQuestionsAreNotShownOnThePage(): void
    {
        // Act
        $html = Help::html("# T\n<!-- questions: a | b -->\n\ntext\n\n## S\n  <!-- questions: c -->\n\nmore <!-- inline --> here");

        // Assert
        $this->assertStringNotContainsString('questions', $html);
        $this->assertStringContainsString('<h2 id="s">S</h2>', $html);
        $this->assertStringContainsString('more &lt;!-- inline --&gt; here', $html, 'a comment inside text is text');
    }
}
