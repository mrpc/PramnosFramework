<?php

namespace Pramnos\Database\Migrations;

use Pramnos\Database\Migration;

/**
 * CreateUserAppAuthorizationsTable migration.
 *
 * Creates the authserver.user_app_authorizations table for tracking OAuth consent
 * and authorization scope grants per user/application pair. This enables users
 * to revoke application access and tracks when consent was given.
 *
 * Dependent on: CreateUsersTable (auth/000010) and CreateApplicationsTable (authserver/000025)
 */
class CreateUserAppAuthorizationsTable extends Migration
{
    /**
     * Run the migration.
     *
     * @return void
     */
    public function up(): void
    {
        $this->schema('authserver')
            ->create('user_app_authorizations', function ($table) {
                $table->increments('id');

                // Foreign keys
                $table->bigInteger('userid')->unsigned()->notNull();
                $table->integer('appid')->unsigned()->notNull();

                // Scope information (JSON array of granted scopes)
                if ($this->DB()->getDriverName() === 'pgsql') {
                    $table->addColumn('text[]', 'scope')->nullable();
                } else {
                    $table->json('scope')->nullable();
                }

                // Authorization status
                $table->enum('status', ['granted', 'revoked', 'pending', 'expired'])
                    ->default('granted');

                // Authorization timestamps
                $table->timestamp('granted_at')->useCurrent();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('last_used_at')->nullable();

                // Tracking info
                $table->bigInteger('requested_by')->unsigned()->nullable()
                    ->comment('User who approved this authorization (e.g., admin)');

                $table->string('user_agent', 255)->nullable()
                    ->comment('User agent from authorization request');
                $table->string('ip_address', 45)->nullable()
                    ->comment('IP address from authorization request (IPv4 or IPv6)');

                // Indexes
                $table->unique(['userid', 'appid'], 'idx_user_app_auth_unique');
                $table->index('userid', 'idx_user_app_auth_userid');
                $table->index('appid', 'idx_user_app_auth_appid');
                $table->index('status', 'idx_user_app_auth_status');
                $table->index('revoked_at', 'idx_user_app_auth_revoked_at');

                // Foreign keys
                $table->foreign('userid')
                    ->references('userid')
                    ->on('public.users')
                    ->onDelete('cascade')
                    ->onUpdate('cascade');

                $table->foreign('appid')
                    ->references('appid')
                    ->on('public.applications')
                    ->onDelete('cascade')
                    ->onUpdate('cascade');

                $table->foreign('requested_by')
                    ->references('userid')
                    ->on('public.users')
                    ->onDelete('set null')
                    ->onUpdate('cascade');
            });

        // Add comment to table (PostgreSQL)
        if ($this->DB()->getDriverName() === 'pgsql') {
            $this->DB()->statement(
                "COMMENT ON TABLE authserver.user_app_authorizations IS 
                'OAuth consent and authorization scope grants per user/application pair'"
            );
        }
    }

    /**
     * Rollback the migration.
     *
     * @return void
     */
    public function down(): void
    {
        $this->schema('authserver')
            ->dropIfExists('user_app_authorizations');
    }
}
