<?php

declare(strict_types=1);

return [
    'bootstrap' => [
        \common\bootstrap\MailerBootstrap::class,
    ],
    // DI bindings (DIP, §12): ports -> adapters resolved from the container, never
    // `new`ed in callers. Swapping the LLM generator or a future API exporter is a
    // one-line change here. Stateless services (classifiers/normalizer/validator)
    // have no-arg constructors and are auto-wired, so they need no explicit binding.
    'container' => [
        'singletons' => [
            \common\adgen\AdCopyGenerator::class => \common\adgen\TemplateAdCopyGenerator::class,
            \common\export\CampaignExporter::class => \common\export\GoogleAdsEditorExporter::class,
            // Repository ports -> PG adapters (§15.4). Closures wire the app db at the
            // composition root; nothing else `new`s a repository.
            \common\repositories\KeywordRepositoryInterface::class => static fn () => new \common\repositories\PgKeywordRepository(\Yii::$app->db),
            \common\repositories\ConfigRepositoryInterface::class => static fn () => new \common\repositories\PgConfigRepository(\Yii::$app->db),
            \common\repositories\AdGroupRepositoryInterface::class => static fn () => new \common\repositories\PgAdGroupRepository(\Yii::$app->db),
            \common\repositories\NegativeKeywordRepositoryInterface::class => static fn () => new \common\repositories\PgNegativeKeywordRepository(\Yii::$app->db),
            \common\repositories\ImportBatchRepositoryInterface::class => static fn () => new \common\repositories\PgImportBatchRepository(\Yii::$app->db),
        ],
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
