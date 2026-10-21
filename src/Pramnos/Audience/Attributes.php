<?php

declare(strict_types=1);

namespace Pramnos\Audience;

use Pramnos\Database\QueryBuilder;

/**
 * Every attribute a filter can ask about, and who counts as a person at all.
 *
 * ```php
 * // a service provider, or app/app.php's boot
 * Attributes::scope(fn (QueryBuilder $q) => $q->where('users.usertype', '<', 97)->where('users.active', '!=', 2));
 * Attributes::define(Attribute::column('pillar', 'pillar', Attribute::NUMBER)->from('professionals')->label(t('Pillar')));
 * ```
 *
 * The framework declares the generic ones — account type, language, registered, last sign-in,
 * active, email verified, groups. An attribute defined under a key that is taken replaces the
 * earlier one, so an application relabels `usertype` with its own account types by defining it
 * again.
 */
final class Attributes
{
    /** A person is one row of this table, and its columns are qualified with this name. */
    public const TABLE = 'users';

    /** The table as a query names it: prefixed, under {@see TABLE}. */
    public const FROM = '#PREFIX#users AS users';

    /** ...identified by this column; every person id in the audience tables is one of these. */
    public const KEY = 'userid';

    /** @var array<string, Attribute> */
    private static array $defined = [];

    /** @var (callable(QueryBuilder): mixed)|null */
    private static $scope = null;

    /** Add an attribute, or replace the one under its key. */
    public static function define(Attribute $attribute): void
    {
        self::$defined[$attribute->key()] = $attribute;
    }

    /**
     * Who is a person: applied to every count, sample, segment and rule. Staff and deleted
     * accounts are the usual exclusions.
     *
     * @param (callable(QueryBuilder): mixed)|null $scope
     */
    public static function scope(?callable $scope): void
    {
        self::$scope = $scope;
    }

    /** Apply the scope to a query over the users table. */
    public static function applyScope(QueryBuilder $query): QueryBuilder
    {
        if (self::$scope !== null) {
            (self::$scope)($query);
        }

        return $query;
    }

    /** The attribute under a key, or null. */
    public static function get(string $key): ?Attribute
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Every attribute by key: the framework's, then the application's.
     *
     * @return array<string, Attribute>
     */
    public static function all(): array
    {
        return array_replace(self::framework(), self::$defined);
    }

    /**
     * The ones the signed-in account may see — what the filter builder offers and a filter from
     * the admin may use.
     *
     * @return array<string, Attribute>
     */
    public static function visible(): array
    {
        return array_filter(self::all(), static fn (Attribute $a): bool => $a->requiredAbility() === ''
            || \Pramnos\Auth\Gate::allows($a->requiredAbility()));
    }

    /** Forget what was defined: tests. */
    public static function reset(): void
    {
        self::$defined = [];
        self::$scope   = null;
    }

    /**
     * The framework's own, over the columns every users table has.
     *
     * @return array<string, Attribute>
     */
    private static function framework(): array
    {
        $account = t('Account');
        $list    = [
            Attribute::column('usertype', 'usertype', Attribute::NUMBER)->label(t('Account type'))->group($account),
            Attribute::column('language', 'language', Attribute::TEXT)->label(t('Language'))->group($account),
            Attribute::column('regdate', 'regdate', Attribute::DATE)->label(t('Registered'))->group($account),
            Attribute::column('lastlogin', 'lastlogin', Attribute::DATE)->label(t('Last sign-in'))->group($account),
            Attribute::column('active', 'active', Attribute::LIST)->label(t('Status'))->group($account)
                ->values([1 => t('Active'), 0 => t('Inactive')]),
            // Odd means the address is verified, both where `validated` is 1 and where an
            // application keeps it as a bitmask with the email bit first.
            Attribute::expression('validated', 'users.validated % 2', Attribute::BOOLEAN)->label(t('Email verified'))->group($account),
            Attribute::many('groups', '#PREFIX#userstogroups AS userstogroups', 'groupid')->label(t('Groups'))->group($account)
                ->values(self::groupNames(...)),
        ];

        $all = [];
        foreach ($list as $attribute) {
            $all[$attribute->key()] = $attribute;
        }

        return $all;
    }

    /**
     * The groups by id, for the groups attribute's choices; none where the table is missing.
     *
     * @return array<int, string>
     */
    public static function groupNames(): array
    {
        try {
            $rows = \Pramnos\Database\Database::getInstance()->queryBuilder()
                ->table('#PREFIX#usergroups')->select(['groupid', 'name'])->orderBy('name')->getAll();
        } catch (\Throwable) {
            return [];
        }
        $names = [];
        foreach ($rows as $row) {
            $names[(int) $row['groupid']] = (string) $row['name'];
        }

        return $names;
    }
}
