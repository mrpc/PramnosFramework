<?php

declare(strict_types=1);

namespace Pramnos\Email;

/**
 * What the sending domain's DNS says about this installation's mail, and what is missing.
 *
 * None of this lives in the message. SPF, DKIM, DMARC and BIMI are records on a domain, and a
 * message is accepted or filed as spam by what a receiving server finds there — so an
 * application can be composing a perfect email and still be undeliverable, with nothing in any
 * log to say so. That is the whole reason this class exists: the failure is invisible from
 * inside the application, and the only symptom is mail quietly not arriving.
 *
 * ### The order they matter in
 *
 * 1. **SPF** — which servers may send as this domain. Missing, a receiver has one fewer reason
 *    to trust the message; wrong, it has a reason not to.
 * 2. **DKIM** — the signature. Gmail and Yahoo require it from anyone sending in volume, and
 *    without it the `List-Unsubscribe` headers this framework sets will not save you.
 * 3. **DMARC** — what to do when the first two fail, and where to send the reports. Required
 *    for bulk senders since February 2024, and the prerequisite for the next one.
 * 4. **BIMI** — the logo beside the subject. Needs DMARC at `quarantine` or `reject`, an SVG
 *    Tiny PS, and — for Gmail and Apple — a Verified Mark Certificate, which is bought.
 *
 * A check that only reported "found" or "not found" would miss the way each of these actually
 * goes wrong: a DMARC record at `p=none` looks present and enforces nothing, and BIMI beside it
 * is a record no mailbox provider will ever act on.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class DnsAuthentication
{
    /** @var callable(string, int): (array<int, array<string, mixed>>|false) */
    private $resolver;

    /**
     * @param ?callable $resolver A DNS reader, for tests. Defaults to `dns_get_record()`.
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? static fn (string $host, int $type) => @dns_get_record($host, $type);
    }

    /**
     * Everything the sending domain says, and what it should say instead.
     *
     * @param  string $domain   The `From:` domain
     * @param  string $selector The DKIM selector, if the application knows it
     * @return array{domain: string, checks: array<string, array<string, mixed>>, ready: bool}
     */
    public function inspect(string $domain, string $selector = ''): array
    {
        $domain = strtolower(trim($domain, " \t\n\r\0\x0B."));

        if ($domain === '') {
            return ['domain' => '', 'checks' => [], 'ready' => false];
        }

        $checks = [
            'spf'   => $this->spf($domain),
            'dkim'  => $this->dkim($domain, $selector),
            'dmarc' => $this->dmarc($domain),
            'bimi'  => $this->bimi($domain),
        ];

        /*
         * "Ready" is the bulk-sender bar Gmail and Yahoo set in February 2024, not every check
         * passing: SPF, DKIM, and a DMARC record — `p=none` included. An enforcing policy is
         * better and is reported as such, but it is not what they ask for, and a check that
         * failed everybody at `p=none` would be failing most of the internet.
         *
         * BIMI is deliberately not part of it: it is a logo, a VMC is bought, and an
         * installation without one is not misconfigured. DKIM without a selector is unknown
         * rather than failed for the same reason — the application may sign through a relay
         * that owns the selector, and reporting that as broken would be a false alarm on a
         * perfectly good installation. False alarms are how a check stops being read.
         */
        $ready = ($checks['spf']['ok'] ?? false)
            && ($checks['dmarc']['ok'] ?? false)
            && ($checks['dkim']['ok'] ?? null) !== false;

        return ['domain' => $domain, 'checks' => $checks, 'ready' => $ready];
    }

    /**
     * @return array<string, mixed>
     */
    protected function spf(string $domain): array
    {
        $records = $this->txt($domain);
        $found   = [];

        foreach ($records as $record) {
            if (stripos($record, 'v=spf1') === 0) {
                $found[] = $record;
            }
        }

        if ($found === []) {
            return [
                'ok'     => false,
                'record' => null,
                'says'   => 'No SPF record.',
                'fix'    => 'Publish a TXT record on ' . $domain . ' beginning `v=spf1`, listing '
                    . 'the servers that send as this domain, ending `-all` (reject) or `~all` '
                    . '(soft fail).',
            ];
        }

        if (count($found) > 1) {
            /*
             * Two SPF records is not "more coverage", it is a PermError.
             *
             * RFC 7208 says a domain with more than one is in error, and a receiver that gets a
             * PermError treats the check as having no result at all — so two records
             * authenticate strictly less than one. It is a common state, because each is added
             * by a different person adding a different service.
             */
            return [
                'ok'      => false,
                'record'  => $found,
                'says'    => 'More than one SPF record — which is a PermError, so nothing is authenticated.',
                'fix'     => 'Merge them into one `v=spf1` record. Two records authenticate less than one.',
            ];
        }

        $record = $found[0];
        /*
         * `~` is a valid SPF qualifier *and* was this pattern's delimiter, which ended the
         * expression inside its own character class — so a record ending in a plain `-all`
         * was reported as ending in nothing. A confidently wrong answer about the one line
         * somebody would then go and change.
         */
        $all    = preg_match('/[-~?+]all\s*$/i', trim($record)) === 1;

        return [
            'ok'     => true,
            'record' => $record,
            'says'   => $all
                ? 'Present.'
                : 'Present, but it does not end in an `all` mechanism, so it says nothing about '
                    . 'servers it did not list.',
            'fix'    => $all ? null : 'End the record with `-all` or `~all`.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function dkim(string $domain, string $selector): array
    {
        if (trim($selector) === '') {
            return [
                'ok'     => null,
                'record' => null,
                'says'   => 'Not checked — a DKIM record lives under a selector, and the selector '
                    . 'is chosen by whatever signs the mail.',
                'fix'    => 'Send yourself a message, read `DKIM-Signature` in its source, and '
                    . 're-run with the `s=` value from it.',
            ];
        }

        $host    = trim($selector) . '._domainkey.' . $domain;
        $records = $this->txt($host);

        foreach ($records as $record) {
            if (stripos($record, 'v=DKIM1') !== false || stripos($record, 'p=') !== false) {
                return [
                    'ok'     => true,
                    'record' => $record,
                    'host'   => $host,
                    'says'   => 'Present.',
                ];
            }
        }

        return [
            'ok'     => false,
            'record' => null,
            'host'   => $host,
            'says'   => 'No DKIM key at that selector.',
            'fix'    => 'Publish the public key your mail server or relay generated, as a TXT '
                . 'record at ' . $host . '. Gmail and Yahoo require a signature from anyone '
                . 'sending in volume.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function dmarc(string $domain): array
    {
        $records = $this->txt('_dmarc.' . $domain);

        foreach ($records as $record) {
            if (stripos($record, 'v=DMARC1') !== 0) {
                continue;
            }

            $policy = 'none';

            if (preg_match('~\bp\s*=\s*([a-z]+)~i', $record, $matches) === 1) {
                $policy = strtolower($matches[1]);
            }

            $enforcing = $policy === 'quarantine' || $policy === 'reject';

            return [
                'ok'        => true,
                'record'    => $record,
                'policy'    => $policy,
                'enforcing' => $enforcing,
                'says'      => $enforcing
                    ? 'Present, and enforcing (`p=' . $policy . '`).'
                    : 'Present, but `p=none` — it asks for reports and enforces nothing. Somebody '
                        . 'forging this domain is still delivered.',
                'fix'       => $enforcing
                    ? null
                    : 'Read the reports for a few weeks, then move to `p=quarantine`. It is also '
                        . 'the prerequisite for BIMI.',
            ];
        }

        return [
            'ok'     => false,
            'record' => null,
            'says'   => 'No DMARC record.',
            'fix'    => 'Publish a TXT record at _dmarc.' . $domain . ', starting with '
                . '`v=DMARC1; p=none; rua=mailto:you@' . $domain . '` so the reports start '
                . 'arriving, and tighten `p` once you have read some. Required of bulk senders '
                . 'by Gmail and Yahoo.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function bimi(string $domain): array
    {
        $records = $this->txt('default._bimi.' . $domain);

        foreach ($records as $record) {
            if (stripos($record, 'v=BIMI1') !== 0) {
                continue;
            }

            $logo = preg_match('~\bl\s*=\s*([^;]+)~i', $record, $matches) === 1
                ? trim($matches[1])
                : '';
            $mark = preg_match('~\ba\s*=\s*([^;]+)~i', $record, $matches) === 1
                ? trim($matches[1])
                : '';

            return [
                'ok'     => $logo !== '',
                'record' => $record,
                'logo'   => $logo,
                'vmc'    => $mark,
                'says'   => $logo === ''
                    ? 'Present, but it names no logo (`l=`), so there is nothing to show.'
                    : ($mark === ''
                        ? 'Present. Without a Verified Mark Certificate (`a=`) Gmail and Apple '
                            . 'will not show the logo; some other providers will.'
                        : 'Present, with a Verified Mark Certificate.'),
                'fix'    => $mark === ''
                    ? 'A VMC is bought from a certificate authority and requires a registered '
                        . 'trademark. Without one the record still works at a few providers.'
                    : null,
            ];
        }

        return [
            'ok'     => false,
            'record' => null,
            'says'   => 'No BIMI record — no logo beside the subject.',
            'fix'    => 'Optional, and it has prerequisites: DMARC at `p=quarantine` or '
                . '`p=reject` first, then an SVG Tiny PS at a public HTTPS URL, then a TXT '
                . 'record at default._bimi.' . $domain . ' reading `v=BIMI1; l=<url>`. Gmail and '
                . 'Apple additionally require a Verified Mark Certificate.',
        ];
    }

    /**
     * Every TXT string on a host, joined the way DNS chunks them.
     *
     * A TXT record longer than 255 characters is stored as several strings and must be
     * concatenated — which is exactly the case for a DKIM key, so reading only the first chunk
     * would report a valid key as malformed.
     *
     * @return list<string>
     */
    protected function txt(string $host): array
    {
        $records = ($this->resolver)($host, DNS_TXT);

        if (!is_array($records)) {
            return [];
        }

        $out = [];

        foreach ($records as $record) {
            if (isset($record['entries']) && is_array($record['entries'])) {
                $out[] = implode('', $record['entries']);

                continue;
            }

            if (isset($record['txt'])) {
                $out[] = (string) $record['txt'];
            }
        }

        return $out;
    }
}
