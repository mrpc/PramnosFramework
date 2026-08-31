<?php
return [
    'database' => [
        'hostname' => 'db', // Docker MySQL service
        'user' => 'root',
        'password' => 'secret',
        'database' => 'pramnos_test',
        'type' => 'mysql',
        // Strict mode, so a failed query is an exception on **every** backend.
        // mysqli throws by default; pg_* returns false. Without this the PostgreSQL
        // lane silently tolerates failures the MySQL lane surfaces, and a test that
        // passes on one engine proves nothing about the other.
        'throwOnError' => true,
        'port' => 3306,
        'prefix' => '',
        'collation' => 'utf8mb4'
    ],
    'postgresql' => [
        'hostname' => 'timescaledb', // Docker Postgres service
        'user' => 'postgres',
        'password' => 'secret',
        'database' => 'pramnos_test',
        'type' => 'postgresql',
        'port' => 5432,
        'prefix' => '',
        'schema' => 'public'
    ],
    'securitySalt' => 'test_salt_123456789'
];
