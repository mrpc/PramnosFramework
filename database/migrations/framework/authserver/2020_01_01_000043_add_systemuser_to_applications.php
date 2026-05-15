<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Adds the systemuser column to the applications table.
 *
 * When a client_credentials grant is issued via JWT client assertion
 * (RFC 7523), the authorization server creates one dedicated system user
 * per application and stores its userid here.  Subsequent requests reuse
 * that user instead of creating a new one on every token request.
 *
 * @package PramnosFramework
 */
class AddSystemuserToApplications extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 57;
    public array  $dependencies = ['create_applications_table'];
    public $description  = 'Adds systemuser column to applications for JWT client_credentials system account';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasColumn('applications', 'systemuser')) {
            return;
        }

        $schema->alterTable('applications', function ($table) {
            $table->bigInteger('systemuser')->nullable()
                ->comment('FK to users.userid — dedicated system account for client_credentials JWT grant');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasColumn('applications', 'systemuser')) {
            return;
        }

        $schema->alterTable('applications', function ($table) {
            $table->dropColumn('systemuser');
        });
    }
}
