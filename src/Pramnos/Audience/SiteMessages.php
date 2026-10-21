<?php

declare(strict_types=1);

namespace Pramnos\Audience;

use Pramnos\Database\Database;

/**
 * Messages on the site's own pages for one person — a banner, a popup, a card — kept until they
 * close it or it expires.
 *
 * ```php
 * SiteMessages::post($userid, 'banner', t('Your Premium ends on Friday'), '<p>Renew to keep it.</p>',
 *     link: '/premium', linkText: t('Renew'), days: 7);
 *
 * echo \Pramnos\Html\SiteMessages::render();   // the layout, for whoever is signed in
 * ```
 *
 * A rule's `site_message` action writes them; an application may too. When one is first shown it
 * is marked seen, when the person closes it, dismissed. Nothing at all happens before the table is
 * installed.
 */
final class SiteMessages
{
    public const KINDS = ['banner', 'popup', 'card'];

    /**
     * Put a message on a person's pages.
     *
     * @return int|null Its id, or null when the table is not installed
     */
    public static function post(int $person, string $kind, string $title, string $body = '', string $link = '', string $linkText = '', int $days = 0, string $origin = ''): ?int
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('A site message is a ' . implode(', ', self::KINDS) . '.');
        }
        if ($person < 1 || !Installed::has('site_messages')) {
            return null;
        }
        $now = time();
        self::db()->queryBuilder()->table('#PREFIX#site_messages')->insert([
            'personid'  => $person,
            'kind'      => $kind,
            'title'     => mb_substr(trim(strip_tags($title)), 0, 255),
            'body'      => \Pramnos\Html\SafeHtml::clean($body),
            'link'      => $link === '' ? null : mb_substr($link, 0, 500),
            'linktext'  => $linkText === '' ? null : mb_substr($linkText, 0, 100),
            'origin'    => $origin === '' ? null : mb_substr($origin, 0, 255),
            'createdat' => $now,
            'expiresat' => $days > 0 ? $now + $days * 86400 : null,
        ]);

        return (int) self::db()->getInsertId();
    }

    /**
     * What a person has to see now, oldest first — of one kind, or all — marked seen.
     *
     * @return list<array{id: int, kind: string, title: string, body: string, link: string, linkText: string}>
     */
    public static function pending(int $person, ?string $kind = null): array
    {
        if ($person < 1 || !Installed::has('site_messages')) {
            return [];
        }
        $query = self::db()->queryBuilder()->table('#PREFIX#site_messages')->where('personid', $person)->whereNull('dismissedat')
            ->where(static function ($q): void {
                $q->whereNull('expiresat');
                $q->where('expiresat', '>', time(), 'or');
            })->orderBy('id');
        if ($kind !== null) {
            $query->where('kind', $kind);
        }
        $rows = $query->getAllOrFail();
        $new  = array_map(static fn (array $r): int => (int) $r['id'], array_filter($rows, static fn (array $r): bool => $r['seenat'] === null));
        if ($new !== []) {
            self::db()->queryBuilder()->table('#PREFIX#site_messages')->whereIn('id', $new)->update(['seenat' => time()]);
        }

        return array_map(static fn (array $r): array => [
            'id'       => (int) $r['id'],
            'kind'     => (string) $r['kind'],
            'title'    => (string) $r['title'],
            'body'     => (string) ($r['body'] ?? ''),
            'link'     => (string) ($r['link'] ?? ''),
            'linkText' => (string) ($r['linktext'] ?? ''),
        ], $rows);
    }

    /** The person closed it. Only their own; true when something changed. */
    public static function dismiss(int $id, int $person): bool
    {
        if ($person < 1 || !Installed::has('site_messages')) {
            return false;
        }
        $result = self::db()->queryBuilder()->table('#PREFIX#site_messages')->where('id', $id)->where('personid', $person)
            ->whereNull('dismissedat')->update(['dismissedat' => time()]);

        return is_object($result) && method_exists($result, 'getAffectedRows') ? (int) $result->getAffectedRows() > 0 : (bool) $result;
    }

    private static function db(): Database
    {
        return Database::getInstance();
    }
}
