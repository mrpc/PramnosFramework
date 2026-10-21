<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Audience\ConsentLists;
use Pramnos\Audience\Filter;

/**
 * Consent lists on MySQL, prefixed MySQL and PostgreSQL: a send narrows its audience to a list,
 * an email list always respects the unsubscribe link, and a new account gets the lists' defaults.
 *
 * anna and bob agreed to SMS offers in their profile; cara did not.
 */
#[CoversClass(ConsentLists::class)]
#[CoversClass(Filter::class)]
class ConsentListsTest extends TestCase
{
    use AudienceProbe;

    /** The SMS source over the users column, and the consent of the three. */
    private function smsSource(): void
    {
        ConsentLists::source('smsoffers', 'Offers by SMS', ['conditions' => [['attribute' => 'smsoffers', 'op' => 'true']]], column: 'smsoffers');
        \Pramnos\Audience\Attributes::define(\Pramnos\Audience\Attribute::column('smsoffers', 'smsoffers', \Pramnos\Audience\Attribute::BOOLEAN));
        foreach (['anna' => 1, 'bob' => 1, 'cara' => 0] as $name => $agreed) {
            $this->db->queryBuilder()->table('users')->where('userid', $this->ids[$name])->update(['smsoffers' => $agreed]);
        }
    }

    /** An address leaving a list by the footer link. */
    private function optOut(string $email, string $list): void
    {
        $this->db->queryBuilder()->table('pramnos.emailoptouts')->insert(['email' => $email, 'list' => $list, 'source' => 'page', 'created_at' => time()]);
    }

    /**
     * A list narrows an audience to the people who agreed, says how many it took out, and answers
     * for one person — the three things a send's picker shows.
     */
    #[DataProvider('databases')]
    public function testAListNarrowsAnAudienceToThoseWhoAgreed(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->smsSource();
        $sms      = ConsentLists::create('SMS offers', 'sms', 'smsoffers', 'Offers and discounts by SMS');
        $everyone = Filter::fromArray([]);

        // Act
        $final   = ConsentLists::narrow($everyone, $sms);
        $removed = ConsentLists::removes([], $sms);

        // Assert
        $this->assertSame(['anna', 'bob'], $this->names($final->ids()));
        $this->assertSame(1, $removed, 'cara');
        $this->assertTrue(ConsentLists::allows($sms, $this->ids['anna']));
        $this->assertFalse(ConsentLists::allows($sms, $this->ids['cara']));
        $this->assertSame([], ConsentLists::filter($sms)['conditions'][1] ?? [], 'not email: no opt-out condition');
    }

    /**
     * An email list leaves out everybody who unsubscribed from everything and, when it names one,
     * from its own opt-out list — matched on the address whatever its case, as the footer link
     * records it lowercased.
     */
    #[DataProvider('databases')]
    public function testAnEmailListRespectsTheUnsubscribeLink(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->db->queryBuilder()->table('users')->where('userid', $this->ids['cara'])->update(['email' => 'Cara@Example.com']);
        $this->optOut('bob@example.com', 'all');
        $this->optOut('cara@example.com', 'newsletter');
        $news   = ConsentLists::create('Newsletter', 'email', 'everyone', optOutList: 'newsletter');
        $notice = ConsentLists::create('Service notices', 'email', 'everyone');

        // Act
        $newsIds   = ConsentLists::narrow([], $news)->ids();
        $noticeIds = ConsentLists::narrow([], $notice)->ids();
        $why       = ConsentLists::narrow([], $news)->explain($this->ids['cara']);

        // Assert
        $this->assertSame(['anna'], $this->names($newsIds));
        $this->assertSame(['anna', 'cara'], $this->names($noticeIds), '"all" counts for every email list');
        $this->assertSame(2, ConsentLists::removes([], $news));
        $optOuts = $why['conditions'][1]['conditions'][0];
        $this->assertSame('email_optouts', $optOuts['attribute']);
        $this->assertSame(['newsletter'], $optOuts['actual']);
        $this->assertFalse($optOuts['ok']);
    }

    /**
     * A new account gets each list's default in the column its source keeps it in; the list made
     * last wins where two read the same column, and a list over a source with no column writes
     * nothing.
     */
    #[DataProvider('databases')]
    public function testANewAccountGetsTheDefaults(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->smsSource();
        ConsentLists::create('SMS, old', 'sms', 'smsoffers', defaultOn: false);
        ConsentLists::create('SMS offers', 'sms', 'smsoffers', defaultOn: true);
        ConsentLists::create('Newsletter', 'email', 'everyone', defaultOn: true);

        // Act
        $written = ConsentLists::applyDefaults($this->ids['cara']);
        $value   = $this->db->queryBuilder()->table('users')->where('userid', $this->ids['cara'])->first()->fields['smsoffers'];

        // Assert
        $this->assertSame(['smsoffers' => 1], $written);
        $this->assertEquals(1, $value);
    }

    /**
     * What a list may be is checked when it is saved; an update changes only what it names; a
     * list in use is not deleted; and a list over a source the code no longer declares says so.
     */
    #[DataProvider('databases')]
    public function testListsAreCheckedAndProtected(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->smsSource();
        $refusals = [];
        foreach ([[' ', 'sms', 'smsoffers'], ['Fax', 'fax', 'everyone'], ['X', 'sms', 'carrier-pigeon']] as [$name, $channel, $source]) {
            try {
                ConsentLists::create($name, $channel, $source);
            } catch (\InvalidArgumentException $e) {
                $refusals[] = $e->getMessage();
            }
        }
        $sms = ConsentLists::create('SMS offers', 'sms', 'smsoffers', optOutList: '  ');
        $push = ConsentLists::create('Push news', 'push', 'everyone');
        ConsentLists::usage('rules', fn (int $id): array => $id === $sms ? ['rule Win-back'] : []);

        // Act
        ConsentLists::update($sms, ['description' => 'By SMS', 'defaultOn' => true, 'optOutList' => 'promo']);
        try {
            ConsentLists::delete($sms);
        } catch (\RuntimeException $e) {
            $refusals[] = $e->getMessage();
        }
        ConsentLists::delete($push);
        ConsentLists::reset();

        // Assert
        $this->assertSame([
            'A consent list needs a name.',
            'A consent list is for one of: email, sms, push, phone, site.',
            'No consent source carrier-pigeon is declared.',
            'This consent list is in use: rule Win-back',
        ], $refusals);
        $list = ConsentLists::get($sms);
        $this->assertSame(['SMS offers', 'sms', 'By SMS', true, 'promo'], [$list['name'], $list['channel'], $list['description'], $list['defaultOn'], $list['optOutList']]);
        $this->assertSame([$sms], array_column(ConsentLists::all('sms'), 'id'));
        $this->assertCount(1, ConsentLists::all());
        $this->assertNull(ConsentLists::get($push));
        $this->expectExceptionMessage('reads source smsoffers, which the application no longer declares');
        ConsentLists::filter($sms);
    }

    /** An unknown list is refused rather than read as "nobody agreed". */
    #[DataProvider('databases')]
    public function testAnUnknownListIsRefused(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);

        // Act
        $messages = [];
        foreach ([fn () => ConsentLists::filter(999), fn () => ConsentLists::update(999, ['name' => 'x'])] as $call) {
            try {
                $call();
            } catch (\InvalidArgumentException $e) {
                $messages[] = $e->getMessage();
            }
        }

        // Assert
        $this->assertSame(['No consent list 999.', 'No consent list 999.'], $messages);
    }
}
