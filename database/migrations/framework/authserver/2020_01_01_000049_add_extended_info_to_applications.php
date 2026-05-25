<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Adds extended metadata columns to the applications table.
 *
 * These fields allow richer OAuth2 client registration (support contact,
 * legal URLs, version tracking, and logo display) matching the full
 * Urbanwater Application model.
 *
 * @package PramnosFramework
 */
class AddExtendedInfoToApplications extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 61;
    public array  $dependencies = ['create_applications_table'];
    public $description  = 'Adds supportemail, termsurl, privacyurl, appversion, logourl to applications';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasColumn('applications', 'supportemail')) {
            return;
        }

        $schema->alterTable('applications', function ($table) {
            $table->string('supportemail', 255)->nullable()
                ->comment('Support contact email shown on consent screens');
            $table->string('termsurl', 500)->nullable()
                ->comment('URL to the application terms of service');
            $table->string('privacyurl', 500)->nullable()
                ->comment('URL to the application privacy policy');
            $table->string('appversion', 50)->default('')
                ->comment('Application version string — managed by the client application');
            $table->string('logourl', 500)->nullable()
                ->comment('URL to the application logo image');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        $cols = ['supportemail', 'termsurl', 'privacyurl', 'appversion', 'logourl'];
        foreach ($cols as $col) {
            if ($schema->hasColumn('applications', $col)) {
                $schema->alterTable('applications', function ($table) use ($col) {
                    $table->dropColumn($col);
                });
            }
        }
    }
}
