<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;

/**
 * Indexes on `LOWER(username)` and `LOWER(email)` of the users table, on PostgreSQL: signing in
 * finds an account by its name or address whatever the case it is typed in
 * ({@see \Pramnos\User\User::whereLogin()}), which without them reads the whole table at every
 * sign-in. MySQL compares through its case-insensitive collation and its own indexes: nothing
 * to add there. DDL the builder has no word for, so written out.
 */
class AddCaseInsensitiveLoginIndexesToUsers extends Migration
{
    public string $feature     = 'auth';
    public string $scope       = 'framework';
    public int    $priority    = 50;
    public $description = 'Indexes the users table by its name and address in lower case, for sign-in';

    /** Add them. */
    public function up(): void
    {
        $db = $this->application->database;
        if ($db->type !== 'postgresql' || !$db->schema()->hasTable('#PREFIX#users')) {
            return;
        }
        $users = $db->schema()->quoteTable('#PREFIX#users');
        $db->query('CREATE INDEX IF NOT EXISTS idx_users_lower_username ON ' . $users . ' (LOWER(username))');
        $db->query('CREATE INDEX IF NOT EXISTS idx_users_lower_email ON ' . $users . ' (LOWER(email))');
    }

    /** Take them away. */
    public function down(): void
    {
        $db = $this->application->database;
        if ($db->type !== 'postgresql') {
            return;
        }
        $db->query('DROP INDEX IF EXISTS idx_users_lower_username');
        $db->query('DROP INDEX IF EXISTS idx_users_lower_email');
    }
}
