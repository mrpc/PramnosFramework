<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Messages;
use Pramnos\Conversations\SystemPost;

/**
 * Posting in the shape a chat webhook takes, on each backend: a `#name` is a system channel, made
 * the first time; a person — by id, `@id:N`, a name the application resolves, or a username — gets
 * their own conversation with the bot; an attachment becomes a card; the text is the message's
 * Markdown; a retry and a repeat in the same request are written once; nobody to post to is null.
 */
#[CoversClass(SystemPost::class)]
#[CoversClass(Conversations::class)]
class SystemPostTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** No resolver and nothing remembered, for the next test. */
    protected function tearDown(): void
    {
        SystemPost::resolvePeople(null);
        SystemPost::reset();
        $this->probeTearDown();
    }

    /**
     * To a channel: made as a system channel the first time, the same one after; the text as it
     * was written, the attachment as a card signed by the bot; a second attachment a second
     * message; a retry with the same external id, and the same post twice in a request, once.
     */
    #[DataProvider('databases')]
    public function testToAChannel(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        $attachment = ['title' => 'Τιμολόγιο 2291', 'title_link' => 'https://admin.example/invoice/2291', 'text' => "29,90 €\nPremium",
            'color' => '#D00000', 'image_url' => 'https://cdn.example/a.png'];

        // Act
        $first  = SystemPost::post('#administration-log', "Θέμα: **Επιστροφή**\n[Ελένη](https://admin.example/u/5)", [$attachment], ['username' => 'Nannuka Bot']);
        $again  = SystemPost::post('#administration-log', "Θέμα: **Επιστροφή**\n[Ελένη](https://admin.example/u/5)", [$attachment], ['username' => 'Nannuka Bot']);
        SystemPost::reset();
        $two    = SystemPost::post('#administration-log', 'δύο', [['title' => 'α'], ['title' => 'β', 'color' => 'good']], ['source' => 'webhook', 'externalid' => 'x1']);
        $retry  = SystemPost::post('#administration-log', 'δύο', [['title' => 'α'], ['title' => 'β', 'color' => 'good']], ['source' => 'webhook', 'externalid' => 'x1']);
        $plain  = SystemPost::post('#administration-log', 'μόνο κείμενο');
        $other  = SystemPost::post('Πληρωμές', 'σε άλλο', [], ['topic' => 'Ό,τι πληρώνεται']);
        $channel  = Conversations::bySlug('administration-log');
        $messages = Messages::list($channel['id']);

        // Assert
        $this->assertSame('system', $channel['kind'], 'made as a system channel');
        $this->assertSame($first, $again, 'the same post in the same request: once');
        $this->assertSame($two, $retry, 'a retry with the same external id: once');
        $this->assertSame([$first, $two, $two + 1, $plain], array_column($messages, 'id'), 'a second attachment is a second message');
        $byId = array_column($messages, null, 'id');
        $this->assertSame("Θέμα: **Επιστροφή**\n[Ελένη](https://admin.example/u/5)", $byId[$first]['body'], 'the Markdown as it was written');
        $this->assertSame(['title' => 'Τιμολόγιο 2291', 'url' => 'https://admin.example/invoice/2291', 'text' => "29,90 €\nPremium",
            'image' => 'https://cdn.example/a.png', 'color' => 'error', 'from' => 'Nannuka Bot'], $byId[$first]['card']);
        $this->assertSame(['title' => 'β', 'color' => 'success'], $byId[$two + 1]['card']);
        $this->assertNull($byId[$plain]['card'], 'nobody signs it: no card');
        $this->assertSame(['system', 'πληρωμές', 'Ό,τι πληρώνεται'], [Conversations::get((int) Messages::get($other)['conversationId'])['kind'],
            Conversations::get((int) Messages::get($other)['conversationId'])['slug'], Conversations::get((int) Messages::get($other)['conversationId'])['topic']]);
    }

    /**
     * To a person: by account id, `@id:N`, the application's own name, or a username — each their
     * own conversation with the bot, one per bot, listed with their direct messages under its name;
     * nobody, or somebody who may not use the conversations, is null.
     */
    #[DataProvider('databases')]
    public function testToAPerson(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        $maria = $this->ids['maria'];
        $username = (string) $this->db->queryBuilder()->table('users')->where('userid', $this->ids['nikos'])->getAllOrFail()[0]['username'];

        // Act
        $byId      = SystemPost::post($maria, 'Σου ανατέθηκε', [], ['username' => 'Nannuka Bot']);
        $byTag     = SystemPost::post('@id:' . $maria, 'Και αυτό', [], ['username' => 'Nannuka Bot']);
        $other     = SystemPost::post($maria, 'Από άλλο bot', [], ['username' => 'Jira']);
        $byName    = SystemPost::post('@' . $username, 'Με το username');
        SystemPost::resolvePeople(fn (string $name): ?int => $name === 'maria.k' ? $maria : null);
        $resolved  = SystemPost::post('@maria.k', 'Με το όνομα του άλλου συστήματος', [], ['username' => 'Nannuka Bot']);
        $unknown   = SystemPost::post('@nobody', 'κανείς');
        $tasos     = SystemPost::post($this->ids['tasos'], 'μεταφραστής');
        $missing   = SystemPost::post(424242, 'δεν υπάρχει');
        $mine      = array_values(array_filter(Conversations::forPerson($maria), static fn (array $c): bool => $c['kind'] === 'bot'));
        $refusal   = null;
        try {
            Conversations::create('bot', '', [$maria]);
        } catch (\InvalidArgumentException $e) {
            $refusal = $e->getMessage();
        }

        // Assert
        $this->assertSame(Messages::get($byId)['conversationId'], Messages::get($byTag)['conversationId'], 'one conversation with this bot');
        $this->assertSame(Messages::get($byId)['conversationId'], Messages::get($resolved)['conversationId'], 'and the application\'s name finds her');
        $this->assertNotSame(Messages::get($byId)['conversationId'], Messages::get($other)['conversationId'], 'another bot, another conversation');
        $this->assertEqualsCanonicalizing(['Nannuka Bot', 'Jira'], array_column($mine, 'name'));
        $this->assertSame([$maria], $mine[0]['members'], 'hers alone');
        $this->assertSame(['System'], array_values(array_map(static fn (int $id): string => (string) Conversations::get($id)['name'],
            [(int) Messages::get($byName)['conversationId']])), 'nobody named: "System"');
        $this->assertSame([null, null, null], [$unknown, $tasos, $missing], 'nobody, or somebody who may not use them');
        $this->assertSame('A bot conversation is one person and a named bot.', $refusal);
        $this->assertSame([(int) Messages::get($byId)['conversationId'] => 'Nannuka Bot'], Conversations::names([(int) Messages::get($byId)['conversationId']]));
    }

    /**
     * An attachment as a card, field by field; colours by name and by hue; nothing to show is no
     * card; only http(s) links.
     */
    public function testCardsAndTones(): void
    {
        // Arrange
        $full = ['pretext' => 'πριν', 'title' => 'Τίτλος', 'title_link' => 'javascript:alert(1)', 'fallback' => 'κείμενο',
            'fields' => [['title' => 'Ποσό', 'value' => '29,90 €'], ['title' => '', 'value' => ''], 'Αγορά' => 'GR'], 'color' => '#36a64f'];

        // Act
        $card  = SystemPost::card($full);
        $tones = array_map([SystemPost::class, 'tone'], ['good', 'warning', 'danger', 'info', '#D00000', '#f80', '#ffcc00', '#36a64f', '#439FE0', '#7d3c98', '#999', 'neutral', 'teal', '']);

        // Assert
        $this->assertSame(['title' => 'Τίτλος', 'text' => "πριν\nκείμενο", 'fields' => ['Ποσό' => '29,90 €', 'Αγορά' => 'GR'], 'color' => 'success'], $card,
            'no javascript: link; empty fields dropped');
        $this->assertNull(SystemPost::card(['color' => 'good']), 'a colour alone is no card');
        $this->assertNull(SystemPost::card([]));
        $this->assertSame(['success', 'warning', 'error', 'info', 'error', 'warning', 'warning', 'success', 'info', 'info', 'neutral', 'neutral', '', ''], $tones);
    }
}
