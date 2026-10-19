<?php

declare(strict_types=1);

namespace Pramnos\Email;

use Pramnos\Framework\Factory;

/**
 * A person in the mail log: what they were sent, for their copy of their data, and their erasure,
 * which keeps the fact that mail went.
 *
 * ```php
 * MailPersonalData::export('someone@example.com');  // every message to them, with its body
 * MailPersonalData::erase('someone@example.com');   // how many messages were erased
 * ```
 *
 * {@see \Pramnos\Auth\Controllers\Account}'s data export carries the first as its `mail`
 * section: each message's date, status, sender, subject and module, and its body as it was sent
 * ({@see Body::of()}), from wherever it is kept.
 *
 * On erasure each `mails` row sent to the address stays, pseudonymised: no address, no name, the subject
 * `[erased]`, no body, no outbox state or error naming them. What survives is that a message of
 * this module went out then, with this outcome — the audit log's question — without the person.
 * The body goes from wherever it was kept: the row, its `mail_bodies` row, its file — unless the
 * file is another recipient's too ({@see BodyStore::discard()}). The tracking row loses the
 * address and the subject, and keeps its counts.
 *
 * {@see \Pramnos\Auth\AccountErasure} calls it with the account's address. What it leaves on
 * purpose: `bounces` (a hash, which tells nothing to somebody without the address) and
 * `emailoptouts` — the record that this address asked not to be mailed is what stops the
 * framework mailing it again, and erasing it would undo the request.
 */
final class MailPersonalData
{
    /** Rows erased a batch at a time. */
    public const BATCH = 1000;

    /** The subject an erased message keeps. */
    public const SUBJECT = '[erased]';

    /**
     * Every message sent to this address, oldest first, with its body as it was sent.
     *
     * @return list<array{date: string, status: int, from: string, subject: string, module: string, body: string}>
     */
    public static function export(string $email): array
    {
        $email = trim($email);
        $db    = Factory::getDatabase();
        if ($email === '' || !$db->schema()->hasTable('mails')) {
            return [];
        }
        $messages = [];
        $result   = self::toAddress($db->queryBuilder()->table('#PREFIX#mails'), $email)->orderBy('id')->get();
        while ($result && ($row = $result->fetch()) !== null) {
            $messages[] = [
                'date'    => gmdate('Y-m-d\TH:i:s\Z', (int) $row['date']),
                'status'  => (int) $row['status'],
                'from'    => (string) $row['frommail'],
                'subject' => (string) $row['subject'],
                'module'  => (string) $row['module'],
                'body'    => Body::of((array) $row),
            ];
        }

        return $messages;
    }

    /** Erase every message to this address; answer how many there were. */
    public static function erase(string $email): int
    {
        $email = trim($email);
        $db    = Factory::getDatabase();
        if ($email === '' || !$db->schema()->hasTable('mails')) {
            return 0;
        }
        $addresses = array_values(array_unique([$email, strtolower($email)]));
        $toThem    = static fn ($query) => self::toAddress($query, $email);
        $columns   = [
            'tomail' => '', 'toname' => '', 'subject' => self::SUBJECT, 'content' => '',
            'path' => '', 'hash' => '', 'extrainfo' => '',
        ];
        if (BodyStore::hasBodyVars()) {
            $columns['bodyvars'] = null;
        }
        $tracking = $db->schema()->hasTable('pramnos.emailtracking');
        $erased   = 0;

        do {
            // The erased rows no longer match, so each batch is the next one.
            $rows = $toThem($db->queryBuilder()->table('#PREFIX#mails')->select(['id', 'date', 'path']))
                ->orderBy('id')->limit(self::BATCH)->get()->fetchAll();
            if ($rows === []) {
                break;
            }
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
            BodyStore::discard($rows);
            // A write the database refused would match again for ever: said, not looped on.
            if ($db->queryBuilder()->table('#PREFIX#mails')->whereIn('id', $ids)->update($columns) === false) {
                throw new \RuntimeException('The mail log could not be erased for an address: ' . $db->getError()['message']);
            }
            if ($tracking) {
                $db->queryBuilder()->table('pramnos.emailtracking')->whereIn('mailid', $ids)
                    ->update(['recipient' => '', 'subject' => self::SUBJECT]);
            }
            $erased += count($rows);
        } while (count($rows) === self::BATCH);
        // And a tracking row whose message is not in the log any more.
        if ($tracking) {
            $db->queryBuilder()->table('pramnos.emailtracking')
                ->where(static fn ($q) => self::toAddress($q, $email, 'recipient'))
                ->update(['recipient' => '', 'subject' => self::SUBJECT]);
        }

        return $erased;
    }

    /**
     * The rows to an address. Addresses are stored as typed: MySQL compares them without case,
     * PostgreSQL exactly — so there the column is lowercased, a scan, for a request that is rare.
     */
    private static function toAddress($query, string $email, string $column = 'tomail')
    {
        return Factory::getDatabase()->type === 'postgresql'
            ? $query->whereRaw('LOWER(' . $column . ') = ?', [strtolower($email)])
            : $query->whereIn($column, array_values(array_unique([$email, strtolower($email)])));
    }
}
