<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Assist\Help;
use Pramnos\Console\Commands\HelpGaps;
use Pramnos\Llm\Analyst\Analyst;
use Pramnos\Llm\Analyst\Tools\HelpTool;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Ratings;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Tests\Unit\Llm\LlmTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `help:gaps` from the analyst's conversations, on MySQL and PostgreSQL.
 *
 * Why it matters: every answer keeps the tools it called, so each question the analyst put to the
 * help is already stored with the answer it was for and that answer's rating. Read from there — not
 * from a log file — the gaps know which conversation they came from, a bad rating's note sits beside
 * what the help gave, and they are kept and pruned as the conversations are.
 */
#[CoversClass(HelpGaps::class)]
#[CoversClass(HelpTool::class)]
class HelpGapsTest extends LlmTestCase
{
    use ChatDatabase;

    private string $base = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/pf-help-gaps-' . bin2hex(random_bytes(4));
        mkdir($this->base, 0777, true);
        file_put_contents($this->base . '/billing.md', "# Billing\n\nHow money comes in.\n\n## Gateways\n<!-- questions: Which card processors are there? -->\n\nA gateway takes card payments: Stripe and PayPal.\n\n## Refunds\n\nA refund is made from the order page.\n");
        Help::useDirectories([$this->base]);
    }

    protected function tearDown(): void
    {
        Help::useDirectories(null);
        foreach (glob($this->base . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->base);
        \Pramnos\Auth\AuditLog::resetTableCache();
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /**
     * An answer of a conversation in a space, with the tools it called; its id. `$age` days old.
     *
     * @param list<array<string, mixed>> $tools
     */
    private function storedAnswer(Scope $scope, array $tools, int $age = 0): int
    {
        $chat = (new Conversations(new RecordingAssistant()))->as($scope);
        $id   = (int) $chat->seed('Help', [['role' => 'user', 'content' => 'How?'], ['role' => 'assistant', 'content' => 'Like this.']]);
        $answer = (int) $chat->find($id)['messages'][1]['message_id'];
        $this->db->queryBuilder()->table('#PREFIX#chat_messages')->where('message_id', $answer)->update([
            'tools'      => json_encode($tools),
            'created_at' => \Pramnos\Llm\CallLog::instant(time() - $age * 86400),
        ]);

        return $answer;
    }

    /** The command's output for these options. @param array<string, mixed> $options */
    private function gaps(array $options = []): string
    {
        $tester = new CommandTester(new HelpGaps());
        $tester->execute($options);

        return $tester->getDisplay();
    }

    /**
     * The three lists come from the analyst's answers: a question nothing answers, one answered
     * below the weak score, a section read by name — counted, most asked first. A question
     * answered well is in none; another space's conversations, other tools and answers older than
     * `--days` are left out; `--golden` prints the questions as lines with the answer they get.
     */
    #[DataProvider('databases')]
    public function testTheListsComeFromTheConversations(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $admin = Analyst::scope(7);
        $this->storedAnswer($admin, [['name' => 'help', 'arguments' => ['question' => 'Which card processors are there?']], ['name' => 'users', 'arguments' => []]]);
        $this->storedAnswer($admin, [['name' => 'help', 'arguments' => ['question' => 'zebra crossing']]]);
        $this->storedAnswer($admin, [['name' => 'help', 'arguments' => ['question' => 'zebra crossing']], ['name' => 'help', 'arguments' => ['question' => 'order page']]]);
        $this->storedAnswer($admin, [['name' => 'help', 'arguments' => ['page' => 'billing', 'section' => 'Refunds']], ['name' => 'help', 'arguments' => ['map' => true]]]);
        $this->storedAnswer(new Scope(7, null), [['name' => 'help', 'arguments' => ['question' => 'a chat question']]]);
        $this->storedAnswer($admin, [['name' => 'help', 'arguments' => ['question' => 'an old one']]], 90);

        // Act
        $text   = $this->gaps(['--weak' => '100']);
        $golden = $this->gaps(['--golden' => true]);

        // Assert
        $this->assertStringContainsString("Nothing answers (1):\n    2× zebra crossing", $text);
        $this->assertMatchesRegularExpression('/Answered weakly, below 100 \(1\):\n\s+1× order page → billing#refunds \(\d+\.\d\d\)/', $text);
        $this->assertStringContainsString("Read by name, after a search did not bring them (1):\n    1× billing#refunds", $text);
        $this->assertStringNotContainsString('Which card processors', $text, 'answered well: in none of the lists');
        $this->assertStringNotContainsString('a chat question', $golden, 'another space');
        $this->assertStringNotContainsString('an old one', $golden, 'older than --days');
        $this->assertStringContainsString("    'order page' => 'billing#refunds',", $golden);
        $this->assertStringContainsString("    'zebra crossing' => '',", $golden);
    }

    /**
     * `--rated=bad` lists the help behind each answer rated bad, with its note, and says which it
     * was: the help had a strong section the answer did not use, or it has nothing for it.
     */
    #[DataProvider('databases')]
    public function testTheAnswersRatedBad(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $admin   = Analyst::scope(7);
        $had     = $this->storedAnswer($admin, [['name' => 'help', 'arguments' => ['question' => 'Which card processors are there?']]]);
        $missing = $this->storedAnswer($admin, [['name' => 'help', 'arguments' => ['question' => 'zebra crossing']], ['name' => 'help', 'arguments' => ['page' => 'billing', 'section' => 'Gateways']], ['name' => 'help', 'arguments' => ['map' => true]]]);
        $this->storedAnswer($admin, [['name' => 'help', 'arguments' => ['question' => 'order page']]]);
        (new Ratings())->rate($admin, $had, -1, 'It said there are none');
        (new Ratings())->rate($admin, $missing, -1);

        // Act
        $text  = $this->gaps(['--rated' => 'bad']);
        $wrong = (new CommandTester(new HelpGaps()));
        $code  = $wrong->execute(['--rated' => 'good']);

        // Assert
        $this->assertStringContainsString('2 answers rated bad asked the help:', $text);
        $this->assertStringContainsString('#' . $had . ' ', $text);
        $this->assertStringContainsString('— "It said there are none"', $text);
        $this->assertStringContainsString('The help has a strong section for it: the answer did not find or use it.', $text);
        $this->assertStringContainsString("zebra crossing → nothing\n  read billing#gateways\n  map\n  The help has nothing strong for it", $text);
        $this->assertStringNotContainsString('order page', $text, 'not rated bad');
        $this->assertSame(2, $code);
    }

    /** With nothing asked, or nothing rated bad, it says so. */
    #[DataProvider('databases')]
    public function testNothingAsked(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);

        // Act & Assert
        $this->assertStringContainsString('Nothing was asked of the help in that time.', $this->gaps());
        $this->assertStringContainsString('No answer rated bad in that time asked the help.', $this->gaps(['--rated' => 'bad']));
    }

    /**
     * lookedUp() reads one stored call as the help answers it now: a question's best section and
     * score, a section read by name, a page alone, the map.
     */
    #[DataProvider('databases')]
    public function testLookedUp(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);

        // Act
        $question = HelpTool::lookedUp(['question' => 'Which card processors are there?']);
        $read     = HelpTool::lookedUp(['page' => 'billing', 'section' => 'refunds']);
        $page     = HelpTool::lookedUp(['page' => 'billing']);
        $map      = HelpTool::lookedUp(['map' => true]);

        // Assert
        $this->assertSame(['question', 1, 'billing#gateways', 'Billing › Gateways'], [$question['kind'], $question['found'], $question['best'], $question['path']]);
        $this->assertGreaterThan(1000, $question['score']);
        $this->assertSame(['read', 1, 'billing#refunds', 'Billing › Refunds'], [$read['kind'], $read['found'], $read['best'], $read['path']]);
        $this->assertSame(['read', 0, 'billing'], [$page['kind'], $page['found'], $page['best']]);
        $this->assertSame('map', $map['kind']);
    }
}
