<?php

declare(strict_types=1);

namespace Pramnos\Security;

/**
 * Which tables and columns hold data about people.
 *
 * Read by anything that would otherwise hand rows to somebody — today
 * `db-inspect` over MCP, which is how a developer asks a production database a
 * question without opening a shell on it.
 *
 * ## Why a denial list, and what that costs
 *
 * The framework's own {@see \Pramnos\Mcp\PublicRegistry} argues the opposite case
 * for *tools*, and the argument is right there: «filtering a shared list would
 * mean every future tool is public until somebody remembers to exclude it». The
 * same shape applied to tables would mean an application's new `customers` table
 * is readable until somebody updates a list, and the failure is silent.
 *
 * Tables are not tools, though. An unknown tool is dangerous by definition; an
 * unknown table is usually mundane, and an allow-list would mean nothing new can
 * be looked at until a person adds it — friction on exactly the diagnosis the
 * tool exists for. So this is a denial list, and the cost of that choice is paid
 * by the second half:
 *
 * **Column names are matched wherever they appear.** A table nobody thought to
 * list still has its `email`, `password`, `token` and `phone` withheld, because
 * the guess that catches an unlisted table is the column it stores people in. It
 * is a heuristic and it is stated as one: it reduces what leaks from a table
 * nobody classified, and it is not a substitute for classifying it.
 *
 * ## Declaring your own
 *
 * ```php
 * // app.php
 * 'personal_data' => [
 *     'tables'  => ['customers', 'support_tickets'],
 *     'columns' => ['tax_number'],
 *     // Replace the framework's lists instead of adding to them. Rarely right.
 *     'replace' => false,
 * ],
 * ```
 *
 * `create:migration` asks whether each table it generates holds personal data and
 * prints the declaration to paste, which is the point at which somebody actually
 * knows the answer.
 *
 * Loaded from `app.php` in {@see \Pramnos\Application\Application::init()} and
 * again in {@see \Pramnos\Console\Application::__construct()} — a console
 * command runs neither the other's lifecycle, and `db-inspect` is a console tool.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class PersonalDataRegistry
{
    /** @var array<string, true> Tables whose rows are not returned */
    protected static array $tables = [];

    /** @var array<string, true> Column names withheld wherever they appear */
    protected static array $columns = [];

    /** @var bool Whether the framework's own declarations have been loaded */
    protected static bool $defaultsLoaded = false;

    /**
     * Declare a table as holding data about people.
     *
     * Its rows are not returned; its structure and aggregates still are, because
     * «how many are there» is the question a diagnosis usually asks and it
     * exposes nobody.
     */
    public static function registerTable(string $table): void
    {
        static::ensureDefaults();

        $table = static::normalise($table);

        if ($table !== '') {
            static::$tables[$table] = true;
        }
    }

    /**
     * Withhold a column name wherever it appears, in any table.
     */
    public static function registerColumn(string $column): void
    {
        static::ensureDefaults();

        $column = strtolower(trim($column));

        if ($column !== '') {
            static::$columns[$column] = true;
        }
    }

    /**
     * Does this table hold data about people?
     *
     * Schema-qualified names are compared on the bare name as well, so
     * `authserver.user_consents` matches a declaration of either form.
     */
    public static function isPersonalTable(string $table): bool
    {
        static::ensureDefaults();

        $table = static::normalise($table);

        if (isset(static::$tables[$table])) {
            return true;
        }

        // `pramnos_users` on a prefixed installation is `users`. The prefix is not
        // part of what the table *is*, and a declaration should not have to know it.
        foreach (array_keys(static::$tables) as $declared) {
            if ($declared !== '' && str_ends_with($table, '_' . $declared)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Should this column be withheld?
     *
     * Matched on the whole name and on a `_`-separated tail, so `billing_email`
     * and `user_phone` are caught by declaring `email` and `phone`.
     */
    public static function isPersonalColumn(string $column): bool
    {
        static::ensureDefaults();

        $column = strtolower(trim($column));

        if ($column === '') {
            return false;
        }

        if (isset(static::$columns[$column])) {
            return true;
        }

        foreach (array_keys(static::$columns) as $declared) {
            if (str_ends_with($column, '_' . $declared) || str_starts_with($column, $declared . '_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every declared table.
     *
     * @return array<int, string>
     */
    public static function tables(): array
    {
        static::ensureDefaults();

        return array_keys(static::$tables);
    }

    /**
     * Every declared column name.
     *
     * @return array<int, string>
     */
    public static function columns(): array
    {
        static::ensureDefaults();

        return array_keys(static::$columns);
    }

    /**
     * Apply an application's `personal_data` block from `app.php`.
     *
     * Adds to the framework's lists by default. `'replace' => true` starts from
     * nothing instead, which is rarely what anybody wants — the framework's
     * entries are its own tables, and an application removing them is saying it
     * knows better about `usertokens`.
     *
     * @param array<string, mixed> $config
     */
    public static function loadFromConfig(array $config): void
    {
        static::ensureDefaults();

        if (!empty($config['replace'])) {
            static::$tables  = [];
            static::$columns = [];
        }

        foreach ((array) ($config['tables'] ?? []) as $table) {
            if (is_string($table)) {
                static::registerTable($table);
            }
        }

        foreach ((array) ($config['columns'] ?? []) as $column) {
            if (is_string($column)) {
                static::registerColumn($column);
            }
        }
    }

    /**
     * Forget everything, including the framework's own.
     */
    public static function reset(): void
    {
        static::$tables         = [];
        static::$columns        = [];
        static::$defaultsLoaded = false;
    }

    /**
     * A table name as it is compared: lower case, unquoted, unqualified, and with
     * the `#PREFIX#` placeholder gone.
     *
     * Public because a caller that *reports* which tables it matched should report
     * the name this class compared, not the raw token it read out of a statement —
     * `#prefix#users` in an answer is noise, and two spellings of one table in a
     * list are a bug waiting to be believed.
     */
    public static function normalise(string $table): string
    {
        $table = strtolower(trim($table));
        $table = str_replace(array('"', '`', '[', ']'), '', $table);
        // Framework SQL is written against `#PREFIX#users`, not `users`. The
        // placeholder is the prefix, so it is dropped for the same reason the
        // real prefix is: neither is part of what the table holds.
        $table = str_replace('#prefix#', '', $table);

        if (str_contains($table, '.')) {
            $table = substr($table, strrpos($table, '.') + 1);
        }

        return $table;
    }

    protected static function ensureDefaults(): void
    {
        if (static::$defaultsLoaded) {
            return;
        }
        static::$defaultsLoaded = true;

        foreach (static::frameworkTables() as $table) {
            static::$tables[$table] = true;
        }

        foreach (static::frameworkColumns() as $column) {
            static::$columns[$column] = true;
        }
    }

    /**
     * The framework's own tables that hold data about people.
     *
     * Credentials, identity, and the GDPR record-keeping that exists precisely
     * because these are personal. `tokenactions` is here too: a request log keyed
     * to a token is a record of what one person did and when.
     *
     * @return array<int, string>
     */
    protected static function frameworkTables(): array
    {
        return array(
            'users',
            'usertokens',
            'tokenactions',
            'user_twofactor',
            'twofactor_setup',
            'twofactor_attempts',
            'twofactor_email_codes',
            'passkey_credentials',
            'password_history',
            'loginlockout',
            'user_activity_log',
            'user_consents',
            'user_privacy_settings',
            'gdpr_requests',
            'data_processing_records',
            'oauth2_user_consents',
            'oauth2_device_codes',
            'mails',
            'notifications',
            'pushtokens',
        );
    }

    /**
     * Column names withheld wherever they appear.
     *
     * The half that covers a table nobody classified. Short and generic on
     * purpose: these are the names people actually use, and a longer list of
     * specific ones would give a false sense that the set is complete.
     *
     * @return array<int, string>
     */
    protected static function frameworkColumns(): array
    {
        return array(
            'email',
            'password',
            'passwd',
            'token',
            'secret',
            'apikey',
            'phone',
            'mobile',
            'address',
            'ip',
            'ipaddress',
            'iban',
            'vat',
            'ssn',
            'fullname',
            'firstname',
            'lastname',
            'birthdate',
        );
    }
}
