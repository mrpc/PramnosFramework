<?php

declare(strict_types=1);

namespace Pramnos\Email;

/**
 * Everything that can be known about one message that was sent.
 *
 * The `mails` table stores the rendered HTML, which means the message can be *read back*: the
 * pixel it carries, the actions Gmail would draw from it, where each of its links really goes,
 * and what a text-only client sees. Together with the tracking row, that is the whole answer to
 * "what happened to this email" — and until now the preview screen showed four fields and an
 * iframe.
 *
 * Every finding is derived from the stored body rather than from what the sender *intended*.
 * That distinction is the point: a template that lost its unsubscribe link, an action that was
 * never embedded, a tracked message whose links were not wrapped — none of those is visible from
 * the sending code, and all of them are visible here.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class MessageReport
{
    /**
     * @param array<string, mixed> $mail    The `mails` row
     * @param ?array<string, mixed> $tracking The `emailtracking` row, when there is one
     */
    public function __construct(
        private array $mail,
        private ?array $tracking = null
    ) {
    }

    /** The rendered body as it was sent. */
    public function body(): string
    {
        return (string) ($this->mail['content'] ?? $this->mail['body'] ?? '');
    }

    /**
     * Delivery: what was attempted, to whom, and whether it worked.
     *
     * @return array<string, mixed>
     */
    public function delivery(): array
    {
        $status = (int) ($this->mail['status'] ?? 0);

        return array_filter([
            'status' => match ($status) {
                1 => 'sent',
                2 => 'queued',
                default => 'failed',
            },
            'to'      => (string) ($this->mail['tomail'] ?? ''),
            'toName'  => (string) ($this->mail['toname'] ?? ''),
            'from'    => (string) ($this->mail['frommail'] ?? ''),
            'subject' => (string) ($this->mail['subject'] ?? ''),
            'module'  => (string) ($this->mail['module'] ?? ''),
            'sentAt'  => (int) ($this->mail['date'] ?? 0),
            // Only on a failure, where it holds the transport's own words. On a success it is
            // empty, and an empty "error" row on a delivered message reads as a problem.
            'error'   => $status === 1 ? '' : (string) ($this->mail['extrainfo'] ?? ''),
        ], static fn ($value): bool => $value !== '' && $value !== null);
    }

    /**
     * Tracking: whether this message carries a pixel, and what came back.
     *
     * Reports the pixel's presence **from the body** rather than from the tracking row, because
     * they can disagree — a message tracked in the database whose body has no pixel measures
     * nothing, and that is exactly the failure worth seeing.
     *
     * @return array<string, mixed>
     */
    public function tracking(): array
    {
        $body     = $this->body();
        $matches  = [];
        $hasPixel = preg_match(
            '~<img[^>]+src="[^"]*' . preg_quote(Tracking::PIXEL_PATH, '~') . '\?t=([^"&]+)~i',
            $body,
            $matches
        ) === 1;

        $report = [
            'pixel'      => $hasPixel,
            'pixelId'    => $hasPixel ? $matches[1] : '',
            'recorded'   => $this->tracking !== null,
            'wrappedLinks' => substr_count($body, Tracking::CLICK_PATH . '?c='),
        ];

        if ($this->tracking === null) {
            $report['note'] = $hasPixel
                ? 'This message carries a pixel and has no tracking row — nothing it reports '
                    . 'can be recorded. The row may have been removed, or the send failed '
                    . 'between writing the body and writing the row.'
                : 'Not tracked. Tracking is off unless the installation enables it, the message '
                    . 'belongs to a list, and the sender asked for it.';

            return $report;
        }

        $report += [
            'trackingId'  => (string) ($this->tracking['tracking_id'] ?? ''),
            'list'        => (string) ($this->tracking['list'] ?? ''),
            'opens'       => (int) ($this->tracking['opens'] ?? 0),
            'proxyOpens'  => (int) ($this->tracking['proxy_opens'] ?? 0),
            'clicks'      => (int) ($this->tracking['clicks'] ?? 0),
            'firstOpenAt' => (int) ($this->tracking['first_open_at'] ?? 0),
            'lastOpenAt'  => (int) ($this->tracking['last_open_at'] ?? 0),
            'firstClickAt' => (int) ($this->tracking['first_click_at'] ?? 0),
        ];

        if (!$hasPixel) {
            $report['note'] = 'Tracked, but the stored body has no pixel — opens cannot be '
                . 'recorded for this message. Clicks still can, if its links were wrapped.';
        } elseif ($report['opens'] === 0 && $report['proxyOpens'] > 0) {
            $report['note'] = 'Only a mailbox provider has fetched the image, which happens on '
                . 'delivery. That is not somebody reading it.';
        }

        return $report;
    }

    /**
     * The structured-data blocks in the body, as Gmail would read them.
     *
     * @return list<array<string, mixed>>
     */
    public function structuredData(): array
    {
        $blocks  = [];
        $matches = [];

        preg_match_all(
            '~<script[^>]+type="application/ld\+json"[^>]*>(.*?)</script>~is',
            $this->body(),
            $matches
        );

        foreach ($matches[1] ?? [] as $json) {
            $decoded = json_decode(trim($json), true);

            if (!is_array($decoded)) {
                // Present and unreadable is a finding in itself: Gmail would ignore it silently.
                $blocks[] = ['type' => 'unreadable', 'raw' => substr(trim($json), 0, 200)];

                continue;
            }

            $blocks[] = $this->describeBlock($decoded);
        }

        return $blocks;
    }

    /**
     * One block, in the terms somebody reading this screen cares about.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function describeBlock(array $data): array
    {
        $type = (string) ($data['@type'] ?? 'unknown');

        if ($type !== 'EmailMessage') {
            return array_filter([
                'type'        => $type,
                'name'        => (string) ($data['name'] ?? ''),
                'url'         => (string) ($data['url'] ?? ''),
                'logo'        => (string) ($data['logo'] ?? ''),
                'description' => (string) ($data['description'] ?? ''),
            ], static fn ($value): bool => $value !== '');
        }

        $actions = $data['potentialAction'] ?? [];

        // One action or several — the schema allows both, and a screen that only handled one
        // would show an RSVP as nothing.
        if (isset($actions['@type'])) {
            $actions = [$actions];
        }

        $described = [];

        foreach ((array) $actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $described[] = array_filter([
                'action' => (string) ($action['@type'] ?? ''),
                'name'   => (string) ($action['name'] ?? ''),
                'url'    => (string) ($action['target']
                    ?? ($action['handler']['url'] ?? '')),
                'method' => (string) ($action['handler']['method'] ?? ''),
                'answer' => (string) ($action['rsvpResponse'] ?? ''),
            ], static fn ($value): bool => $value !== '');
        }

        return array_filter([
            'type'        => 'EmailMessage',
            'description' => (string) ($data['description'] ?? ''),
            'actions'     => $described,
        ], static fn ($value): bool => $value !== '' && $value !== []);
    }

    /**
     * Every link in the message, and where it really goes.
     *
     * A wrapped link's destination is inside a signed token, so the address in the markup is not
     * the address the reader reaches. Unwrapping it here is the only way this screen can answer
     * "where does this button actually go" — which is the question somebody has when a campaign
     * points at the wrong page.
     *
     * @return list<array<string, mixed>>
     */
    public function links(): array
    {
        $matches = [];
        preg_match_all('~href="([^"]+)"~i', $this->body(), $matches);

        $links = [];
        $seen  = [];

        foreach ($matches[1] ?? [] as $href) {
            $href = html_entity_decode($href, ENT_QUOTES, 'UTF-8');

            if (isset($seen[$href])) {
                $links[$seen[$href]]['count']++;

                continue;
            }

            $link = ['url' => $href, 'count' => 1, 'wrapped' => false];

            if (str_contains($href, Tracking::CLICK_PATH . '?c=')) {
                $token = urldecode(explode('c=', $href, 2)[1] ?? '');
                $claim = MailAction::verify($token);

                $link['wrapped']     = true;
                $link['destination'] = (string) ($claim['claim']['u'] ?? '');

                if ($link['destination'] === '') {
                    // A wrapped link whose token no longer verifies: the signing key changed, or
                    // it expired. The reader gets the front page instead of the offer.
                    $link['broken'] = true;
                }
            }

            $seen[$href] = count($links);
            $links[]     = $link;
        }

        return $links;
    }

    /**
     * What a text-only client shows.
     *
     * Rendered here rather than described, because the text part is the half nobody looks at —
     * and it is the half that used to arrive as the stylesheet with every link removed.
     */
    public function plainText(): string
    {
        return PlainText::fromHtml($this->body());
    }

    /**
     * Whether this message offers a way out, and how.
     *
     * @return array<string, mixed>
     */
    public function unsubscribe(): array
    {
        $body    = $this->body();
        $matches = [];

        $visible = preg_match('~href="([^"]*/unsubscribe[^"]*)"~i', $body, $matches) === 1;

        return array_filter([
            'visibleLink' => $visible,
            'url'         => $visible ? html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8') : '',
            'note'        => $visible
                ? ''
                : 'No visible unsubscribe link. Correct for transactional mail — nobody '
                    . 'unsubscribes from being able to sign in — and a problem on anything sent '
                    . 'to a list.',
        ], static fn ($value): bool => $value !== '' && $value !== false);
    }
}
