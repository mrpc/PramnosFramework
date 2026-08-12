<?php

namespace Pramnos\Database;

/**
 * Runtime detection and management of database engine capabilities.
 *
 * Results are cached per database-connection object (a WeakMap keyed by the
 * live Database instance) so repeated has() calls incur no extra queries.
 *
 * ## MariaDB is a flavor, not a separate engine
 *
 * MariaDB connects through mysqli, speaks MySQL's wire protocol and is
 * configured in this framework as `type = 'mysql'`.  This class therefore
 * treats MariaDB as a *member of the MySQL family*:
 *
 *   - `isMySQL()` returns **true** on MariaDB. This is deliberate and load
 *     bearing: every existing `isMySQL()` call site in the framework uses it to
 *     mean "compile MySQL-compatible grammar / use backtick quoting /
 *     `information_schema` introspection", all of which are correct on MariaDB.
 *     Flipping it to false would silently route MariaDB down the PostgreSQL
 *     branch of every one of those gates.
 *   - `isMariaDB()` is the *narrowing* predicate. It answers "is this MySQL
 *     server specifically MariaDB", and it is only ever true when `isMySQL()`
 *     is also true.
 *
 * The practical rule: ask a feature question (`has(self::SEQUENCES)`), not an
 * identity question. Engine identity is for grammar selection; the feature
 * constants below are for behaviour.
 *
 * ## Version-aware features
 *
 * SEQUENCES / RETURNING / NATIVE_JSON / CHECK_CONSTRAINTS resolve from the
 * triple (engine, flavor, server version).  When the server version cannot be
 * determined — an unconnected Database, as unit tests construct — the answer is
 * the conservative one (false), so callers keep their pre-existing behaviour.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license    MIT
 */
class DatabaseCapabilities
{
    // -------------------------------------------------------------------------
    // Engine constants
    // -------------------------------------------------------------------------

    const ENGINE_MYSQL      = 'mysql';
    const ENGINE_POSTGRESQL = 'postgresql';

    /**
     * MariaDB flavor of the MySQL family.  ENGINE_MYSQL stays true alongside
     * it — see the class docblock for why.
     */
    const ENGINE_MARIADB    = 'mariadb';

    // -------------------------------------------------------------------------
    // Feature constants
    // -------------------------------------------------------------------------

    const TIMESCALEDB        = 'timescaledb';
    const JSONB              = 'jsonb';
    const MATERIALIZED_VIEWS = 'materialized_views';
    const ENUMS              = 'enums';
    const FEATURE_JSON       = 'json';
    const FEATURE_FULLTEXT   = 'fulltext';
    const FEATURE_SPATIAL    = 'spatial';

    /**
     * Native named sequences (CREATE SEQUENCE / NEXTVAL / SETVAL).
     * PostgreSQL: always.  MariaDB: 10.3+.  Oracle MySQL: never.
     */
    const SEQUENCES          = 'sequences';

    /**
     * A RETURNING clause on data-modifying statements.
     * PostgreSQL: all statements.  MariaDB: INSERT (10.5+) and DELETE (10.0+),
     * modelled here as 10.5+.  Oracle MySQL: never.
     */
    const RETURNING          = 'returning';

    /**
     * A genuine, natively-typed JSON column with binary storage and validation.
     * PostgreSQL: json/jsonb.  Oracle MySQL: 5.7.8+.  MariaDB: **no** — its
     * `JSON` is an alias for LONGTEXT with a `CHECK (json_valid(...))`
     * constraint, so it parses but is neither binary nor a distinct type.
     */
    const NATIVE_JSON        = 'native_json';

    /**
     * Enforced CHECK constraints.
     * PostgreSQL: always.  MariaDB: 10.2+.  Oracle MySQL: 8.0.16+ (earlier
     * versions parse CHECK and silently ignore it).
     */
    const CHECK_CONSTRAINTS  = 'check_constraints';

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    /** @var Database */
    protected $db;

    /**
     * WeakMap<Database, array<string, bool>>
     *
     * Keyed by the live Database object — entries are automatically removed
     * when the object is garbage-collected, so no stale entries survive between
     * test cases or across long-lived processes that cycle through connections.
     *
     * @var \WeakMap|null
     */
    protected static $cache = null;

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    /**
     * @param Database $db Connection whose capabilities are being described.
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // -------------------------------------------------------------------------
    // Core API
    // -------------------------------------------------------------------------

    /**
     * Fluent alias for has() — matches the Laravel Capsule / migration-file API.
     *
     * @param  string $feature One of the ENGINE_* or feature constants.
     * @return bool
     */
    public function supports($feature): bool
    {
        return $this->has($feature);
    }

