<?php

/**
 * Fixture: timestamped migration for MigrationLoaderUnitTest.
 * Slug: ml_create_alpha | Timestamp: 2024_01_01_000000
 * Feature: core | Scope: framework | Priority: 10
 */
class Ml_CreateAlpha extends \Pramnos\Database\Migration
{
    public string $feature  = 'core';
    public string $scope    = 'framework';
    public int    $priority = 10;

    public function __construct(?\Pramnos\Application\Application $app = null)
    {
        // Nullable constructor so the fixture can be instantiated in unit tests
        // without a real Application (the loader passes the mock app here).
        if ($app !== null) {
            parent::__construct($app);
        }
    }

    public function up(): void {}
    public function down(): void {}
}
