<?php

// Test DB connection (PostgreSQL). Always a DEDICATED test database (default
// `keyforge_test`) so tests never touch dev data; host/port/user/pass come from
// KEYFORGE_DB_*. CI runs `php yii migrate` with KEYFORGE_DB_NAME=keyforge_test so
// the migrated DB and this test connection point at the same place.
return [
    'components' => [
        'db' => [
            'dsn' => 'pgsql:host=' . (getenv('KEYFORGE_DB_HOST') ?: '127.0.0.1')
                . ';port=' . (getenv('KEYFORGE_DB_PORT') ?: '5432')
                . ';dbname=' . (getenv('KEYFORGE_TEST_DB_NAME') ?: 'keyforge_test'),
        ],
    ],
];
