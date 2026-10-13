<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Llm\Analyst\Redactor;
use Pramnos\Llm\Analyst\Tools\Window;

/**
 * What the analyst sends its provider: personal data and secrets masked, and the dates a
 * question is about.
 *
 * The provider is a third party and a log line carries whatever somebody typed, so every
 * shape of personal data a log or an email subject holds is masked — and the numbers that
 * only look like one (an order number, a Unix time, a date, a version) are left alone, or the
 * figures the analyst reads become unreadable.
 */
#[CoversClass(Redactor::class)]
#[CoversClass(Window::class)]
class AnalystRedactionTest extends TestCase
{
    /** @return array<string, array{string, string}> What is masked, and to what */
    public static function masked(): array
    {
        return [
            'an email'                  => ['mail john.doe@example.com failed', 'mail j***@e***.com failed'],
            'an international phone'    => ['call +30 694 123 4567', 'call [phone]'],
            'a phone with parentheses'  => ['call (210) 123-4567 now', 'call [phone] now'],
            'a phone with dots'         => ['call 210.123.4567', 'call [phone]'],
            'a card in groups'          => ['card 4111 1111 1111 1111 declined', 'card [card] declined'],
            'a card in one run'         => ['card 4111111111111111', 'card [card]'],
            'an IPv4 address'           => ['from 192.168.1.10 at noon', 'from [ip] at noon'],
            'an IPv6 address'           => ['from 2001:db8::ff00:42:8329', 'from [ip]'],
            'loopback IPv6'             => ['bound to ::1', 'bound to [ip]'],
            'a bearer token'            => ['Authorization: Bearer abc.def-ghi', 'Authorization: Bearer [token]'],
            'an API key'                => ['key sk-abcdefghijklmnopqrstuv used', 'key [token] used'],
            'a long hex token'          => ['reset 0123456789abcdef0123456789abcdef', 'reset [token]'],
            'a JWT'                     => ['jwt eyJhbGc.eyJzdWIi.c2ln', 'jwt [token]'],
        ];
    }

    /** Each shape of personal data or secret is replaced by what it was. */
    #[DataProvider('masked')]
    public function testItIsMasked(string $text, string $expected): void
    {
        // Act / Assert
        $this->assertSame($expected, Redactor::clean($text));
    }

    /** @return array<string, array{string}> What looks like personal data and is not */
    public static function nearMisses(): array
    {
        return [
            'an order number'        => ['order 1234567890 shipped'],
            'a Unix time'            => ['at 1728820000'],
            'a date and a time'      => ['on 2026-10-13 12:30:45'],
            'a method call'          => ['in Feed::add and Db::query'],
            'a version'              => ['framework v1.2.3.4'],
            'a number that fails Luhn' => ['ref 1234567890123'],
            'a formatted amount'     => ['amount 12.345.678 euros, 3.14'],
            'a bad IPv4'             => ['code 300.1.1.1'],
        ];
    }

    /** A figure that only looks like personal data stays as it is. */
    #[DataProvider('nearMisses')]
    public function testANearMissIsLeftAlone(string $text): void
    {
        // Act / Assert
        $this->assertSame($text, Redactor::clean($text));
    }

    /** The one email a lookup was asked for survives; any other is masked. */
    public function testTheEmailAskedForIsKept(): void
    {
        // Act
        $clean = Redactor::clean('keep me@x.org but not you@y.org', ['me@x.org', '']);

        // Assert
        $this->assertSame('keep me@x.org but not y***@y***.org', $clean);
    }

    /**
     * A window is the last N days, or from/to; its periods follow its length unless asked; and
     * it is never longer than a year.
     */
    public function testTheWindow(): void
    {
        // Arrange
        $now = gmmktime(12, 0, 0, 10, 13, 2026);

        // Act
        $month  = Window::from([], $now);
        $span   = Window::from(['from' => '2026-01-01', 'to' => '2026-03-31'], $now);
        $year   = Window::from(['days' => 9999, 'group' => 'month'], $now);
        $longTo = Window::from(['from' => '2020-01-01', 'to' => '2026-01-01'], $now);

        // Assert
        $this->assertSame(['from' => '2026-09-13', 'to' => '2026-10-13', 'group' => 'day'], $month->describe());
        $this->assertSame('week', $span->group);
        $this->assertSame('2026-W01', $span->label(gmmktime(0, 0, 0, 1, 1, 2026)));
        $this->assertNull($span->label($now), 'after the window');
        $this->assertSame('month', $year->group);
        $this->assertArrayHasKey('2026-10', $year->buckets());
        $this->assertSame(gmmktime(0, 0, 0, 1, 1, 2026) + 86399 - 366 * 86400, $longTo->from, 'clamped to a year');
        $this->assertCount(31, $month->buckets());
    }

    /** A date that is not one, or a window that ends before it starts, is said. */
    public function testABadWindowIsRefused(): void
    {
        // Act / Assert
        foreach ([['from' => 'yesterday'], ['from' => '2026-10-10', 'to' => '2026-10-01']] as $input) {
            try {
                Window::from($input);
                $this->fail('accepted ' . json_encode($input));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }
}
