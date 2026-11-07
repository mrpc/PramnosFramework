<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Broadcasting\Testing\FakeDriver;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Drafts;
use Pramnos\Conversations\Messages;

/**
 * Drafts kept on the server, on each backend.
 *
 * Why it matters: what somebody began on the computer must be there on the phone — one per
 * conversation and thread, replaced as they type, gone once it is sent — and their other devices
 * have to hear of each change, or the phone shows yesterday's half-sentence after it was sent.
 */
#[CoversClass(Drafts::class)]
class DraftsTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** The real broadcaster back, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        FakeDriver::restore();
        $this->probeTearDown();
    }

    /**
     * A draft for the conversation and one for a thread, each replaced as it changes and read back;
     * an empty body lets it go; `mine()` lists them newest first; each change is told on the
     * writer's own channel as `conversation.draft`.
     */
    #[DataProvider('databases')]
    public function testKeptAndToldToOnesOtherDevices(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        $ops  = Conversations::create('channel', 'ops', [$maria], $eleni);
        $root = Messages::post($ops, $maria, 'Ερώτηση');
        $fake = FakeDriver::swap();

        // Act
        Drafts::save($eleni, $ops, null, 'Καλημ');
        Drafts::save($eleni, $ops, null, 'Καλημέρα σε');
        Drafts::save($eleni, $ops, $root, 'Η απάντηση είναι');
        $top    = Drafts::get($eleni, $ops);
        $thread = Drafts::get($eleni, $ops, $root);
        $mine   = Drafts::mine($eleni);
        Drafts::save($eleni, $ops, $root, '   ');
        $after  = Drafts::mine($eleni);

        // Assert
        $this->assertSame(['Καλημέρα σε', 'Η απάντηση είναι'], [$top, $thread], 'one per conversation and thread, the latest');
        $this->assertCount(2, $mine);
        $this->assertSame([$ops], array_unique(array_column($mine, 'conversationId')));
        $this->assertEqualsCanonicalizing([null, $root], array_column($mine, 'thread'));
        $this->assertSame([['conversationId' => $ops, 'thread' => null, 'body' => 'Καλημέρα σε']],
            array_map(static fn (array $d): array => array_intersect_key($d, ['conversationId' => 1, 'thread' => 1, 'body' => 1]), $after), 'emptied: gone');
        $told = array_values(array_filter($fake->recorded(), static fn (array $t): bool => $t['event'] === 'conversation.draft'));
        $this->assertCount(4, $told, 'every change, the emptying too');
        $this->assertSame('private-users.' . $eleni, $told[0]['channel'], 'on the writer\'s own channel');
        $this->assertSame(['conversationId' => $ops, 'thread' => $root, 'body' => ''], array_intersect_key($told[3]['payload'], ['conversationId' => 1, 'thread' => 1, 'body' => 1]));
    }

    /**
     * Sending a message lets its writer's draft there go — only theirs, only there — and tells their
     * other devices; a message where they had none tells nothing.
     */
    #[DataProvider('databases')]
    public function testSendingLetsTheDraftGo(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        $ops  = Conversations::create('channel', 'ops', [$maria], $eleni);
        $root = Messages::post($ops, $maria, 'Ερώτηση');
        Drafts::save($eleni, $ops, null, 'Καλημέρα');
        Drafts::save($eleni, $ops, $root, 'Ναι');
        Drafts::save($maria, $ops, null, 'Κι εγώ');
        $fake = FakeDriver::swap();

        // Act
        Messages::post($ops, $eleni, 'Καλημέρα σε όλους');
        Messages::post($ops, $eleni, 'Δεύτερο');

        // Assert
        $this->assertSame(['', 'Ναι', 'Κι εγώ'], [Drafts::get($eleni, $ops), Drafts::get($eleni, $ops, $root), Drafts::get($maria, $ops)],
            'hers in the conversation went; her thread\'s and maria\'s stay');
        $told = array_values(array_filter($fake->recorded(), static fn (array $t): bool => $t['event'] === 'conversation.draft'));
        $this->assertCount(1, $told, 'the second message had no draft to let go');
    }

    /**
     * Somebody not in the conversation keeps no draft there, nor one longer than a message may be;
     * and a conversation still opens where the drafts cannot be read.
     */
    #[DataProvider('databases')]
    public function testRefusals(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        $ops = Conversations::create('channel', 'ops', [$maria], $eleni);

        // Act
        $refused = [];
        foreach ([fn () => Drafts::save($nikos, $ops, null, 'Γεια'), fn () => Drafts::save($eleni, $ops, null, str_repeat('α', Messages::MAX_LENGTH + 1))] as $attempt) {
            try {
                $attempt();
                $refused[] = false;
            } catch (\InvalidArgumentException $e) {
                $refused[] = true;
            }
        }
        $this->db->schema()->dropTableIfExists('conversation_drafts');
        $this->probeChanged($this->db->type);
        $unreadable = Drafts::get($eleni, $ops);
        Messages::post($ops, $eleni, 'Γράφεται κανονικά');

        // Assert
        $this->assertSame([true, true], $refused);
        $this->assertSame('', $unreadable, 'nothing, not an error');
        $this->assertCount(1, Messages::list($ops), 'a message is written though its draft cannot be let go');
    }
}
