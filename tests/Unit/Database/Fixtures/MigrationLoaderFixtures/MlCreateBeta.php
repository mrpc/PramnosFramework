<?php

/**
 * Fixture: non-timestamped CamelCase migration for MigrationLoaderUnitTest.
 * Slug derived from class name: ml_create_beta
 * Feature: auth | Scope: app | Priority: 20
 */
class MlCreateBeta extends \Pramnos\Database\Migration
{
    public string $feature  = 'auth';
    public string $scope    = 'app';
    public int    $priority = 20;

    public function __construct(?\Pramnos\Application\Application $app = null)
    {
        if ($app !== null) {
            parent::__construct($app);
        }
    }

    public function up(): void {}
    public function down(): void {}
}
