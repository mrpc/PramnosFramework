<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Messages;
use Pramnos\Conversations\Summary;
use Pramnos\Llm\Analyst\Tools\ConversationSummaryTool;

/**
 * A client that records what it was asked and answers with the reply it is given.
 */
final class SummaryClient extends \Pramnos\Llm\Client
{
    /** @var list<array{system: string, user: string, about: array<string, mixed>}> */
    public static array $asked = [];

    /** The reply every call gets. */
    public static string $reply = '';

    /** @var array<string, mixed> */
    private array $said = [];

    /** Keeps the context, to be read back. */
    public function about(array $context): static
    {
        $this->said = $context;

        return $this;
    }

    /** Records the call and answers. */
    public function askCheaply(string $systemPrompt, array $messages): array
    {
        self::$asked[] = ['system' => $systemPrompt, 'user' => (string) $messages[0]['content'], 'about' => $this->said];

        return ['ok' => true, 'reply' => self::$reply];
    }
}

/**
 * The analyst's summary of a conversation (`Summary`) and its tool, on MySQL with and without a
 * prefix and PostgreSQL: what is unread — threads in, deleted messages out — in the reader's
 * language and costed to the reader; only the ids the messages have are cited; nothing unread is
 * an empty answer with no call; a private channel the reader is not in is refused; a long one is
 * cut to the newest; the tool finds a channel by its name.
 */
