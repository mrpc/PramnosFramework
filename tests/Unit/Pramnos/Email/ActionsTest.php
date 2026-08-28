<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Email\Actions;
use Pramnos\Email\Email;
use Pramnos\Email\PlainText;

/**
 * The schema.org blocks Gmail reads out of a message.
 *
 * Gmail draws a control **in the message list** — a "Confirm" button beside the subject, before
 * the message is opened — when it finds an `ld+json` block it recognises. That is the difference
 * between a confirmation that takes one tap and one that takes four, and the markup for it is
 * nested JSON nobody writes correctly from memory.
 *
 * Two things this file is really guarding. That the encoding cannot break the message: a
 * `</script>` inside any value would end the block early and everything after it would be parsed
 * as markup, and the values here come from record titles and user input. And that a builder with
 * nothing to describe produces **nothing** rather than an empty claim.
 */
#[CoversClass(Actions::class)]
#[CoversClass(Email::class)]
class ActionsTest extends TestCase
{
    /** An `Email` with the protected embedding exposed. */
    private function sender(): Email
    {
        return new class extends Email {
            public function embed(string $html): string
            {
                return $this->embedStructuredData($html);
            }

            public function markup(): string
            {
                return $this->structuredDataMarkup();
            }
        };
    }

    // ── the builders ─────────────────────────────────────────────────────────

    /**
     * A confirm action names a POST handler.
     *
     * Gmail issues a POST, and a handler that only accepts GET silently does nothing — so the
     * method is stated rather than left to a default.
     */
    public function testConfirmNamesAPostHandler(): void
    {
        // Act
        $action = Actions::confirm('Confirm address', 'https://example.com/c/abc', 'Nearly done');

        // Assert
        $this->assertSame('EmailMessage', $action['@type']);
        $this->assertSame('Nearly done', $action['description']);
        $this->assertSame('ConfirmAction', $action['potentialAction']['@type']);
        $this->assertSame('Confirm address', $action['potentialAction']['name']);
        $this->assertSame(
            'https://example.com/c/abc',
            $action['potentialAction']['handler']['url']
        );
        $this->assertStringContainsString(
            'POST',
            $action['potentialAction']['handler']['method']
        );
    }

    /**
     * A view action carries a target rather than a handler.
     *
     * Different key for a different verb: `target` is a place to go, `handler` is something to
     * call. Gmail ignores an action with the wrong one.
     */
    public function testViewCarriesATarget(): void
    {
        // Act
        $action = Actions::view('View invoice', 'https://example.com/i/1');

        // Assert
        $this->assertSame('ViewAction', $action['potentialAction']['@type']);
        $this->assertSame('https://example.com/i/1', $action['potentialAction']['target']);
        $this->assertArrayNotHasKey('handler', $action['potentialAction']);
        $this->assertArrayNotHasKey('description', $action, 'absent, not an empty string');
    }

    /**
     * A save action is a handler, not a target.
     *
     * "Save 20%" adds the offer to the reader's account; it does not navigate anywhere. Using
     * `target` here — the shape a view action wants — makes Gmail ignore it.
     */
    public function testSaveIsAHandler(): void
    {
        // Act
        $action = Actions::save('Save 20%', 'https://example.com/save/abc');

        // Assert
        $this->assertSame('SaveAction', $action['potentialAction']['@type']);
        $this->assertSame(
            'https://example.com/save/abc',
            $action['potentialAction']['handler']['url']
        );
        $this->assertArrayNotHasKey('target', $action['potentialAction']);
    }

    /**
     * An RSVP is three handlers, one per answer.
     *
     * The answer *is* which URL was called — a single endpoint with the reply in a query string
     * is not what Gmail sends. An answer with no URL is left out rather than pointed at nothing.
     */
    public function testRsvpIsOneHandlerPerAnswer(): void
    {
        // Act
        $action = Actions::rsvp([
            'yes' => 'https://example.com/yes',
            'no'  => 'https://example.com/no',
        ]);

        // Assert
        $this->assertCount(2, $action['potentialAction'], 'maybe was not offered');
        $this->assertStringContainsString('RsvpResponseYes', $action['potentialAction'][0]['rsvpResponse']);
        $this->assertStringContainsString('RsvpResponseNo', $action['potentialAction'][1]['rsvpResponse']);
    }

