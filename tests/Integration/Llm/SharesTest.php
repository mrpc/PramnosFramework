<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\Artifacts;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\SharedWithYou;
use Pramnos\Llm\Chat\SharePolicy;
use Pramnos\Llm\Chat\Shares;
use Pramnos\Llm\Chat\Web\ShareView;
use Pramnos\Notification\NotificationInterface;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

// RecordingAssistant is declared there, under a name the autoloader cannot map to a file.
require_once __DIR__ . '/ConversationsTest.php';
require_once __DIR__ . '/ArtifactsTest.php';

/**
 * Sharing an Ask conversation or artifact — on MySQL and PostgreSQL.
 *
 * What holds: a conversation is shared as it stood — what is said after is not in the recipient's
 * copy, until it is shared again; the recipient opens it as a conversation of their own, with the
 * original's data tags and its artifacts, and opening it again returns to that copy; an artifact
 * arrives inside a conversation of its own; names that are nobody are said, the person sharing is
 * never a recipient; only the owner shares, lists or withdraws; deleting what was shared withdraws
 * it; and the recipient is told, with the link that opens it.
 */
#[CoversClass(Shares::class)]
#[CoversClass(SharePolicy::class)]
#[CoversClass(SharedWithYou::class)]
#[CoversClass(ShareView::class)]
class SharesTest extends LlmTestCase
{
    use ChatDatabase;

