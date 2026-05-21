<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;
use Pramnos\Database\DatabaseCapabilities;

/**
 * Creates the users table — the central user account registry.
 *
 * This schema matches the UrbanWater production schema exactly. The `usertype`
 * column encodes the role at the account level (0=simple, 1=salesman, 2=admin),
 * while fine-grained permissions are managed through the authserver RBAC tables.
 *
 * @package PramnosFramework
 */
class CreateUsersTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 10;
    public $description  = 'Creates the users table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('users')) {
            return;
        }

        $schema->createTable('users', function ($table) {
            $table->comment('User account registry — one row per registered user');

            // BIGSERIAL in PostgreSQL is a SIGNED bigint — use bigInteger+autoIncrement
            // to produce BIGINT AUTO_INCREMENT (signed) on MySQL, matching the legacy
            // userstogroups FK constraint which declares userid as plain BIGINT.
            $table->bigInteger('userid')->autoIncrement()->primary()
                ->comment('Auto-increment user identifier (BIGSERIAL on PostgreSQL)');
            $table->string('username', 50)->default('')
                ->comment('Unique login name chosen by the user');
            $table->string('password', 100)->default('')
                ->comment('Bcrypt hash of the user password (legacy md5 hashes also accepted during migration)');
            $table->string('email', 150)->default('')
                ->comment('Primary email address; used for login and notifications');
            $table->string('lastname', 128)->default('')
                ->comment('Last name / family name');
            $table->string('firstname', 128)->default('')
                ->comment('First name / given name');
            $table->integer('regdate')->default(0)
                ->comment('Unix timestamp of account registration');
            $table->integer('regcompletion')->nullable()
                ->comment('Unix timestamp when the user completed registration (email verified etc.)');
            $table->integer('lasttermsagreed')->nullable()
                ->comment('Unix timestamp when the user last agreed to the terms of service');
            $table->integer('lastlogin')->default(0)
                ->comment('Unix timestamp of the most recent successful login');
            $table->tinyInteger('active')->default(1)
                ->comment('1 = account is active and can log in; 0 = deactivated');
            $table->tinyInteger('validated')->default(1)
                ->comment('1 = email address has been verified; 0 = pending verification');
            $table->string('language', 50)->default('')
                ->comment('Preferred UI language code (e.g. "el", "en")');
            $table->char('timezone', 3)->default('')
                ->comment('Timezone abbreviation used for date display (e.g. "EET")');
            $table->string('dateformat', 15)->default('d/m/Y H:i')
                ->comment('PHP date format string for the user\'s preferred date/time display');
            $table->tinyInteger('usertype')
                ->comment('Account privilege level: 0 = Simple user, 1 = Salesman, 2 = Administrator');
            $table->tinyInteger('sex')
                ->comment('Gender: 0 = female, 1 = male');
            $table->bigInteger('birthdate')
                ->comment('Birth date as a Unix timestamp (stored as bigint for historical compatibility)');
            $table->integer('photo')->nullable()
                ->comment('usageid reference to the user\'s profile photo in the media/usage table');
            $table->string('phone', 50)->default('')
                ->comment('Primary phone number');
            $table->string('fax', 50)->default('')
                ->comment('Fax number (legacy field, kept for compatibility)');
            $table->string('mobile', 50)->default('')
                ->comment('Mobile / cell phone number');
            $table->string('vat', 15)->default('')
                ->comment('VAT registration number (Greece: ΑΦΜ)');
            $table->string('website', 255)->default('')
                ->comment('User\'s personal or company website URL');
            $table->integer('modified')
                ->comment('Unix timestamp of the last profile modification');
            $table->bigInteger('fbauth')->nullable()
                ->comment('Facebook numeric user ID for OAuth-linked accounts; NULL if not linked');

            $table->index(['username'], 'idx_users_username');
            $table->index(['email'], 'idx_users_email');
            $table->index(['active', 'validated'], 'idx_users_active_validated');
            $table->index(['usertype'], 'idx_users_usertype');
        });

        // Reserve userid=1 for the Guest/anonymous identity by advancing the
        // auto-increment sequence to 2 before any application row is inserted.
        // This ensures the scaffold's first admin user receives userid=2 and
        // does not collide with the Guest account that User::setupDb() seeds
        // separately at userid=1.
        $db = $this->application->database;

        $schema->ifCapable(DatabaseCapabilities::ENGINE_POSTGRESQL, function () use ($db) {
            // setval(seq, 1, true) → current value = 1, is_called = true,
            // so the next nextval() call returns 2.
            $db->query("SELECT setval(pg_get_serial_sequence('users', 'userid'), 1)");
        });

        $schema->ifCapable(DatabaseCapabilities::ENGINE_MYSQL, function () use ($db) {
            $db->query('ALTER TABLE users AUTO_INCREMENT = 2');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('users');
    }
}