    /**
     * A builder with nothing to describe returns nothing.
     *
     * Not an `EmailMessage` with an empty action list. A `<script>` containing `[]` is a claim
     * that the message has no actions, which is a different statement from making no claim — the
     * same rule the JSON-LD encoder documents, applied one level up.
     */
    public function testABuilderWithNothingToSayReturnsNothing(): void
    {
        // Assert
        $this->assertSame([], Actions::rsvp([]));
        $this->assertSame([], Actions::promotion([]));
        $this->assertSame([], Actions::promotion(['image' => 'https://example.com/a.png']));
    }

    /**
     * A promotion needs a title, and its expiry is ISO 8601.
     *
     * A date in any other format is dropped without comment, so a timestamp, a `DateTime` and a
     * human string are all converted rather than passed through.
     */
    public function testAPromotionIsBuiltWithAnIsoExpiry(): void
    {
        // Act
        $fromString = Actions::promotion([
            'title'   => 'Spring sale',
            'expires' => '2026-09-30 23:59:59',
            'code'    => 'SPRING',
        ]);
        $fromTimestamp = Actions::promotion([
            'title'   => 'Spring sale',
            'expires' => mktime(23, 59, 59, 9, 30, 2026),
        ]);
        $fromObject = Actions::promotion([
            'title'   => 'Spring sale',
            'expires' => new \DateTimeImmutable('2026-09-30 23:59:59'),
        ]);

        // Assert
        $this->assertSame('DiscountOffer', $fromString['@type']);
        $this->assertSame('Spring sale', $fromString['promotion']['name']);
        $this->assertSame('SPRING', $fromString['promotion']['discountCode']);

        foreach ([$fromString, $fromTimestamp, $fromObject] as $offer) {
            $this->assertMatchesRegularExpression(
                '~^2026-09-30T23:59:59~',
                $offer['promotion']['availabilityEnds']
            );
        }

        // An unparseable date is left out rather than guessed at
        $unparseable = Actions::promotion(['title' => 'x', 'expires' => 'whenever']);
        $this->assertArrayNotHasKey('availabilityEnds', $unparseable['promotion']);
    }

    /**
     * The sender block omits what it has no value for.
     */
    public function testTheSenderBlockOmitsWhatItLacks(): void
    {
        // Act
        $withUrl    = Actions::sender('Acme', 'https://example.com/logo.png', 'https://example.com');
        $withoutUrl = Actions::sender('Acme', 'https://example.com/logo.png');

        // Assert
        $this->assertSame('Organization', $withUrl['@type']);
        $this->assertSame('https://example.com', $withUrl['url']);
        $this->assertArrayNotHasKey('url', $withoutUrl, 'a `"url": ""` claims there is no site');
    }

    /**
     * The requirements are returned as data, not only written in a guide.
     *
     * Because the failure mode is somebody concluding the code is broken when the button does
     * not appear — and the actual cause, that the domain is not registered with Google, is not
     * visible from anywhere inside the application.
     */
    public function testTheRequirementsAreAvailableAsData(): void
    {
        // Act
        $requirements = Actions::requirements();

        // Assert
        $this->assertNotEmpty($requirements);
        $this->assertStringContainsString('registered with Google', $requirements[0]);
        $this->assertStringContainsString(
            'first request',
            implode(' ', $requirements),
            'the ConfirmAction contract is the one that silently fails'
        );
    }

    // ── embedding ────────────────────────────────────────────────────────────

    /**
     * A block goes into the head, where Gmail's own documentation puts it.
     */
    public function testABlockGoesIntoTheHead(): void
    {
        // Arrange
        $mail = $this->sender();
        $mail->addStructuredData(Actions::view('View', 'https://example.com/x'));

        // Act
        $html = $mail->embed('<html><head><title>x</title></head><body><p>Hi</p></body></html>');

        // Assert
        $this->assertStringContainsString('application/ld+json', $html);
        $this->assertLessThan(
            strpos($html, '</head>'),
            strpos($html, 'ld+json'),
            'inside the head, not after it'
        );
    }

    /**
     * A body fragment with no head still gets them.
     *
     * A template that renders only the content produces one, and dropping the block there would
     * make the feature depend on which template an application happens to use.
     */
    public function testAFragmentWithNoHeadStillGetsThem(): void
    {
        // Arrange
        $mail = $this->sender();
        $mail->addStructuredData(Actions::view('View', 'https://example.com/x'));

        // Act
        $fragment = $mail->embed('<p>Just the content.</p>');
        $bodyOnly = $mail->embed('<body><p>Content.</p></body>');

        // Assert
        $this->assertStringContainsString('ld+json', $fragment);
        $this->assertStringContainsString('Just the content.', $fragment);
        $this->assertLessThan(
            strpos($bodyOnly, '</body>'),
            strpos($bodyOnly, 'ld+json')
        );
    }

