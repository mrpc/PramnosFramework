<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Logs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Logs\PagePath;

/**
 * A page's path as the logs keep it: a segment with a digit or an `@`, or a long one, is `:id`;
 * one with other characters is `:x`; lower case, six segments at most, no query; an address keeps
 * its scheme and host.
 */
#[CoversClass(PagePath::class)]
class PagePathTest extends TestCase
{
    /** Tokens, ids, emails and the rest masked; words kept. */
    public function testWhatIsMasked(): void
    {
        // Arrange
        $cases = [
            '/el/account/rpc/u/48211/v/9F3C'   => '/el/account/rpc/u/:id/v',
            '/rsvp/12/34/abc'                  => '/rsvp/:id/:id/abc',
            '/el/Jobs/'                        => '/el/jobs/',
            '/'                                => '/',
            ''                                 => '/',
            '/el/user@example.gr'              => '/el/:id',
            '/el/Μαρία'                        => '/el/:x',
            '/p/' . str_repeat('a', 61)        => '/p/:id',
            '/search?q=secret#top'             => '/search',
        ];

        // Act
        $masked = array_map(PagePath::mask(...), array_keys($cases));

        // Assert
        $this->assertSame(array_values($cases), $masked);
        $this->assertSame('https://site.test:8443/el/reset/:id', PagePath::maskUrl('https://Site.TEST:8443/el/reset/a1b2?t=1'));
        $this->assertSame('/el/:id', PagePath::maskUrl('/el/42'), 'a path alone, as a path');
    }
}
