<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\SessionExchange;

/**
 * Where an exchanged token is allowed to travel.
 *
 * This is the half of the feature that is only wrong invisibly. A token appended as
 * `?token=…` works, reviews identically, and writes the credential into the access log
 * of every hop between the browser and the application — where it stays for as long as
 * logs are kept, and reaches `Referer` on the next outbound link.
 *
 * A fragment is never sent to a server. So the framework builds the URL rather than
 * documenting a rule and hoping, which is what the reporting application had to do by
 * hand along with three other decisions of the same kind.
 */
class SessionExchangeUrlTest extends TestCase
{
    /**
     * The token lands in the fragment, not the query string.
     *
     * @return void
     */
    public function testTheTokenGoesInTheFragment(): void
    {
        // Act
        $url = SessionExchange::redirectUrl('https://example.com/panel/', 'a.b.c');

        // Assert
        $this->assertSame('https://example.com/panel/#session=a.b.c', $url);
        $this->assertStringNotContainsString('?', $url, 'A query string reaches the access log.');
    }

    /**
     * A token containing URL-significant characters survives the round trip.
     *
     * JWTs are base64url and safe, but the signature alphabet is not guaranteed by this
     * method's contract — and a token that arrives truncated at a `&` fails as
     * "invalid credential", which sends the investigation to the verifier.
     *
     * @return void
     */
    public function testTheTokenIsEncoded(): void
    {
        // Act
        $url = SessionExchange::redirectUrl('https://example.com/panel/', 'a+b/c=d&e');

        // Assert
        $this->assertStringContainsString('#session=', $url);
        $this->assertStringNotContainsString('&e', $url);
        $this->assertSame(
            'a+b/c=d&e',
            rawurldecode(explode('#session=', $url)[1]),
            'The receiving page must get back exactly what was issued.'
        );
    }

    /**
     * A target that already has a fragment does not end up with two.
     *
     * Two fragments is not a thing; concatenating them produces a URL neither half
     * reads, and the SPA reports "no token" while the token is right there in the bar.
     *
     * @return void
     */
    public function testAnExistingFragmentIsReplaced(): void
    {
        // Act
        $url = SessionExchange::redirectUrl('https://example.com/panel/#/dashboard', 'a.b.c');

        // Assert
        $this->assertSame('https://example.com/panel/#session=a.b.c', $url);
        $this->assertSame(1, substr_count($url, '#'));
    }

    /**
     * The fragment key is the consumer's to choose.
     *
     * An application whose SPA router already owns `#` needs to say so rather than
     * discover a collision at runtime.
     *
     * @return void
     */
    public function testTheFragmentKeyCanBeChosen(): void
    {
        // Act
        $url = SessionExchange::redirectUrl('https://example.com/p/', 'a.b.c', 'handoff');

        // Assert
        $this->assertSame('https://example.com/p/#handoff=a.b.c', $url);
    }
}