    /**
     * Nothing to embed changes nothing.
     *
     * Not an empty `<script>`, and not a modified message: a mail that uses none of this must be
     * byte-for-byte what it was.
     */
    public function testNothingToEmbedChangesNothing(): void
    {
        // Arrange
        $mail = $this->sender();
        $mail->addStructuredData([]);   // ignored

        // Act
        $html = '<html><head></head><body><p>Hi</p></body></html>';

        // Assert
        $this->assertSame($html, $mail->embed($html));
        $this->assertSame('', $mail->markup());
    }

    /**
     * A `</script>` in a value cannot end the block.
     *
     * The only injection this format has, and the values come from record titles and user input.
     * Without the HEX_TAG flag the block ends early and the rest of it is parsed as markup — so
     * the encoder is `Seo::jsonLd()` rather than `json_encode()`, and this is the assertion that
     * it stayed that way.
     */
    public function testAScriptTagInAValueCannotEndTheBlock(): void
    {
        // Arrange
        $mail = $this->sender();
        $mail->addStructuredData(Actions::view(
            'View </script><script>alert(1)</script>',
            'https://example.com/x'
        ));

        // Act
        $markup = $mail->markup();

        // Assert
        $this->assertStringNotContainsString('</script><script>alert(1)', $markup);
        $this->assertSame(
            1,
            substr_count($markup, '</script>'),
            'exactly one closing tag: the one that ends the block'
        );
    }

    /**
     * The blocks never reach the plain-text part.
     *
     * `PlainText` drops `head` and `script` outright, so the text half does not begin with a
     * paragraph of JSON — which would be both unreadable and a difference between the two halves
     * of the message.
     */
    public function testTheBlocksAreNotInThePlainTextPart(): void
    {
        // Arrange
        $mail = $this->sender();
        $mail->addStructuredData(Actions::confirm('Confirm', 'https://example.com/c'));

        // Act
        $html = $mail->embed('<html><head></head><body><p>Please confirm.</p></body></html>');
        $text = PlainText::fromHtml($html);

        // Assert
        $this->assertSame('Please confirm.', $text);
        $this->assertStringNotContainsString('schema.org', $text);
    }

    // ── where the framework uses this itself ─────────────────────────────────

    /**
     * A `ViewAction` needs no handler, which is why one of the framework's own mails has one.
     *
     * The distinction that was got wrong first time round: the one-request contract belongs to
     * `ConfirmAction` alone. A `ViewAction` is a URL — no POST, no immediacy, nothing to build —
     * so "we have no handler for it" was never a reason not to use one.
     *
     * The password-reset mail contains exactly one link, which is its entire purpose, so an
     * action pointing at that link exposes nothing the message did not already expose and turns
     * four taps on a phone into one.
     */
    public function testAViewActionNeedsNothingBuiltForIt(): void
    {
        // Arrange
        $action = Actions::view('Reset password', 'https://example.com/reset/abc123');

        // Assert
        $this->assertArrayNotHasKey(
            'handler',
            $action['potentialAction'],
            'nothing to receive a POST, so nothing to build'
        );
        $this->assertSame('https://example.com/reset/abc123', $action['potentialAction']['target']);

        // And the requirement that stops `confirm` being used the same way is about `confirm`
        $requirements = implode(' ', Actions::requirements());
        $this->assertStringContainsString('ConfirmAction handler must act', $requirements);
    }

    /**
     * The new-sign-in alert deliberately carries no action.
     *
     * Asserted because it is a decision that reads like an omission, and the next person to add
     * "one-tap review your sessions" will be improving the product. A link in an unexpected
     * security email is the shape of the attack the message warns about — and a button in the
     * message list is the same thing, larger and easier to press. The notification says so in
     * its own docblock; this is the assertion that it stays true.
     */
    public function testTheNewSignInAlertOffersNoActionOnPurpose(): void
    {
        // Act
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/src/Pramnos/Auth/Notifications/NewSignInNotification.php'
        );

        // Assert
        $this->assertStringNotContainsString('addStructuredData', $source);
        $this->assertStringNotContainsString('Actions::', $source);
        $this->assertStringContainsString(
            'rather than following a link in an email',
            $source,
            'the reason is stated in the message itself'
        );
    }
}
