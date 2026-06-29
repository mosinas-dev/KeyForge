<?php

// Test DB connection (PostgreSQL). Env-driven so the same committed config runs
// locally, in CI (KEYFORGE_DB_NAME=keyforge_test), and in the Docker `test` profile.
// Defaults to KEYFORGE_DB_NAME; set KEYFORGE_TEST_DB_NAME to isolate from dev data.
return [
    'components' => [
        'db' => [
            'dsn' => 'pgsql:host=' . (getenv('KEYFORGE_DB_HOST') ?: '127.0.0.1')
                . ';port=' . (getenv('KEYFORGE_DB_PORT') ?: '5432')
                . ';dbname=' . (getenv('KEYFORGE_TEST_DB_NAME') ?: (getenv('KEYFORGE_DB_NAME') ?: 'keyforge')),
        ],
    ],
];
