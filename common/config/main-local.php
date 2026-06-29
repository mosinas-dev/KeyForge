<?php

return [
    'container' => [
        'singletons' => [
            \yii\mail\MailerInterface::class => [
                'class' => \yii\symfonymailer\Mailer::class,
                'viewPath' => '@common/mail',
            ],
        ],
    ],
    'components' => [
        // KeyForge runs on PostgreSQL only (see docs/adr/0002-postgresql-datastore.md).
        // Connection is env-driven (KEYFORGE_DB_*) so the SAME committed config works
        // locally, in CI, and in the zero-touch Docker image — no `yii init` needed.
        // Defaults mirror docker-compose.yml / .env.example for a bare `php yii` run.
        'db' => [
            'class' => \yii\db\Connection::class,
            'dsn' => 'pgsql:host=' . (getenv('KEYFORGE_DB_HOST') ?: '127.0.0.1')
                . ';port=' . (getenv('KEYFORGE_DB_PORT') ?: '5432')
                . ';dbname=' . (getenv('KEYFORGE_DB_NAME') ?: 'keyforge'),
            'username' => getenv('KEYFORGE_DB_USER') ?: 'keyforge',
            'password' => getenv('KEYFORGE_DB_PASSWORD') ?: 'keyforge_local_pw',
            'charset' => 'utf8',
        ],
        'mailer' => \yii\mail\MailerInterface::class,
    ],
];
