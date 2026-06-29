<?php

declare(strict_types=1);

return [
    'bootstrap' => [
        \common\bootstrap\MailerBootstrap::class,
    ],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'vendorPath' => dirname(dirname(__DIR__)) . '/vendor',
    'components' => [
        'cache' => [
            'class' => \yii\caching\FileCache::class,
        ],
        // RBAC backend (DbManager) — shared by console (seed migration) and backend
        // (admin access control, Phase 7). Roles admin/marketer seeded per ADR 0006.
        'authManager' => [
            'class' => \yii\rbac\DbManager::class,
        ],
    ],
];
