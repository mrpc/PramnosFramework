<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Email\Email;
use Pramnos\Email\Unsubscribe;

/**
 * Unsubscribe links, and the headers a mailbox provider actually reads.
 *
 * Gmail and Yahoo require both of them from anyone sending in volume, and they do not report a
 * failure back: a sender whose header is malformed, or whose one-click endpoint refuses, is not
 * blocked with an error — the mail is quietly filed as spam, including the mail people wanted.
 * So every part of this is asserted rather than eyeballed in a dump, where a wrong header looks
 * exactly like a right one.
 *
 * The token is the part worth being careful about: it is the only authorisation the endpoint
 * has, because a one-click request arrives from a provider's server with no session and no
 * login. Anything that let a token be edited into naming a different address would be a way to
 * unsubscribe strangers.
 */
#[CoversClass(Unsubscribe::class)]
#[CoversClass(Email::class)]
class UnsubscribeTest extends TestCase
{
    protected function tearDown(): void
    {
        Unsubscribe::reset();
        parent::tearDown();
    }

    /**
     * A token names one address and one list, and comes back saying so.
     */
    public function testATokenRoundTrips(): void
    {
        // Act
        $claim = Unsubscribe::verify(Unsubscribe::token('Someone@Example.COM', 'Marketing'));

        // Assert — normalised on the way in, so one address is one record
        $this->assertSame(['email' => 'someone@example.com', 'list' => 'marketing'], $claim);
    }

    /**
     * An edited token does not verify.
     *
     * The whole security model: the endpoint takes no login, so a token that could be rewritten
     * into naming somebody else's address would be a way to unsubscribe strangers — and the
     * first anybody would know is a customer asking why they stopped receiving mail.
     */
    public function testAnEditedTokenIsRefused(): void
    {
        // Arrange
        $token = Unsubscribe::token('victim@example.com', 'marketing');

        // Act — swap the payload, keep the signature
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        [, $list, $signature] = explode('|', (string) $decoded);
        $forged = rtrim(strtr(base64_encode(
            'attacker@example.com|' . $list . '|' . $signature
        ), '+/', '-_'), '=');

        // Assert
        $this->assertNull(Unsubscribe::verify($forged));
        $this->assertNull(Unsubscribe::verify('nonsense'));
        $this->assertNull(Unsubscribe::verify(''));
    }

    /**
     * A missing list means "everything", not an empty string.
     *
     * `all` is the reserved name every suppression check also looks for, so a token with no
     * list has to land on it — otherwise a link in a message that named no list would
     * unsubscribe the reader from a list called nothing.
     */
    public function testAnEmptyListBecomesAll(): void
    {
        // Act
        $claim = Unsubscribe::verify(Unsubscribe::token('someone@example.com', '   '));

        // Assert
        $this->assertSame(Unsubscribe::LIST_ALL, $claim['list']);
    }

    /**
     * The header carries each entry in angle brackets, comma separated.
     *
     * RFC 2369, and the reason this is a test rather than a comment: a bare URL in
     * `List-Unsubscribe` is ignored, silently. The header was there, read correctly in a dump,
     * and did nothing at all — which is the same as not sending it, except that it looks like
     * compliance.
     */
    public function testTheHeaderValueIsBracketedAndComposed(): void
    {
        // Arrange
        $email = new class extends Email {
            public function value(): string
            {
                return $this->unsubscribeHeaderValue();
            }
        };
        $email->unsubscribe       = 'https://example.com/unsubscribe?u=abc';
        $email->unsubscribeMailto = 'mailto:list@example.com?subject=unsubscribe';

        // Act
        $value = $email->value();

        // Assert
        $this->assertSame(
            '<https://example.com/unsubscribe?u=abc>, <mailto:list@example.com?subject=unsubscribe>',
            $value
        );
    }

    /**
     * A value a caller already bracketed is not bracketed twice.
     *
     * The property predates the helper and applications set it by hand, so both spellings have
     * to produce a valid header. `<<url>>` is not one.
     */
    public function testAnAlreadyBracketedValueIsLeftAlone(): void
    {
        // Arrange
        $email = new class extends Email {
            public function value(): string
            {
                return $this->unsubscribeHeaderValue();
            }
        };
        $email->unsubscribe = '<https://example.com/u?x=1>';

        // Act & Assert
        $this->assertSame('<https://example.com/u?x=1>', $email->value());
    }

    /**
     * With nothing to offer, there is no header rather than an empty one.
     */
    public function testNoOfferMeansNoHeader(): void
    {
        // Arrange
        $email = new class extends Email {
            public function value(): string
            {
                return $this->unsubscribeHeaderValue();
            }
        };

        // Act & Assert
        $this->assertSame('', $email->value());
    }

    /**
     * `offerUnsubscribe()` sets all four things at once, and they agree.
     *
     * Separately settable, they can contradict each other: a `List-Unsubscribe-Post` promising
     * one-click over a URL that shows a confirmation page is worse than no header at all,
     * because a provider that follows it and gets a page counts the message as unhandled.
     */
    public function testOfferUnsubscribeSetsTheWholeArrangement(): void
    {
        // Arrange
        $email = new Email();
        $email->to = 'reader@example.com';

        // Act
        $email->offerUnsubscribe('digest');

        // Assert
        $this->assertSame('digest', $email->unsubscribeList);
        $this->assertTrue($email->unsubscribeOneClick, 'the endpoint accepts a POST');
        $this->assertStringContainsString('unsubscribe?u=', (string) $email->unsubscribe);

        // …and the link it built names this reader and this list
        parse_str((string) parse_url((string) $email->unsubscribe, PHP_URL_QUERY), $query);
        $this->assertSame(
            ['email' => 'reader@example.com', 'list' => 'digest'],
            Unsubscribe::verify((string) $query['u'])
        );
    }

    /**
     * With no recipient there is nothing to offer, and no half-built link.
     *
     * A token over an empty address would verify — it is properly signed — and unsubscribe
     * nobody, while the message went out looking as though it had a working link.
     */
    public function testNoRecipientMeansNoLink(): void
    {
        // Arrange
        $email = new Email();

        // Act
        $email->offerUnsubscribe('digest');

        // Assert
        $this->assertNull($email->unsubscribe);
        $this->assertFalse($email->unsubscribeOneClick);
    }

    /**
     * A list can say what unsubscribing from it means.
     *
     * For a list backed by a preference the person can see, flipping that preference is the
     * whole job: a suppression record the profile screen knows nothing about would stop the
     * mail while the checkbox still said it was on — a switch that lies to the person holding
     * it.
     */
    public function testARegisteredHandlerDecidesWhatOptingOutMeans(): void
    {
        // Arrange
        $seen = [];
        Unsubscribe::handle('digest', function (string $email, string $list) use (&$seen): void {
            $seen = [$email, $list];
        });

        // Act — through the protected seam, so this half needs no database
        $probe = new class extends Unsubscribe {
            public static function run(string $email, string $list): void
            {
                static::applyOptOut($email, $list);
            }
        };
        $probe::run('reader@example.com', 'digest');

        // Assert
        $this->assertSame(['reader@example.com', 'digest'], $seen);
    }
}
