<?php

// Codeception Yii2-module app config for the `common` suites.
// Committed (not init-generated) so tests run with no `yii init` step in CI/Docker.
return yii\helpers\ArrayHelper::merge(
    require __DIR__ . '/main.php',
    require __DIR__ . '/main-local.php',
    require __DIR__ . '/test.php',
    require __DIR__ . '/test-local.php',
    [
        'components' => [
            'request' => [
                // Fixed dev/test key — not a production secret.
                'cookieValidationKey' => getenv('KEYFORGE_COOKIE_VALIDATION_KEY') ?: 'keyforge-test-cookie-key',
            ],
        ],
    ]
);
