<?php
return [
    'database' => [
        'hostname' => 'db', // Docker MySQL service
        'user' => 'root',
        'password' => 'secret',
        'database' => 'pramnos_test',
        'type' => 'mysql',
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
