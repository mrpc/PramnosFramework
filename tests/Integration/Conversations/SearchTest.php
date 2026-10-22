<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Messages;

/**
 * Searching the conversations on each backend: through the full-text index for words of three
 * letters up, by `LIKE` when a word is shorter than the index keeps; only in what the searcher reads
 * — a closed conversation is not searched by somebody outside it, though root searches everything;
 * never a deleted message.
 */
#[CoversClass(Messages::class)]
#[CoversClass(Conversations::class)]
class SearchTest extends TestCase
{
    use ConversationsProbe;

    /**
     * Every word must match, each at its start; short words fall back to LIKE; results are newest
     * first, with their conversation's name; closed conversations stay closed to outsiders.
     */
    #[DataProvider('databases')]
    public function testSearchingWhatOneReads(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        $general = Conversations::create('channel', 'general', [$maria], $eleni);
        $finance = Conversations::create('private', 'finance', [$maria], $eleni);
        $dm      = Conversations::create('dm', null, [$maria], $eleni);
        $first   = Messages::post($general, $eleni, 'Το τιμολόγιο του Μαρτίου στάλθηκε');
        $secret  = Messages::post($finance, $maria, 'Το τιμολόγιο της εταιρείας είναι λάθος');
        $private = Messages::post($dm, $maria, 'Το ΦΠΑ στο τιμολόγιο');
        $gone    = Messages::post($general, $maria, 'τιμολόγιο προς διαγραφή');
        Messages::delete($gone, $this->user('maria'));
        Messages::post($general, $eleni, 'άσχετο μήνυμα');

        // Act
        $maria_  = Messages::search($this->user('maria'), 'τιμολ');
        $nikos_  = Messages::search($this->user('nikos'), 'τιμολόγιο');
        $both    = Messages::search($this->user('maria'), 'τιμολόγιο Μαρτίου');
        $short   = Messages::search($this->user('maria'), 'ΦΠΑ σ');
        $inOne   = Messages::search($this->user('maria'), 'τιμολόγιο', $finance);
        $root    = Messages::search($this->user('root'), 'τιμολόγιο');
        $outside = Messages::search($this->user('nikos'), 'τιμολόγιο', $finance);

        // Assert
        $this->assertSame([$private, $secret, $first], array_column($maria_, 'id'), 'newest first, never the deleted');
        $this->assertSame(['Ελένη Σ., Μαρία Κ.', '#finance', '#general'], array_column($maria_, 'conversation'));
        $this->assertSame([$first], array_column($nikos_, 'id'), 'nikos reads the channel, not the closed ones');
        $this->assertSame([$first], array_column($both, 'id'), 'every word');
        $this->assertSame([$private], array_column($short, 'id'), 'a short word through LIKE');
        $this->assertSame([$secret], array_column($inOne, 'id'));
        $this->assertSame([$private, $secret, $first], array_column($root, 'id'), 'root reads everything');
        $this->assertSame([], $outside);
        $this->assertSame([], Messages::search($this->user('maria'), ' ;; '), 'no words, nothing');
        $this->assertSame([], Messages::search($this->user('tasos'), 'τιμολόγιο'), 'not one who uses them');
        $this->assertSame([], Messages::search($this->user('nikos'), 'λάθος', $finance));
        $this->assertSame([], Conversations::names([]));
    }

    /** Somebody in no conversation, with no open one to read, finds nothing and asks nothing. */
    #[DataProvider('databases')]
    public function testNothingToRead(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        Conversations::create('private', 'finance', [$this->ids['maria']], $this->ids['eleni']);

        // Act
        $found = Messages::search($this->user('nikos'), 'οτιδήποτε');

        // Assert
        $this->assertSame([], $found);
    }
}
