<?php

declare(strict_types=1);

namespace Pramnos\Auth\Drivers;

use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;

/**
 * Default authentication driver — verifies credentials against the `users` table.
 *
 * This driver is equivalent to the legacy Addon\Auth\UserDatabase addon and is
 * registered automatically by Auth when no addon-based auth handlers are present.
 * Applications that still use the addon continue to work unchanged (BC).
 *
 * Password verification order:
 *   1. bcrypt_verify(password + md5(salt+uid), stored_hash)  → success
 *   2. [legacy_md5=true] md5(password) == stored_hash        → success + optional rehash
 *   3. [encryptedPassword=true] direct string comparison      → success
 *   4. failure
 *
 * Configuration (from app.php 'auth' key):
 *   legacy_md5   (bool, default false) — accept MD5 passwords from old stores
 *   auto_upgrade (bool, default true)  — rehash matched MD5 passwords to bcrypt
 *
 */
class DatabaseAuthDriver implements AuthDriverInterface
{
    /**
     * @param array{legacy_md5?: bool, auto_upgrade?: bool} $config
     *   Overrides values from app.php when provided; app.php values are read
     *   lazily if this array is empty (the common case).
     */
    public function __construct(private readonly array $config = []) {}

    /**
     * {@inheritDoc}
     *
     * Status codes mirror the Addon\Auth\UserDatabase convention:
     *   0   — inactive user
     *   2   — deleted user
     *   5   — banned user
     *   400 — wrong password
     *   404 — user not found
     *   1   — active, normal login (default success code)
     */
    public function verify(
        string $username,
        string $password,
        bool   $encryptedPassword = false
    ): AuthResult {
        $database = Factory::getDatabase();

        [$legacyMd5, $autoUpgrade] = $this->resolveConfig();

        $sql = $database->prepareQuery(
            "SELECT `userid`, `username`, `password`, `email`, `active`, `validated` "
            . "FROM `#PREFIX#users` "
            . "WHERE (`username` = %s OR `email` = %s) "
            . "LIMIT 1",
            $username,
            $username
        );

        $result = $database->query($sql);

        if (!$result || !isset($result->numRows) || $result->numRows == 0) {
            return AuthResult::failure("User doesn't exist", 404);
        }

        $row = $result->fields;

        if ($row['active'] == 0 && $row['active'] != 't') {
            return AuthResult::failure('Inactive User', 0);
        }
        if ($row['active'] == 2) {
            return AuthResult::failure('Deleted User', 2);
        }
        if ($row['active'] == 5) {
            return AuthResult::failure('Banned User', 5);
        }

        $uid = (int) $row['userid'];

        /**
         * Paths 1 and 2: every scheme a stored hash might be in, in one call.
         *
         * This method used to compose the peppered input itself and compare only that,
         * with a separate MD5 branch beside it — the same logic
         * {@see \Pramnos\User\User::verifyPassword()} also carried, in its own copy. Two
         * copies of "is this the right password" is one too many, and they had already
         * drifted: neither recognised the plain `password_hash($password)` an application
         * writes for its own accounts, so a shared `users` table meant the front door
         * refused correct passwords on half its rows.
         *
         * A successful match reports **which** scheme matched, which is what makes the
         * upgrade below possible for more than MD5.
         */
        $scheme = $encryptedPassword
            ? null
            : \Pramnos\Auth\PasswordHash::verify($password, (string) $row['password'], $uid, $legacyMd5);

        if ($scheme !== null) {
            // Auto-upgrade, when the application allows it and the hash is not already in
            // the preferred scheme. Writing the *preferred* scheme rather than the one
            // this driver used to write is the point: an upgrade to a scheme that is
            // itself outdated has to be done twice.
            // Everything except a plain `password_hash()` row, which may belong to another
            // writer sharing this table — rewriting it would leave that writer unable to
            // read what it wrote. `all` is how an application says the table is its own.
            // See {@see \Pramnos\User\User::upgradePasswordHash()}.
            $upgradable = $this->rehashPolicy === 'all'
                || $scheme !== \Pramnos\Auth\PasswordHash::SCHEME_PLAIN;
            if ($autoUpgrade && $upgradable
                && \Pramnos\Auth\PasswordHash::needsUpgrade($scheme, (string) $row['password'])) {
                try {
                    $newHash = \Pramnos\Auth\PasswordHash::make($password, $uid);
                    $database->query($database->prepareQuery(
                        "UPDATE `#PREFIX#users` SET `password` = %s WHERE `userid` = %d",
                        $newHash,
                        $uid
                    ));
                    $row['password'] = $newHash;
                } catch (\Throwable $ex) {
                    // A login must not fail because the upgrade could not be written.
                    \Pramnos\Logs\Logger::log(
                        'Password hash upgrade failed for user ' . $uid . ': ' . $ex->getMessage()
                    );
                }
            }

            return AuthResult::success(
                $row['username'], $uid, $row['email'], $row['password'],
                (int) $row['active']
            );
        }

        // Path 3: pre-hashed password direct comparison (used by cookie re-auth)
        if ($encryptedPassword && $password === $row['password']) {
            return AuthResult::success(
                $row['username'], $uid, $row['email'], $row['password'],
                (int) $row['active']
            );
        }

        return AuthResult::failure('Wrong Password!', 400);
    }

    /**
     * Resolve effective configuration: constructor config takes priority, then
     * app.php 'auth' key, then built-in defaults.
     *
     * @return array{bool, bool} [legacyMd5, autoUpgrade]
     */
    /** The resolved rehash policy: `off`, `modern` or `all`. Set by {@see resolveConfig()}. */
    private string $rehashPolicy = 'modern';

    private function resolveConfig(): array
    {
        $appConfig = [];

        // A configuration lookup, which is exactly what `getInstance()` must not be used
        // for: it is a factory, and reading two boolean settings does not warrant
        // constructing an application, a database connection and a session. The
        // `!== null` below has been here all along, describing behaviour the call could
        // not produce.
        $app = Application::currentInstance();
        if ($app !== null) {
            $appConfig = $app->applicationInfo['auth'] ?? [];
        }

        $legacyMd5 = (bool) ($this->config['legacy_md5'] ?? $appConfig['legacy_md5'] ?? false);

        // One decision, and it used to have two names: this driver's own boolean
        // `auto_upgrade`, and `rehash_on_login` — which `User::verifyPassword()` reads and
        // which the guide documents. So a project that turned rehashing off got a login
        // that upgraded the row anyway, which is worse than either behaviour: whichever
        // one you thought you had configured, the other one happened somewhere.
        //
        // `rehash_on_login` decides, `auto_upgrade` is honoured as the older name for the
        // same thing, and both defaults are the same as before.
        $policy = (string) ($this->config['rehash_on_login'] ?? $appConfig['rehash_on_login'] ?? '');
        if (!in_array($policy, ['off', 'modern', 'all'], true)) {
            $policy = 'modern';
        }

        $autoUpgrade = (bool) ($this->config['auto_upgrade'] ?? $appConfig['auto_upgrade'] ?? true)
            && $policy !== 'off';

        // md5 is only rewritten when the application asked for `all`, as in
        // {@see \Pramnos\User\User::upgradePasswordHash()}.
        $this->rehashPolicy = $policy;

        return [$legacyMd5, $autoUpgrade];
    }
}