    /** @var array<string, int> Test users by username */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        CapturedShare::$sent = [];
    }

    protected function tearDown(): void
    {
        if ($this->users !== []) {
            $this->db->queryBuilder()->table('#PREFIX#users')->whereIn('userid', array_values($this->users))->delete();
        }
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** Three people — the one sharing and two to share with — with notifications captured. */
    private function people(): void
    {
        $this->configure(['chat' => ['share_notification' => CapturedShare::class]]);
        $mark = 'sh' . bin2hex(random_bytes(3));
        foreach (['owner', 'maria', 'nikos'] as $who) {
            $this->db->queryBuilder()->table('#PREFIX#users')->insert([
                'username' => $mark . $who, 'email' => $mark . $who . '@example.com', 'password' => 'x', 'regdate' => time(),
                'firstname' => ucfirst($who), 'lastname' => 'Test',
            ]);
            $this->users[$who] = (int) $this->db->getInsertId();
        }
        // An earlier test's account by the same id — in the static cache, or a "nobody" the query
        // cache kept for ten seconds — is not this one.
        \Pramnos\User\User::clearUserCache();
        $this->db->cacheflush('userlist');
    }

    private function username(string $who): string
    {
        return (string) $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $this->users[$who])->first()->fields['username'];
    }

    private function chat(string $who): Conversations
    {
        return (new Conversations(new RecordingAssistant()))->as(new Scope($this->users[$who], 3));
    }

    /**
     * A conversation shared, then added to: the recipient's copy holds what was there when it was
     * shared, with its tags and artifacts; opened again it is the same copy; shared again, the
     * next opening is a fresh copy with the rest.
     */
    #[DataProvider('databases')]
    public function testAConversationIsSharedAsItStood(string $type, string $host, int $port): void
    {
        // Arrange — an exchange with an artifact, carrying a data tag
        $this->connectDatabase($type, $host, $port);
        $this->people();
        \Pramnos\Llm\Llm::use('rules', [new AcceptsEverything()]);
        $this->answer([
            [200, self::calls([['lookup', []], ['save_artifact', ['kind' => 'text', 'title' => 'Memo', 'text' => 'Kept']]])],
            [200, self::says('First answer')],
            [200, self::says('Second answer')],
        ]);
        $owner = $this->chat('owner');
        $first = $owner->ask(null, 'First question');
        $id    = (int) $first['conversation_id'];
        $shares = new Shares();

        // Act — shared, then the conversation goes on
        $done = $shares->share(new Scope($this->users['owner'], 3), Shares::CONVERSATION, $id, [$this->username('maria'), 'nobody-here', $this->username('owner')]);
        $owner->ask($id, 'Second question');
        $mine   = $this->chat('maria');
        $listed = $shares->withMe($this->users['maria']);
        $copy   = $shares->open($listed[0]['share_id'], $mine);
        $again  = $shares->open($listed[0]['share_id'], $mine);
        $found  = $mine->find((int) $copy);

        // Assert — who it went to, and who was nobody; never the owner themself
        $this->assertSame([$this->username('maria')], $done['shared']);
        $this->assertSame(['nobody-here', $this->username('owner')], $done['unknown']);
        $this->assertSame(['conversation', 'First question', 'Owner Test', false], [$listed[0]['kind'], $listed[0]['title'], $listed[0]['from'], $listed[0]['opened']]);
        // The copy holds the exchange as it stood, nothing said after
        $this->assertSame(['First question', 'First answer'], array_column($found['messages'], 'content'));
        $this->assertSame(['google'], $found['tags'], 'the original\'s data tags travel with it');
        $this->assertSame('Memo', $found['messages'][1]['artifacts'][0]['title'], 'and its artifact, on its answer');
        $this->assertSame($this->users['maria'], $found['messages'][1]['artifacts'][0]['userid'], 'now the recipient\'s');
        $this->assertSame($copy, $again, 'opened again: the same copy');
        $this->assertTrue($shares->withMe($this->users['maria'])[0]['opened']);
        // Told, with the link that opens it
        $this->assertCount(1, CapturedShare::$sent);
        $this->assertStringContainsString('Owner Test shared “First question” with you', CapturedShare::$sent[0][0]);
        $this->assertStringContainsString('chat/shared/' . $listed[0]['share_id'], CapturedShare::$sent[0][1]);

        // Act — shared again: the snapshot moves on
        $shares->share(new Scope($this->users['owner'], 3), Shares::CONVERSATION, $id, [$this->username('maria')]);
        $fresh = $mine->find((int) $shares->open($listed[0]['share_id'], $mine));

        // Assert
        $this->assertSame(['First question', 'First answer', 'Second question', 'Second answer'], array_column($fresh['messages'], 'content'));
        $this->assertCount(1, $shares->withMe($this->users['maria']), 'one share per person, not one per time');
    }

    /**
     * An artifact shared arrives as a conversation holding a copy of it; the owner sees who has it
     * and withdraws it; somebody else cannot; deleting the artifact withdraws every share of it.
     */
    #[DataProvider('databases')]
    public function testAnArtifactIsSharedListedAndWithdrawn(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->people();
        $owner     = new Scope($this->users['owner'], 3);
        $artifacts = new Artifacts($owner);
        $made      = $artifacts->create(['kind' => 'list', 'title' => 'Dormant', 'entity' => 'user', 'ids' => [4, 9]]);
        $shares    = new Shares();

        // Act
        $shares->share($owner, Shares::ARTIFACT, $made['artifact_id'], [$this->username('maria') . '@example.com', $this->username('nikos')]);
        $who      = $shares->sharesOf($owner, Shares::ARTIFACT, $made['artifact_id']);
        $copy     = $shares->open($shares->withMe($this->users['nikos'])[0]['share_id'], $this->chat('nikos'));
        $held     = $this->chat('nikos')->find((int) $copy);
        $notYours = $shares->revoke(new Scope($this->users['maria'], 3), $who[0]['share_id']);
        $revoked  = $shares->revoke($owner, $who[0]['share_id']);
        $artifacts->delete($made['artifact_id']);

        // Assert
        $this->assertSame(['Maria Test', 'Nikos Test'], array_column($who, 'name'));
        $this->assertSame('Owner Test shared this with you.', $held['messages'][0]['content']);
        $this->assertSame(['ids' => [4, 9], 'labels' => []], $held['messages'][0]['artifacts'][0]['content'], 'a copy of the list');
        $this->assertFalse($notYours, 'only its owner withdraws a share');
        $this->assertTrue($revoked);
        $this->assertSame([], $shares->withMe($this->users['maria']));
        $this->assertSame([], $shares->withMe($this->users['nikos']), 'deleting the artifact withdrew the rest');
        $this->assertNotNull($this->chat('nikos')->find((int) $copy), 'a copy already taken stays');
    }

    /**
     * What cannot be shared says why: nothing of theirs by that id, nobody named, a policy that
     * refuses; a share that is not the reader's does not open; a deleted conversation's shares go.
     */
    #[DataProvider('databases')]
    public function testWhatCannotBeSharedSaysWhy(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->people();
        $owner = new Scope($this->users['owner'], 3);
        $id    = (int) $this->chat('owner')->seed('Mine', [['role' => 'user', 'content' => 'Hi']]);
        $shares = new Shares();

        // Act
        $missing   = $shares->share($owner, Shares::CONVERSATION, 999999, ['x']);
        $notMine   = $shares->share(new Scope($this->users['maria'], 3), Shares::CONVERSATION, $id, [$this->username('nikos')]);
        $badKind   = $shares->share($owner, 'chart', $id, ['x']);
        $noArt     = $shares->share($owner, Shares::ARTIFACT, 999999, ['x']);
        $nobody    = $shares->share($owner, Shares::CONVERSATION, $id, ['  ']);
        $refused   = (new Shares(new RefusingShares()))->share($owner, Shares::CONVERSATION, $id, [$this->username('maria')]);
        $shares->share($owner, Shares::CONVERSATION, $id, [$this->username('maria')]);
        $shareId   = $shares->withMe($this->users['maria'])[0]['share_id'];
        $stranger  = $shares->open($shareId, $this->chat('nikos'));
        $signedOut = $shares->open($shareId, (new Conversations(new RecordingAssistant()))->as(null));
        $this->chat('owner')->delete($id);

        // Assert
        $this->assertSame('There is no such conversation.', $missing);
        $this->assertSame('There is no such conversation.', $notMine, 'only the owner shares');
        $this->assertSame('There is no such conversation.', $badKind);
        $this->assertSame('There is no such artifact.', $noArt);
        $this->assertSame('Name somebody to share it with.', $nobody);
        $this->assertSame('Not here.', $refused);
        $this->assertNull($stranger, 'a share opens only for its recipient');
        $this->assertNull($signedOut);
        $this->assertSame([], $shares->withMe($this->users['maria']), 'deleting the conversation withdrew its shares');
        $this->assertSame([], $shares->sharesOf($owner, Shares::CONVERSATION, $id));
        $this->assertFalse($shares->revoke($owner, $shareId));
    }

    /**
     * The analyst's conversations go only to people who may open them: a recipient below the
     * analyst's floor is "nobody here" — told nothing, as one who does not exist — while a
     * person's own Ask conversation goes to anybody named.
     *
     * Sent anyway, the notification told somebody without the analyst that a conversation existed,
     * and its title, and led to a page that refused them.
     */
    #[DataProvider('databases')]
    public function testTheAnalystsConversationsGoOnlyToThoseWhoMayOpenThem(string $type, string $host, int $port): void
    {
        // Arrange — maria at the analyst's floor, nikos below it
        $this->connectDatabase($type, $host, $port);
        $this->people();
        $this->configure(['chat' => ['share_notification' => CapturedShare::class], 'analyst' => ['floor' => 50]]);
        $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $this->users['maria'])->update(['usertype' => 50]);
        $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $this->users['nikos'])->update(['usertype' => 10]);
        $names = [$this->username('maria'), $this->username('nikos')];

        // Act
        $analysts = (new SharePolicy())->recipients(new Scope($this->users['owner'], 3, Scope::ADMIN), $names);
        $own      = (new SharePolicy())->recipients(new Scope($this->users['owner'], 3), $names);

        // Assert
        $this->assertSame([$names[0] => $this->users['maria']], $analysts, 'only who may open it');
        $this->assertSame([$names[0] => $this->users['maria'], $names[1] => $this->users['nikos']], $own, 'a person\'s own Ask: anybody');
    }

    /**
     * The API and the page's actions: share, list who has it, what is shared with me, open, and
     * withdraw — through the endpoints a screen calls.
     */
    #[DataProvider('databases')]
    public function testTheApi(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->people();
        $id   = (int) $this->chat('owner')->seed('Mine', [['role' => 'user', 'content' => 'Hi']]);
        $made = (new Artifacts(new Scope($this->users['owner'], 3)))->create(['kind' => 'text', 'title' => 'Memo', 'text' => 'x']);
        $api  = new SharingApi(new Scope($this->users['owner'], 3), ['names' => $this->username('maria') . ', ' . $this->username('nikos')]);
        $hers = new SharingApi(new Scope($this->users['maria'], 3), []);

        // Act
        $shared   = $api->shareConversation($id);
        $art      = $api->shareArtifact($made['artifact_id']);
        $missing  = $api->shareConversation(999999);
        $empty    = (new SharingApi(new Scope($this->users['owner'], 3), ['names' => []]))->shareConversation($id);
        $who      = $api->conversationShares($id);
        $artWho   = $api->artifactShares($made['artifact_id']);
        $mine     = $hers->sharedWithMe();
        $opened   = $hers->openShared($mine['data']['shared'][0]['share_id']);
        $nothing  = $hers->openShared(999999);
        $revoked  = $api->revokeShare($who['data']['shares'][0]['share_id']);
        $again    = $api->revokeShare($who['data']['shares'][0]['share_id']);

        // Assert
        $this->assertSame(200, $shared['status']);
        $this->assertCount(2, $shared['data']['shared']);
        $this->assertSame(200, $art['status']);
        $this->assertSame(404, $missing['status']);
        $this->assertSame(422, $empty['status']);
        $this->assertCount(2, $who['data']['shares']);
        $this->assertCount(2, $artWho['data']['shares']);
        $this->assertCount(2, $mine['data']['shared'], 'the conversation and the artifact');
        $this->assertGreaterThan(0, $opened['data']['conversation_id']);
        $this->assertSame(404, $nothing['status']);
        $this->assertTrue($revoked['data']['deleted']);
        $this->assertSame(404, $again['status']);
        // Refused by the guard, every endpoint answers with the refusal
        $denied = new SharingApi(new Scope($this->users['owner'], 3), [], true);
        foreach ([$denied->shareConversation($id), $denied->conversationShares($id), $denied->revokeShare(1), $denied->sharedWithMe(), $denied->openShared(1)] as $refusal) {
            $this->assertSame(403, $refusal['status']);
        }
        $this->assertSame([], (new SharingApi(null, []))->sharedWithMe()['data']['shared']);
    }

    /** The markup: the form, who has it with a way to stop, and what is shared with the reader — escaped. */
    public function testTheMarkup(): void
    {
        // Act
        $form  = ShareView::form('/chat/share/', 'artifact', 5, 9, '<input name="t">', ['btn' => 'b', 'input' => 'i', 'muted' => 'm']);
        $who   = ShareView::recipients([['share_id' => 3, 'name' => '<Maria>']], '/chat/unshare/', 9, '', []);
        $none  = ShareView::recipients([], '/chat/unshare/', 9, '', []);
        $list  = ShareView::withMe([['share_id' => 4, 'kind' => 'artifact', 'title' => '<T>', 'from' => 'Nikos']], '/chat/shared/');
        $empty = ShareView::withMe([], '/chat/shared/');

        // Assert
        $this->assertStringContainsString('action="/chat/share/5"', $form);
        $this->assertStringContainsString('name="kind" value="artifact"', $form);
        $this->assertStringContainsString('name="conversation_id" value="9"', $form);
        $this->assertStringContainsString('<input name="t">', $form, 'the session token');
        $this->assertStringContainsString('action="/chat/unshare/3"', $who);
        $this->assertStringContainsString('&lt;Maria&gt;', $who);
        $this->assertSame('', $none);
        $this->assertStringContainsString('href="/chat/shared/4"', $list);
        $this->assertStringContainsString('▦ &lt;T&gt;', $list);
        $this->assertSame('', $empty);
    }

    /** Without the table, nothing can be shared and nothing is shared with anybody. */
    public function testWithoutTheTable(): void
    {
        // Arrange
        $shares = new Shares();
        $scope  = new Scope(7, 3);

        // Act / Assert
        $this->assertFalse(Shares::available());
        $this->assertSame('Sharing is not set up here.', $shares->share($scope, Shares::CONVERSATION, 1, ['x']));
        $this->assertSame([], $shares->withMe(7));
        $this->assertSame([], $shares->sharesOf($scope, Shares::CONVERSATION, 1));
        $this->assertFalse($shares->revoke($scope, 1));
        $this->assertNull($shares->open(1, (new Conversations(new RecordingAssistant()))->as($scope)));
        Shares::forget(Shares::CONVERSATION, 1);
    }

    /** The default notification: to the bell and push, with its words and link. */
    public function testTheNotification(): void
    {
        // Arrange
        $notice = new SharedWithYou('Maria shared “Week” with you', '/chat/shared/4');

        // Act / Assert
        $this->assertSame(['message' => 'Maria shared “Week” with you', 'url' => '/chat/shared/4'], $notice->toDatabase(null));
        $this->assertSame('/chat/shared/4', $notice->toPush(null)['url']);
        $this->assertIsArray($notice->via(null));
    }
}

/** A share notification, recorded rather than delivered. */
final class CapturedShare implements NotificationInterface
{
    /** @var list<array{0: string, 1: string}> */
    public static array $sent = [];

    public function __construct(string $words, string $link)
    {
        self::$sent[] = [$words, $link];
    }

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return [];
    }
}

/** A policy that lets nobody share. */
final class RefusingShares extends SharePolicy
{
    public function refusal(Scope $by): ?string
    {
        return 'Not here.';
    }
}

/** The chat API as a given person, with a given body, without a request. */
final class SharingApi extends \Pramnos\Llm\Chat\Api\ChatController
{
    /** @param array<string, mixed> $body */
    public function __construct(private readonly ?Scope $as, private readonly array $body, private readonly bool $deny = false)
    {
    }

    protected function scope(): ?Scope
    {
        return $this->as;
    }

    protected function refusal(string $action): ?array
    {
        return $this->deny ? ['status' => 403, 'error' => 'refused'] : null;
    }

    protected function input(): array
    {
        return $this->body;
    }

    protected function conversations(): Conversations
    {
        return (new Conversations(new RecordingAssistant()))->as($this->as);
    }
}