#[CoversClass(Summary::class)]
#[CoversClass(ConversationSummaryTool::class)]
class SummaryTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** The test's client gone, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        Summary::useClient(null);
        SummaryClient::$asked = [];
        $this->probeTearDown();
    }

    /** The conversations on, for usertype 30 up, with the recording client. */
    private function setUpConversations(string $type, string $host, int $port, string $user): void
    {
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        Application::getInstance()->applicationInfo['features']      = ['conversations'];
        Summary::useClient(static fn (): \Pramnos\Llm\Client => new SummaryClient());
        \Pramnos\Broadcasting\Testing\FakeDriver::swap();
    }

    /**
     * What the reader has not read is summed up: a reply in a thread is read, a deleted message is
     * not, the reader's own words are not news to them; only cited ids that are among the messages
     * come back; the call is the reader's and in their language.
     */
    #[DataProvider('databases')]
    public function testTheUnreadAreSummedUpForTheReader(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->setUpConversations($type, $host, $port, $user);
        [$maria, $nikos] = [$this->ids['maria'], $this->ids['nikos']];
        $room = Conversations::create('channel', 'launch', [$nikos], $maria);
        try {
            $seen = Messages::post($room, $maria, 'Old news');
            Conversations::markRead($room, $nikos, $seen);
            Messages::post($room, $nikos, 'My own words');
            $plan   = Messages::post($room, $maria, 'We launch on Friday');
            $reply  = Messages::post($room, $maria, 'Banner by Thursday', ['thread' => $plan]);
            $gone   = Messages::post($room, $maria, 'A secret typo');
            Messages::delete($gone, $this->user('maria'));
            SummaryClient::$reply = "- Launch on Friday [#$plan], banner by Thursday [#$reply] [#999999]";

            // Act
            $summary = Summary::of($room, $this->user('nikos'), 'unread', 'el');
            $asked   = SummaryClient::$asked[0];

            // Assert
            $this->assertSame(2, $summary['count'], 'the plan and the reply in its thread');
            $this->assertSame([$plan, $reply], $summary['messages'], 'only the ids among the messages read');
            $this->assertFalse($summary['cut']);
            $this->assertStringContainsString('We launch on Friday', $asked['user']);
            $this->assertStringContainsString('(reply to #' . $plan . ')', $asked['user']);
            $this->assertStringNotContainsString('Old news', $asked['user'], 'read already');
            $this->assertStringNotContainsString('A secret typo', $asked['user'], 'deleted');
            $this->assertStringNotContainsString('My own words', $asked['user']);
            $this->assertStringContainsString('Write in Greek', $asked['system'], 'the language asked for, named');
            $this->assertSame('el', $summary['language']);
            $this->assertSame(['purpose' => 'conversation_summary', 'userid' => $nikos], $asked['about'], 'costed to the reader');
        } finally {
            \Pramnos\Broadcasting\Testing\FakeDriver::restore();
        }
    }

    /**
     * Nothing unread is an empty answer with no call; a private channel the reader is not in is
     * refused as if it were not there; `since` that is none of the three is refused.
     */
    #[DataProvider('databases')]
    public function testNothingUnreadAndNoAccess(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->setUpConversations($type, $host, $port, $user);
        [$maria, $nikos] = [$this->ids['maria'], $this->ids['nikos']];
        $room   = Conversations::create('channel', 'quiet', [$nikos], $maria);
        $secret = Conversations::create('private', 'board', [], $maria);
        try {
            Conversations::markRead($room, $nikos, Messages::post($room, $maria, 'Read already'));

            // Act
            $empty = Summary::of($room, $this->user('nikos'), 'unread');
            $refused = null;
            try {
                Summary::of($secret, $this->user('nikos'), ['hours' => 24]);
            } catch (\DomainException $e) {
                $refused = $e;
            }
            $api = new class extends \Pramnos\Conversations\ConversationsApi {
                /** Who asks, set by the test. */
                public ?object $asking = null;

                /** The test's account, not the API's credentials. */
                protected function user(): ?object
                {
                    return $this->asking;
                }
            };
            $api->asking = $this->user('nikos');
            \Pramnos\Http\Request::setRawInput((string) json_encode(['since' => 'unread']));
            $byApi = $api->call('POST', 'summarize', $room);
            \Pramnos\Http\Request::setRawInput('');
            $wrong = null;
            try {
                Summary::of($room, $this->user('nikos'), 'yesterday');
            } catch (\InvalidArgumentException $e) {
                $wrong = $e;
            }

            // Assert
            $this->assertSame(['summary' => '', 'messages' => [], 'from' => null, 'to' => null, 'count' => 0, 'cut' => false, 'language' => ''], $empty);
            $this->assertSame([], SummaryClient::$asked, 'no call for nothing');
            $this->assertNotNull($refused, 'not a member of the private channel');
            $this->assertNotNull($wrong);
            // The action: nothing unread where the analyst is set up, a 422 where it is not.
            $this->assertSame(\Pramnos\Llm\Analyst\Analyst::available() ? [200, 0] : [422, null], [$byApi['status'], $byApi['data']['count'] ?? null]);
        } finally {
            \Pramnos\Broadcasting\Testing\FakeDriver::restore();
        }
    }

    /**
     * A channel longer than the model is given is cut to its newest messages, and says so; the
     * tool finds the channel by its #name and sums up its last hours.
     */
    #[DataProvider('databases')]
    public function testALongChannelIsCutToTheNewestAndTheToolFindsItByName(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->setUpConversations($type, $host, $port, $user);
        [$maria, $nikos] = [$this->ids['maria'], $this->ids['nikos']];
        $room = Conversations::create('channel', 'longtalk', [$nikos], $maria);
        try {
            $first = Messages::post($room, $maria, 'first ' . str_repeat('a', 25000));
            Messages::post($room, $maria, 'second ' . str_repeat('b', 25000));
            $last = Messages::post($room, $maria, 'third ' . str_repeat('c', 25000));
            SummaryClient::$reply = "Older left out. [#$last]";

            // Act
            $summary = Summary::of($room, $this->user('nikos'), ['hours' => 1]);
            $system  = SummaryClient::$asked[0]['system'];
            $asked   = SummaryClient::$asked[0]['user'];
            $tool    = (new ConversationSummaryTool(0, $this->user('nikos')))->execute(['conversation' => '#longtalk']);
            $none    = (new ConversationSummaryTool(0, $this->user('nikos')))->execute(['conversation' => '#nothing-here']);

            // Assert
            $this->assertSame(2, $summary['count'], 'two of 25,000 characters fit under 60,000');
            $this->assertTrue($summary['cut']);
            $this->assertStringNotContainsString('#' . $first . ']', $asked, 'the oldest is the one left out');
            $this->assertStringContainsString('Older messages were left out', $system);
            $this->assertSame([$last], $summary['messages']);
            $this->assertIsArray($tool);
            $this->assertSame(2, $tool['count'], 'the tool: the same summary, by the channel\'s name');
            $this->assertSame('Error: there is no such conversation you read.', $none);
            $this->assertSame('conversation_summary', (new ConversationSummaryTool())->name());
        } finally {
            \Pramnos\Broadcasting\Testing\FakeDriver::restore();
        }
    }

    /**
     * Without a language asked for, the summary is in the messages' own — by their letters, not
     * the writers' names: English text between people with Greek names is summed up in English,
     * Greek text in Greek; a mention and an address do not count.
     */
    #[DataProvider('databases')]
    public function testTheLanguageIsTheMessagesOwn(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->setUpConversations($type, $host, $port, $user);
        [$maria, $nikos] = [$this->ids['maria'], $this->ids['nikos']];
        $english = Conversations::create('channel', 'invoices', [$nikos], $maria);
        $greek   = Conversations::create('channel', 'timologia', [$nikos], $maria);
        try {
            Messages::post($english, $maria, 'We agreed to ship the new invoices screen on Thursday. <@' . $nikos . '> ok?');
            Messages::post($english, $nikos, 'Ναι — https://example.com/τιμολόγια fine by me.');
            Messages::post($greek, $maria, 'Συμφωνήσαμε να βγει η νέα οθόνη τιμολογίων την Πέμπτη, ok;');
            SummaryClient::$reply = 'ok';

            // Act
            $inEnglish = Summary::of($english, $this->user('nikos'), ['hours' => 1]);
            $inGreek   = Summary::of($greek, $this->user('nikos'), ['hours' => 1]);

            // Assert
            $this->assertSame(['en', 'el'], [$inEnglish['language'], $inGreek['language']]);
            $this->assertStringContainsString('Write in English', SummaryClient::$asked[0]['system']);
            $this->assertStringContainsString('Μαρία', SummaryClient::$asked[0]['user'], 'the writers\' Greek names were there, and did not decide it');
            $this->assertStringContainsString('Write in Greek', SummaryClient::$asked[1]['system']);
        } finally {
            \Pramnos\Broadcasting\Testing\FakeDriver::restore();
        }
    }
}
