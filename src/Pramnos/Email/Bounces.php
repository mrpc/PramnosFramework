<?php

declare(strict_types=1);

namespace Pramnos\Email;

use Pramnos\Framework\Factory;

/**
 * Addresses mail bounced from, and addresses blocked by hand — and whether a message may go to one.
 *
 * ```php
 * Bounces::recordReport(file_get_contents('bounce.eml'));   // a delivery report (RFC 3464)
 * Bounces::record('someone@example.com', soft: false, reason: '550 5.1.1 no such user');
 * Bounces::block('someone@example.com', 'Asked by phone to stop all mail');
 * Bounces::refusal('someone@example.com');   // why not, or null when it may be sent
 * Bounces::clear('someone@example.com');
 * ```
 *
 * {@see Email::send()} and {@see Email::queue()} ask before composing: a refused address is a
 * message not sent, written to `mails` as failed with the reason, as an opted-out one is. Unlike
 * an unsubscribe this stops **every** message, transactional ones included: an address that does
 * not exist does not receive a password reset either, and sending to it is what a mailbox
 * provider counts against the sender.
 *
 * An address is refused when
 *
 * - it was blocked by hand;
 * - it hard-bounced (no such mailbox, no such domain) `hard` times — once, unless set;
 * - it soft-bounced (mailbox full, disabled, the server deferring) `soft` times — 15 — or
 * - it soft-bounced within `softWithin` seconds — a day: a full mailbox is given time to empty.
 *
 * ```php
 * // app/app.php — 0 turns a rule off
 * 'email' => ['bounces' => ['hard' => 1, 'soft' => 15, 'softWithin' => 86400]],
 * ```
 *
 * Rows are kept by the sha256 of the lowercased address, not the address: the table says an
 * address bounced to somebody who has it, and nothing to somebody who has only the table.
 */
final class Bounces
{
    /** @var bool|null Whether `bounces` exists, asked once a process */
    private static ?bool $table = null;

    /**
     * Why a message must not go to this address, or null when it may.
     *
     * An installation without the table refuses nothing.
     */
    public static function refusal(string $email): ?string
    {
        $row = self::status($email);
        if ($row === null) {
            return null;
        }
        $config     = self::config();
        $hard       = (int) $row['hardbounces'];
        $soft       = (int) $row['softbounces'];
        $reason     = trim((string) ($row['reason'] ?? ''));
        $because    = $reason === '' ? '' : ' (' . $reason . ')';
        $softWithin = (int) ($config['softWithin'] ?? 86400);

        if ((int) ($row['blocked'] ?? 0) > 0) {
            return 'blocked since ' . gmdate('Y-m-d H:i', (int) $row['blocked']) . ' UTC' . $because;
        }
        if (($limit = (int) ($config['hard'] ?? 1)) > 0 && $hard >= $limit) {
            return 'hard-bounced ' . $hard . ' time(s), last ' . gmdate('Y-m-d H:i', (int) $row['lasthard']) . ' UTC' . $because;
        }
        if (($limit = (int) ($config['soft'] ?? 15)) > 0 && $soft >= $limit) {
            return 'soft-bounced ' . $soft . ' time(s)' . $because;
        }
        if ($softWithin > 0 && $soft > 0 && (int) $row['lastsoft'] > time() - $softWithin) {
            return 'soft-bounced at ' . gmdate('Y-m-d H:i', (int) $row['lastsoft']) . ' UTC, tried again after '
                . gmdate('Y-m-d H:i', (int) $row['lastsoft'] + $softWithin) . ' UTC' . $because;
        }

        return null;
    }

    /**
     * Record a bounce. False for something that is not an address, or without the table.
     */
    public static function record(string $email, bool $soft, string $reason = ''): bool
    {
        $hash = self::hash($email);
        if ($hash === null || !self::available()) {
            return false;
        }
        $kind   = $soft ? 'soft' : 'hard';
        $now    = time();
        $qb     = Factory::getDatabase()->queryBuilder()->table('#PREFIX#bounces');
        $reason = mb_substr(trim($reason), 0, 255);
        $done = $qb->upsert(
            [
                'hash'        => $hash,
                'lastsoft'    => $soft ? $now : 0,
                'lasthard'    => $soft ? 0 : $now,
                'softbounces' => $soft ? 1 : 0,
                'hardbounces' => $soft ? 0 : 1,
                'reason'      => $reason,
            ],
            ['hash'],
            [
                'last' . $kind        => $now,
                // Qualified: on PostgreSQL the bare name could be the row proposed as well.
                $kind . 'bounces'     => $qb->raw('#PREFIX#bounces.' . $kind . 'bounces + 1'),
                'reason'              => $reason,
            ]
        );

        return self::written($done);
    }

    /**
     * Stop all mail to an address, until it is cleared. False for something that is not an
     * address, or without the table.
     */
    public static function block(string $email, string $reason = ''): bool
    {
        $hash = self::hash($email);
        if ($hash === null || !self::available()) {
            return false;
        }
        $now    = time();
        $reason = mb_substr(trim($reason), 0, 255);
        return self::written(Factory::getDatabase()->queryBuilder()->table('#PREFIX#bounces')->upsert(
            ['hash' => $hash, 'lastsoft' => 0, 'lasthard' => 0, 'softbounces' => 0, 'hardbounces' => 0, 'blocked' => $now, 'reason' => $reason],
            ['hash'],
            ['blocked' => $now, 'reason' => $reason]
        ));
    }

