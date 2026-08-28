<?php

declare(strict_types=1);

namespace Pramnos\Email;

/**
 * The schema.org blocks Gmail reads out of an email, built so nobody has to remember them.
 *
 * Gmail looks for `application/ld+json` in a message and, when it finds one it recognises, draws
 * a control **in the message list** — a "Confirm" button beside the subject, before the message
 * is opened. That is the difference between a confirmation mail that takes one tap and one that
 * takes four, and the markup for it is nine lines of nested JSON that nobody writes correctly
 * from memory.
 *
 * **Gmail will not display any of this until the sending domain is registered with Google.** That
 * is not a bug in this code and it is the first thing anybody concludes when the button does not
 * appear, so it is said here, in the guide, and in the return value of {@see requirements()}.
 * Everything below is still correct and harmless without registration: other clients ignore what
 * they do not understand, and the markup is invisible in the rendered message.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class Actions
{
    /**
     * "Confirm my address", "Confirm my booking" — a one-tap confirmation from the inbox.
     *
     * The action Gmail is most useful for, and the one with a condition attached: the URL must
     * do the thing **on the first request, with no confirmation page and no sign-in**. Gmail
     * calls it when the reader taps the button, and a handler that answers with a "are you sure?"
     * page turns a one-tap action into a dead end.
     *
     * @param  string $name        What the button says — short, and a verb
     * @param  string $url         The handler, which must act immediately
     * @param  string $description The one-line summary Gmail may show beside it
     * @return array<string, mixed>
     */
    public static function confirm(string $name, string $url, string $description = ''): array
    {
        return self::message($description, [
            '@type'  => 'ConfirmAction',
            'name'   => $name,
            'handler' => [
                '@type' => 'HttpActionHandler',
                'url'   => $url,
                // Named explicitly: Gmail issues a POST, and a handler that only accepts GET
                // silently does nothing.
                'method' => 'http://schema.org/HttpRequestMethod/POST',
            ],
        ]);
    }

    /**
     * "View invoice", "Track shipment" — a link, promoted to a button in the list.
     *
     * @param  string $name        What the button says
     * @param  string $url         Where it goes
     * @param  string $description The one-line summary
     * @return array<string, mixed>
     */
    public static function view(string $name, string $url, string $description = ''): array
    {
        return self::message($description, [
            '@type'  => 'ViewAction',
            'name'   => $name,
            'target' => $url,
        ]);
    }

    /**
     * "Save 20%" — an offer, saved to the reader's account rather than opened.
     *
     * @param  string $name        What the button says
     * @param  string $url         The handler
     * @param  string $description The one-line summary
     * @return array<string, mixed>
     */
    public static function save(string $name, string $url, string $description = ''): array
    {
        return self::message($description, [
            '@type'   => 'SaveAction',
            'name'    => $name,
            'handler' => ['@type' => 'HttpActionHandler', 'url' => $url],
        ]);
    }

    /**
     * "Yes / No / Maybe" for an invitation, answered from the list.
     *
     * Three handlers rather than one, because the answer *is* which URL was called — a single
     * endpoint with the reply in a query string is not what Gmail sends.
     *
     * @param  array{yes: string, no: string, maybe?: string} $urls One handler per answer
     * @return array<string, mixed>
     */
    public static function rsvp(array $urls, string $description = ''): array
    {
        $map = [
            'yes'   => 'RsvpResponseYes',
            'no'    => 'RsvpResponseNo',
            'maybe' => 'RsvpResponseMaybe',
        ];

        $actions = [];

        foreach ($map as $answer => $response) {
            $url = trim((string) ($urls[$answer] ?? ''));

            if ($url === '') {
                continue;
            }

            $actions[] = [
                '@type'        => 'RsvpAction',
                'rsvpResponse' => 'http://schema.org/' . $response,
                'handler'      => ['@type' => 'HttpActionHandler', 'url' => $url],
            ];
        }

        return $actions === [] ? [] : self::message($description, $actions);
    }

    /**
     * The sender's logo, for the avatar Gmail draws beside the subject.
     *
     * The "highlight" half of the same feature: not an action, but the same `ld+json` block, and
     * the reason a message from a registered sender shows a brand mark instead of a letter in a
     * coloured circle.
     *
     * @param  string $name The organisation
     * @param  string $logo An absolute URL to a square image, served over HTTPS
     * @param  string $url  The organisation's site
     * @return array<string, mixed>
     */
    public static function sender(string $name, string $logo, string $url = ''): array
    {
        // `array_filter` on purpose: absent is not empty. A `"url": ""` is a claim that the
        // organisation has no site, and consumers read it as one.
        return array_filter([
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => $name,
            'logo'     => $logo,
            'url'      => $url,
        ], static fn ($value): bool => $value !== '' && $value !== []);
    }

    /**
     * A promotion, for the annotations Gmail shows in the Promotions tab.
     *
     * A card with an image, a deal and an expiry, drawn under the subject line in the tab where
     * promotional mail lands. On a transactional message it is wrong twice over — it is not a
     * promotion, and it invites the classifier to file the message where nobody looks for a
     * password reset.
     *
     * @param  array{
     *     title?: string, image?: string, url?: string,
     *     discount?: string, code?: string, expires?: string
     * } $offer
     * @return array<string, mixed>
     */
    public static function promotion(array $offer): array
    {
        $card = array_filter([
            '@type'             => 'PromotionCard',
            'name'              => trim((string) ($offer['title'] ?? '')),
            'image'             => trim((string) ($offer['image'] ?? '')),
            'url'               => trim((string) ($offer['url'] ?? '')),
            'discountValue'     => trim((string) ($offer['discount'] ?? '')),
            'discountCode'      => trim((string) ($offer['code'] ?? '')),
            // ISO 8601, because a date in any other format is dropped without comment.
            'availabilityEnds'  => self::iso($offer['expires'] ?? ''),
        ], static fn ($value): bool => $value !== '' && $value !== []);

        if (!isset($card['name'])) {
            return [];   // a card with no title is a blank card
        }

        return [
            '@context' => 'https://schema.org',
            '@type'    => 'DiscountOffer',
            'promotion' => $card,
        ];
    }

    /**
     * What has to be true before Gmail shows any of this.
     *
     * Returned as data rather than only written in a guide, because the failure mode is somebody
     * concluding the code is broken. Nothing here is enforceable from inside an application —
     * they are all facts about the domain and about Google.
     *
     * @return list<string>
     */
    public static function requirements(): array
    {
        return [
            'The sending domain must be registered with Google before any action or annotation '
                . 'is displayed. Until then the markup is present, valid and invisible.',
            'SPF or DKIM must authenticate the From: domain, and DMARC must pass.',
            'The action URL must be served over HTTPS on a domain you control.',
            'A ConfirmAction handler must act on the first request — no confirmation page, no '
                . 'sign-in. Gmail sends a POST and does not follow up.',
            'One action per message. Gmail shows the first it understands and ignores the rest.',
        ];
    }

    /**
     * The `EmailMessage` wrapper an action has to sit inside.
     *
     * @param  array<string, mixed>|list<array<string, mixed>> $action
     * @return array<string, mixed>
     */
    private static function message(string $description, array $action): array
    {
        return array_filter([
            '@context'        => 'https://schema.org',
            '@type'           => 'EmailMessage',
            'description'     => trim($description),
            'potentialAction' => $action,
        ], static fn ($value): bool => $value !== '' && $value !== []);
    }

    /**
     * A date as ISO 8601, or ''.
     *
     * Accepts what a caller is likely to have — a timestamp, a `DateTimeInterface`, or anything
     * `strtotime()` understands — and refuses to guess at the rest rather than emitting a date
     * that means a different day.
     */
    private static function iso(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        if (is_int($value) && $value > 0) {
            return date('c', $value);
        }

        if (!is_string($value) || trim($value) === '') {
            return '';
        }

        $parsed = strtotime($value);

        return $parsed === false ? '' : date('c', $parsed);
    }
}
