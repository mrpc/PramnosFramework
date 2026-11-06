<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Assist\Help;
use Pramnos\Console\Commands\HelpQuestions;
use Symfony\Component\Console\Tester\CommandTester;

/** A client that answers each section with what the test wants, and counts the asks. */
final class QuestionsClient extends \Pramnos\Llm\Client
{
    /** @var list<string> The sections it was asked about */
    public array $asked = [];

    /** @param \Closure(string): string $reply The reply for a section's path */
    public function __construct(private readonly \Closure $reply)
    {
    }

    /** The reply for the section in the message, as a model would give it. */
    public function askCheaply(string $systemPrompt, array $messages): array
    {
        $section       = (string) (json_decode((string) $messages[0]['content'], true)['section'] ?? '');
        $this->asked[] = $section;

        return ['ok' => true, 'reply' => ($this->reply)($section)];
    }
}

/**
 * `help:questions`: questions proposed for the sections that have none, printed, and written
 * under each heading with `--write`.
 *
 * Why it matters: a section's own questions are what lifted one installation's help search from
 * finding the answer first in under half of real questions to nearly nine in ten, and writing
 * them by hand is the tedious part. What it must not do: touch a section that has questions, write
 * into the framework's pages unasked, or break the comment it writes.
 */
#[CoversClass(HelpQuestions::class)]
final class HelpQuestionsTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/pf-help-questions-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/fw', 0777, true);
        mkdir($this->base . '/app', 0777, true);
        file_put_contents($this->base . '/app/billing.md', "---\ntitle: Billing\n---\n# Billing\n\nHow money comes in, and where the payments are listed.\n\n"
            . "## Gateways\n\nA gateway takes card payments; Stripe and PayPal are supported here.\n\n"
            . "## Refunds\n<!-- questions: How do I refund? -->\n\nA refund is made from the order's page by an administrator.\n\n"
            . "```\n## Not a heading\n```\n\n## Short\n\nToo short.\n");
        file_put_contents($this->base . '/fw/queue.md', "# Queue\n\nBackground jobs run here, one after another, retried when they fail.\n");
        Help::useDirectories([$this->base . '/app', $this->base . '/fw']);
    }

    protected function tearDown(): void
    {
        Help::useDirectories(null);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->base);
    }

    /** The command with a client that answers `$reply`. @return array{0: CommandTester, 1: QuestionsClient} */
    private function command(\Closure $reply): array
    {
        $client          = new QuestionsClient($reply);
        $command         = new HelpQuestions();
        $command->client = static fn (): QuestionsClient => $client;

        return [new CommandTester($command), $client];
    }

    /**
     * Without `--write` it only prints: the sections without questions — not the one with, not a
     * too-short one, not the framework's — each with what the model proposed, cleaned of what
     * would end the comment.
     */
    public function testItPrintsProposalsForSectionsWithout(): void
    {
        // Arrange
        [$tester, $client] = $this->command(static fn (string $s): string => '```json' . "\n" . '{"questions": ["Which gateways are there?", "Is Stripe | PayPal supported -->?", "", "a", "b", "c"]}' . "\n```");
        $before = (string) file_get_contents($this->base . '/app/billing.md');

        // Act
        $code = $tester->execute([]);
        $text = $tester->getDisplay();

        // Assert
        $this->assertSame(0, $code);
        $this->assertSame(['Billing', 'Billing › Gateways'], $client->asked, 'the opening and Gateways; not Refunds, Short or the framework\'s');
        $this->assertStringContainsString("billing#gateways  (Billing › Gateways)\n  - Which gateways are there?\n  - Is Stripe   PayPal supported  ?", $text);
        $this->assertSame($before, (string) file_get_contents($this->base . '/app/billing.md'), 'nothing written');
    }

    /**
     * `--write` puts the questions under each heading — the opening's under the page's title — so
     * the search reads them, and running it again asks for nothing.
     */
    public function testWriteFillsTheSectionsAndTheSearchReadsThem(): void
    {
        // Arrange
        [$tester] = $this->command(static fn (string $s): string => json_encode(['questions' => [$s === 'Billing' ? 'Where are payments listed?' : 'Which card processors work?']]));

        // Act
        $tester->execute(['--write' => true]);
        $file = (string) file_get_contents($this->base . '/app/billing.md');
        [$again, $client] = $this->command(static fn (string $s): string => '{}');
        $again->execute([]);

        // Assert
        $this->assertStringContainsString("# Billing\n<!-- questions: Where are payments listed? -->\n", $file);
        $this->assertStringContainsString("## Gateways\n<!-- questions: Which card processors work? -->\n", $file);
        $this->assertStringContainsString('2 sections written.', $tester->getDisplay());
        $this->assertSame('gateways', Help::admin()->searchSections('Which card processors work?')[0]['anchor']);
        $this->assertSame([], $client->asked);
        $this->assertStringContainsString('Every section has questions.', $again->getDisplay());
    }

    /**
     * `--framework` takes the framework's pages too; `--page` one page; `--limit` says how many
     * are left; a reply without questions is reported, and when nothing came back at all the
     * command fails; a level it does not know is refused.
     */
    public function testOptionsAndFailures(): void
    {
        // Arrange
        [$tester, $client] = $this->command(static fn (string $s): string => 'not json');

        // Act
        $framework = $tester->execute(['--framework' => true, '--page' => 'queue']);
        $frameworkText = $tester->getDisplay();
        $limited   = $tester->execute(['--limit' => '1']);
        $limitedText = $tester->getDisplay();
        $wrong     = $tester->execute(['--level' => 'nowhere']);

        // Assert
        $this->assertSame(1, $framework, 'nothing came back: a failure');
        $this->assertStringContainsString('queue: no questions came back.', $frameworkText);
        $this->assertSame('Queue', $client->asked[0]);
        $this->assertSame(1, $limited);
        $this->assertStringContainsString('1 more sections without questions', $limitedText);
        $this->assertSame(2, $wrong);
    }

    /** A page whose body starts without a title gets its opening's questions at the top of the body. */
    public function testAnOpeningWithoutATitle(): void
    {
        // Arrange
        file_put_contents($this->base . '/app/plain.md', "---\nsection: X\n---\nPlain text that explains enough to be worth asking about.\n\n## Part\n\nmore\n");
        Help::forget();
        [$tester] = $this->command(static fn (string $s): string => '{"questions": ["What is plain?"]}');

        // Act
        $tester->execute(['--write' => true, '--page' => 'plain']);

        // Assert
        $this->assertStringStartsWith("---\nsection: X\n---\n<!-- questions: What is plain? -->\nPlain text", (string) file_get_contents($this->base . '/app/plain.md'));
    }
}
