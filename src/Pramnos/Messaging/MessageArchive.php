<?php

declare(strict_types=1);

namespace Pramnos\Messaging;

use Pramnos\Storage\BodyStore;

/**
 * Moves message bodies that are already in the database out to the store.
 *
 * The columns arrive empty. Every message sent from the moment they exist writes its body to a
 * file, but the rows already in `messages` keep theirs in `text` — and on an installation that
 * has been sending for years, those are all of them. This is how they follow.
 *
 * The counterpart of `mail:archive`, and deliberately the same shape: a batch at a time, safe to
 * interrupt, safe to run again. It is the only part of the body store that has anything to do
 * on a table nobody is writing to.
 *
 * ### The order matters and it is the whole of the safety
 *
 * The file is written first and the row updated second. The other order — clear the column, then
 * write the file — loses the body if anything fails in between, and what it loses is a message
 * somebody is going to open.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class MessageArchive
{
    /** Rows per pass. Small enough that an interrupted run costs nothing. */
    public const BATCH = 500;

    /**
     * How many rows still have their body in the database.
     */
    public static function pending(): int
    {
        try {
            $db     = \Pramnos\Framework\Factory::getDatabase();
            $result = $db->query(
                'SELECT COUNT(*) AS c FROM ' . $db->prefix . 'messages WHERE text <> \'\''
            );

            return $result ? (int) ($result->fields['c'] ?? 0) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Move one batch of bodies to the store.
     *
     * @return array{moved: int, freed: int, failed: int}
     */
    public static function run(int $limit = self::BATCH): array
    {
        $moved = $freed = $failed = 0;

        if (!BodyStore::enabled()) {
            return ['moved' => 0, 'freed' => 0, 'failed' => 0];
        }

        try {
            $db     = \Pramnos\Framework\Factory::getDatabase();
            $result = $db->query(
                'SELECT messageid, text, date FROM ' . $db->prefix . 'messages '
                . 'WHERE text <> \'\' ORDER BY messageid ASC LIMIT ' . max(1, $limit)
            );
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log('Could not read message bodies to archive: '
                . $exception->getMessage(), 'messaging');

            return ['moved' => 0, 'freed' => 0, 'failed' => 0];
        }

        while ($result && $result->fetch()) {
            $id   = (int) ($result->fields['messageid'] ?? 0);
            $body = (string) ($result->fields['text'] ?? '');
            $path = BodyStore::put($body, (int) ($result->fields['date'] ?? 0));

            if ($id < 1 || $path === null) {
                $failed++;

                continue;
            }

            try {
                $db->queryBuilder()->table('#PREFIX#messages')->where('messageid', $id)->update([
                    'text'      => '',
                    'bodypath'  => $path,
                    'bodybytes' => BodyStore::bytes($path),
                    // Written here too: without it the listing has nothing to show for a row
                    // whose body has just left, and an archived inbox goes blank under every
                    // subject line.
                    'excerpt'   => BodyStore::excerpt($body),
                ]);

                $moved++;
                $freed += strlen($body);
            } catch (\Throwable $exception) {
                \Pramnos\Logs\Logger::log('Could not point message ' . $id . ' at its archived '
                    . 'body: ' . $exception->getMessage(), 'messaging');
                $failed++;
            }
        }

        return ['moved' => $moved, 'freed' => $freed, 'failed' => $failed];
    }
}
