<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Audience\Attribute;
use Pramnos\Audience\Attributes;
use Pramnos\Audience\Explained;
use Pramnos\Audience\Filter;
use Pramnos\Audience\PersonEvents;
use Pramnos\Audience\Segments;

/**
 * An explanation in words, on MySQL, prefixed MySQL and PostgreSQL: a list by its labels on both
 * sides, a boolean as yes or no, a date as a day, groups by name, an event by its count and last
 * time, a segment as in or out — what the "why" of a segment shows.
 */
#[CoversClass(Explained::class)]
class ExplainedTest extends TestCase
{
    use AudienceProbe;

    /**
     * Every kind of condition reads as the builder shows it, for cara: a professional, Greek, not
     * verified, in no group, who paid twice, in the Greek segment.
     */
    #[DataProvider('databases')]
    public function testConditionsReadAsTheBuilderShowsThem(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Attributes::define(Attribute::column('usertype', 'usertype', Attribute::LIST)->label('Account type')->values([1 => 'Parent', 2 => 'Professional']));
        PersonEvents::define('payment.made', 'Paid');
        PersonEvents::record($this->ids['cara'], 'payment.made', [], strtotime('2026-03-01 12:00'));
        PersonEvents::record($this->ids['cara'], 'payment.made', [], strtotime('2026-04-01 12:00'));
        $greek = Segments::create('Greek', ['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]]);
        Segments::recompute($greek);
        $why = Filter::fromArray(['conditions' => [
            ['attribute' => 'usertype', 'op' => 'one_of', 'value' => [1, 2]],
            ['attribute' => 'validated', 'op' => 'true'],
            ['attribute' => 'regdate', 'op' => 'after', 'value' => '2026-01-01'],
            ['attribute' => 'groups', 'op' => 'any_of', 'value' => [$this->groups['Press']]],
            ['attribute' => 'pillar', 'op' => 'is', 'value' => 1],
            ['event' => 'payment.made', 'op' => 'at_least', 'value' => 2, 'days' => 30],
            ['event' => 'login', 'op' => 'ever'],
            ['segment' => $greek, 'op' => 'not_in'],
        ]])->explain($this->ids['cara']);

        // Act
        $said = array_map([Explained::class, 'condition'], $why['conditions']);

        // Assert
        $this->assertSame(['what' => 'Account type', 'how' => 'is one of', 'value' => 'Parent, Professional', 'actual' => 'Professional'], $said[0]);
        $this->assertSame(['what' => 'Email verified', 'how' => 'yes', 'value' => null, 'actual' => 'no'], $said[1]);
        $this->assertSame(['2026-01-01', '2026-03-01'], [$said[2]['value'], $said[2]['actual']]);
        $this->assertSame(['Press', '—'], [$said[3]['value'], $said[3]['actual']]);
        $this->assertSame(['1', '1'], [$said[4]['value'], $said[4]['actual']]);
        $this->assertSame(['Paid', 'at least … times', '2 / 30 days', '2 times, last 2026-04-01 · 0 in the window'], array_values($said[5]));
        $this->assertSame(['login', 'never'], [$said[6]['what'], $said[6]['actual']]);
        $this->assertSame(['what' => 'Greek', 'how' => 'is not in segment', 'value' => null, 'actual' => 'in it'], $said[7]);
        $this->assertSame('not in it', Explained::condition(['segment' => 9, 'op' => 'in', 'label' => 'X', 'actual' => false])['actual']);
        $this->assertSame('—', Explained::value(Attributes::get('lastlogin'), 0));
    }
}