    /**
     * Returns true if the connected server supports the given capability.
     * The answer is memoised per Database instance.
     *
     * @param  string $feature One of the ENGINE_* or feature constants.
     * @return bool
     */
    public function has($feature): bool
    {
        $cache = $this->getCache();

        if (!isset($cache[$this->db])) {
            $cache[$this->db] = [];
        }

        $bucket = $cache[$this->db];
        if (array_key_exists($feature, $bucket)) {
            return $bucket[$feature];
        }

        $result            = $this->detect($feature);
        $bucket[$feature]  = $result;
        $cache[$this->db]  = $bucket;

        return $result;
    }

    /**
     * Lazily create the shared WeakMap capability cache.
     *
     * @return \WeakMap
     */
    protected function getCache(): \WeakMap
    {
        if (self::$cache === null) {
            self::$cache = new \WeakMap();
        }
        return self::$cache;
    }

    // -------------------------------------------------------------------------
    // Convenience predicates
    // -------------------------------------------------------------------------

    /**
     * True for every server in the MySQL family — **including MariaDB**.
     *
     * Do not "fix" this to exclude MariaDB: the framework's call sites use it to
     * mean "MySQL-compatible grammar", which MariaDB is.  Use isMariaDB() when
     * you need the narrower question.
     *
     * @return bool
     */
    public function isMySQL(): bool
    {
        return $this->has(self::ENGINE_MYSQL);
    }

    /**
     * True only when the MySQL-family server is specifically MariaDB.
     *
     * Implies isMySQL() === true.  False on PostgreSQL, on Oracle MySQL, and
     * whenever there is no live connection to interrogate.
     *
     * @return bool
     */
    public function isMariaDB(): bool
    {
        return $this->has(self::ENGINE_MARIADB);
    }

    /**
     * @return bool
     */
    public function isPostgreSQL(): bool
    {
        return $this->has(self::ENGINE_POSTGRESQL);
    }

    // -------------------------------------------------------------------------
    // Version
    // -------------------------------------------------------------------------

    /**
     * Normalised numeric server version, e.g. "10.11.6", "8.0.36", "14.10".
     *
     * The raw string from the server carries vendor noise that version_compare()
     * mishandles, so it is stripped here:
     *
     *   - `5.5.5-10.11.6-MariaDB-…` → `10.11.6` (the `5.5.5-` prefix is a
     *     replication-compatibility lie MariaDB tells older clients; taking it
     *     literally would make every MariaDB look like MySQL 5.5)
     *   - `10.11.6-MariaDB-1:10.11.6+maria~ubu2204` → `10.11.6`
     *   - `8.0.36-0ubuntu0.22.04.1` → `8.0.36`
     *
     * @return string Dotted numeric version, or '' when unknown.
     */
    public function getVersion(): string
    {
        $raw = $this->db->getServerVersion();

        if ($raw === '') {
            return '';
        }

        // Drop MariaDB's legacy "5.5.5-" compatibility prefix before parsing.
        if (\preg_match('/^5\.5\.5-(.+)$/', $raw, $stripped)) {
            $raw = $stripped[1];
        }

        if (\preg_match('/^\d+(\.\d+)*/', $raw, $numeric)) {
            return $numeric[0];
        }

        return '';
    }

    /**
     * Is the server at least the given version?
     *
     * Returns false when the version is unknown — an unknown server is assumed
     * to be too old, which keeps new behaviour opt-in rather than accidental.
     *
     * @param  string $version Dotted version to compare against, e.g. "10.3".
     * @return bool
     */
    public function atLeast(string $version): bool
    {
        $current = $this->getVersion();

        if ($current === '') {
            return false;
        }

        return \version_compare($current, $version, '>=');
    }

    public function hasTimescaleDB(): bool
    {
        return $this->has(self::TIMESCALEDB);
    }

    public function hasMaterializedViews(): bool
    {
        return $this->has(self::MATERIALIZED_VIEWS);
    }

    public function hasEnums(): bool
    {
        return $this->has(self::ENUMS);
    }

