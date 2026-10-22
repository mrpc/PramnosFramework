<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Moderation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Conversations\ModeratorCommands;
use Pramnos\Moderation\Filter;
use Pramnos\Moderation\Subject;

/**
 * What the filter lets through, masks and refuses; how a subject is named; how a moderator's
 * duration reads. No database: these are the rules themselves.
 */
#[CoversClass(Filter::class)]
#[CoversClass(Subject::class)]
#[CoversClass(ModeratorCommands::class)]
final class FilterTest extends TestCase
{
    /**
     * Markup that would run where a message is shown is refused whatever the rules — a script, a
     * frame, an event handler, a `javascript:` address — while text that only looks like it is not:
     * "onion = 3" names no handler.
     */
    public function testMarkupThatWouldRunIsRefused(): void
    {
        // Arrange
        $bad  = ['<script>alert(1)</script>', 'δες <iframe src=x>', '<img src=x onerror=alert(1)>', '[x](javascript:alert(1))', 'data:text/html,<b>'];
        $fine = ['onion = 3', 'το script του Γιάννη', 'a < b και c > d'];

        // Act
        $refused = array_map(static fn (string $t): bool => Filter::apply($t, [])['allowed'], $bad);
        $allowed = array_map(static fn (string $t): bool => Filter::apply($t, [])['allowed'], $fine);

        // Assert
        $this->assertSame([false, false, false, false, false], $refused);
        $this->assertSame([true, true, true], $allowed);
        $this->assertSame(['markup'], Filter::apply('<script>', [])['reasons']);
    }

    /**
     * Listed words are masked whole and ignoring case, letter for letter — a word inside another is
     * left alone; a listed phrase refuses the whole message.
     */
    public function testWordsAreMaskedAndPhrasesRefused(): void
    {
        // Arrange
        $rules = ['words' => ['βλάκας', 'idiot', ''], 'refuse' => ['κάνε κλικ εδώ', '']];

        // Act
        $masked  = Filter::apply('Είσαι ΒΛΆΚΑς, idiot! Οι idiots όχι.', $rules);
        $refused = Filter::apply('Κάνε κλικ εδώ τώρα', $rules);
        $clean   = Filter::apply('Καλημέρα', $rules);

        // Assert
        $this->assertSame('Είσαι ******, *****! Οι idiots όχι.', $masked['text'], 'whole words only');
        $this->assertSame(['words'], $masked['reasons']);
        $this->assertSame([false, ['refused']], [$refused['allowed'], $refused['reasons']]);
        $this->assertSame(['Καλημέρα', []], [$clean['text'], $clean['reasons']]);
    }

    /**
     * Links: every one allowed by default; `deny` masks each; `only` keeps the listed sites and
     * their subdomains and masks the rest — a look-alike host that merely ends in the name is not
     * the site.
     */
    public function testLinksAllowedDeniedOrKeptToSomeSites(): void
    {
        // Arrange
        $text = 'Δες https://shop.example.com/a και www.evil.test και https://notexample.com/x';

        // Act
        $all  = Filter::apply($text, []);
        $none = Filter::apply($text, ['links' => 'deny']);
        $only = Filter::apply($text, ['links' => 'only', 'sites' => ['example.com']]);
        $kept = Filter::apply('https://example.com', ['links' => 'only', 'sites' => ['example.com']]);

        // Assert
        $this->assertSame($text, $all['text']);
        $this->assertSame('Δες *** και *** και ***', $none['text']);
        $this->assertSame('Δες https://shop.example.com/a και *** και ***', $only['text']);
        $this->assertSame(['links'], $only['reasons']);
        $this->assertSame([], $kept['reasons'], 'an allowed link changes nothing');
    }

    /**
     * A subject is a kind and a value; a nickname compares trimmed and lower-case; an unknown kind or
     * nothing to name them by is refused.
     */
    public function testSubjectsAreNamedAsTheyCompare(): void
    {
        // Arrange
        $refusals = [];

        // Act
        foreach ([['robot', 'x'], ['ip', '  '], ['visitor', str_repeat('x', 192)]] as [$kind, $value]) {
            try {
                Subject::of($kind, $value);
            } catch (\InvalidArgumentException $e) {
                $refusals[] = $kind;
            }
        }

        // Assert
        $this->assertSame('nickname:μαρία', Subject::nickname('  Μαρία ')->key());
        $this->assertTrue(Subject::account(5)->is(Subject::of('account', '5')));
        $this->assertFalse(Subject::account(5)->is(Subject::visitor('5')), 'the kind is part of it');
        $this->assertSame(['ip:203.0.113.4', 'visitor:abc'], [Subject::ip('203.0.113.4')->key(), Subject::visitor('abc')->key()]);
        $this->assertSame(['robot', 'ip', 'visitor'], $refusals);
    }

    /** A moderator's duration: seconds, minutes, hours, days — and nothing else, nor zero. */
    public function testAModeratorsDuration(): void
    {
        // Act / Assert
        $this->assertSame([45, 600, 7200, 259200], array_map([ModeratorCommands::class, 'duration'], ['45s', '10m', '2h', '3D']));
        $this->assertSame([null, null, null], array_map([ModeratorCommands::class, 'duration'], ['0m', '10', 'ten minutes']));
    }
}
