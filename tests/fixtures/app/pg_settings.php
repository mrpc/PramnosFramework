<?php
/**
 * PostgreSQL-only settings fixture.
 *
 * Used by characterization/integration tests that need Factory::getDatabase()
 * to return a PostgreSQL connection instead of the default MySQL one.
 * Load this file via Settings::loadSettings() in setUp() of tests that are
 * annotated with #[RunTestsInSeparateProcesses] (clean static state).
 */
return [
    'database' => [
        'hostname' => 'timescaledb',
        'user'     => 'postgres',
        'password' => 'secret',
        'database' => 'pramnos_test',
        'type'     => 'postgresql',
        'port'     => 5432,
        'prefix'   => '',
        'schema'   => 'public',
    ],
    'securitySalt' => 'test_salt_123456789',
];