    /**
     * Native sequence objects (CREATE SEQUENCE / NEXTVAL / SETVAL).
     *
     * @return bool
     */
    public function hasSequences(): bool
    {
        return $this->has(self::SEQUENCES);
    }

    /**
     * RETURNING clause on data-modifying statements.
     *
     * @return bool
     */
    public function hasReturning(): bool
    {
        return $this->has(self::RETURNING);
    }

    /**
     * A genuinely native JSON column type (not LONGTEXT + json_valid()).
     *
     * @return bool
     */
    public function hasNativeJson(): bool
    {
        return $this->has(self::NATIVE_JSON);
    }

    /**
     * Enforced (not merely parsed and discarded) CHECK constraints.
     *
     * @return bool
     */
    public function hasCheckConstraints(): bool
    {
        return $this->has(self::CHECK_CONSTRAINTS);
    }

    // -------------------------------------------------------------------------
    // Conditional execution
    // -------------------------------------------------------------------------

    /**
     * Execute $ifTrue when the capability is present, $ifFalse otherwise.
     * Both callables receive the Database instance as their sole argument.
     *
     * @param  string        $capability
     * @param  callable      $ifTrue
     * @param  callable|null $ifFalse
     * @return mixed
     */
    public function ifCapable($capability, callable $ifTrue, ?callable $ifFalse = null)
    {
        if ($this->has($capability)) {
            return $ifTrue($this->db);
        }

        if ($ifFalse !== null) {
            return $ifFalse($this->db);
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Detection logic
    // -------------------------------------------------------------------------

    /**
     * Resolve a single capability from (engine, flavor, version).
     *
     * @param  string $feature One of the ENGINE_* / feature constants.
     * @return bool
     */
    protected function detect(string $feature): bool
    {
        switch ($feature) {
            case self::ENGINE_MYSQL:
                // Deliberately true on MariaDB as well — see class docblock.
                return $this->db->type === 'mysql';

            case self::ENGINE_MARIADB:
                return $this->db->type === 'mysql' && $this->db->isMariaDB();

            case self::ENGINE_POSTGRESQL:
                return $this->db->type === 'postgresql';

            case self::SEQUENCES:
                if ($this->db->type === 'postgresql') {
                    return true;
                }
                // MariaDB gained real sequence objects in 10.3; Oracle MySQL
                // has none at any version.
                return $this->isMariaDB() && $this->atLeast('10.3');

            case self::RETURNING:
                if ($this->db->type === 'postgresql') {
                    return true;
                }
                // MariaDB 10.5 completed RETURNING for INSERT (DELETE had it
                // since 10.0); 10.5 is the version worth gating on.
                return $this->isMariaDB() && $this->atLeast('10.5');

            case self::NATIVE_JSON:
                if ($this->db->type === 'postgresql') {
                    return true;
                }
                if ($this->isMariaDB()) {
                    // MariaDB's JSON is LONGTEXT + CHECK (json_valid(...)).
                    return false;
                }
                return $this->atLeast('5.7.8');

            case self::CHECK_CONSTRAINTS:
                if ($this->db->type === 'postgresql') {
                    return true;
                }
                return $this->isMariaDB()
                    ? $this->atLeast('10.2')
                    : $this->atLeast('8.0.16');

            case self::TIMESCALEDB:
                return $this->detectTimescaleDB();

            case self::JSONB:
                return $this->db->type === 'postgresql';

            case self::FEATURE_JSON:
                return true; // MySQL 5.7.8+ and all supported PG versions

            case self::FEATURE_FULLTEXT:
                return true; // Both MySQL and PostgreSQL support full-text search

            case self::FEATURE_SPATIAL:
                return true; // Both support spatial (GIS / PostGIS)

            case self::MATERIALIZED_VIEWS:
                return $this->db->type === 'postgresql';

            case self::ENUMS:
                // PostgreSQL supports named ENUM types via CREATE TYPE ... AS ENUM
                return $this->db->type === 'postgresql';
        }

        return false;
    }

    protected function detectTimescaleDB(): bool
    {
        if ($this->db->type !== 'postgresql') {
            return false;
        }

        // Framework config shortcut
        if ($this->db->timescale) {
            return true;
        }

        try {
            $result = $this->db->query("SELECT 1 FROM pg_extension WHERE extname = 'timescaledb'");
            return $result && $result->numRows > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