    /**
     * True for a write that happened. A statement the database would not prepare comes back as
     * false rather than an exception, and a bounce that silently was not kept is mail that goes on
     * being sent to it.
     */
    private static function written(mixed $result): bool
    {
        if ($result === false) {
            throw new \RuntimeException('A bounce could not be written: ' . Factory::getDatabase()->getError()['message']);
        }

        return true;
    }

    /** Forget everything recorded for an address — its bounces and its block. */
    public static function clear(string $email): bool
    {
        $hash = self::hash($email);
        if ($hash === null || !self::available()) {
            return false;
        }
        Factory::getDatabase()->queryBuilder()->table('#PREFIX#bounces')->where('hash', $hash)->delete();

        return true;
    }

    /**
     * What is recorded for an address — its `bounces` row — or null for none.
     *
     * @return array<string, mixed>|null
     */
    public static function status(string $email): ?array
    {
        $hash = self::hash($email);
        if ($hash === null || !self::available()) {
            return null;
        }
        $row = Factory::getDatabase()->queryBuilder()->table('#PREFIX#bounces')->where('hash', $hash)->first();

        return $row && $row->numRows > 0 ? (array) $row->fields : null;
    }

    /**
     * Record every failed recipient of a delivery report, and answer what was recorded. A delayed
     * delivery, a message that is not a report, or one about an address that is not one records
     * nothing.
     *
     * @return list<array{email: string, status: string, soft: bool, reason: string}>
     */
    public static function recordReport(string $raw): array
    {
        $recorded = [];
        foreach (self::parseReport($raw) as $failure) {
            if (self::record($failure['email'], $failure['soft'], $failure['reason'])) {
                $recorded[] = $failure;
            }
        }

        return $recorded;
    }

    /**
     * The failed recipients of a delivery report (RFC 3464): its per-recipient fields, where the
     * action is `failed`.
     *
     * Soft is a temporary failure (`4.x.x`), or a mailbox that exists and cannot take mail now —
     * disabled (`5.2.1`) or full (`5.2.2`); every other permanent failure is hard.
     *
     * ponytail: reads a report whose delivery-status part is plain text, as every MTA sends it;
     * a base64 one, and bounces that are prose and not a report (old qmail), yield nothing.
     *
     * @return list<array{email: string, status: string, soft: bool, reason: string}>
     */
    public static function parseReport(string $raw): array
    {
        $text     = str_replace(["\r\n", "\r"], "\n", $raw);
        $failures = [];
        foreach (preg_split('/\n[ \t]*\n/', $text) ?: [] as $block) {
            if (stripos($block, 'final-recipient:') === false) {
                continue;
            }
            $fields = [];
            // Folded lines unfolded, then one field a line.
            foreach (explode("\n", (string) preg_replace('/\n[ \t]+/', ' ', $block)) as $line) {
                if (preg_match('/^([A-Za-z-]+):\s*(.*)$/', trim($line), $m) === 1) {
                    $fields[strtolower($m[1])] = trim($m[2]);
                }
            }
            if (strtolower($fields['action'] ?? '') !== 'failed') {
                continue;
            }
            // `rfc822; someone@example.com`, sometimes in angle brackets.
            $address = trim((string) preg_replace('/^[^;]*;/', '', $fields['final-recipient'] ?? ''), " <>\t");
            $diagnostic = trim((string) preg_replace('/^[^;]*;/', '', $fields['diagnostic-code'] ?? ''));
            $status     = $fields['status'] ?? '';
            if (preg_match('/^[245]\.\d{1,3}\.\d{1,3}/', $status, $m) === 1) {
                $status = $m[0];
            } elseif (preg_match('/\b([245])\d\d\b/', $diagnostic, $m) === 1) {
                $status = $m[1] . '.0.0';
            } else {
                $status = '5.0.0';
            }
            if (self::hash($address) === null) {
                continue;
            }
            $failures[] = [
                'email'  => strtolower($address),
                'status' => $status,
                'soft'   => $status[0] === '4' || in_array($status, ['5.2.1', '5.2.2'], true),
                'reason' => mb_substr($diagnostic !== '' ? $diagnostic : $status, 0, 255),
            ];
        }

        return $failures;
    }

    /** The key an address is kept under — null for something that is not an address. */
    public static function hash(string $email): ?string
    {
        // `Name <someone@example.com>` as well as the bare address.
        if (preg_match('/<([^<>]+)>/', $email, $m) === 1) {
            $email = $m[1];
        }
        $address = \Pramnos\Validation\Validator::checkEmail($email);

        return $address === false ? null : hash('sha256', $address);
    }

    /** Forget whether the table exists — after a migration made it, or between tests. */
    public static function forget(): void
    {
        self::$table = null;
    }

    /** Whether `bounces` exists, with the columns this class writes. */
    public static function available(): bool
    {
        return self::$table ??= Factory::getDatabase()->schema()->hasColumn('bounces', 'blocked');
    }

    /** `'email' => ['bounces' => [...]]` in app.php. @return array<string, mixed> */
    private static function config(): array
    {
        $app    = \Pramnos\Application\Application::currentInstance();
        $config = is_object($app) ? ($app->applicationInfo['email']['bounces'] ?? []) : [];

        return is_array($config) ? $config : [];
    }
}
