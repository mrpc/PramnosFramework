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
        $salt = Settings::getSetting('securitySalt');
        $pwd  = $password . md5($salt . $uid);

        // Path 1: bcrypt verification (normal path)
        if (!$encryptedPassword && password_verify($pwd, $row['password'])) {
            return AuthResult::success(
                $row['username'], $uid, $row['email'], $row['password'],
                (int) $row['active']
            );
        }

        // Path 2: legacy MD5 comparison + optional auto-upgrade
        if ($legacyMd5 && !$encryptedPassword && md5($password) === $row['password']) {
            if ($autoUpgrade) {
                $newHash = password_hash($pwd, PASSWORD_DEFAULT);
                $updateSql = $database->prepareQuery(
                    "UPDATE `#PREFIX#users` SET `password` = %s WHERE `userid` = %d",
                    $newHash,
                    $uid
                );
                $database->query($updateSql);
                $row['password'] = $newHash;
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
    private function resolveConfig(): array
    {
        $appConfig = [];
        $app = Application::getInstance();
        if ($app !== null) {
            $appConfig = $app->applicationInfo['auth'] ?? [];
        }

        $legacyMd5   = (bool) ($this->config['legacy_md5']   ?? $appConfig['legacy_md5']   ?? false);
        $autoUpgrade = (bool) ($this->config['auto_upgrade']  ?? $appConfig['auto_upgrade']  ?? true);

        return [$legacyMd5, $autoUpgrade];
    }
}
